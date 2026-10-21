<?php

namespace Tests\Queue\FindReplace\Exclusion;

use Nails\Queue\FindReplace\Exclusion\Package;
use PHPUnit\Framework\TestCase;

class PackageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('NAILS_DB_PREFIX')) {
            define('NAILS_DB_PREFIX', 'nails_');
        }
    }

    public function testHidden(): void
    {
        self::assertSame(
            [
                '/^' . preg_quote(NAILS_DB_PREFIX, '/') . 'queue_/',
            ],
            (new Package())->hidden()
        );
    }

    public function testReadOnly(): void
    {
        self::assertSame(
            [],
            (new Package())->readOnly()
        );
    }
}
