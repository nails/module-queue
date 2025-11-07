<?php

namespace Nails\Queue\Queues;

use Nails\Queue\Interface\Queue;
use Nails\Queue\Resource\Worker;

class DefaultQueue implements Queue
{
    public static function setup(Worker $worker): void
    {
        //  Satisfying interface, nothing to set up
    }

    public static function refresh(Worker $worker): void
    {
        //  Satisfying interface, nothing to refresh
    }
}
