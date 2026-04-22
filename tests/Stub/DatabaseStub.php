<?php

namespace Tests\Queue\Stub;

use Nails\Common\Service\Database;

/**
 * Database stub which explicitly declares magic methods as concrete methods
 * so they can be mocked with PHPUnit's onlyMethods() in PHPUnit 12+.
 */
abstract class DatabaseStub extends Database
{
    public function query($sql, $binds = false, $return_object = null): mixed
    {
        return parent::__call('query', [$sql, $binds, $return_object]);
    }
}
