<?php

namespace KubernetesClient;

/**
 * Used for the various kubernetes watch endpoints for continuous feed of data
 *
 * Class Watch
 * @package KubernetesClient
 */
class Watch implements WatchIteratorInterface
{
    /**
     * Default streamTimeout
     */
    const DEFAULT_STREAM_TIMEOUT = 100000; // .1 seconds

    /**
     * Default streamReadLength
     */
    const DEFAULT_STREAM_READ_LENGTH = 8192;

    /**
     * Default deadPeerDetectionTimeout
     */
    const DEFAULT_DEAD_PEER_DETECTION_TIMEOUT = 600;

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
     * Used to keep track of the very last resourceVersion successfully received by the watcch.  This is used to
     * prevent re-triggering ADDED events for objects that change very infrequently that either re-connect due to
     * timeout or otherwise.  ie: Do NOT trigger 'ADDED' event twice for the same resource of the same version.
     *
     * @var string
     */
    private $resourceVersionLastSuccess = null;

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
     * Time after which no data has been received that the socket connection is reestablished
     *
     * @var int
     */
    private $deadPeerDetectionTimeout = self::DEFAULT_DEAD_PEER_DETECTION_TIMEOUT;

    /**
     * Used to keep track of the very last time data was successfully read from the socket.
     * If the time + deadPeerDetectionTimeout is < 'now' then the socket/connection is re-established
     *
     * @var int
     */
    private $lastBytesReadTimestamp = 0;

    /**
     * Used to track when a connection is made
     *
     * @var int
     */
    private $handleStartTimestamp = 0;

    /**
     * Watch constructor.
     *
     * @param Client $client
     * @param $endpoint
     * @param array $params
     * @param \Closure $callback
     */
    public function __construct(Client $client, $endpoint, $params, \Closure $callback)
    {
        $this->client = $client;
        $this->endpoint = $endpoint;
        $this->callback = $callback;
        $this->params = $params;

        // cleanse the resourceVersion to prevent usage after initial read
        if (isset($this->params['resourceVersion'])) {
            $this->setResourceVersion($this->params['resourceVersion']);
            $this->setResourceVersionLastSuccess($this->params['resourceVersion']);
            unset($this->params['resourceVersion']);
        }
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
     * @throws \Exception
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
            $handle = @fopen($url, 'r', false, $this->getClient()->getStreamContext());
            if ($handle === false) {
                $e = error_get_last();
                throw new \Exception($e['message'], $e['type']);
            }
            stream_set_timeout($handle, 0, $this->getStreamTimeout());
            $this->handle = $handle;
            $this->setHandleStartTimestamp(time());
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
     * Set deadPeerDetectionTimeout (seconds)
     *
     * @param $value
     */
    public function setDeadPeerDetectionTimeout($value)
    {
        $this->deadPeerDetectionTimeout = (int) $value;
    }

    /**
     * Get deadPeerDetectionTimeout (seconds)
     *
     * @return int
     */
    public function getDeadPeerDetectionTimeout()
    {
        return $this->deadPeerDetectionTimeout;
    }

    /**
     * Get handleStartTimestamp
     *
     * @return int
     */
    private function getHandleStartTimestamp()
    {
        return $this->handleStartTimestamp;
    }

    /**
     * Set handleStartTimestamp
     *
     * @param $value
     */
    private function setHandleStartTimestamp($value)
    {
        $this->handleStartTimestamp = (int) $value;
    }

    /**
     * Set resourceVersion
     *
     * @param $value
     */
    private function setResourceVersion($value)
    {
        if ($value > $this->resourceVersion || $value === null) {
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
     * Set resourceVersionLastSuccess
     *
     * @param $value
     */
    private function setResourceVersionLastSuccess($value)
    {
        if ($value > $this->resourceVersionLastSuccess) {
            $this->resourceVersionLastSuccess = $value;
        }
    }

    /**
     * Get resourceVersionLastSuccess
     *
     * @return string
     */
    private function getResourceVersionLastSuccess()
    {
        return $this->resourceVersionLastSuccess;
    }

    /**
     * Set lastBytesReadTimestamp
     *
     * @param $value
     */
    private function setLastBytesReadTimestamp($value)
    {
        $this->lastBytesReadTimestamp = (int) $value;
    }

    /**
     * Get lastBytesReadTimestamp
     *
     * @return int
     */
    private function getLastBytesReadTimestamp()
    {
        return $this->lastBytesReadTimestamp;
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

            // detect dead peers
            $now = time();
            if ($this->getDeadPeerDetectionTimeout() > 0 &&
                $now >= ($this->getHandleStartTimestamp() + $this->getDeadPeerDetectionTimeout()) &&
                $now >= ($this->getLastBytesReadTimestamp() + $this->getDeadPeerDetectionTimeout())
            ) {
                $this->resetHandle();
                $handle = $this->getHandle();
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
                // PHP 7.4 now returns false when the timeout is hit
                if (version_compare(PHP_VERSION, '7.4', 'ge')) {
                    $data = "";
                } else {
                    throw new \Exception('Failed to read bytes from stream: ' . $this->getClient()->getConfig()->getServer());
                }
            }

            if (strlen($data) > 0) {
                $this->setLastBytesReadTimestamp(time());
            }

            $this->buffer .= $data;

            //break immediately if nothing is on the buffer
            if (empty($this->buffer) && $cycles > 0) {
                return;
            }

            if ((bool) strstr($this->buffer, "\n")) {
                $parts = explode("\n", $this->buffer);
                $parts_count = count($parts);
                for ($x = 0; $x < ($parts_count - 1); $x++) {
                    if (!empty($parts[$x])) {
                        try {
                            $response = json_decode($parts[$x], true);
                            $code = $this->preProcessResponse($response);
                            if ($code != 0) {
                                $this->resetHandle();
                                $this->resourceVersion = null;
                                $handle = $this->getHandle();
                                goto end;
                            }

                            if ($response['object']['metadata']['resourceVersion'] > $this->getResourceVersionLastSuccess()) {
                                ($this->callback)($response, $this);
                            }

                            $this->setResourceVersion($response['object']['metadata']['resourceVersion']);
                            $this->setResourceVersionLastSuccess($response['object']['metadata']['resourceVersion']);

                            if ($this->getStop()) {
                                $this->internal_stop();
                                return;
                            }
                        } catch (\Exception $e) {
                            //TODO: log failure
                        }
                    }
                }
                $this->buffer = $parts[($parts_count - 1)];
            }

            end:
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
     * @return void
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

            // detect dead peers
            $now = time();
            if ($this->getDeadPeerDetectionTimeout() > 0 &&
                $now >= ($this->getHandleStartTimestamp() + $this->getDeadPeerDetectionTimeout()) &&
                $now >= ($this->getLastBytesReadTimestamp() + $this->getDeadPeerDetectionTimeout())
            ) {
                $this->resetHandle();
                $handle = $this->getHandle();
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
                // PHP 7.4 now returns false when the timeout is hit
                if (version_compare(PHP_VERSION, '7.4', 'ge')) {
                    $data = "";
                } else {
                    throw new \Exception('Failed to read bytes from stream: ' . $this->getClient()->getConfig()->getServer());
                }
            }

            if (strlen($data) > 0) {
                $this->setLastBytesReadTimestamp(time());
            }

            $this->buffer .= $data;

            //break immediately if nothing is on the buffer
            if (empty($this->buffer) && $cycles > 0) {
                return;
            }

            if ((bool) strstr($this->buffer, "\n")) {
                $parts = explode("\n", $this->buffer);
                $parts_count = count($parts);
                for ($x = 0; $x < ($parts_count - 1); $x++) {
                    if (!empty($parts[$x])) {
                        try {
                            $response = json_decode($parts[$x], true);
                            $code = $this->preProcessResponse($response);
                            if ($code != 0) {
                                $this->resetHandle();
                                $this->resourceVersion = null;
                                $handle = $this->getHandle();
                                goto end;
                            }

                            $yield = ($response['object']['metadata']['resourceVersion'] > $this->getResourceVersionLastSuccess());

                            $this->setResourceVersion($response['object']['metadata']['resourceVersion']);
                            $this->setResourceVersionLastSuccess($response['object']['metadata']['resourceVersion']);

                            if ($yield) {
                                yield $response;
                            }

                            if ($this->getStop()) {
                                $this->internal_stop();
                                return;
                            }
                        } catch (\Exception $e) {
                            //TODO: log failure
                        }
                    }
                }
                $this->buffer = $parts[($parts_count - 1)];
            }

            end:
            $i_cycles++;
            if ($cycles > 0 && $cycles >= $i_cycles) {
                return;
            }
        }
    }

    private function preProcessResponse($response)
    {
        if (!is_array($response)) {
            return 1;
        }

        if (key_exists('kind', $response) && $response['kind'] == 'Status' && $response['status'] == 'Failure') {
            return 1;
        }

        // resourceVersion is too old
        if ($response['type'] == 'ERROR' && $response['object']['code'] == 410) {
            return 1;
        }

        return 0;
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
     * @return void
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
