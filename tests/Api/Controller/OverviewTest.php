<?php

namespace Tests\Queue\Api\Controller;

use Nails\Config;
use Nails\Factory;
use Nails\Queue\Api\Controller\Overview;
use Nails\Queue\Resource\Worker as WorkerResource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nails\Queue\Api\Controller\Overview
 */
class OverviewTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::set('NAILS_TIME_NOW', 'now');
        parent::tearDown();
    }

    /**
     * Worker rows include heartbeat timestamps and a stale flag from Worker::isStale().
     *
     * @covers \Nails\Queue\Api\Controller\Overview::mapWorker
     * @covers \Nails\Queue\Resource\Worker::isStale
     */
    public function test_map_worker_includes_stale_from_heartbeat(): void
    {
        // Arrange
        Config::set('NAILS_TIME_NOW', '2025-06-01 12:00:00');
        Config::set('QUEUE_WORKER_HEARTBEAT_STALE', 300);

        $overview = new class extends Overview {
            public function __construct()
            {
            }

            public function expose(WorkerResource $worker): array
            {
                return $this->mapWorker($worker);
            }
        };

        $created = '2025-06-01 10:00:00';
        $fresh   = $this->makeWorker('2025-06-01 11:59:50', $created);
        $stale   = $this->makeWorker('2025-06-01 11:54:59', $created);
        $edge    = $this->makeWorker('2025-06-01 11:55:00', $created);

        // Act
        $freshMapped = $overview->expose($fresh);
        $staleMapped = $overview->expose($stale);
        $edgeMapped  = $overview->expose($edge);

        // Assert
        self::assertFalse($freshMapped['stale']);
        self::assertTrue($staleMapped['stale']);
        self::assertFalse($edgeMapped['stale']);

        self::assertSame((int) $fresh->heartbeat->format('U'), $freshMapped['heartbeat']['unix']);
        self::assertSame($fresh->heartbeat->formatted, $freshMapped['heartbeat']['user']);
        self::assertSame((int) $stale->heartbeat->format('U'), $staleMapped['heartbeat']['unix']);
        self::assertSame((int) $fresh->created->format('U'), $freshMapped['created']['unix']);
        self::assertSame((int) $stale->created->format('U'), $staleMapped['created']['unix']);
        self::assertNotSame($freshMapped['created']['unix'], $freshMapped['heartbeat']['unix']);
    }

    private function makeWorker(string $heartbeat, string $created): WorkerResource
    {
        return new WorkerResource((object) [
            'id'        => 1,
            'token'     => 'tok',
            'queues'    => json_encode(['Nails\\Queue\\Queue\\Queues\\DefaultQueue']),
            'heartbeat' => $heartbeat,
            'created'   => Factory::resource('DateTime', null, ['raw' => $created]),
        ]);
    }
}
