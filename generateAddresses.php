<?php

require_once dirname(__FILE__) . '/functions.php';

CONST CITY_CODE = 0;
CONST CITY_NAME = 1;
CONST STATE_CODE = 2;
CONST STATE_NAME = 3;
CONST COUNTRY_CODE = 4;

$rowCounter = 0;
$arr = [];

if (($handle = fopen("Lista-de-ciudades.csv", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
        $rowCounter++;
        if ($rowCounter < 2) continue;

        echo "<pre>", print_r($data, true), "</pre>";

        $stateName = clearAddressName($data[STATE_NAME]);
        $cityName = clearAddressName($data[CITY_NAME]);

        $arr[$data[COUNTRY_CODE]][$stateName]['state_code'] = $data[STATE_CODE];
        $arr[$data[COUNTRY_CODE]][$stateName][$cityName] = $data[CITY_CODE];
        //$arr[$data[COUNTRY_CODE]][$cityName]['city_code'] = $data[CITY_CODE];
        //$arr[$data[COUNTRY_CODE]][$cityName]['state_code'] = $data[STATE_CODE];
    }

    file_put_contents(dirname(__FILE__) . '/generatedAddresses', json_encode($arr));
}

