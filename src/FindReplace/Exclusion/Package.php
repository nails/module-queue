<?php

namespace Nails\Queue\FindReplace\Exclusion;

use Nails\FindReplace\Interfaces\Exclusion;

class Package implements Exclusion
{
    public function hidden(): array
    {
        return [
            '/^' . preg_quote(NAILS_DB_PREFIX, '/') . 'queue_/',
        ];
    }

    public function readOnly(): array
    {
        return [];
    }
}
