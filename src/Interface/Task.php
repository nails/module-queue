<?php

namespace Nails\Queue\Interface;

interface Task
{
    /**
     * Returns the maximum number of retries a given task will allow
     */
    public static function getMaxRetries(): int;

    /**
     * Performs the task with the provided data
     */
    public function run(Data $data): void;
}
