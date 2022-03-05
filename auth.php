<?php

$logger = loggerBuild('AUTH');
$logger->info('- getting new auth token for ' . $site);


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
    $logger->error('✗ ' . $e->getMessage());
    die();
}


$accessToken = null;
if (isset($result['access_token']) && !empty($result['access_token'])) {
    $accessToken = $result['access_token'];
    $logger->info('✓ access token obtained for ' . $site);
} else {
    $logger->error('✗ there is no access token for ' . $site);
    die();
}

$headers = [
    'Accept' => 'application/json',
    'Content-Type' => 'application/json',
    'Authorization' => 'Bearer ' . $accessToken,
];

//var_dump($headers);
