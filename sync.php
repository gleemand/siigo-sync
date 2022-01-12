<?php

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

require_once dirname(__FILE__) . '/vendor/autoload.php';
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/functions.php';

if (!is_dir(dirname(__FILE__) . '/logs')) {
    mkdir(dirname(__FILE__) . '/logs');
}

$logger = new Logger('Log');
$handler = new RotatingFileHandler(__DIR__ . '/logs/log.log', 30,  Logger::DEBUG);
$formatter = new LineFormatter(null, 'd-M-Y H:i:s', false, true);
$handler->setFormatter($formatter);
$logger->pushHandler($handler);

$logger->info('--------------------------------------------------------');

// arguments
$loadIcml = $loadStocks = $loadCustomers = $loadOrders = $resetHist = false;
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
    if ($arg == 'hist_reset') {
        $resetHist = true;
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
        if (!is_dir(dirname(__FILE__) . '/icml')) {
            mkdir(dirname(__FILE__) . '/icml');
        }

        include dirname(__FILE__) . '/icml.php';
    }

    if ($loadStocks) {
        include dirname(__FILE__) . '/stocks.php';
    }

    if ($loadCustomers) {
        if (!is_dir(dirname(__FILE__) . '/siigo')) {
            mkdir(dirname(__FILE__) . '/siigo');
        }

        include dirname(__FILE__) . '/customers.php';
    }

    if ($loadOrders) {
        if (!is_dir(dirname(__FILE__) . '/siigo')) {
            mkdir(dirname(__FILE__) . '/siigo');
        }

        include dirname(__FILE__) . '/orders.php';
    }

    if ($resetHist) {
        if (!is_dir(dirname(__FILE__) . '/siigo')) {
            mkdir(dirname(__FILE__) . '/siigo');
        }

        $siigoInvoicesUpdatedFile = dirname(__FILE__) . '/siigo/' . $site . '_last_invoice_updated';
        $siigoCustomersUpdatedFile = dirname(__FILE__) . '/siigo/'.$site.'_last_customer_updated';

        $nowOrders = new \DateTime('now', new DateTimeZone('America/Bogota'));
        $nowCustomers = new \DateTime('now', new DateTimeZone('UTC'));

        file_put_contents($siigoInvoicesUpdatedFile, $nowOrders->format('Y-m-d\TH:i:s.v\Z'));
        file_put_contents($siigoCustomersUpdatedFile, $nowCustomers->format('Y-m-d\TH:i:s.v\Z'));
    }

    if ($resetCrmHist) {
        if (!is_dir(dirname(__FILE__) . '/crm')) {
            mkdir(dirname(__FILE__) . '/crm');
        }

        include dirname(__FILE__) . '/crm_hist_reset.php';
    }

    if ($loadCrmCustomers) {
        if (!is_dir(dirname(__FILE__) . '/crm')) {
            mkdir(dirname(__FILE__) . '/crm');
        }

        include dirname(__FILE__) . '/crm_customers.php';
    }

    if ($loadCrmOrders) {
        if (!is_dir(dirname(__FILE__) . '/crm')) {
            mkdir(dirname(__FILE__) . '/crm');
        }

        include dirname(__FILE__) . '/crm_orders.php';
    }

    $logger->info("Sync for " . $site . " is DONE");
}
