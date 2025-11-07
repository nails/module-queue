<?php

namespace Nails\Queue\Factory;

use Nails\Queue\Interface;
use stdClass;

class Data implements Interface\Data
{
    public function __construct(protected array|string|int|float|bool|stdClass|null $data)
    {
    }

    public function get(): array|string|int|float|bool|stdClass|null
    {
        return $this->data;
    }

    public function toJson(): string
    {
        return json_encode($this->data);
    }
}
