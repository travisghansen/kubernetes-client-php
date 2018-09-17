<?php

// be sure to run composer install first
require_once 'vendor/autoload.php';

declare(ticks = 1);
pcntl_signal(SIGINT, function () {
    exit(0);
});

$config = KubernetesClient\Config::BuildConfigFromFile();
$client = new KubernetesClient\Client($config);


$configMapName = 'kubernetes-php-client-test';
$configMapNamespace = 'kube-system';

//POST
$data = [
    'kind' => 'ConfigMap',
    'metadata' => [
        'name' => $configMapName
    ],
    'data' => [
        'foo' => 'bar',
    ],
];
$response = $client->request("/api/v1/namespaces/${configMapNamespace}/configmaps", 'POST', [], $data);
var_dump($response);

//PATCH
$data = [
    'kind' => 'ConfigMap',
    'metadata' => [
        'name' => $configMapName
    ],
    'data' => [
        'bar' => 'baz',
    ],
];
$response = $client->request("/api/v1/namespaces/${configMapNamespace}/configmaps/${configMapName}", 'PATCH', [], $data);
var_dump($response);

//GET
$response = $client->request("/api/v1/namespaces/${configMapNamespace}/configmaps/${configMapName}");
var_dump($response);

//DELETE
$response = $client->request("/api/v1/namespaces/${configMapNamespace}/configmaps/${configMapName}", 'DELETE');
var_dump($response);

//LIST (retrieve large responses)
$params = [
    'limit' => 1
];
$list = $client->createList('/api/v1/nodes', $params);

// get all
$items = $list->get();
var_dump($items);

// get 1 page
$pages = 1;
$items = $list->get($pages);
var_dump($items);

// iterate
foreach ($list->stream() as $item) {
    var_dump($item);
}

// shared state for closures
$state = [];
$response = $client->request('/api/v1/nodes');
$state['nodes']['list'] = $response;

$callback = function ($event, $watch) use (&$state) {
    echo date("c") . ': ' . $event['object']['kind'] . ' ' . $event['object']['metadata']['name'] . ' ' . $event['type'] . ' - ' . $event['object']['metadata']['resourceVersion'] . "\n";
};
$params = [
    'watch' => '1',
    //'timeoutSeconds' => 10,//if set, the loop will break after the server has severed the connection
    'resourceVersion' => $state['nodes']['list']['metadata']['resourceVersion'],
];
$watch = $client->createWatch('/api/v1/nodes?', $params, $callback);
//$watch->setStreamReadLength(55);

// blocking (unless timeoutSeconds has been supplied)
//$watch->start();

// non blocking
$i = 0;
while (true) {
    $watch->start(1);
    usleep(100 * 1000);
    $i++;
    if ($i > 100) {
        echo date("c").": breaking while loop\n";
        break;
    }
}


// generator style, blocking
$i = 0;
foreach ($watch->stream() as $event) {
    echo date("c") . ': ' . $event['object']['kind'] . ' ' . $event['object']['metadata']['name'] . ' ' . $event['type'] . ' - ' . $event['object']['metadata']['resourceVersion'] . "\n";
    //$watch->stop();
    $i++;
    if ($i > 10) {
        echo date("c").": breaking foreach loop\n";
        break;
    }
}
