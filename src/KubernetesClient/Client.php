<?php

namespace KubernetesClient;

use Flow\JSONPath\JSONPathException;
use Symfony\Component\Yaml\Yaml;

/**
 * Client class for interacting with a kubernetes API.  Primary interface should be:
 *  - ->request()
 *  - ->createWatch()
 *  - ->createList()
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

    /**
     * Default request options
     *
     * @var array
     */
    protected $defaultRequestOptions = [];

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
     * @throws \Error
     * @throws JSONPathException
     */
    protected function getContextOptions()
    {
        $opts = array(
            'http'=>array(
                'ignore_errors' => true,
                'header' => "Accept: application/json, */*\r\nContent-Encoding: gzip\r\n"
            ),
        );

        if (!$this->config->getVerifyPeerName()) {
            $opts['ssl']['verify_peer_name'] = false;
        }

        if (!empty($this->config->getCertificateAuthorityPath())) {
            $opts['ssl']['cafile'] = $this->config->getCertificateAuthorityPath();
        }

        if (!empty($this->config->getClientCertificatePath())) {
            $opts['ssl']['local_cert'] = $this->config->getClientCertificatePath();
        }

        if (!empty($this->config->getClientKeyPath())) {
            $opts['ssl']['local_pk'] = $this->config->getClientKeyPath();
        }

        $token = $this->config->getToken();
        if (!empty($token)) {
            $opts['http']['header'] .= "Authorization: Bearer {$token}\r\n";
        }

        return $opts;
    }

    /**
     * Get the stream context
     *
     * @param string $verb
     * @param array $opts
     * @return resource
     * @throws \Error
     * @throws JSONPathException
     */
    public function getStreamContext($verb = 'GET', $opts = [])
    {
        $o = array_merge_recursive($this->getContextOptions(), $opts);
        $o['http']['method'] = $verb;

        if (substr($verb, 0, 5) == 'PATCH') {
            $o['http']['method'] = 'PATCH';

            /**
             * https://github.com/kubernetes/community/blob/master/contributors/devel/sig-architecture/api-conventions.md#patch-operations
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
                case 'PATCH-APPLY':
                    $o['http']['header'] .= "Content-Type: application/apply-patch+yaml\r\n";
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

    protected function setStreamBody($context, $verb = 'GET', $data, $options = [])
    {
        if (is_array($data) || is_object($data)) {
            switch ($verb) {
                case 'PATCH-APPLY':
                    stream_context_set_option($context, array('http' => array('content' => $this->encodeYamlBody($data, $options))));
                    break;
                default:
                    stream_context_set_option($context, array('http' => array('content' => $this->encodeJsonBody($data, $options))));
                    break;
            }
        } else {
            stream_context_set_option($context, array('http' => array('content' => $data)));
        }
    }

    private function encodeJsonBody($data, $options = [])
    {
        $encode_flags = $this->getRequestOption('encode_flags', $options);
        return json_encode($data, $encode_flags);
    }

    private function encodeYamlBody($data, $options = [])
    {
        if (function_exists('yaml_emit')) {
            return yaml_emit($data);
        } else {
            return Yaml::dump(
                $data,
                // This is the depth that symfony/yaml switches to "inline" (JSON-ish) YAML.
                // Set to a high number to try and keep behaviour vaguely consistent with
                // yaml_emit which never does this.
                PHP_INT_MAX,
                // Default to 2 spaces, as in yaml_emit
                2,
                // When dumping associative arrays, yaml_emit will output an empty array as `[]`
                // by default, where-as symfony/yaml will output as `{}`. This flag has it dumped
                // as `[]` to keep them consistent.
                Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE
            );
        }
    }

    /**
     * Make a request to the API
     *
     * @param $endpoint
     * @param string $verb
     * @param array $params
     * @param mixed $data
     * @param array $options
     * @throws \Exception
     * @return bool|mixed|string
     */
    public function request($endpoint, $verb = 'GET', $params = [], $data = null, $options = [])
    {
        $decode_flags = $this->getRequestOption('decode_flags', $options);

        $context = $this->getStreamContext($verb);
        if ($data) {
            $this->setStreamBody($context, $verb, $data, $options);
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

        $handle = fopen($url, 'r', false, $context);
        if ($handle === false) {
            $e = error_get_last();
            throw new \Exception($e['message'], $e['type']);
        }
        $response = stream_get_contents($handle);
        fclose($handle);

        $decode_response = $this->getRequestOption('decode_response', $options);
        if ($decode_response) {
            $associative = $this->getRequestOption('decode_associative', $options);
            $response = json_decode($response, $associative, 512, $decode_flags);
        }

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
    public function createWatch($endpoint, $params, \Closure $callback)
    {
        return new Watch($this, $endpoint, $params, $callback);
    }

    /**
     * Create a List for retrieving large lists
     *
     * @param $endpoint
     * @param array $params
     * @return ResourceList
     */
    public function createList($endpoint, $params = [])
    {
        return new ResourceList($this, $endpoint, $params);
    }

    /**
     * Set default request options
     *
     * @param $options
     * @return void
     */
    public function setDefaultRequestOptions($options) {
        $this->defaultRequestOptions = $options;
    }

    /**
     * Get request option value
     *
     * @param $option
     * @param $options
     * @return mixed|void
     */
    public function getRequestOption($option, $options) {
        $defaults = [
            'encode_flags' => 0,
            'decode_flags' => 0,
            'decode_response' => true,
            'decode_associative' => true,
        ];

        // request specific
        if (key_exists($option, $options)) {
            return $options[$option];
        }

        // client defaults
        if (key_exists($option, $this->defaultRequestOptions)) {
            return $this->defaultRequestOptions[$option];
        }

        // system defaults
        if (key_exists($option, $defaults)) {
            return $defaults[$option];
        }
    }
}
