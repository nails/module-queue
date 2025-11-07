<?php

namespace Tests\Queue;

use Nails\Queue\Constants;
use PHPUnit\Framework\TestCase;

/**
 * Class ConstantsTest
 *
 * @package Test\Phone
 */
class ConstantsTest extends TestCase
{
    public function test_module_slug_is_correct()
    {
        $this->assertEquals(
            'nails/module-queue',
            Constants::MODULE_SLUG
        );
    }
}
