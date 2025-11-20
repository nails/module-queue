<?php

namespace Nails\Queue\Admin\Permission\Overview;

use Nails\Admin\Interfaces\Permission;

class View implements Permission
{
    public function label(): string
    {
        return 'Can view queue overview';
    }

    public function group(): string
    {
        return 'Queue';
    }
}
