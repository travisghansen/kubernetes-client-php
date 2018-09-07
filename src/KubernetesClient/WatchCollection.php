<?php

namespace KubernetesClient;

/**
 * Used to support looping operations with several watches in a synchronous manner
 *
 * Class WatchCollection
 * @package KubernetesClient
 */
class WatchCollection implements WatchIteratorInterface
{
    /**
     * Watches
     *
     * @var array
     */
    private $watches = [];

    /**
     * Break a loop
     *
     * @var bool
     */
    private $stop = false;

    /**
     * Add a watch to the collection
     *
     * @param Watch $watch
     */
    public function addWatch(Watch $watch)
    {
        $this->watches[] = $watch;
    }

    /**
     * Get list of watches in the collection
     *
     * @return array
     */
    public function getWatches()
    {
        return $this->watches;
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
        $this->setStop(false);
    }

    /**
     * Stop all watches in the collection and break the loop
     */
    public function stop()
    {
        $this->setStop(true);
        foreach ($this->getWatches() as $watch) {
            $watch->stop();
        }
    }

    /**
     * Synchronously process watches
     *
     * @param int $cycles
     * @throws \Exception
     */
    public function start($cycles = 0)
    {
        foreach ($this->getWatches() as $watch) {
            $watch->start($cycles);
        }
    }

    /**
     * Generator interface for looping
     *
     * @param int $cycles
     * @return \Generator|void
     */
    public function stream($cycles = 0)
    {
        $i_cycles = 0;
        while (true) {
            if ($this->getStop()) {
                $this->internal_stop();
                return;
            }
            foreach ($this->getWatches() as $watch) {
                if ($this->getStop()) {
                    $this->internal_stop();
                    return;
                }
                foreach ($watch->stream(1) as $message) {
                    if ($this->getStop()) {
                        $this->internal_stop();
                        return;
                    }
                    yield $message;
                }
            }
            $i_cycles++;
            if ($cycles > 0 && $cycles >= $i_cycles) {
                return;
            }
        }
    }
}
