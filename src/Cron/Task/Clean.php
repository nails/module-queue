<?php

namespace Nails\Queue\Cron\Task;

use Nails\Cron\Task\Base;

class Clean extends Base
{
    const CRON_EXPRESSION = '*/5 * * * *';
    const CONSOLE_COMMAND = 'queue:clean';
}
