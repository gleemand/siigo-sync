<?php

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

require_once dirname(__FILE__) . '/vendor/autoload.php';
require_once dirname(__FILE__) . '/config.php';

$logger = new Logger('Log');
$handler = new RotatingFileHandler(__DIR__ . '/logs/log.log', 30,  Logger::DEBUG);
$formatter = new LineFormatter(null, null, false, true);
$handler->setFormatter($formatter);
$logger->pushHandler($handler);

$logger->info('-------------------------Display info-------------------------------');

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

    $simlaUrl = $config['crm']['url'];

    $logger->info("Trying to get Siigo token for " . $site . " ...");

    include dirname(__FILE__) . '/auth.php';
}

echo html('begin');

// Payments
$payments = getDataFromSiigo('https://api.siigo.com/v1/document-types?type=FV');

displayHtml('Formas de Pago', $payments);

// Taxes
$taxes = getDataFromSiigo('https://api.siigo.com/v1/taxes');

displayHtml('Impuestos (taxes)', $taxes);

// Users
$users = getDataFromSiigo('https://api.siigo.com/v1/users');

displayHtml('Usuarios', $users['results']);

// Centros de Costo
$costs = getDataFromSiigo('https://api.siigo.com/v1/cost-centers');

displayHtml('Centros de Costo', $costs);

echo html('end');

function getDataFromSiigo($request)
{
    global $headers, $client, $logger;

    try {
        $response = $client->request('GET', $request, ['headers' => $headers]);

        return json_decode($response->getBody()->getContents(), true);
    } catch (\Exception $e) {
        $logger->error('✗ ' . $e->getMessage());
        echo $e->getMessage();

        die();
    }
}


function displayHtml($titleData, $data)
{
    echo "<h3>$titleData: </h3>";
    echo "<table class='styled-table' border style='border-collapse:collapse; width:500px; text-align:center'>";

    if ($titleData == 'Formas de Pago') {
        echo '<p>Copy siigo-id to "Description" field of appropriate payment method at CRM <a target="_blank" href="'
        . $simlaUrl
        . 'admin/payment-types">here</a>.</p>';
    }
    echo $titleData != 'Usuarios'
        ? "<thead><tr>" . "<td>siigo-id" . "<td>name" . "</tr></thead>"
        : "<thead><tr>" . "<td>siigo-id" . "<td>e-mail" . "<td>active" . "</tr></thead>";

    foreach ($data as $value) {
        echo "<tr>";
        echo "<td>siigo-{$value['id']}</td>";

        if ($titleData == 'Usuarios') {
            echo "<td>{$value['email']}</td>";
            echo "<td>{$value['active']}</td>";
        } else {
            echo "<td>{$value['name']}</td>";
        }

        echo "</tr>";
    }

    echo "</table>";
}

function html($place)
{
    if ($place === 'begin') {
        return '<!DOCTYPE html>
        <html>
        <head>
        <style>
        * {
            font-family: sans-serif;
            text-align: center;
        }
        p {
            font-size: 0.9em;
        }
        h3 {
            font-size: 1.2em;
        }
        .styled-table {
            border-collapse: collapse;
            font-size: 0.9em;
            font-family: sans-serif;
            min-width: 400px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.15);
            margin-left: auto;
            margin-right: auto;
        }
        .styled-table thead tr {
            background-color: #009879;
            color: #ffffff;
            font-weight: bold;
        }
        .styled-table th,
        .styled-table td {
            padding: 12px 15px;
        }
        .styled-table tbody tr {
            border-bottom: 1px solid #dddddd;
        }

        .styled-table tbody tr:nth-of-type(even) {
            background-color: #f3f3f3;
        }

        .styled-table tbody tr:last-of-type {
            border-bottom: 2px solid #009879;
        }
        </style>
        </head>
        <body>
        <h3>Data provided by Siigo</h3>
        <br>';
    } else if ($place === 'end') {
        return '</body>
        </html>';
    }
}
