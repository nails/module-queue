<?php

namespace Tests\Queue;

use Nails\Queue\Constants;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nails\Queue\Constants
 */
class ConstantsTest extends TestCase
{
    /**
     * The module slug constant should match the package name.
     */
    public function test_module_slug_is_correct(): void
    {
        // Arrange
        $expected = 'nails/module-queue';

        // Act
        $actual = Constants::MODULE_SLUG;

        // Assert
        self::assertEquals($expected, $actual);
    }
}
