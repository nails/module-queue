<?php

namespace Tests\Queue\Queues;

use Nails\Queue\Interface\Queue as QueueInterface;
use Nails\Queue\Queue\Queues\PriorityQueue;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nails\Queue\Queue\Queues\PriorityQueue
 */
class PriorityQueueTest extends TestCase
{
    /**
     * PriorityQueue should implement the Queue interface.
     */
    public function test_implements_queue_interface(): void
    {
        // Arrange
        $queue = new PriorityQueue();

        // Act
        // No action required; verifying type compliance.

        // Assert
        self::assertInstanceOf(QueueInterface::class, $queue);
    }
}
