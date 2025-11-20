<?php

namespace Tests\Queue\Resource;

use Nails\Queue\Enum\Job\Status;
use Nails\Queue\Factory\Data as DataFactory;
use Nails\Queue\Queue\Queues\DefaultQueue;
use Nails\Queue\Resource\Job as JobResource;
use Nails\Queue\Tasks\DoNothing as DoNothingTask;
use PHPUnit\Framework\TestCase;
use Tests\Queue\Stub\TestTask;

/**
 * @covers \Nails\Queue\Resource\Job
 */
class JobResourceTest extends TestCase
{
    /**
     * Construction should convert raw/stdClass payload into typed properties.
     */
    public function test_constructs_with_expected_types(): void
    {
        // Arrange
        $jobStd = (object) [
            'id'           => 42,
            'queue'        => DefaultQueue::class,
            'task'         => DoNothingTask::class,
            'data'         => json_encode(['hello' => 'world']),
            'status'       => Status::PENDING->value,
            'worker_id'    => null,
            'worker'       => null,
            'available_at' => '2024-01-01 12:00:00',
            'started'      => null,
            'finished'     => null,
            'errors'       => json_encode([]),
            'attempts'     => 0,
        ];

        // Act
        $job = new JobResource($jobStd);

        // Assert
        self::assertInstanceOf(DefaultQueue::class, $job->queue);
        self::assertInstanceOf(DoNothingTask::class, $job->task);
        self::assertInstanceOf(DataFactory::class, $job->data);
        self::assertSame(Status::PENDING, $job->status);
        self::assertNull($job->worker_id);
        self::assertNull($job->worker);
        self::assertSame(0, $job->attempts);
        self::assertIsArray($job->errors);
        self::assertNotEmpty($job->available_at);
    }

    /**
     * run() should delegate execution to the underlying Task::run().
     */
    public function test_run_delegates_to_task(): void
    {
        // Arrange
        // Use a dedicated stub task which records invocations
        $taskClass = TestTask::class;
        $job       = new JobResource((object) [
            'id'           => 42,
            'queue'        => DefaultQueue::class,
            'task'         => $taskClass,
            'data'         => json_encode(['x' => 1]),
            'status'       => Status::PENDING->value,
            'worker_id'    => null,
            'worker'       => null,
            'available_at' => '2024-01-01 12:00:00',
            'started'      => null,
            'finished'     => null,
            'errors'       => json_encode([]),
            'attempts'     => 0,
        ]);

        // Act
        $taskClass::$ran = false;
        $job->run();

        // Assert
        self::assertTrue($taskClass::$ran);
    }
}
