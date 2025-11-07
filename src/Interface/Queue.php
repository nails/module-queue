<?php

namespace Nails\Queue\Interface;

use Nails\Queue\Resource\Worker;

interface Queue
{
    /**
     * Hook for the queue to perform setup operations before any tasks are processed
     */
    public static function setup(Worker $worker): void;

    /**
     * Hook to allow the queue to performn refresh operations during operation, will
     * be called every ~5 minutes depending on the jobs being run
     *
     * @return void
     */
    public static function refresh(Worker $worker): void;
}
