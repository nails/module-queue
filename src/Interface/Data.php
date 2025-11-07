<?php

namespace Nails\Queue\Interface;

use stdClass;

interface Data
{
    /**
     * Constructs with the data that will be persisted to the job when it is run
     */
    public function __construct(array|string|int|float|bool|stdClass|null $data);

    /**
     * Retrieve the data as it was passed to the constructor
     *
     * @return array|string|int|float|bool|stdClass|null
     */
    public function get(): array|string|int|float|bool|stdClass|null;

    /**
     * Returns JSON representation of the data, used to persist the data to the database
     */
    public function toJson(): string;
}
