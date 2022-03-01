<?php

$logger = loggerBuild('CRM_HIST');
$logger->info('- resetting CRM history');

$lastCustomerHistFile = dirname(__FILE__) . '/crm/' . $site . '_last_customer_history';
$lastOrderHistFile = dirname(__FILE__) . '/crm/' . $site . '_last_order_history';

$custSinceId = null;
$orderSinceId = null;

// customer history reset
try {
    $response = $api->request->customersHistory([], 1, 20);
} catch (\RetailCrm\Exception\CurlException $e) {
    $logger->error('Connection error: ' . $e->getMessage());
}

try {
    $response = $api->request->customersHistory([], $response['pagination']['totalPageCount'], 20);
    foreach ($response['history'] as $history) {
        $custSinceId = $history['id'];
    }
} catch (\RetailCrm\Exception\CurlException $e) {
    $logger->error('Connection error: ' . $e->getMessage());
}

file_put_contents($lastCustomerHistFile, $custSinceId);

// order history reset
try {
    $response = $api->request->ordersHistory([], 1, 20);
} catch (\RetailCrm\Exception\CurlException $e) {
    $logger->error('Connection error: ' . $e->getMessage());
}

try {
    $response = $api->request->ordersHistory([], $response['pagination']['totalPageCount'], 20);
    foreach ($response['history'] as $history) {
        $orderSinceId = $history['id'];
    }
} catch (\RetailCrm\Exception\CurlException $e) {
    $logger->error('Connection error: ' . $e->getMessage());
}

file_put_contents($lastOrderHistFile, $orderSinceId);

$logger->info('✓ CRM history reseted');
