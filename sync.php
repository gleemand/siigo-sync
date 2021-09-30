<?php

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

require_once dirname(__FILE__) . '/vendor/autoload.php';
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/functions.php';

$logger = new Logger('Log');
$handler = new RotatingFileHandler(__DIR__ . '/logs/log.log', 30,  Logger::DEBUG);
$formatter = new LineFormatter(null, null, false, true);
$handler->setFormatter($formatter);
$logger->pushHandler($handler);

$logger->info('--------------------------------------------------------');

// arguments
$loadIcml = $loadStocks = $loadCustomers = $loadOrders = false;
$resetCrmHist = $loadCrmCustomers = $loadCrmOrders = false;
foreach ($argv as $arg) {

    if ($arg == 'icml') {
        $loadIcml = true;
    }
    if ($arg == 'stocks') {
        $loadStocks = true;
    }
    if ($arg == 'customers') {
        $loadCustomers = true;
    }
    if ($arg == 'orders') {
        $loadOrders = true;
    }

    if ($arg == 'crm_hist_reset') {
        $resetCrmHist = true;
    }
    if ($arg == 'crm_customers') {
        $loadCrmCustomers = true;
    }
    if ($arg == 'crm_orders') {
        $loadCrmOrders = true;
    }
}

// choose accounts to load from args
$loadAccounts = [];
foreach ($argv as $arg) {
    if (array_key_exists($arg, $sites)) {
        $loadAccounts[] = $arg;
    }
}
if (empty($loadAccounts)) {
    $loadAccounts = array_keys($sites);
}

foreach ($sites as $site => $config) {

    // check account
    if (!in_array($site, $loadAccounts)) {
        continue;
    }

    if (!isset($config['crm']['url']) || empty($config['crm']['url'])
        || !isset($config['crm']['api_key']) || empty($config['crm']['api_key'])
    ) {
        continue;
    }

    // crm api client (ApiKey with access to all stores)
    $api = new \RetailCrm\ApiClient($config['crm']['url'], $config['crm']['api_key'], \RetailCrm\ApiClient::V5);

    $logger->info("Starting sync for " . $site . " ...");

    include dirname(__FILE__) . '/auth.php';

    if ($loadIcml) {
        include dirname(__FILE__) . '/icml.php';
    }
    if ($loadStocks) {
        include dirname(__FILE__) . '/stocks.php';
    }

    if ($loadCustomers) {
        include dirname(__FILE__) . '/customers.php';
    }
    if ($loadOrders) {
        include dirname(__FILE__) . '/orders.php';
    }

    if ($resetCrmHist) {
        include dirname(__FILE__) . '/crm_hist_reset.php';
    }
    if ($loadCrmCustomers) {
        include dirname(__FILE__) . '/crm_customers.php';
    }
    if ($loadCrmOrders) {
        include dirname(__FILE__) . '/crm_orders.php';
    }

    $logger->info("Sync for " . $site . " is DONE");
}
