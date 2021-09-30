<?php

$fields = [
    'customer' => [
        'id' => 'id',
        'first_name' => 'firstName',
        'last_name' => 'lastName',
        'email' => 'email',
        'phones' => 'phones',
        'external_id' => 'externalId',
    ],
    'customerAddress' => [
        'address.index' => 'index',
        'address.country' => 'country',
        'address.region' => 'region',
        'address.city' => 'city',
        'address.street' => 'street',
        'address.building' => 'building',
        'address.house' => 'house',
        'address.block' => 'block',
        'address.flat' => 'flat',
        'address.floor' => 'floor',
        'address.intercom_code' => 'intercom_code',
        'address.metro' => 'metro',
        'address.notes' => 'notes',
    ],
    'customerContragent' => [
        'contragent.contragent_type' => 'contragentType',
        'contragent.legal_name' => 'legalName',
        'contragent.legal_address' => 'legalAddress',
        'contragent.certificate_number' => 'certificateNumber',
        'contragent.certificate_date' => 'certificateDate',
        'contragent.bank' => 'bank',
        'contragent.bank_address' => 'bankAddress',
        'contragent.corr_account' => 'corrAccount',
        'contragent.bank_account' => 'bankAccount',
    ],
    'order' => [
        'id' => 'id',
        'created_at' => 'created_at',
        'order_type' => 'orderType',
        'order_method' => 'orderMethod',
        'site' => 'site',
        'status' => 'status',
        'customer' => 'customer',
        'manager' => 'manager',
        'first_name' => 'firstName',
        'last_name' => 'lastName',
        'patronymic' => 'patronymic',
        'phone' => 'phone',
        'additional_phone' => 'additionalPhone',
        'email' => 'email',
        'payment_type' => 'paymentType',
        'payment_status' => 'paymentStatus',
        'discount' => 'discount',
        'discount_percent' => 'discountPercent',
        'prepay_sum' => 'prepaySum',
        'customer_comment' => 'customerComment',
        'manager_comment' => 'managerComment',
        'shipment_store' => 'shipmentStore',
        'shipment_date' => 'shipmentDate',
        'shipped' => 'shipped',
        'contact' => 'contact',
        'company' => 'company',
        'payment' => 'payment',
        'payments.amount' => 'amount',
        'payments.paid_at' => 'paidAt',
        'payments.comment' => 'comment',
        'payments.type' => 'type',
        'payments.status' => 'status',
    ],
    'item' => [
        'order_product.id' => 'id',
        'order_product.initial_price' => 'initialPrice',
        'order_product.discount' => 'discount',
        'order_product.discount_percent' => 'discountPercent',
        'order_product.quantity' => 'quantity',
        'order_product.status' => 'status',
        'order_product.summ' => 'summ',
    ],
    'delivery' => [
        'delivery_type' => 'code',
        'delivery_service' => 'service',
        'delivery_date' => 'date',
        'delivery_time' => 'time',
        'delivery_cost' => 'cost',
        'delivery_net_cost' => 'netCost',
    ],
    'orderAddress' => [
        'delivery_address.country' => 'country',
        'delivery_address.index' => 'index',
        'delivery_address.region' => 'region',
        'delivery_address.city' => 'city',
        'delivery_address.street' => 'street',
        'delivery_address.building' => 'building',
        'delivery_address.house' => 'house',
        'delivery_address.block' => 'block',
        'delivery_address.flat' => 'flat',
        'delivery_address.floor' => 'floor',
        'delivery_address.intercom_code' => 'intercomCode',
        'delivery_address.metro' => 'metro',
        'delivery_address.notes' => 'notes',
    ],
    'integrationDelivery' => [
        'integration_delivery_data.status' => 'status',
        'integration_delivery_data.track_number' => 'trackNumber',
        'integration_delivery_data.courier' => 'courier',
    ],
];

function assemblyCustomer($customerHistory) {

    global $fields;

    $customers = array();
    $customerHistory = filterHistory($customerHistory, 'customer');

    foreach ($customerHistory as $change) {
        $change['customer'] = removeEmpty($change['customer']);

        // deleted
        if (isset($change['deleted'])
            && $change['deleted']
            //&& isset($customers[$change['customer']['id']])
        ) {
            $customers[$change['customer']['id']]['deleted'] = true;
            continue;
        }


        // create - put data from array
        if ($change['field'] == 'id') {
            $customers[$change['customer']['id']] = $change['customer'];
        }

        // merge fields in array
        if (isset($customers[$change['customer']['id']])) {
            $customers[$change['customer']['id']] = array_merge($customers[$change['customer']['id']], $change['customer']);
        } else {
            $customers[$change['customer']['id']] = $change['customer'];
        }


        // update by main fields
        if (isset($fields['customer'][$change['field']]) && $fields['customer'][$change['field']]) {

            $customers[
                $change['customer']['id']
            ][
                $fields['customer'][$change['field']]
            ] = newValue($change['newValue']);
        }

        // update by address fields
        if (isset($fields['customerAddress'][$change['field']]) && $fields['customerAddress'][$change['field']]) {

            if (!isset($customers[$change['customer']['id']]['address'])) {
                $customers[$change['customer']['id']]['address'] = [];
            }

            $customers[
                $change['customer']['id']
            ][
                'address'
            ][
                $fields['customerAddress'][$change['field']]
            ] = newValue($change['newValue']);
        }

        // update subscribe
        if (isset($change['customer']['id']) && $change['field'] == 'email_marketing_unsubscribed_at') {

            if ($change['oldValue'] == null && is_string(newValue($change['newValue']))) {
                $customers[$change['customer']['id']]['subscribed'] = false;
            } elseif (is_string($change['oldValue']) && newValue($change['newValue']) == null) {
                $customers[$change['customer']['id']]['subscribed'] = true;
            }
        }
    }

    return $customers;
}

function assemblyOrder($orderHistory) {

    global $fields;

    $orders = array();
    $orderHistory = filterHistory($orderHistory, 'order');

    foreach ($orderHistory as $change) {
        $change['order'] = removeEmpty($change['order']);

        // items
        if (isset($change['order']['items']) && $change['order']['items']) {
            $items = array();
            foreach($change['order']['items'] as $item) {
                if (isset($change['created'])) {
                    $item['create'] = 1;
                }
                $items[$item['id']] = $item;
            }
            $change['order']['items'] = $items;
        }

        // contragentType
        if (isset($change['order']['contragent']['contragentType']) && $change['order']['contragent']['contragentType']) {
            $change['order']['contragentType'] = $change['order']['contragent']['contragentType'];
            unset($change['order']['contragent']);
        }

        // merge fields in array
        if (!empty($orders) && isset($orders[$change['order']['id']])) {
            $orders[$change['order']['id']] = array_merge($orders[$change['order']['id']], $change['order']);
        } else {
            $orders[$change['order']['id']] = $change['order'];
        }

        // items
        if (isset($change['item']) && $change['item']) {

            // merge fields in array
            if (isset($orders[$change['order']['id']]['items'][$change['item']['id']])) {
                $orders[$change['order']['id']]['items'][$change['item']['id']] = array_merge($orders[$change['order']['id']]['items'][$change['item']['id']], $change['item']);
            } else {
                $orders[$change['order']['id']]['items'][$change['item']['id']] = $change['item'];
            }

            // create item
            if ($change['oldValue'] === null && $change['field'] == 'order_product') {
                $orders[$change['order']['id']]['items'][$change['item']['id']]['create'] = true;
            }

            // delete item
            if ($change['newValue'] === null && $change['field'] == 'order_product') {
                $orders[$change['order']['id']]['items'][$change['item']['id']]['delete'] = true;
            }

            // set item fields
            if (!isset($orders[$change['order']['id']]['items'][$change['item']['id']]['create'])
                && isset($fields['item'][$change['field']])
                && $fields['item'][$change['field']]
            ) {
                $orders[$change['order']['id']]['items'][$change['item']['id']][$fields['item'][$change['field']]] = $change['newValue'];
            }

        // payment
        } elseif ($change['field'] == 'payments' && isset($change['payment'])) {

            if ($change['newValue'] !== null) {
                $orders[$change['order']['id']]['payments'][] = newValue($change['payment']);
            }

        // other
        }  else {

            // set delivery service
            if (isset($fields['delivery'][$change['field']]) && $fields['delivery'][$change['field']] == 'service') {
                $orders[$change['order']['id']]['delivery']['service']['code'] = newValue($change['newValue']);

            // set delivery fields
            } elseif (isset($fields['delivery'][$change['field']]) && $fields['delivery'][$change['field']]) {
                $orders[$change['order']['id']]['delivery'][$fields['delivery'][$change['field']]] = newValue($change['newValue']);

            // set orderAddress fields
            } elseif (isset($fields['orderAddress'][$change['field']]) && $fields['orderAddress'][$change['field']]) {
                $orders[$change['order']['id']]['delivery']['address'][$fields['orderAddress'][$change['field']]] = $change['newValue'];

            // set integrationDelivery fields
            } elseif (isset($fields['integrationDelivery'][$change['field']]) && $fields['integrationDelivery'][$change['field']]) {
                $orders[$change['order']['id']]['delivery']['service'][$fields['integrationDelivery'][$change['field']]] = newValue($change['newValue']);

            // set customerContragent fields
            } elseif (isset($fields['customerContragent'][$change['field']]) && $fields['customerContragent'][$change['field']]) {
                $orders[$change['order']['id']][$fields['customerContragent'][$change['field']]] = newValue($change['newValue']);

            // set custom fileds
            } elseif (strripos($change['field'], 'custom_') !== false) {
                $orders[$change['order']['id']]['customFields'][str_replace('custom_', '', $change['field'])] = newValue($change['newValue']);

            // set order fileds
            } elseif (isset($fields['order'][$change['field']]) && $fields['order'][$change['field']]) {
                $orders[$change['order']['id']][$fields['order'][$change['field']]] = newValue($change['newValue']);
            }

            // created
            if (isset($change['created'])) {
                $orders[$change['order']['id']]['create'] = 1;
            }

            // deleted
            if (isset($change['deleted'])) {
                $orders[$change['order']['id']]['deleted'] = 1;
            }
        }
    }

    return $orders;
}

function filterHistory($historyEntries, $recordType) {

    $history = [];
    $organizedHistory = [];
    $notOurChanges = [];

    foreach ($historyEntries as $entry) {

        // объекты без externalId: все изменения сделанные чужим ключом или изм самого externalId
        if (!isset($entry[$recordType]['externalId'])) {
            if ($entry['source'] == 'api'
                && isset($change['apiKey']['current'])
                && $entry['apiKey']['current'] == true
                && $entry['field'] != 'externalId'
            ) {
                continue;
            } else {
                $history[] = $entry;
            }

            continue;
        }

        // объекты с externalId
        $externalId = $entry[$recordType]['externalId'];
        $field = $entry['field'];

        if (!isset($organizedHistory[$externalId])) {
            $organizedHistory[$externalId] = [];
        }

        if (!isset($notOurChanges[$externalId])) {
            $notOurChanges[$externalId] = [];
        }

        // изменения текущим ключом которые были начаты чужим или изм externalId
        if ($entry['source'] == 'api'
            && isset($entry['apiKey']['current'])
            && $entry['apiKey']['current'] == true
        ) {
            if (isset($notOurChanges[$externalId][$field]) || $entry['field'] == 'externalId') {
                $organizedHistory[$externalId][] = $entry;
            } else {
                continue;
            }

        // все изменения сделанные чужими ключами
        } else {
            $organizedHistory[$externalId][] = $entry;
            $notOurChanges[$externalId][$field] = true;
        }
    }

    unset($notOurChanges);

    // сливаем организованные по externalId изменения в общий массив
    foreach ($organizedHistory as $historyChunk) {
        $history = array_merge($history, $historyChunk);
    }

    return $history;
}

function newValue($value) {

    if (isset($value['code'])) {
        return $value['code'];
    } else {
        return $value;
    }
}

function removeEmpty($inputArray) {

    $outputArray = array();

    if (!empty($inputArray)) {
        foreach ($inputArray as $key => $element) {
            if(!empty($element) || $element === 0 || $element === '0') {
                if (is_array($element)) {
                    $element = removeEmpty($element);
                }

                $outputArray[$key] = $element;
            }
        }
    }

    return $outputArray;
}

// vars ----------------------------------------------------------------------------------------------------------------
$emailPattern = "/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,})$/i";
$logDateFormat = 'Y-m-d H:i:s';


// functions -----------------------------------------------------------------------------------------------------------
function getDateFromStr($dateStr = 'now', $timeZone = 'America/Bogota', $toTimeZone = 'America/Bogota') {

    $date = new \DateTime($dateStr, new DateTimeZone($timeZone));
    $date->setTimezone(new DateTimeZone($toTimeZone));

    return $date;
}

function createPaymentTypeInCRM($paymentMethod) {

    global $api;
    $crmPaymentStatuses = [];

    try {
        $response = $api->request->paymentStatusesList();
        if ($response->isSuccessful()) {
            foreach ($response['paymentStatuses'] as $paymentStatus) {
                $crmPaymentStatuses[] = $paymentStatus['code'];
            }
        }
    } catch (\RetailCrm\Exception\CurlException $e) {
        $logger->error('✗ connection error: ' . $e->getMessage());

        return false;
    }

    $paymentType = [
        'name' => $paymentMethod['name'],
        'code' => 'siigo-'.$paymentMethod['id'],
        'active' => true,
        'description' => 'Siigo type '.$paymentMethod['type'],
        'paymentStatuses' => $crmPaymentStatuses,
    ];

    try {
        $response = $api->request->paymentTypesEdit($paymentType);
        if ($response->isSuccessful()) {

            return true;
        }
    } catch (\RetailCrm\Exception\CurlException $e) {
        $logger->error('✗ connection error: ' . $e->getMessage());

        return false;
    }
}

/**
 * @param array $address = [
        'city' => $customer['address']['city'],
        'region' => $customer['address']['region'],
        'countryCode' => $customer['address']['countryIso'],
    ];
 * @return array|bool
 */
function prepareCustomerAddress(array $address) {
    $result = [];
    $addressList = '';
    $addressFile = dirname(__FILE__) . '/generatedAddresses';
    if (file_exists($addressFile)) {
        $addressList = file_get_contents($addressFile);
    }

    if (!strlen($addressList)) {
        $logger->error('✗ File with addresses not found or empty');
        die();
    }

    $addressList = json_decode($addressList, true);
    $countryCode = $address['countryCode'];
    $city = $address['city'];
    $region = $address['region'];

    if (!$countryCode || !$city || !$region) {
        return false;
    }

    $countryCode = ucfirst(strtolower($countryCode));
    if (isset($addressList[$countryCode])) {
        $county = $addressList[$countryCode];

        $result['country_code'] = $countryCode;

        $region = clearAddressName($region);
        $city = clearAddressName($city);

        if (isset($county[$region])) {
            $state = $county[$region];
            $result['state_code'] = $state['state_code'];

            if (isset($state[$city])) {
                $result['city_code'] = $state[$city];

                return $result;
            }
        }

        if (isset($county[$city])) {
            $findedCity = $county[$city];

            $result['city_code'] = $findedCity['city_code'];
            $result['state_code'] = $findedCity['state_code'];

            return $result;
        }
    }

    return false;
}

/**
 * @param string $address
 * @return string
 */
function clearAddressName(string $address) {
        $unwanted_array = [
        'Š'=>'S', 'š'=>'s', 'Ž'=>'Z', 'ž'=>'z', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
        'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U',
        'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c',
        'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o',
        'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ü' => 'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y'
    ];

    $result = str_replace([" ", '-', ',', "'", '`', chr(0xC2).chr(0xA0)], '', $address);
    $result = strtr($result, $unwanted_array);

    return strtolower($result);
}

// корявый метод который можно вытащить название города из Siigo по коду (требуется свежий ASPXAUTH)
function getCityNameByCode($cityCode)
{
    $cityName = null;

    $headers = [
        'cookie' => '.ASPXAUTH=A2E35DC820F575E567701AFCF822A66FE1BE638D0F39B60A0AE0144F4D2202D921EFE2A62BDDF0DA5BA9252FC77C7E2FC8F33D25FD115297DB2819FE93ABA5F4D857F68EF8FB6530DC99E555D1D39F5A7E1044AC951B19653CB74AF0046576F588F9A7B0C39DB0214719DEBC8B0DB932EA1381475152A68B49AFBE622AA75C902FBB5C87DCEE0EEC6524EC0D8F15110B51CD27DA8144D98F7F718D000AAA74F190B71A79E70269987CCFF85955CF3C05E2DBC604DE0CC1D4C9E730CB19D9AF0B4C00D86548E3A31280ED6434B24AA14ADDE27254;',
    ];

    $post = [
        'BrowseCode' => '5432',
        'SearchText' => $cityCode,
        'RequestWHERE' => '',
        'txtAutoCompleteID' => 'Default_ucControlPane0_ctl00_ctl11_ctl00_oACCityCode_txtAutoCompleteLookup',
        'addRecordExtraParams' => '',
    ];

    $client = new \Guzzle\Http\Client();
    $client->setConfig([\Guzzle\Http\Client::SSL_CERT_AUTHORITY => 'system']);

    $url = 'https://siigonube.siigo.com/INVERSIONESPIELBONITASAS/Framework/Controls/AutoComplete.ashx';
    $request = $client->post($url, $headers, $post);

    try {
        $response = $request->send();
        $response = mb_substr($response, mb_strrpos($response, '[{'));
        $result = json_decode($response, true);

        if (is_array($result) && $result) {
            $city = current($result);
            $cityName = $city['Name'];
        }

    } catch (\Guzzle\Common\Exception\GuzzleException $e) {
        $logger->error('✗ ' . $e->getMessage());
    }

    return $cityName;
}
