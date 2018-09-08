<?php

namespace KubernetesClient;

/**
 * Client class for interacting with a kubernetes API.  Primary interface should be:
 *  - ->request()
 *  - ->createWatch()
 *
 * Class Client
 * @package KubernetesClient
 */
class Client
{
    /**
     * Client configuration
     *
     * @var Config
     */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Get client configuration
     *
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Get common options to be used for the stream context
     *
     * @return array
     */
    private function getContextOptions()
    {
        $opts = array(
            'http'=>array(
                'ignore_errors' => true,
                'header' => "Accept: application/json, */*\r\nContent-Encoding: gzip\r\n"
            ),
            'ssl'=>array(
                'cafile' => $this->config->getCertificateAuthorityPath(),
                'local_cert' => $this->config->getClientCertificatePath(),
                'local_pk' => $this->config->getClientKeyPath(),
            )
        );

        $token = $this->config->getToken();
        if (!empty($token)) {
            $opts['http']['header'] .= "Authorization: Bearer ${token}\r\n";
        }

        return $opts;
    }

    /**
     * Get the stream context
     *
     * @param string $verb
     * @param array $opts
     * @return resource
     */
    public function getStreamContext($verb = 'GET', $opts = [])
    {
        $o = array_merge_recursive($this->getContextOptions(), $opts);
        $o['http']['method'] = $verb;

        if (substr($verb, 0, 5) == 'PATCH') {
            /**
             * https://github.com/kubernetes/community/blob/master/contributors/devel/api-conventions.md#patch-operations
             * https://github.com/kubernetes/community/blob/master/contributors/devel/strategic-merge-patch.md
             *
             * Content-Type: application/json-patch+json
             * Content-Type: application/merge-patch+json
             * Content-Type: application/strategic-merge-patch+json
             */
            switch ($verb) {
                case 'PATCH-JSON':
                    $o['http']['header'] .= "Content-Type: application/json-patch+json\r\n";
                    break;
                case 'PATCH-STRATEGIC-MERGE':
                    $o['http']['header'] .= "Content-Type: application/strategic-merge-patch+json\r\n";
                    break;
                case 'PATCH':
                case 'PATCH-MERGE':
                default:
                    $o['http']['header'] .= "Content-Type: application/merge-patch+json\r\n";
                    break;
            }
        } else {
            $o['http']['header'] .= "Content-Type: application/json\r\n";
        }

        return stream_context_create($o);
    }

    /**
     * Make a request to the API
     *
     * @param $endpoint
     * @param string $verb
     * @param array $params
     * @param null $data
     * @throws \Exception
     * @return bool|mixed|string
     */
    public function request($endpoint, $verb = 'GET', $params = [], $data = null)
    {
        $context = $this->getStreamContext($verb);
        if ($data) {
            stream_context_set_option($context, array('http' => array('content' => json_encode($data))));
        }

        $query = http_build_query($params);
        $base = $this->getConfig()->getServer().$endpoint;
        $url = $base;

        if (!empty($query)) {
            $parsed = parse_url($base);
            if (key_exists('query', $parsed) || substr($base, -1) == "?") {
                $url .= '&'.$query;
            } else {
                $url .= '?'.$query;
            }
        }

        $handle = @fopen($url, 'r', false, $context);
        if ($handle === false) {
            $e = error_get_last();
            throw new \Exception($e['message'], $e['type']);
        }
        $response = stream_get_contents($handle);
        fclose($handle);

        $response = json_decode($response, true);

        return $response;
    }

    /**
     * Create a Watch for api feed
     *
     * @param $endpoint
     * @param array $params
     * @param \Closure $callback
     * @return Watch
     */
    public function createWatch($endpoint, $params = [], \Closure $callback)
    {
        $watch = new Watch($this, $endpoint, $params, $callback);

        return $watch;
    }
}
