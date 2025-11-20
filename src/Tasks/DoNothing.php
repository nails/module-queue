<?php

namespace Nails\Queue\Tasks;

use Nails\Queue\Interface\Data;
use Nails\Queue\Interface\Task;

class DoNothing implements Task
{
    public static function getMaxRetries(): int
    {
        return 0;
    }

    public function run(Data $data): void
    {
        //  Silence is golden
    }
}
