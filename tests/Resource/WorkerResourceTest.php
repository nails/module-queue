<?php

namespace Tests\Queue\Resource;

use Nails\Queue\Resource\Worker as WorkerResource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nails\Queue\Resource\Worker
 */
class WorkerResourceTest extends TestCase
{
    /**
     * Constructing a Worker resource decodes queues JSON and converts heartbeat to DateTime resource.
     */
    public function test_constructs_with_expected_types(): void
    {
        // Arrange
        $workerStd = (object) [
            'id'        => 7,
            'token'     => 'tok_123',
            'queues'    => json_encode(['Foo', 'Bar']),
            'heartbeat' => '2024-01-01 12:00:00',
        ];

        // Act
        $worker = new WorkerResource($workerStd);

        // Assert
        self::assertSame('tok_123', $worker->token);
        self::assertIsArray($worker->queues);
        self::assertSame(['Foo', 'Bar'], $worker->queues);
        self::assertNotEmpty($worker->heartbeat);
        self::assertSame('2024-01-01 12:00:00', $worker->heartbeat->format('Y-m-d H:i:s'));
    }
}
