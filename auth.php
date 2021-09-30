<?php

echo "\n- getting new auth token for ".$site."\n";


$client = new \GuzzleHttp\Client([
    'ssl.certificate_authority' => 'system',
    'http_errors' => false,
]);


// get access token ----------------------------------------------------------------------------------------------------
$headers = [
    'Accept'=> 'application/json',
];

$post = [
    'username' => $config['siigo']['username'],
    'access_key' => $config['siigo']['access_key'],
];

try {
    $response = $client->request('POST', 'https://api.siigo.com/auth', [
        'headers' => $headers,
        'json' => $post,
    ]);
    $result = json_decode($response->getBody()->getContents(), true);

} catch (\Exception $e) {
    echo '✗ '.$e->getMessage()."\n"; die();
}


$accessToken = null;
if (isset($result['access_token']) && !empty($result['access_token'])) {
    $accessToken = $result['access_token'];
    echo "✓ access token obtained for ".$site."\n";
} else {
    echo "✗ there is no access token for ".$site."\n"; die();
}

$headers = [
    'Accept' => 'application/json',
    'Content-Type' => 'application/json',
    'Authorization' => 'Bearer '.$accessToken,
];

