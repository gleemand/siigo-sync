<?php

$logger->info('- loading orders for ' . $site);

$now = new \DateTime('now', new DateTimeZone('America/Bogota'));

// payment methods -----------------------------------------------------------------------------------------------------
$paymentMethods = [];

try {
    $response = $client->request('GET', 'https://api.siigo.com/v1/payment-types?document_type=FV', ['headers' => $headers]);
    $result = json_decode($response->getBody()->getContents(), true);
} catch (\Exception $e) {
    $logger->error('✗ '.$e->getMessage());
    die();
}
foreach ($result as $item) {
    $paymentMethods[$item['id']] = $item;
}

// get payment types from crm
$crmPaymentTypes = [];

try {
    usleep(1000000);
    $response = $api->request->paymentTypesList();
    if ($response->isSuccessful()) {
        foreach ($response['paymentTypes'] as $paymentType) {
            if (isset($paymentType['description'])) {
                $crmPaymentTypes[$paymentType['description']] = $paymentType;
            }
        }
    }
} catch (\RetailCrm\Exception\CurlException $e) {
    $logger->error('✗ connection error: ' . $e->getMessage());
    die();
}


// Siigo invoices ------------------------------------------------------------------------------------------------------
$siigoInvoices = [];

$invoceUpdated = null;
$siigoInvoicesUpdatedFile = dirname(__FILE__) . '/siigo/' . $site . '_last_invoice_updated';

if (file_exists($siigoInvoicesUpdatedFile)) {
    $invoceUpdated = file_get_contents($siigoInvoicesUpdatedFile);
}

$url = 'https://api.siigo.com/v1/invoices';

if ($invoceUpdated) {
    $url .= '?created_start=' . $invoceUpdated;

    // to do: check update invoices
    //$url .= '?updated_start=' . $invoceUpdated;

    $logger->info('✓ load from date ' . $invoceUpdated . ' (Bogota time)');
}

// stored data
//$invoicesFile = dirname(__FILE__) . '/siigo/'.$site.'_invoices';
//if (file_exists($invoicesFile)) {
//    $siigoInvoices = unserialize(file_get_contents($invoicesFile));
//} else {
//    file_put_contents($invoicesFile, serialize($siigoInvoices));
//}

do {
    try {
        $response = $client->request('GET', $url, ['headers' => $headers]);
        $result = json_decode($response->getBody()->getContents(), true);
    } catch (\Exception $e) {
        $logger->error('✗ '.$e->getMessage());
        continue;
    }

    foreach ($result['results'] as $item) {

        // only with customers
        if (!isset($item['customer']['id']) || empty($item['customer']['id'])) {
            continue;
        }

        // with id
        if (!isset($item['id']) || empty($item['id'])) {
            continue;
        }

        $siigoInvoices[$item['id']] = $item;
    }

    $url = $result['results'] && isset($result['_links']['next']['href']) ? $result['_links']['next']['href'] : null;
    usleep(300000);

} while ($url);

$logger->info("✓ found ".count($siigoInvoices)." invoice(s) in Siigo");

// prepare orders for retailcrm ----------------------------------------------------------------------------------------
if ($siigoInvoices) {
    foreach ($siigoInvoices as $invoice) {

        usleep(1000000);

        // find customer
        $customer = [];
        if (isset($invoice['customer']['id']) && $invoice['customer']['id']) {

            try {
                $url = 'https://api.siigo.com/v1/customers/' . $invoice['customer']['id'];
                $response = $client->request('GET', $url, ['headers' => $headers]);
                $customer = json_decode($response->getBody()->getContents(), true);

            } catch (\Exception $e) {
                $logger->error('✗ '.$e->getMessage());
                continue;
            }
        }

        $contact = [];

        if (isset($customer['contacts']) && $customer['contacts']) {
            $contact = current($customer['contacts']);
        }

        $address = [];

        if (
            isset($customer['address']['city']['country_code'])
            && $customer['address']['city']['country_code']
        ) {
            $address['countryIso'] = mb_strtoupper($customer['address']['city']['country_code']);
        }

        if (
            isset($customer['address']['city']['state_name'])
            && $customer['address']['city']['state_name']
        ) {
            $address['region'] = $customer['address']['city']['state_name'];
        }

        if (
            isset($customer['address']['city']['city_name'])
            && $customer['address']['city']['city_name']
        ) {
            $address['city'] = $customer['address']['city']['city_name'];
        }

        if (
            isset($customer['address']['address'])
            && $customer['address']['address']
        ) {
            $address['text'] = $customer['address']['address'];
        }

        $phone = null;

        if (isset($customer['phones']) && $customer['phones']) {
            $curPhone = current($customer['phones']);

            if (isset($curPhone['number']) && $curPhone['number']) {
                $phone = (isset($curPhone['indicative']) && $curPhone['indicative'] ? $curPhone['indicative'] : '') . $curPhone['number'];
            }
        }

        $items = [];

        foreach ($invoice['items'] as $i => $item) {
            $initialPrice = round($item['total'] / $item['quantity'], 2);

            /*$discountManualAmount = $discountManualPercent = null;
            if (isset($item['DiscountPercentage']) && $item['DiscountPercentage']) {
                $discountManualPercent = $item['DiscountPercentage'];
                $initialPrice = ($initialPrice * 100) / $discountManualPercent;
            } elseif (isset($item['DiscountValue']) && $item['DiscountValue']) {
                $discountManualAmount = $item['DiscountValue'];
                $initialPrice = $initialPrice + ($discountManualAmount / $item['Quantity']);
            }*/

            $offer = [];
            $offer['article'] = $item['code'];
            $offer['externalId'] = $item['code'];

            $tax = null;
            if (isset($item['taxes']) && $item['taxes']) {
                $tax = current($item['taxes']);
            }

            $items[] = [
                'id' => $i.'_'.$invoice['number'].'_'.$item['code'],
                'initialPrice' => $initialPrice,
                //'discountManualAmount' => $discountManualAmount,
                //'discountManualPercent' => $discountManualPercent,
                'vatRate' => isset($tax['percentage']) && $tax['percentage'] ? $tax['percentage'] : 'none',
                'quantity' => $item['quantity'],
                'properties' => [[
                    'code' => 'siigo_code',
                    'name' => 'Siigo code',
                    'value' => $item['code'],
                ]],
                'offer' => $offer,
                'productName' => $item['description'],
                'status' => 'sold',
            ];
        }

        $createdAt = getDateFromStr($invoice['metadata']['created']);

        $payments = [];

        foreach ($invoice['payments'] as $pi => $payment) {
            if ($payment['value'] <= 0) {
                continue;
            }

            $paimentId = $invoice['id'] . '_' . $pi;
            $pCreatedAt = getDateFromStr($payment['due_date'] ?? null);

            if (!array_key_exists('siigo-' . $payment['id'], $crmPaymentTypes)) {
                createPaymentTypeInCRM($paymentMethods[$payment['id']]);
            }

            $payments[$paimentId] = [
                'externalId' => $paimentId,
                'amount' => $payment['value'],
                'paidAt' => $pCreatedAt->format('Y-m-d H:i:s'),
                'comment' => 'Siigo: '.$payment['name'],
                'type' => 'siigo-'.$payment['id'],
                'status' => 'paid',
            ];
        }

        $email = null;

        if (
            isset($contact['email'])
            && $contact['email']
            && preg_match($emailPattern, $contact['email'])
        ) {
            $email = strtolower($contact['email']);
        }

        $order = [
            'number' => $invoice['number'],
            'externalId' => $invoice['id'],
            'countryIso' => isset($address['countryIso']) ? mb_strtoupper($address['countryIso']) : null,
            'createdAt' => $createdAt->format('Y-m-d H:i:s'),

            'lastName' => $contact['last_name'],
            'firstName' => $contact['first_name'],
            'phone' => $phone,
            'email' => $email,

            'managerComment' => isset($invoice['observations']) ? $invoice['observations'] : null,
            //'orderType' => null,
            'orderMethod' => 'offline',

            'customer' => $customer ? ['externalId' => $customer['id']] : null,
            'status' => 'complete',

            'items' => $items,
            'delivery' => [
                'address' => $address,
            ],

            'payments' => $payments,

            'contragent' => ['contragentType' => 'individual'],
            'source' => ['source' => 'Siigo'],
            'customFields' => [
                'siigo_id' => $invoice['id'],
                $config['dni_custom_field'] => $customer['identification'],
            ],
        ];

        try {

            // check order
            //$response = $api->request->ordersGet($invoice['id'], 'externalId', $site);
            //if ($response->isSuccessful() && $response['order']) {

            $response = $api->request->ordersList([
                'customFields' => ['siigo_id' => $invoice['id']],
            ]);
            if ($response->isSuccessful() && count($response['orders'])) {

                $foundOrder = current($response['orders']);
                $logger->info('✓ order ' . $invoice['id'] . ' was found in ' . $site . ' and skipped for update');
                continue;

                // update
                $response = $api->request->ordersEdit($order, 'externalId', $site);

                if ($response->isSuccessful()) {
                    $logger->info('✓ order ' . $invoice['id'] . ' was updated in ' . $site);
                } else {
                    $logger->error('✗ order update ' . 'error: [HTTP-code ' . $response->getStatusCode() . '] ' . $response->getErrorMsg());
                    $logger->error(json_encode($order));
                    continue;
                }

            } else {

                // create
                usleep(1000000);
                $response = $api->request->ordersCreate($order, $site);

                if ($response->isSuccessful()) {
                    $logger->info('✓ order ' . $invoice['id'] . ' was created in ' . $site);
                } else {
                    $logger->error('✗ order create ' . 'error: [HTTP-code ' . $response->getStatusCode() . '] ' . $response->getErrorMsg());
                    $logger->error(json_encode($order));
                    continue;
                }
            }

        } catch (\RetailCrm\Exception\CurlException $e) {
            $logger->error('✗ connection error: ' . $e->getMessage());
            continue;
        }
    }
} else {
    $logger->info('- there is no new invoices in Siigo');
}

file_put_contents($siigoInvoicesUpdatedFile, $now->format('Y-m-d\TH:i:s.v\Z'));

$logger->info('✓ orders are uploaded for ' . $site);
