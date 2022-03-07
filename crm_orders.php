<?php

$logger = loggerBuild('CRM_ORD');
$logger->info("- load order history from CRM");

$lastOrderHistFile = dirname(__FILE__) . '/crm/' . $site . '_last_order_history';

if (file_exists($lastOrderHistFile)) {
    $ordSinceId = file_get_contents($lastOrderHistFile);
}

if ($ordSinceId) {
    $logger->info("✓ load from sinceId " . $ordSinceId);
}

$ordersHistory = [];
$page = 1;
$filter = [];

if ($ordSinceId) {
    $filter['sinceId'] = $ordSinceId;
}

while (true) {
    $response = sendSimlaApiRequest($api, 'ordersHistory', $filter, $page, 100);

    foreach ($response['history'] as $history) {
        if (isset($history['apiKey']['current']) && $history['apiKey']['current'] == 1) {
            continue;
        }

        $ordersHistory[] = $history;
        $ordSinceId = $history['id'];
    }

    ++$page;

    if (
        $page > $response['pagination']['totalPageCount']
        || $page > 1000
    ) {
        break;
    }

    if ($page%100 == 0) {
        $logger->info("✓ loaded page " . $page . ' of '. $response['pagination']['totalPageCount']);
    }

    if ($page%10 == 0) {
        sleep(1);
    } else {
        usleep(200000);
    }
}

// Siigo: types of document --------------------------------------------------------------------------------------------
$siigoTypesOfDocs = [];

try {
    $response = $client->request('GET', 'https://api.siigo.com/v1/document-types?type=FV', ['headers' => $headers]);
    $result = json_decode($response->getBody()->getContents(), true);
} catch (\Exception $e) {
    $logger->error('✗ ' . $e->getMessage());

    die();
}

if (isset($result['Errors'])) {
    $errors = [];

    foreach ($result['Errors'] as $error) {
        $errors[] = $error['Message'] . " (" . $error['Code'] . ")";
        $logger->error(json_encode($error));
    }

    die();
}

foreach ($result as $item) {
    if (isset($item['active']) && $item['active']) {
        $siigoTypesOfDocs[$item['id']] = $item;
    }
}

// Siigo: taxes --------------------------------------------------------------------------------------------------------
$siigoTaxes = [];

try {
    $response = $client->request('GET', 'https://api.siigo.com/v1/taxes', ['headers' => $headers]);
    $result = json_decode($response->getBody()->getContents(), true);
} catch (\Exception $e) {
    $logger->error('✗ ' . $e->getMessage());

    die();
}

foreach ($result as $item) {
    $siigoTaxes[$item['id']] = $item;
}

function findTaxId($percentage) {
    global $siigoTaxes;

    if ($percentage == 'none') {
        $percentage = 0;
    }

    foreach ($siigoTaxes as $id => $tax) {
        if ($tax['percentage'] == $percentage && $tax['active']) {
            return $tax['id'];
        }
    }
}

//$orders = assemblyOrder($ordersHistory);
//foreach ($orders as $order) {

// get orders statuses and payment types from CRM
//$crmStatuses = sendSimlaApiRequest($api, 'statusesList');
$crmPayments = sendSimlaApiRequest($api, 'paymentTypesList');

foreach ($ordersHistory as $change) {
    switch ($config['trigger']) {
        case 'status':
            if ((
                    $change['field'] != 'status'
                    || !isset($change['order']['status'])
                    || $change['order']['status'] != $config['status']
                )
                && $change['field'] != 'custom_siigo_last_error'
            ) {
                continue 2;
            }

            break;
        case 'group':
            if ($change['field'] == 'status' || $change['field'] == 'custom_siigo_last_error') {
                $orderStatus = $change['order']['status'];
                $statusGroup = $crmStatuses['statuses'][$orderStatus]['group'];
            } else {
                continue 2;
            }

            if (!in_array($statusGroup, $config['groups_of_status'])) {
                continue 2;
            }

            break;
        case 'full_paid':
            if (
                ($change['field'] != 'full_paid_at' || empty($change['newValue']))
                && $change['field'] != 'custom_siigo_last_error'
                && !isset($change['order']['fullPaidAt'])
            ) {
                continue 2;
            }

            break;
    }

    if (!isset($change['order']['site']) || empty($change['order']['site'])) {
        $logger->warning("- order ".$change['order']['id']." does not have site field - skipping ...");
        continue;
    }

    usleep(200000);
    // get full and up to date order
    $response = sendSimlaApiRequest($api, 'ordersGet', $change['order']['id'], 'id', $change['order']['site']);

    if ($response->isSuccessful() && $response['order']) {
        $order = $response['order'];
    } else {
        $logger->warning("- order " . $change['order']['id'] . " not found in CRM - skipping ...");
        continue;
    }

    if (isset($order['customFields']['siigo_id'])) {
        $logger->warning("- order " . $change['order']['id'] . " already has Siigo ID - skipping ...");
        continue;
    }

    $siigoTypesOfDoc = end($siigoTypesOfDocs);

    // checks
    if (
        !isset($order['customer']['customFields'][$config['dni_custom_field']])
        || empty($order['customer']['customFields'][$config['dni_custom_field']])
    ) {
        $upd = [
            'id' => $order['id'],
            'customFields' => [
                'siigo_last_error' => "The customer doesn't have identification (cedula)",
            ],
        ];

        $response = sendSimlaApiRequest($api, 'ordersEdit', $upd, 'id', $order['site']);

        continue;
    }

    //$createdAt = getDateFromStr($order['fullPaidAt'], 'America/Bogota');
    $createdAt = getDateFromStr($order['createdAt'], 'America/Bogota');

    $items = [];
    $retentions = [];

    foreach ($order['items'] as $item) {
        if (!isset($item['offer']['article']) || empty($item['offer']['article'])) {
            $logger->warning("- article of item is empty - skipping ...");
            continue;
        }

        $price = $item['initialPrice'] - $item['discountTotal'];
        $taxes = null;

        if (isset($item['vatRate']) && $item['vatRate']) {
            if ($item['vatRate'] == 'none') {
                $item['vatRate'] = 0;
            }

            $price = round($price / (1 + $item['vatRate'] / 100), 5);
            $taxes = [['id' => findTaxId($item['vatRate'])]];
            $retentions = array_merge($retentions, $taxes);
        }

        $productArticle = $item['offer']['article'];
        $productName = $item['offer']['name'];

        if ($config['auto_create_product']['account_group']) {
            try {
                $response = $client->request('GET', 'https://api.siigo.com/v1/products?code=' . $productArticle, [
                    'headers' => $headers,
                ]);
                $result = json_decode($response->getBody()->getContents(), true);

                if (
                    isset($result['pagination']['total_results'])
                    && $result['pagination']['total_results'] == 0
                ) {
                    $logger->info('- creating product in Siigo for order #' . $order['id'] . '...');

                    $response = $client->request('POST', 'https://api.siigo.com/v1/products', [
                        'headers' => $headers,
                        'json' => [
                            'code' => $productArticle,
                            'name' => $productName,
                            'account_group' => $config['auto_create_product']['account_group'],
                            'type' => 'Product',
                            'stock_control' => true
                        ]
                    ]);
                }

                usleep(200000);
            } catch (\Exception $e) {
                $itemError = true;
                $logger->error('✗ ' . $e->getMessage());

                continue;
            }
        }

        $items[] = [
            'code' => $productArticle,
            'description' => $productName,
            'quantity' => $item['quantity'],
            'price' => $price,
            //'discount' => '', // in percent
            'taxes' => $taxes,
            //'warehouse' => 200,
        ];
    }

    if ($itemError) {
        $logger->error("- error creating product in Siigo for order #" . $order['id']);

        continue;
    }

    // delivery as item
    if ($config['delivery_item_code'] !== '' && isset($order['delivery']['cost']) && $order['delivery']['cost']) {
        $items[] = [
            'code' => $config['delivery_item_code'],
            'description' => isset($order['delivery']['code']) ? 'Delivery (' . $order['delivery']['code'] . ')' : 'Delivery',
            'quantity' => 1,
            'price' => isset($order['delivery']['cost']) ? $order['delivery']['cost'] : 0,
            'taxes' => [['id' => $config['tax_id_for_delivery']]],
        ];
        $deliveryDeducted = true;
    } else {
        $deliveryDeducted = false;
    }

    $payments = [];

    foreach ($order['payments'] as $payment) {

        $orderPayment = $payment['type'];
        $paymentDescription = isset($crmPayments['paymentTypes'][$orderPayment]['description'])
            ? $crmPayments['paymentTypes'][$orderPayment]['description']
            : null;

        if (mb_substr($paymentDescription, 0, mb_strlen('siigo-')) != 'siigo-') {
            $logger->warning("- payment of order " . $order['id'] . " in CRM doesn't have Siigo payment id - skipping ...");
            continue;
        }

        if (!isset($payment['paidAt'])) {
            continue;
        }

        $siigoPaymentId = mb_substr($paymentDescription, mb_strlen('siigo-'));
        $paidAt = getDateFromStr($payment['paidAt'], 'America/Bogota');

        $pval = $payment['amount'];
        if (!$deliveryDeducted && isset($order['delivery']['cost']) && $order['delivery']['cost']) {
            $pval -= $order['delivery']['cost'];
            $deliveryDeducted = true;
        }

        $payments[] = [
            'id' => $siigoPaymentId,
            'value' => $pval,
            'due_date' => $paidAt ? $paidAt->format('Y-m-d') : $paidAt,
        ];
    }

    //seller
    if (
        isset($order['managerId'])
        && isset($config['sellers'][$order['managerId']])
        && $order['orderMethod'] = 'phone'
        && empty($order['externalId'])
    ) {
        $seller = $config['sellers'][$order['managerId']];
    } else {
        $seller = $config['sellers']['default'];
    }

    $post = [
        'document' => [
            'id' => isset($siigoTypesOfDoc['id']) ? $siigoTypesOfDoc['id'] : null,
        ],
        //'number' => 1000000000 + intval($order['id']),
        'date' => $createdAt ? $createdAt->format('Y-m-d') : $createdAt,
        'customer' => [
            'identification' =>
                str_replace([',', '.', '-', ' '], '', $order['customer']['customFields'][$config['dni_custom_field']]),
            //'branch_office' => 0,
        ],
        //'cost_center' => 235,
        'seller' => $seller,
        //'retentions' => $retentions,
        'observations' => 'RetailCRM order ' . $order['id'],
        'items' => $items,
        'payments' => $payments,
    ];

    if (isset($config['invoices']['currency']) && $config['invoices']['currency']) {
        $post['currency'] = $config['invoices']['currency'];
    }

    try {
        $response = $client->request('POST', 'https://api.siigo.com/v1/invoices', [
            'headers' => $headers,
            'json' => $post,
        ]);
        $result = json_decode($response->getBody()->getContents(), true);
    } catch (\Exception $e) {
        $logger->error('✗ ' . $e->getMessage());
        continue;
    }

    // error
    if (isset($result['Errors'])) {
        $logger->error(json_encode($post));

        $errors = [];
        foreach ($result['Errors'] as $error) {
            $errors[] = $error['Message'] . " (" . $error['Code'] . ")";
            $logger->error(json_encode($error));
        }

        // set siigo last error
        $upd = [
            'id' => $order['id'],
            'customFields' => [
                'siigo_last_error' => implode("\n", $errors),
            ],
        ];

        $response = sendSimlaApiRequest($api, 'ordersEdit', $upd, 'id', $order['site']);

        continue;
    }

    // done
    if (isset($result['id']) && $result['id']) {
        if (!isset($order['customFields']['siigo_id'])) {
            $logger->info("✓ order " . $order['id'] . " was created in Siigo, id = " . $result['id']);

            // set siigo id
            $upd = [
                'id' => $order['id'],
                'customFields' => [
                    'siigo_id' => $result['id'],
                    'siigo_last_error' => '',
                ],
            ];

            $response = sendSimlaApiRequest($api, 'ordersEdit', $upd, 'id', $order['site']);
        } else {
            $logger->info("✓ order " . $order['id'] . " was updated in Siigo");
        }
    }
}

file_put_contents($lastOrderHistFile, $ordSinceId);

$logger->info("✓ orders history loaded from CRM");
