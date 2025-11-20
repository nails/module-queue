<?php

namespace Tests\Queue\Queues;

use Nails\Queue\Interface\Queue as QueueInterface;
use Nails\Queue\Queue\Queues\DefaultQueue;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nails\Queue\Queue\Queues\DefaultQueue
 */
class DefaultQueueTest extends TestCase
{
    /**
     * DefaultQueue should implement the Queue interface.
     */
    public function test_implements_queue_interface(): void
    {
        // Arrange
        $queue = new DefaultQueue();

        // Act
        // No action required; verifying type compliance.

        // Assert
        self::assertInstanceOf(QueueInterface::class, $queue);
    }
}
