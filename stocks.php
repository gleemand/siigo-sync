<?php

$logger->info('- loading stocks for ' . $site);


// products ------------------------------------------------------------------------------------------------------------
if (!isset($products) || !is_array($products) || empty($products)) {

    $products = [];

    $url = 'https://api.siigo.com/v1/products';
    do {
        try {
            $response = $client->request('GET', $url, [
                'headers' => $headers,
            ]);
            $result = json_decode($response->getBody()->getContents(), true);

        } catch (\Exception $e) {
            $logger->error('✗ ' . $e->getMessage());
            die();
        }

        if (!empty($result['results']) && is_array($result['results'])) {
            foreach ($result['results'] as $item) {
                $products[$item['code']] = $item;
            }
        }

        $url = isset($result['_links']['next']['href']) ? $result['_links']['next']['href'] : null;
        usleep(500000);

    } while ($url);
    $logger->info('✓ found ' . count($products) . ' products');
}


// check store in retailcrm --------------------------------------------------------------------------------------------
if (!isset($config['crm_store_code']) || empty($config['crm_store_code'])) {
    $logger->error('✗ Specify the crm_store_code parameter first');
    die();
}

$storeCode = $config['crm_store_code'];
$stores = [];

try {

    usleep(500000);
    $response = $api->request->storesList();
    foreach ($response['stores'] as $store) {
        $stores[$store['code']] = $store;
    }

} catch (\RetailCrm\Exception\CurlException $e) {
    $logger->error('Connection error: ' . $e->getMessage());
}

if (array_key_exists($storeCode, $stores)) {
    $logger->info('✓ Siigo store found in RetailCRM');
} else {
    try {
        $store = [
            'name' => 'Siigo',
            'type' => 'store-type-warehouse',
            'inventoryType' => 'integer',
            'code' => $storeCode,
            'externalId' => $storeCode,
            'description' => 'Store with stocks from Siigo ERP',
            'active' => true,
        ];

        $response = $api->request->storesEdit($store);

        if ($response->isSuccessful()) {
            $logger->info('✓ Siigo store cretaed in RetailCRM');
        }

    } catch (\RetailCrm\Exception\CurlException $e) {
        $logger->error('Connection error: ' . $e->getMessage());
    }
}


// upload stocks to RetailCRM ------------------------------------------------------------------------------------------
$offers = [];

foreach ($products as $product) {

    if (!isset($product['available_quantity'])) {
        continue;
    }

    $offers[] = [
        'article' => $product['code'],
        'stores' => [[
            'code' => $storeCode,
            'available' => $product['available_quantity'] < 0 ? 0 : $product['available_quantity'],
            //'purchasePrice' => null,
        ]],
    ];
}
$logger->info('✓ found ' . count($offers) . ' product(s) with stocks in Siigo');

$chunks = array_chunk($offers, 250);
foreach ($chunks as $chunk) {

    try {

        usleep(2000000);
        $response = $api->request->storeInventoriesUpload($chunk, $site);
        if (!$response->isSuccessful()) {

            $logger->error('✗ error: [HTTP-code ' . $response->getStatusCode() . '] ' . $response->getErrorMsg());
            $logger->error(json_encode($response));
        }

    } catch (\RetailCrm\Exception\CurlException $e) {
        $logger->error('Connection error: ' . $e->getMessage());
    }
}

$logger->info('✓ stock were updated in RetailCRM store');
