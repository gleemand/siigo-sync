<?php

$logger = loggerBuild('CRM_CUST');
$logger->info("- load customer history from CRM");

$lastCustomerHistFile = dirname(__FILE__) . '/crm/'.$site.'_last_customer_history';
if (file_exists($lastCustomerHistFile)) {
    $custSinceId = file_get_contents($lastCustomerHistFile);
}
if ($custSinceId) {
    $logger->info("✓ load from sinceId " . $custSinceId);
}

$continueHistoryLoading = false;

$customersHistory = [];
$page = 1;
$filter = [];

if ($custSinceId) {
    $filter['sinceId'] = $custSinceId;
}

while (true) {
    $response = sendSimlaApiRequest($api, 'customersHistory', $filter, $page, 100);

    foreach ($response['history'] as $history) {
        if (isset($history['apiKey']['current']) && $history['apiKey']['current'] == true) {
            continue;
        }

        $customersHistory[] = $history;
        $custSinceId = $history['id'];
    }

    ++$page;

    if (
        $page > $response['pagination']['totalPageCount']
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

$customers = assemblyCustomer($customersHistory);

foreach ($customers as $customer) {

    if (!isset($customer['site']) || empty($customer['site'])) {
        continue;
    }

    usleep(200000);
    // get full and up to date customer
    $response = sendSimlaApiRequest($api, 'customersGet', $customer['id'], 'id', $customer['site']);

    if ($response->isSuccessful() && $response['customer']) {
        $customer = $response['customer'];
    } else {
        $logger->warning("- customer ".$customer['id']." not found in CRM - skipping ...");
        continue;
    }

    // deleted
    if (isset($customer['deleted']) && $customer['deleted']) {
        $logger->warning("- customer ".$customer['id'].": is deleted - skipping ...");
        continue;
    }

    if (
        !isset($customer['customFields'][$config['dni_custom_field']])
        || empty($customer['customFields'][$config['dni_custom_field']])
    ) {
        $upd = [
            'id' => $customer['id'],
            'customFields' => [
                'siigo_last_error' => "The customer doesn't have identification",
            ],
        ];

        $response = sendSimlaApiRequest($api, 'customersEdit', $upd, 'id', $customer['site']);

        continue;
    }

    $phones = [];
    foreach ($customer['phones'] as $phone) {

        $clearPhone = preg_replace('/[^0-9]/', '', $phone['number']);
        $phones[] = [
            'indicative' => mb_substr($clearPhone, 0, -10),
            'number' => mb_substr($clearPhone, -10),
            'extension' => '',
        ];
    }

    $address = [
        'city' => $customer['address']['city'] ?? null,
        'region' => $customer['address']['region'] ?? null,
        'countryCode' => $customer['address']['countryIso'] ?? null,
    ];

    $addressCodes = prepareCustomerAddress($address);


    $post = [
        'type' => 'Customer',
        'person_type' => 'Person',
        'id_type' => '13',                       // Cédula de ciudadanía
        'identification' => str_replace([',', '.', '-', ' '], '', $customer['customFields'][$config['dni_custom_field']]),
        //'check_digit' => '4',                  // Digito verificación (Si el tipo de identificación es NIT se calcula)
        'name' => [
            isset($customer['firstName']) ? $customer['firstName'] : null,
            isset($customer['lastName']) ? $customer['lastName'] : null,
        ],
        //'commercial_name' => '',
        //'branch_office' => 0,                  // Sucursal
        'active' => true,
        'vat_responsible' => false,
        'fiscal_responsibilities' => [[
            'code' => 'R-99-PN',
        ]],
        'address' => [
            'address' => $customer['address']['text'] ?? null,
            'city' => $addressCodes,
            "postal_code" => isset($customer['address']['index']) ? substr($customer['address']['index'], 0, 10) : null,
        ],
        'phones' => $phones,
        'contacts' => [[
            'first_name' => $customer['firstName'] ?? null,
            'last_name' => $customer['lastName'] ?? null,
            'email' => $customer['email'] ?? null,
            'phone' => $phones ? current($phones) : null,
        ]],
        'comments' => 'RetailCRM customer ' . $customer['id'],
    ];

    // create
    $method = 'POST';
    $url = 'https://api.siigo.com/v1/customers';

    // or update
    if (isset($customer['customFields']['siigo_id'])) {
        $method = 'PUT';
        $url .= '/' . $customer['customFields']['siigo_id'];
    }

    try {
        $response = $client->request($method, $url, [
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
        $logger->error('Customer: ' . $customer['id'] . ' - ' . $post['identification']);

        $errors = [];
        foreach ($result['Errors'] as $error) {
            $errors[] = $error['Message'] . " (" . $error['Code'] . ")";
            $logger->error($error['Message'] . " (" . $error['Code'] . ")");
        }

        // set siigo last error
        $upd = [
            'id' => $customer['id'],
            'customFields' => [
                'siigo_last_error' => implode("\n", $errors),
            ],
        ];

        $response = sendSimlaApiRequest($api, 'customersEdit', $upd, 'id', $customer['site']);

        continue;
    }

    // done
    if (isset($result['id']) && $result['id']) {
        if (!isset($customer['customFields']['siigo_id'])) {
            $logger->info("✓ customer " . $customer['id'] . " was created in Siigo, id = " . $result['id']);

            // set siigo id
            $upd = [
                'id' => $customer['id'],
                'customFields' => [
                    'siigo_id' => $result['id'],
                ],
            ];

            $response = sendSimlaApiRequest($api, 'customersEdit', $upd, 'id', $customer['site']);
        } else {
            $logger->info("✓ customer " . $customer['id'] . " was updated in Siigo");
        }
    }
}

file_put_contents($lastCustomerHistFile, $custSinceId);

$logger->info("✓ customers history loaded from CRM");
