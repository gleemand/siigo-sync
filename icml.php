<?php

$logger = loggerBuild('ICML');
$logger->info('- loading products from ' . $site);

// groups --------------------------------------------------------------------------------------------------------------
$cats = [];

try {
    $response = $client->request('GET', 'https://api.siigo.com/v1/account-groups', [
        'headers' => $headers,
    ]);
    $result = json_decode($response->getBody()->getContents(), true);

} catch (\Exception $e) {
    $logger->error('✗ ' . $e->getMessage());
    die();
}

foreach ($result as $item) {
    $cats[$item['id']] = $item;
}
$logger->info('✓ found ' . count($cats) . ' categories');


// products ------------------------------------------------------------------------------------------------------------
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

    if (isset($result['results'])) {
        foreach ($result['results'] as $item) {
            $products[$item['code']] = $item;
        }
    }

    $url = isset($result['_links']['next']['href']) ? $result['_links']['next']['href'] : null;
    usleep(250000);

} while ($url);
$logger->info('✓ found ' . count($products) . ' products');


// taxas ---------------------------------------------------------------------------------------------------------------
$taxes = [];

try {
    $response = $client->request('GET', 'https://api.siigo.com/v1/taxes', [
        'headers' => $headers,
    ]);
    $result = json_decode($response->getBody()->getContents(), true);

} catch (\Exception $e) {
    $logger->error('✗ ' . $e->getMessage());
    die();
}

foreach ($result as $item) {
    $taxes[$item['id']] = $item;
}
$logger->info('✓ found ' . count($taxes) . ' taxes');


// generate icml -------------------------------------------------------------------------------------------------------
$logger->info('- creating new ICML with ' . count($products) . ' products');

$icml = new DomDocument('1.0','utf-8');
$yml_catalog = $icml->createElement('yml_catalog');
$yml_catalog->setAttribute("date", date('Y-m-d H:i:s'));
$icml->appendChild($yml_catalog);

$shop = $icml->createElement('shop');
$yml_catalog->appendChild($shop);

$shop->appendChild($icml->createElement('name', $site));
$shop->appendChild($icml->createElement('company', $site));

$categories = $icml->createElement('categories');
$shop->appendChild($categories);

foreach ($cats as $item) {
    $category = $icml->createElement('category', $item['name']);
    $category->setAttribute("id", $item['id']);
    if (isset($item['parent']) && $item['parent']) {
        $category->setAttribute("parentId", $item['parent']);
    }
    $categories->appendChild($category);
}

$offers = $icml->createElement('offers');
$shop->appendChild($offers);

foreach ($products as $item) {

    $offer = $icml->createElement('offer');
    //$offer->setAttribute("id", $item['id']);
    //$offer->setAttribute("productId", $item['id']);
    $offer->setAttribute("id", $item['code']);
    $offer->setAttribute("productId", $item['code']);
    $offer->setAttribute("quantity", $item['available_quantity'] < 0 ? 0 : $item['available_quantity']);

    //$offer->appendChild($icml->createElement('url', 'https://'));

    if (isset($item['prices']) && $item['prices']) {
        foreach ($item['prices'] as $price) {
            foreach ($price['price_list'] as $priceList) {

                if (isset($priceList['value'])) {
                    $offer->appendChild($icml->createElement('price', $priceList['value']));
                }
            }
        }
    }

    if (isset($item['account_group']) && $item['account_group']) {
        foreach ($item['account_group'] as $accountGroupId) {

            if (array_key_exists($accountGroupId, $cats)) {
                $offer->appendChild($icml->createElement('categoryId', $accountGroupId));
            }
        }
    }

    //$offer->appendChild($icml->createElement('picture', 'https://'));
    $offer->appendChild($icml->createElement('name', $item['name']));
    $offer->appendChild($icml->createElement('productName', $item['name']));

    if (isset($item['taxes']) && $item['taxes']) {
        foreach ($item['taxes'] as $tax) {
            if (array_key_exists($tax['id'], $taxes)) {
                $offer->appendChild($icml->createElement('vatRate', $taxes[$tax['id']]['percentage']));
            }
        }
    }

    // params
    if (isset($item['reference']) && $item['reference']) {

        $param = $icml->createElement('param', $item['reference']);
        $param->setAttribute("name", 'Reference');
        $param->setAttribute("code", 'reference');
        $offer->appendChild($param);
    }

    if (isset($item['code']) && $item['code']) {
        $param = $icml->createElement('param', $item['code']);
        $param->setAttribute("name", 'Article');
        $param->setAttribute("code", 'article');
        $offer->appendChild($param);
    }

    if (isset($item['description']) && $item['description']) {
        $param = $icml->createElement('param', $item['description']);
        $param->setAttribute("name", 'Description');
        $param->setAttribute("code", 'description');
        $offer->appendChild($param);
    }

    if (isset($item['additional_fields']) && $item['additional_fields']) {
        foreach ($item['additional_fields'] as $key => $value) {
            $param = $icml->createElement('param', $value);
            $param->setAttribute("name", $key);
            $param->setAttribute("code", $key);
            $offer->appendChild($param);
        }
    }

    $offers->appendChild($offer);
}


$icml->formatOutput = true;
$icmlPath = '/icml/'.$site.'_retailcrm.xml';
$icml->save(dirname(__FILE__) . $icmlPath);

$logger->info('✓ ICML was stored in ' . $icmlPath);
