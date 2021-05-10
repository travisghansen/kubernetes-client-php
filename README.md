# Intro
No nonsense PHP client for the Kubernetes API.  It supports standard `REST` calls along with `watch`es for a continuous
feed of data.  Because no models are used it's usable with `CRD`s and other functionality/endpoints that may not be
built-in.

# Example
See [sample.php](sample.php)

# Watches
Watches can (will) stay connected indefinitely, automatically reconnecting after server-side timeout.  The client will
keep track of the most recent `resourceVersion` processed to automatically start where you left off.

Watch callback closures should have the following signature:
```
$callback = function($event, $watch)..
```
Receiving the watch allows access to the client (and any other details on the watch) and also provides an ability to
stop the watch (break the loop) based off of event logic.

 * `GET /apis/batch/v1beta1/watch/namespaces/{namespace}/cronjobs/{name}` (specific resource)
 * `GET /apis/batch/v1beta1/watch/namespaces/{namespace}/cronjobs` (resource type namespaced)
 * `GET /apis/batch/v1beta1/watch/cronjobs` (resource type cluster-wide)
 * https://kubernetes.io/docs/reference/generated/kubernetes-api/v1.10/#watch

Other notes:
 - if using labelSelectors triggered events will fire with ADDED / DELETED types if the label is added/delete
 (ie: ADDED/DELETED do not necessarily equate to literally being added/deleted from k8s)

# Development
Note on `resourceVersion` per the doc:
> When specified with a watch call, shows changes that occur after that particular version of a resource. Defaults to
> changes from the beginning of history. When specified for list: - if unset, then the result is returned from remote
> storage based on quorum-read flag; - if it's 0, then we simply return what we currently have in cache, no guarantee; -
> if set to non zero, then the result is at least as fresh as given rv.

Note that it's only changes **after** the version.

## TODO
 * Introduce threads for callbacks?
 * Do codegen on swagger docs to provide and OO interface to requests/responses?

## Links
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
