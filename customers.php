<?php

$logger->info('- loading customers for ' . $site);

$now = new \DateTime('now', new DateTimeZone('UTC'));

// Siigo customers -----------------------------------------------------------------------------------------------------
$siigoCustomers = [];

$customerUpdated = null;
$siigoCustomersUpdatedFile = dirname(__FILE__) . '/siigo/'.$site.'_last_customer_updated';

if (file_exists($siigoCustomersUpdatedFile)) {
    $customerUpdated = file_get_contents($siigoCustomersUpdatedFile);
}

// created
$url = 'https://api.siigo.com/v1/customers';

if ($customerUpdated) {

    $url .= '?created_start=' . $customerUpdated;
    $logger->info('✓ load from date ' . $customerUpdated . ' (UTC time)');
}

do {
    try {
        $response = $client->request('GET', $url, ['headers' => $headers]);
        $result = json_decode($response->getBody()->getContents(), true);
    } catch (\Exception $e) {
        $logger->error('✗ '.$e->getMessage());
        continue;
    }

    foreach ($result['results'] as $item) {
        if (!isset($item['id']) || empty($item['id'])) {
            continue;
        }

        $siigoCustomers[$item['id']] = $item;
    }

    $url = $result['results'] && isset($result['_links']['next']['href']) ? $result['_links']['next']['href'] : null;
    usleep(300000);

} while ($url);

// updated
if ($customerUpdated) {
    $url = 'https://api.siigo.com/v1/customers?updated_start=' . $customerUpdated;

    do {
        try {
            $response = $client->request('GET', $url, ['headers' => $headers]);
            $result = json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            $logger->error('✗ ' . $e->getMessage());
            continue;
        }

        foreach ($result['results'] as $item) {
            if (!isset($item['id']) || empty($item['id'])) {
                continue;
            }

            $siigoCustomers[$item['id']] = $item;
        }

        $url = $result['results'] && isset($result['_links']['next']['href']) ? $result['_links']['next']['href'] : null;
        usleep(300000);

    } while ($url);
}

$logger->info('✓ found ' . count($siigoCustomers) . ' customer(s) in Siigo');

// prepare clients for retailcrm ---------------------------------------------------------------------------------------
if ($siigoCustomers) {
    foreach ($siigoCustomers as $data) {

        usleep(1000000);

        $contact = [];
        if (isset($data['contacts']) && $data['contacts']) {
            $contact = current($data['contacts']);
        }

        $address = [];
        if (isset($data['address']['city']['country_code']) && $data['address']['city']['country_code']) {
            $address['countryIso'] = mb_strtoupper($data['address']['city']['country_code']);
        }
        if (isset($data['address']['city']['state_name']) && $data['address']['city']['state_name']) {
            $address['region'] = $data['address']['city']['state_name'];
        }
        if (isset($data['address']['city']['city_name']) && $data['address']['city']['city_name']) {
            $address['city'] = $data['address']['city']['city_name'];
        }
        if (isset($data['address']['address']) && $data['address']['address']) {
            $address['text'] = $data['address']['address'];
        }

        $phones = [];
        if (isset($data['phones']) && $data['phones']) {

            foreach ($data['phones'] as $phone) {
                if (empty($phone['indicative']) && empty($phone['number'])) {
                    continue;
                }
                $phones[] = ['number' => (isset($phone['indicative']) && $phone['indicative'] ? $phone['indicative'] : '') . $phone['number']];
            }
        }

        $email = null;
        if (isset($contact['email']) && $contact['email'] && preg_match($emailPattern, $contact['email'])) {
            $email = $contact['email'];
        }

        $createdAt = null;
        if (isset($data['metadata']['created']) && $data['metadata']['created'] != '0001-01-01T00:00:00Z') {
            $createdAt = getDateFromStr($data['metadata']['created'], 'UTC');
        }

        $customer = [
            'createdAt' => $createdAt ? $createdAt->format('Y-m-d H:i:s') : $createdAt,
            'externalId' => $data['id'],

            'firstName' => isset($contact['first_name']) && $contact['first_name'] ? $contact['first_name'] : null,
            'lastName' => isset($contact['last_name']) && $contact['last_name'] ? $contact['last_name'] : null,
            'email' => $email,
            'subscribed' => true,

            'address' => $address,
            'phones' => $phones,

            'contragent' => ['contragentType' => 'individual'],
            'source' => ['source' => 'Siigo'],
            'customFields' => [
                'siigo_id' => $data['id'],
                $config['dni_custom_field'] => $data['identification'],
                //'siigo_id_type' => isset($data['id_type']['code']) ? $data['id_type']['code'] : null,
                //'siigo_check_digit' => isset($data['check_digit']) ? $data['check_digit'] : null,
                //'siigo_vat_responsible' => isset($data['vat_responsible']) ? $data['vat_responsible'] : null,
                //'siigo_active' => isset($data['active']) ? $data['active'] : null,
            ],
        ];

        try {

            // check customer
            //$response = $api->request->customersGet($data['id'], 'externalId', $site);
            //if ($response->isSuccessful() && $response['customer']) {

            $response = $api->request->customersList([
                'customFields' => ['siigo_id' => $data['id']],
            ]);
            if ($response->isSuccessful() && count($response['customers'])) {

                $foundCustomer = current($response['customers']);
                $logger->info('✓ customer ' . $data['id'] . ' was found in ' . $site . ' and skipped for update');
                continue;


                unset($customer['externalId']);
                $customer['id'] = $foundCustomer['id'];

                // compare fields
                if (isset($foundCustomer['emailMarketingUnsubscribedAt']) && $foundCustomer['emailMarketingUnsubscribedAt']) {
                    $customer['subscribed'] = false;
                }

                // update
                $response = $api->request->customersEdit($customer, 'id', $site);
                if ($response->isSuccessful()) {
                    $logger->info('✓ customer ' . $data['id'] . ' was updated in ' . $site);
                } else {
                    $logger->error('✗ customer update ' . 'error: [HTTP-code ' . $response->getStatusCode() . '] ' . $response->getErrorMsg());
                    $logger->error(json_encode($customer));
                    continue;
                }

            } else {

                // create
                usleep(1000000);

                $response = $api->request->customersCreate($customer, $site);

                if ($response->isSuccessful()) {
                    $logger->info('✓ customer ' . $data['id'] . ' was created in ' . $site);
                } else {
                    $logger->error('✗ customer create ' . 'error: [HTTP-code ' . $response->getStatusCode() . '] ' . $response->getErrorMsg());
                    $logger->error(json_encode($customer));
                    continue;
                }
            }

        } catch (\RetailCrm\Exception\CurlException $e) {
            $logger->error('✗ connection error: ' . $e->getMessage());
            continue;
        }
    }
} else {
    $logger->info('- there is no new customers in Siigo');
}

file_put_contents($siigoCustomersUpdatedFile, $now->format('Y-m-d\TH:i:s.v\Z'));

$logger->info('✓ customers are uploaded for ' . $site);
