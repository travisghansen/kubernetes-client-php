<?php

namespace KubernetesClient;

/**
 * Used to ensure a consistent interface/behavior between a raw Watch and WatchCollection
 *
 * Interface WatchIteratorInterface
 * @package KubernetesClient
 */
interface WatchIteratorInterface
{
    /**
     * Start the watch and process events with the supplied closure.  If cycles > 0 then $cycles fread operations will
     * occur and then the loop will break.
     *
     * @param $cycles
     * @return mixed
     */
    public function start($cycles);

    /**
     * Break a read loop
     *
     * @return mixed
     */
    public function stop();

    /**
     * Generator interface (foreach) looping
     *
     * @param $cycles
     * @return mixed
     */
    public function stream($cycles);
}
