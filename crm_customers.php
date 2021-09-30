<?php

$logger->info("- load customer history from CRM");

$lastCustomerHistFile = dirname(__FILE__) . '/crm/'.$site.'_last_customer_history';
if (file_exists($lastCustomerHistFile)) {
    $custSinceId = file_get_contents($lastCustomerHistFile);
}
if ($custSinceId) {
    $logger->info("✓ load from sinceId " . $custSinceId);
}

$customersHistory = [];

try {

    $page = 1;
    $filter = [];
    if ($custSinceId) {
        $filter['sinceId'] = $custSinceId;
    }

    while (true) {

        $response = $api->request->customersHistory($filter, $page, 100);
        foreach ($response['history'] as $history) {

            $customersHistory[] = $history;
            $custSinceId = $history['id'];
        }

        ++$page;
        if ($page > $response['pagination']['totalPageCount']) {
            break;
        }

        if ($page%10 == 0) {
            sleep(2);
        }
    }

} catch (\RetailCrm\Exception\CurlException $e) {
    $logger->error("Connection error: " . $e->getMessage());
}

$customers = assemblyCustomer($customersHistory);

foreach ($customers as $customer) {

    if (!isset($customer['site']) || empty($customer['site'])) {
        $logger->warning("- customer " . $customer['id'] . ": site is empty - skipping ...");
        continue;
    }

    // get full and up to date customer
    $response = $api->request->customersGet($customer['id'], 'id', $customer['site']);
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

    if (!isset($customer['customFields'][$config['dni_custom_field']]) || empty($customer['customFields'][$config['dni_custom_field']])) {
        $logger->warning("- customer ".$customer['id']." doesn't have cedula (identification number) - skipping ...");
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
        'city' => $customer['address']['city'],
        'region' => $customer['address']['region'],
        'countryCode' => $customer['address']['countryIso'],
    ];
    $addressCodes = prepareCustomerAddress($address);


    $post = [
        'type' => 'Customer',
        'person_type' => 'Person',
        'id_type' => '13',                       // Cédula de ciudadanía
        'identification' => $customer['customFields'][$config['dni_custom_field']],
        //'check_digit' => '4',                  // Digito verificación (Si el tipo de identificación es NIT se calcula)
        'name' => [
            $customer['firstName'],
            $customer['lastName'],
        ],
        //'commercial_name' => '',
        //'branch_office' => 0,                  // Sucursal
        'active' => true,
        'vat_responsible' => false,
        'fiscal_responsibilities' => [[
            'code' => 'R-99-PN',
        ]],
        'address' => [
            'address' => isset($customer['address']['text']) ? $customer['address']['text'] : null,
            'city' => $addressCodes,
            "postal_code" => isset($customer['address']['index']) ? $customer['address']['index'] : null,
        ],
        'phones' => $phones,
        'contacts' => [[
            'first_name' => $customer['firstName'],
            'last_name' => $customer['lastName'],
            'email' => $customer['email'],
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
        $logger->error(json_encode($post));

        $errors = [];
        foreach ($result['Errors'] as $error) {
            $errors[] = $error['Message'] . " (" . $error['Code'] . ")";
            $logger->error(json_encode($error));
        }

        // set siigo last error
        $upd = [
            'id' => $customer['id'],
            'customFields' => [
                'siigo_last_error' => implode("\n", $errors),
            ],
        ];
        $response = $api->request->customersEdit($upd, 'id', $customer['site']);

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
            $response = $api->request->customersEdit($upd, 'id', $customer['site']);
        } else {
            $logger->info("✓ customer " . $customer['id'] . " was updated in Siigo");
        }
    }
}

file_put_contents($lastCustomerHistFile, $custSinceId);

$logger->info("✓ customers history loaded from CRM");
