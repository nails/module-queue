<?php

namespace Tests\Queue\Stub;

use Nails\Queue\Interface\Data;
use Nails\Queue\Interface\Task;

/**
 * Test stub task which records when it is run.
 */
class TestTask implements Task
{
    /** @var bool Whether run() has been invoked */
    public static bool $ran = false;

    /**
     * {@inheritDoc}
     */
    public static function getMaxRetries(): int
    {
        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function run(Data $data): void
    {
        self::$ran = true;
    }
}
