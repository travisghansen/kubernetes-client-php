<?php

namespace KubernetesClient;

/**
 * Used for the various kubernetes watch endpoints for continuous feed of data
 *
 * Class Watch
 * @package KubernetesClient
 */
class Watch
{
    /**
     * Default streamTimeout
     */
    const DEFAULT_STREAM_TIMEOUT = 100000;

    /**
     * Default streamReadLength
     */
    const DEFAULT_STREAM_READ_LENGTH = 8192;

    /**
     * Client instance;
     *
     * @var Client
     */
    private $client;

    /**
     * The URL endpoint to watch (added to server part)
     *
     * @var string
     */
    private $endpoint;

    /**
     * Closure for event processing
     *
     * @var \Closure
     */
    private $callback;

    /**
     * resource for the HTTP connection
     *
     * @var resource
     */
    private $handle;

    /**
     * Internal buffer used when reading data from the HTTP stream
     *
     * @var string
     */
    private $buffer;

    /**
     * Break a loop
     *
     * @var bool
     */
    private $stop = false;

    /**
     * Used to keep track of most recently processed resourceVersion
     *
     * @var string
     */
    private $resourceVersion = null;

    /**
     * URL params for the HTTP request
     *
     * @var array
     */
    private $params = [];

    /**
     * Stream timeout in microseconds
     *
     * @var int
     */
    private $streamTimeout = self::DEFAULT_STREAM_TIMEOUT;

    /**
     * Stream read length (bytes)
     *
     * @var int
     */
    private $streamReadLength = self::DEFAULT_STREAM_READ_LENGTH;

    /**
     * Watch constructor.
     *
     * @param Client $client
     * @param $endpoint
     * @param array $params
     * @param \Closure $callback
     */
    public function __construct(Client $client, $endpoint, $params = [], \Closure $callback)
    {
        $this->client = $client;
        $this->endpoint = $endpoint;
        $this->callback = $callback;
        $this->params = $params;
    }

    /**
     * Watch destructor.
     */
    public function __destruct()
    {
        $this->closeHandle();
    }

    /**
     * Get client
     *
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Retrieve (open if necessary) the HTTP connection
     *
     * @return bool|resource
     */
    private function getHandle()
    {
        // make sure to clean up old handles
        if ($this->handle !== null && feof($this->handle)) {
            $this->resetHandle();
        }

        // provision new handle
        if ($this->handle == null) {
            $params = $this->params;
            if (!empty($this->getResourceVersion())) {
                $params['resourceVersion'] = $this->getResourceVersion();
            }
            $query = http_build_query($params);
            $base = $this->getClient()->getConfig()->getServer().$this->endpoint;
            $url = $base;

            if (!empty($query)) {
                $parsed = parse_url($base);
                if (key_exists('query', $parsed) || substr($base, -1) == "?") {
                    $url .= '&'.$query;
                } else {
                    $url .= '?'.$query;
                }
            }
            $handle = fopen($url, 'r', false, $this->getClient()->getStreamContext());
            stream_set_timeout($handle, 0, $this->getStreamTimeout());
            $this->handle = $handle;
        }

        return $this->handle;
    }

    /**
     * Cleanly reset connection
     */
    private function resetHandle()
    {
        $this->closeHandle();
        $this->handle = null;
        $this->buffer = null;
    }

    /**
     * Close the connection handle
     *
     * @return bool
     */
    private function closeHandle()
    {
        if ($this->handle == null) {
            return true;
        }

        return fclose($this->handle);
    }

    /**
     * Set streamTimeout (microseconds)
     *
     * @param $value
     */
    public function setStreamTimeout($value)
    {
        if ($value < 1) {
            $value = self::DEFAULT_STREAM_TIMEOUT;
        }

        $this->streamTimeout = (int) $value;
        if ($this->handle !== null) {
            stream_set_timeout($this->handle, 0, $this->getStreamTimeout());
        }
    }

    /**
     * Get streamTimeout (microseconds)
     *
     * @return float|int
     */
    public function getStreamTimeout()
    {
        if ($this->streamTimeout < 1) {
            $this->setStreamTimeout(self::DEFAULT_STREAM_TIMEOUT);
        }

        return $this->streamTimeout;
    }

    /**
     * Set streamReadLength (bytes)
     *
     * @param $value
     */
    public function setStreamReadLength($value)
    {
        if ($value < 1) {
            $value = self::DEFAULT_STREAM_READ_LENGTH;
        }

        $this->streamReadLength = (int) $value;
    }

    /**
     * Get streamReadLength (bytes)
     *
     * @return int
     */
    public function getStreamReadLength()
    {
        if ($this->streamReadLength < 1) {
            $this->setStreamReadLength(self::DEFAULT_STREAM_READ_LENGTH);
        }

        return $this->streamReadLength;
    }

    /**
     * Set resourceVersion
     *
     * @param $value
     */
    private function setResourceVersion($value)
    {
        if ($value > $this->resourceVersion) {
            $this->resourceVersion = $value;
        }
    }

    /**
     * Get resourceVersion
     *
     * @return string
     */
    private function getResourceVersion()
    {
        return $this->resourceVersion;
    }

    /**
     * Read and process event messages (closure/callback)
     *
     * @param int $cycles
     * @throws \Exception
     */
    private function internal_iterator($cycles = 0)
    {
        $handle = $this->getHandle();
        $i_cycles = 0;
        while (true) {
            if ($this->getStop()) {
                $this->internal_stop();
                return;
            }

            //$meta = stream_get_meta_data($handle);
            if (feof($handle)) {
                if ($this->params['timeoutSeconds'] > 0) {
                    //assume we've reached a successful end of watch
                    return;
                } else {
                    $this->resetHandle();
                    $handle = $this->getHandle();
                }
            }

            $data = fread($handle, $this->getStreamReadLength());
            if ($data === false) {
                throw new \Exception('Failed to read bytes from stream: ' . $this->getClient()->getConfig()->getServer());
            }
            $this->buffer .= $data;

            //break immediately if nothing is on the buffer
            if (empty($this->buffer) && $cycles > 0) {
                return;
            }

            if ((bool) strstr($this->buffer, "\n")) {
                $parts = explode("\n", $this->buffer);
                $parts_count = count($parts);
                for ($x = 0; $x < $parts_count; $x++) {
                    if (!empty($parts[$x])) {
                        try {
                            $response = json_decode($parts[$x], true);
                            ($this->callback)($response, $this);
                            $this->setResourceVersion($response['object']['metadata']['resourceVersion']);

                            if ($this->getStop()) {
                                $this->internal_stop();
                                return;
                            }
                        } catch (\Exception $e) {
                            //TODO: log failure
                        }
                    }
                }
                $this->buffer = $parts[$parts_count];
            }

            $i_cycles++;
            if ($cycles > 0 && $cycles >= $i_cycles) {
                return;
            }
        }
    }

    /**
     * Read and process event messages (generator)
     *
     * @param int $cycles
     * @throws \Exception
     */
    private function internal_generator($cycles = 0)
    {
        $handle = $this->getHandle();
        $i_cycles = 0;
        while (true) {
            if ($this->getStop()) {
                $this->internal_stop();
                return;
            }

            //$meta = stream_get_meta_data($handle);
            if (feof($handle)) {
                if ($this->params['timeoutSeconds'] > 0) {
                    //assume we've reached a successful end of watch
                    return;
                } else {
                    $this->resetHandle();
                    $handle = $this->getHandle();
                }
            }

            $data = fread($handle, $this->getStreamReadLength());
            if ($data === false) {
                throw new \Exception('Failed to read bytes from stream: ' . $this->getClient()->getConfig()->getServer());
            }
            $this->buffer .= $data;

            //break immediately if nothing is on the buffer
            if (empty($this->buffer) && $cycles > 0) {
                return;
            }

            if ((bool) strstr($this->buffer, "\n")) {
                $parts = explode("\n", $this->buffer);
                $parts_count = count($parts);
                for ($x = 0; $x < $parts_count; $x++) {
                    if (!empty($parts[$x])) {
                        try {
                            $response = json_decode($parts[$x], true);
                            $this->setResourceVersion($response['object']['metadata']['resourceVersion']);
                            yield $response;

                            if ($this->getStop()) {
                                $this->internal_stop();
                                return;
                            }
                        } catch (\Exception $e) {
                            //TODO: log failure
                        }
                    }
                }
                $this->buffer = $parts[$parts_count];
            }

            $i_cycles++;
            if ($cycles > 0 && $cycles >= $i_cycles) {
                return;
            }
        }
    }

    /**
     * Get stop
     *
     * @return bool
     */
    private function getStop()
    {
        return $this->stop;
    }

    /**
     * Set stop
     *
     * @param $value
     */
    private function setStop($value)
    {
        $this->stop = (bool) $value;
    }

    /**
     * Internal logic for loop breakage
     */
    private function internal_stop()
    {
        $this->resetHandle();
        $this->setStop(false);
    }

    /**
     * Stop the watch and break the loop
     */
    public function stop()
    {
        $this->setStop(true);
    }

    /**
     * Start the loop.  If cycles is provided the loop is broken after N reads() of the connection
     *
     * @param int $cycles
     * @throws \Exception
     */
    public function start($cycles = 0)
    {
        return $this->internal_iterator($cycles);
    }

    /**
     * Start the loop.  If cycles is provided the loop is broken after N reads() of the connection
     *
     * @param int $cycles
     * @throws \Exception
     */
    public function stream($cycles = 0)
    {
        return $this->internal_generator($cycles);
    }

    /**
     * Fork a watch into a new process
     *
     * @return bool
     * @throws \Exception
     */
    public function fork()
    {
        if (!function_exists('pcntl_fork')) {
            return false;
        }
        $pid = pcntl_fork();

        if ($pid == -1) {
            //failure
            return false;
        } elseif ($pid) {
            return true;
        } else {
            $this->start();
            exit(0);
        }
    }
}
