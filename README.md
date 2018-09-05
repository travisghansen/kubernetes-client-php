# Intro
No nonsense PHP client for the Kubernetes API.  It supports standard `REST` calls along with `watch`es for a continuous
feed of data.

# Example
```php
<?php

require_once 'vendor/autoload.php';

declare(ticks = 1);
pcntl_signal(SIGINT, function() {
    exit(0);
});


$config = KubernetesClient\Config::BuildConfigFromFile();
$client = new KubernetesClient\Client($config);

// shared state for closures
$state = [];
$response = $client->request('/api/v1/namespaces/metallb-system/configmaps/config');
$state['metallb']['config'] = $response;

$response = $client->request('/api/v1/nodes');
$state['nodes']['list'] = $response;


$watches = new KubernetesClient\WatchCollection();

$callback = function($watch, $data) use (&$state) {
    echo date("c") . ': ' . $data['object']['kind'] . ' ' . $data['object']['metadata']['name'] . ' ' . $data['type'] . ' - ' . $data['object']['metadata']['resourceVersion'] . "\n";
};
$params = [
    'watch' => '1',
    //'timeoutSeconds' => 10,
    'resourceVersion' => $state['nodes']['list']['metadata']['resourceVersion'],
];
$watch = $client->createWatch('/api/v1/nodes?', $params, $callback);
$watches->addWatch($watch);

// closure style, blocking
//$watch->start();

// generator style, blocking
//foreach($watch->stream() as $event) {
//    var_dump($event);
//    $watch->stop();
//}

$params = [
    'resourceVersion' => $state['metallb']['config']['metadata']['resourceVersion'],
];
$callback = function($watch, $data) use (&$state) {
    echo date("c") . ': ' . $data['object']['kind'] . ' ' . $data['object']['metadata']['name'] . ' ' . $data['type'] . ' - ' . $data['object']['metadata']['resourceVersion'] . "\n";
};
$watch = $client->createWatch('/api/v1/watch/namespaces/metallb-system/configmaps/config', $params, $callback);
$watches->addWatch($watch);

// generator version on set of watches
//foreach ($watches->stream() as $event) {
//    var_dump($event);
//    $watches->stop();//move on
//}

while (true) {
    $watches->startSync();
    usleep(100 * 1000);
}
```

# Watches
Watches should be create with closure with the following signature:
```
$callback = function($watch, $event)..
```
Receiving the watch allows access to the client (and any other details on the watch) and also provides an ability to
stop the watch (break the loop) based off of event logic.

 * `GET /apis/batch/v1beta1/watch/namespaces/{namespace}/cronjobs/{name}` (specific resource)
 * `GET /apis/batch/v1beta1/watch/namespaces/{namespace}/cronjobs` (resource type namespaced)
 * `GET /apis/batch/v1beta1/watch/cronjobs` (resource type cluster-wide)
 * https://kubernetes.io/docs/reference/generated/kubernetes-api/v1.10/#watch

# TODO
 * Introduce threads for callbacks?
 * Do codegen on swagger docs to provide and OO interface to requests/responses

# Links
 * https://github.com/swagger-api/swagger-codegen/blob/master/README.md
 * https://github.com/kubernetes/community/blob/master/contributors/devel/api-conventions.md#api-conventions
 * https://kubernetes.io/docs/reference/using-api/client-libraries/#community-maintained-client-libraries
 * https://kubernetes.io/docs/tasks/administer-cluster/access-cluster-api/
 * https://kubernetes.io/docs/reference/using-api/api-concepts/
 * https://kubernetes.io/docs/concepts/overview/kubernetes-api/
 * https://stackoverflow.com/questions/1342583/manipulate-a-string-that-is-30-million-characters-long/1342760#1342760
 * https://github.com/kubernetes/client-go/blob/master/README.md
 * https://github.com/kubernetes-client/python-base/blob/master/watch/watch.py
 * https://github.com/kubernetes-client/python/issues/124

## Async
 * https://github.com/spatie/async/blob/master/README.md
 * https://github.com/krakjoe/pthreads/blob/master/README.md
 * http://php.net/manual/en/function.pcntl-fork.php
