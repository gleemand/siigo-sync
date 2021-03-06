<?php

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

require_once dirname(__FILE__) . '/vendor/autoload.php';
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/functions.php';

$logger = loggerBuild('INFO');

$logger->info('----------------Display info----------------');

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
$payments = getDataFromSiigo('https://api.siigo.com/v1/payment-types?document_type=FV');

displayHtml('Formas de Pago (payments)', $payments);

// Warehouses
$warehouses = getDataFromSiigo('https://api.siigo.com/v1/warehouses');

displayHtml('Bodegas (warehouses)', $warehouses);

// Taxes
$taxes = getDataFromSiigo('https://api.siigo.com/v1/taxes');

displayHtml('Impuestos (taxes)', $taxes);

// Users
$users = getDataFromSiigo('https://api.siigo.com/v1/users');

displayHtml('Usuarios (users)', $users['results']);

// Centros de Costo
$costs = getDataFromSiigo('https://api.siigo.com/v1/cost-centers');

displayHtml('Centros de Costo (cost centers)', $costs);

// Grupos de Inventario
$inventario = getDataFromSiigo('https://api.siigo.com/v1/account-groups');

displayHtml('Grupos de Inventario (account groups)', $inventario);

// FV Document types
$doctypes = getDataFromSiigo('https://api.siigo.com/v1/document-types?type=FV');

displayHtml('FV Document types', $doctypes);

echo html('end');

function getDataFromSiigo($request)
{
    global $headers, $client, $logger;

    try {
        $response = $client->request('GET', $request, ['headers' => $headers]);

        return json_decode($response->getBody()->getContents(), true);
    } catch (\Exception $e) {
        $logger->error('??? ' . $e->getMessage());
        echo $e->getMessage();

        die();
    }
}


function displayHtml($titleData, $data)
{
    global $simlaUrl;

    echo "<div class='filter'><details>";
    echo "<summary>$titleData</summary>";
    echo "<br>";
    echo "<table class='styled-table' border style='border-collapse:collapse; width:500px; text-align:center'>";

    if ($titleData == 'Formas de Pago (payments)') {
        echo '<p>Copy "ID" to "Description" field of appropriate payment method at CRM <a target="_blank" href="'
        . $simlaUrl
        . 'admin/payment-types">here</a>.</p>'
        . '<p><b>Don`t forget to activate payment method in Siigo if you want to use it!</b></p>';
    }

    echo $titleData != 'Usuarios (users)'
        ? "<thead><tr>" . "<td>ID" . "<td>Name" . "<td>Active" . "</tr></thead>"
        : "<thead><tr>" . "<td>ID" . "<td>User" . "<td>Active" . "</tr></thead>";

    foreach ($data as $value) {
        echo "<tr>";

        if ($titleData == 'Formas de Pago (payments)') {
            echo "<td>siigo-{$value['id']}</td>";
        } else {
            echo "<td>{$value['id']}</td>";
        }

        if ($titleData == 'Usuarios (users)') {
            echo "<td>{$value['email']}</td>";
            echo "<td>{$value['active']}</td>";
        } else {
            echo "<td>{$value['name']}</td>";
            echo "<td>{$value['active']}</td>";
        }

        echo "</tr>";
    }

    echo "</table>";
    echo "</details></div>";
}

function html($place)
{
    if ($place === 'begin') {
        return
            '<!DOCTYPE html>
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
                            div {
                                margin-bottom: 20px;
                            }
                            .filter summary {
                                font-weight: 700;
                                cursor: pointer;
                            }
                            .filter summary:hover {
                                color: #009879;
                            }
                            .styled-table {
                                border-collapse: collapse;
                                font-size: 0.9em;
                                font-family: sans-serif;
                                min-width: 400px;
                                box-shadow: 0 0 20px rgba(0, 0, 0, 0.15);
                                margin-left: auto;
                                margin-right: auto;
                                margin-top: 15px;
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
                        <title>
                            Simla <-> Siigo
                        </title>
                    </head>
                    <body>
                        <h3>Data provided by Siigo</h3>
                        <br>';
    } else if ($place === 'end') {
        return
                    '</body>
                </html>';
    }
}
