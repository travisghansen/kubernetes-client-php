<?php

namespace KubernetesClient;

use KubernetesClient\Dotty\DotAccess;

/**
 * Used to iterate large lists of data over multiple requests. Uses the kubernetes 'continue' feature to keep making
 * subsequent requests.
 *
 * Class ResourceList
 * @package KubernetesClient
 */
class ResourceList
{
    /**
     * Client instance
     *
     * @var Client
     */
    private $client;

    /**
     * GET List resource endpoint
     *
     * @var string
     */
    private $endpoint;

    /**
     * GET params to be used in API request
     *
     * @var array
     */
    private $params;

    /**
     * ResourceList constructor.
     * @param Client $client
     * @param $endpoint
     * @param array $params
     */
    public function __construct(Client $client, $endpoint, $params = [])
    {
        $this->client = $client;
        $this->endpoint = $endpoint;
        $this->params = $params;
    }

    /**
     * Get client instance
     *
     * @return Client
     */
    private function getClient()
    {
        return $this->client;
    }

    /**
     * Get endpoint
     *
     * @return string
     */
    private function getEndpoint()
    {
        return $this->endpoint;
    }

    /**
     * Get params
     *
     * @return array
     */
    private function getParams()
    {
        return $this->params;
    }

    /**
     * Get all values from a list endpoint.  Full list is returned as if made in a single call.
     *
     * @param int $pages
     * @return bool|mixed|string
     * @throws \Exception
     */
    public function get($pages = 0)
    {
        $endpoint = $this->getEndpoint();
        $params = $this->getParams();
        $list = $this->getClient()->request($endpoint, 'GET', $params);

        $i = 1;
        while (DotAccess::get($list, 'metadata.continue', false)) {
            if ($pages > 0 && $pages >= $i) {
                return $list;
            }
            $params['continue'] = DotAccess::get($list, 'metadata.continue');
            $i_list = $this->getClient()->request($endpoint, 'GET', $params);
            DotAccess::set($i_list, 'items', array_merge(DotAccess::get($list, 'items'), DotAccess::get($i_list, 'items')));
            $list = $i_list;
            unset($i_list);
            $i++;
        }

        return $list;
    }

    /**
     * Get all values from a list endpoint.  Used for iterators like foreach().
     *
     * @return \Generator
     * @throws \Exception
     */
    public function stream()
    {
        $endpoint = $this->getEndpoint();
        $params = $this->getParams();
        $list = $this->getClient()->request($endpoint, 'GET', $params);
        foreach (DotAccess::get($list, 'items') as $item) {
            yield $item;
        }

        while (DotAccess::get($list, 'metadata.continue', false)) {
            $params['continue'] = DotAccess::get($list, 'metadata.continue');
            $list = $this->getClient()->request($endpoint, 'GET', $params);
            foreach (DotAccess::get($list, 'items', false) as $item) {
                yield $item;
            }
        }
    }
}
