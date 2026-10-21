<?php

namespace Nails\Queue\FindReplace\Exclusion;

use Nails\FindReplace\Interfaces\Exclusion;

class Package implements Exclusion
{
    public function hidden(): array
    {
        return [
            NAILS_DB_PREFIX . 'queue_job',
            NAILS_DB_PREFIX . 'queue_worker',
        ];
    }

    public function readOnly(): array
    {
        return [];
    }
}
