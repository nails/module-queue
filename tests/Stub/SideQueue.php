<?php

namespace Tests\Queue\Stub;

use Nails\Queue\Interface\Queue;
use Nails\Queue\Resource\Worker;

class SideQueue implements Queue
{
    public static function setup(Worker $worker): void
    {
    }

    public static function refresh(Worker $worker): void
    {
    }
}
