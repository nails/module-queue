<?php

namespace Tests\Queue\Service;

use DateInterval;
use DateTime;
use Nails\Common\Factory\Database\Transaction;
use Nails\Common\Helper\Model\Where;
use Nails\Common\Service\Database;
use Nails\Queue\Enum\Job\Status;
use Nails\Queue\Factory\Data as DataFactory;
use Nails\Queue\Model\Job as JobModel;
use Nails\Queue\Model\Worker as WorkerModel;
use Nails\Queue\Queue\Queues\DefaultQueue;
use Nails\Queue\Queue\Queues\PriorityQueue;
use Nails\Queue\Resource\Job as JobResource;
use Nails\Queue\Resource\Worker as WorkerResource;
use Nails\Queue\Service\Manager;
use Nails\Queue\Tasks\DoNothing as DoNothingTask;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Queue\Stub\ManagerStub;

/**
 * Tests the Queue Manager service behaviours.
 *
 * @covers \Nails\Queue\Service\Manager
 */
class ManagerTest extends TestCase
{
    /**
     * Build a Worker resource with sensible defaults for tests.
     */
    private function makeWorkerResource(array $overrides = []): WorkerResource
    {
        return new WorkerResource((object) [
            'id'        => $overrides['id'] ?? 1,
            'token'     => $overrides['token'] ?? 'tok',
            'queues'    => $overrides['queues'] ?? json_encode([DefaultQueue::class]),
            'heartbeat' => $overrides['heartbeat'] ?? '2025-01-01 00:00:00',
        ]);
    }

    /**
     * Build a Job resource with sensible defaults for tests.
     */
    private function makeJobResource(array $overrides = []): JobResource
    {
        return new JobResource((object) [
            'id'           => $overrides['id'] ?? 10,
            'queue'        => $overrides['queue'] ?? DefaultQueue::class,
            'task'         => $overrides['task'] ?? DoNothingTask::class,
            'data'         => $overrides['data'] ?? json_encode(['a' => 1]),
            'status'       => $overrides['status'] ?? Status::PENDING->value,
            'worker_id'    => $overrides['worker_id'] ?? null,
            'worker'       => $overrides['worker'] ?? null,
            'available_at' => $overrides['available_at'] ?? '2025-01-01 00:00:00',
            'started'      => $overrides['started'] ?? null,
            'finished'     => $overrides['finished'] ?? null,
            'errors'       => $overrides['errors'] ?? json_encode([]),
            'attempts'     => $overrides['attempts'] ?? 0,
        ]);
    }

    /**
     * Utility for making mock objects
     */
    private function makeMock(
        string $class,
        array $onlyMethods = [],
        array $addMethods = [],
    ): MockObject {

        $mockBuilder = $this->getMockBuilder($class)
            ->disableOriginalConstructor();

        if ($onlyMethods) {
            $mockBuilder->onlyMethods($onlyMethods);
        }

        //  addMethods is deprecated in PHPUnit 10.1 and removed in PHPUnit 12
        // https://github.com/sebastianbergmann/phpunit/issues/5320
        if ($addMethods) {
            $mockBuilder->addMethods($addMethods);
        }

        return $mockBuilder->getMock();
    }

    /**
     * Build a deterministic Manager using the shared ManagerStub.
     * Provides fixed time/backoff while allowing caller to pass in mocks.
     */
    private function makeManager(
        ?Database $database = null,
        ?WorkerModel $workerModel = null,
        ?JobModel $jobModel = null,
        ?DateTime $now = null,
        ?int $backoffSeconds = null
    ): Manager {
        $database       = $database ?? $this->createMock(Database::class);
        $workerModel    = $workerModel ?? $this->makeMock(WorkerModel::class);
        $jobModel       = $jobModel ?? $this->makeMock(JobModel::class);
        $now            = $now ?? new DateTime('2025-01-01 00:00:00');
        $backoffSeconds = $backoffSeconds ?? 42;

        return new ManagerStub($database, $workerModel, $jobModel, $now, $backoffSeconds);
    }


    //  @todo (Pablo 2025-11-07) - test addAlias()

    /**
     * resolveQueue: supports the built-in 'default' alias.
     *
     * @covers \Nails\Queue\Service\Manager::resolveQueue
     */
    public function test_resolve_queue_allows_default_alias(): void
    {
        // Arrange
        $manager = $this->makeManager();

        // Act
        $resolved = $manager->resolveQueue('default');

        // Assert
        self::assertInstanceOf(DefaultQueue::class, $resolved);
    }

    /**
     * resolveQueue: supports the built-in 'priority' alias.
     *
     * @covers \Nails\Queue\Service\Manager::resolveQueue
     */
    public function test_resolve_queue_allows_priority_alias(): void
    {
        // Arrange
        $manager = $this->makeManager();

        // Act
        $resolved = $manager->resolveQueue('priority');

        // Assert
        self::assertInstanceOf(PriorityQueue::class, $resolved);
    }

    /**
     * resolveQueue: ensure aliases are case insensitive.
     *
     * @covers \Nails\Queue\Service\Manager::resolveQueue
     */
    public function test_resolve_job_allows_case_insensitive_alias(): void
    {
        // Arrange
        $manager = $this->makeManager();

        // Act
        $resolved = $manager->resolveQueue('DeFaUlT');

        // Assert
        self::assertInstanceOf(DefaultQueue::class, $resolved);
    }

    /**
     * resolveQueue: passes through Queue instances unchanged.
     *
     * @covers \Nails\Queue\Service\Manager::resolveQueue
     */
    public function test_resolve_queue_allows_instance_passthrough(): void
    {
        // Arrange
        $manager = $this->makeManager();
        $queue   = new DefaultQueue();

        // Act
        $resolved = $manager->resolveQueue($queue);

        // Assert
        self::assertSame(DefaultQueue::class, $resolved::class);
    }

    /**
     * resolveQueue: accepts a queue class-string.
     *
     * @covers \Nails\Queue\Service\Manager::resolveQueue
     */
    public function test_resolve_queue_allows_class_string(): void
    {
        // Arrange
        $manager = $this->makeManager();

        // Act
        $resolved = $manager->resolveQueue(DefaultQueue::class);

        // Assert
        self::assertInstanceOf(DefaultQueue::class, $resolved);
    }

    /**
     * resolveQueue: throws for invalid alias.
     *
     * @covers \Nails\Queue\Service\Manager::resolveQueue
     */
    public function test_resolve_queue_invalid_alias_throws(): void
    {
        // Arrange
        $manager = $this->makeManager();

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        $manager->resolveQueue('nope');
    }

    /**
     * registerWorker should normalise queue inputs to class-strings.
     *
     * @covers \Nails\Queue\Service\Manager::registerWorker
     */
    public function test_register_worker_normalises_queues(): void
    {
        // Arrange
        /** @var WorkerModel&MockObject $workerModel */
        $workerModel = $this->makeMock(
            class: WorkerModel::class,
            onlyMethods: [
                'create',
            ]
        );

        $workerModel
            ->expects($this->once())
            ->method('create')
            ->with(
                $this->callback(function (array $data) {
                    // Asserts queues should be JSON of resolved class names
                    $queues = json_decode($data['queues'], true);
                    return $queues === [DefaultQueue::class, PriorityQueue::class];
                }),
                true
            )
            ->willReturn($this->makeWorkerResource([
                'queues' => json_encode([DefaultQueue::class, PriorityQueue::class]),
            ]));

        $manager = $this->makeManager(
            workerModel: $workerModel,
        );

        // Act
        $manager->registerWorker(['default', new PriorityQueue()]);

        // Assert
        // No assertions here; asserted above
    }

    /**
     * registerWorker should set the heartbeat to the fixed current time.
     *
     * @covers \Nails\Queue\Service\Manager::registerWorker
     */
    public function test_register_worker_sets_heartbeat(): void
    {
        // Arrange
        $now = new DateTime('2025-01-01 00:00:00');
        /** @var WorkerModel&MockObject $workerModel */
        $workerModel = $this->makeMock(
            class: WorkerModel::class,
            onlyMethods: [
                'create',
            ]
        );

        $workerModel
            ->expects($this->once())
            ->method('create')
            ->with(
                $this->callback(function (array $data) use ($now) {
                    // Assert heartbeat matches the fixed timestamp
                    return $data['heartbeat'] === $now->format('Y-m-d H:i:s');
                }),
                true
            )
            ->willReturn($this->makeWorkerResource());

        $manager = $this->makeManager(
            workerModel: $workerModel,
            now: $now
        );

        // Act
        $manager->registerWorker(['default']);

        // Assert
        // No assertions here; asserted above
    }

    /**
     * registerWorker should generate a non-empty token for the worker.
     *
     * @covers \Nails\Queue\Service\Manager::registerWorker
     */
    public function test_register_worker_generates_token(): void
    {
        // Arrange
        /** @var WorkerModel&MockObject $workerModel */
        $workerModel = $this->makeMock(
            class: WorkerModel::class,
            onlyMethods: [
                'create',
            ]
        );

        $workerModel
            ->expects($this->once())
            ->method('create')
            ->with(
                $this->callback(function (array $data) {
                    // Assert token exists and is non-empty
                    return isset($data['token'])
                        && is_string($data['token'])
                        && strlen($data['token']) > 0;
                }),
                true
            )
            ->willReturn($this->makeWorkerResource());

        $manager = $this->makeManager(
            workerModel: $workerModel,
        );

        // Act
        $manager->registerWorker(['default']);

        // Assert
        // No assertions here; asserted above
    }

    /**
     * touchWorker should update heartbeat when debounce window has elapsed and return the fresh worker.
     *
     * @covers \Nails\Queue\Service\Manager::touchWorker
     */
    public function test_touch_worker_updates_when_debounced_elapsed(): void
    {
        // Arrange
        $debounce     = 60;
        $now          = new DateTime('2025-01-01 00:00:00');
        $willTouch    = (clone $now)->sub(new DateInterval('PT' . $debounce + 1 . 'S'));
        $willNotTouch = (clone $now)->sub(new DateInterval('PT' . $debounce - 1 . 'S'));

        /** @var WorkerModel&MockObject $workerModel */
        $workerModel = $this->makeMock(
            class: WorkerModel::class,
            onlyMethods: [
                'update',
                'getById',
            ]
        );

        $touchedWorker = $this->makeWorkerResource([
            'heartbeat' => $willTouch->format('Y-m-d H:i:s'),
        ]);

        $updatedWorker = $this->makeWorkerResource([
            'id'        => $touchedWorker->id,
            'heartbeat' => $now->format('Y-m-d H:i:s'),
        ]);

        $untouchedWorker = $this->makeWorkerResource([
            'heartbeat' => $willNotTouch->format('Y-m-d H:i:s'),
        ]);

        $workerModel
            ->expects($this->once())
            ->method('update')
            ->with(
                $touchedWorker->id,
                $this->callback(function (array $data) use ($now) {
                    //  Assert updated heartbeat
                    return isset($data['heartbeat'])
                        && is_string($data['heartbeat'])
                        && $data['heartbeat'] === $now->format('Y-m-d H:i:s');
                }),
            )
            ->willReturn(true);

        $workerModel
            ->expects($this->once())
            ->method('getById')
            ->with($touchedWorker->id)
            ->willReturn($updatedWorker);

        $manager = $this->makeManager(
            workerModel: $workerModel,
            now: $now
        );

        // Act
        $updatedTouchedWorker   = $manager->touchWorker($touchedWorker, $debounce);
        $updatedUntouchedWorker = $manager->touchWorker($untouchedWorker, $debounce);

        // Assert
        self::assertEquals($touchedWorker->id, $updatedTouchedWorker->id);
        self::assertEquals($untouchedWorker->id, $updatedUntouchedWorker->id);
    }

    /**
     * unregisterWorker should delegate deletion to the Worker model.
     *
     * @covers \Nails\Queue\Service\Manager::unregisterWorker
     */
    public function test_unregister_worker_deletes_record(): void
    {
        // Arrange
        /** @var WorkerModel&MockObject $workerModel */
        $workerModel = $this->makeMock(
            class: WorkerModel::class,
            onlyMethods: [
                'delete',
            ]
        );

        $worker = $this->makeWorkerResource(['id' => 9]);

        $workerModel
            ->expects($this->once())
            ->method('delete')
            ->with($worker->id)
            ->willReturn(true);

        $manager = $this->makeManager(workerModel: $workerModel);

        // Act
        $manager->unregisterWorker($worker);

        // Assert
        // No assertions here; asserted above
    }

    /**
     * deleteStaleWorkers should delete old workers
     *
     * @covers \Nails\Queue\Service\Manager::deleteStaleWorkers
     */
    public function test_delete_stale_workers_finds_and_deletes()
    {
        // Arrange
        $grace = 300;
        $now   = new DateTime('2025-01-01 00:00:00');
        /** @var WorkerModel&MockObject $workerModel */
        $workerModel = $this->makeMock(
            class: WorkerModel::class,
            onlyMethods: [
                'getAll',
                'deleteMany',
            ]
        );

        $staleWorkers = [
            $this->makeWorkerResource([
                'id'        => 1,
                'heartbeat' => (clone $now)->sub(new DateInterval('PT' . ($grace + 20) . 'S'))->format('Y-m-d H:i:s'),
            ]),
            $this->makeWorkerResource([
                'id'        => 2,
                'heartbeat' => (clone $now)->sub(new DateInterval('PT' . ($grace + 10) . 'S'))->format('Y-m-d H:i:s'),
            ]),
        ];

        $workerModel
            ->expects($this->once())
            ->method('getAll')
            ->with(
                $this->callback(function ($data) use ($now, $grace) {
                    //  Assert proper where is passed
                    if (is_array($data)) {
                        foreach ($data as $datum) {
                            if ($datum instanceof Where) {
                                [$column, $value] = $datum->compile();
                                if ($column === 'heartbeat <') {
                                    $expected = (clone $now)
                                        ->sub(new DateInterval('PT' . $grace . 'S'))
                                        ->format('Y-m-d H:i:s');
                                    return $value === $expected;
                                }
                            }
                        }
                    }

                    return false;
                })
            )
            ->willReturn($staleWorkers);

        $workerModel
            ->expects($this->once())
            ->method('deleteMany')
            ->with(array_column($staleWorkers, 'id'))
            ->willReturn(true);

        $manager = $this->makeManager(
            workerModel: $workerModel,
            now: $now
        );

        // Act
        $removed = $manager->deleteStaleWorkers($grace);

        // Assert
        self::assertCount(2, $removed);
    }

    /**
     * push: ensure job is pushed to queue with correct defaults
     *
     * @covers \Nails\Queue\Service\Manager::push
     */
    public function test_push_creates_job_with_defaults()
    {
        // Arrange
        $now = new DateTime('2025-01-01 00:00:00');
        /** @var JobModel&MockObject $jobModel */
        $jobModel = $this->makeMock(
            class: JobModel::class,
            onlyMethods: [
                'create',
            ]
        );

        $jobModel
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $data) use ($now) {
                return $data['queue'] === DefaultQueue::class
                    && $data['task'] === DoNothingTask::class
                    && $data['data'] === json_encode(['ok' => true])
                    && $data['available_at'] === $now->format('Y-m-d H:i:s')
                    && $data['errors'] === json_encode([]);
            }), true)
            ->willReturn($this->makeJobResource());

        $manager = $this->makeManager(jobModel: $jobModel, now: $now);

        // Act
        $manager->push(new DoNothingTask(), new DataFactory(['ok' => true]));

        // Assert
        // No assertions here; asserted above
    }

    /**
     * getNextJob: selects the next available job from the given queues and marks it as running.
     * Note: this test doesn't cover the job selection logic/query, just that it properly fetches
     * a job and marks it as running within a transaction
     *
     * @covers \Nails\Queue\Service\Manager::getNextJob
     */
    public function test_get_next_job_queries_and_marks_running()
    {
        // Arrange
        /** @var Database&MockObject $database */
        $database = $this->makeMock(
            class: Database::class,
            onlyMethods: [
                'transaction',
            ],
            addMethods: [
                'query',
            ],
        );

        /** @var Transaction&MockObject $database */
        $transaction = $this->makeMock(
            class: Transaction::class,
            onlyMethods: [
                'start',
                'commit',
            ],
        );

        $transaction
            ->expects($this->once())
            ->method('start')
            ->willReturnSelf();

        $transaction
            ->expects($this->once())
            ->method('commit')
            ->willReturnSelf();

        $database
            ->method('transaction')
            ->willReturn($transaction);

        // Mock result of query->row()
        $database
            ->expects($this->once())
            ->method('query')
            ->willReturn(
                new class {
                    public function row()
                    {
                        return (object) [
                            'id' => 123,
                        ];
                    }
                }
            );

        /** @var JobModel&MockObject $jobModel */
        $jobModel = $this->makeMock(
            class: JobModel::class,
            onlyMethods: [
                'getTableName',
                'getById',
                'update',
            ]
        );

        $jobModel
            ->method('getTableName')
            ->willReturn('nails_queue_job');

        $jobModel
            ->method('getById')
            ->with(123)
            ->willReturn($this->makeJobResource(['id' => 123]));

        // Expect update called by markJobAsRunning
        $jobModel
            ->expects($this->once())
            ->method('update')
            ->with(
                123,
                $this->callback(function (array $data) {
                    return $data['status'] === Status::RUNNING->value
                        && isset($data['started'])
                        && isset($data['worker_id']);
                }),
            )
            ->willReturn(true);

        $manager = $this->makeManager(database: $database, jobModel: $jobModel);
        $worker  = $this->makeWorkerResource(['id' => 55]);

        // Act
        $job = $manager->getNextJob([DefaultQueue::class], $worker);

        // Assert
        self::assertInstanceOf(JobResource::class, $job);
        self::assertSame(123, $job->id);
    }

    /**
     * markJobAsComplete: updates status to COMPLETE and sets finished timestamp.
     *
     * @covers \Nails\Queue\Service\Manager::markJobAsComplete
     */
    public function test_mark_job_as_complete_updates_status_and_finished()
    {
        // Arrange
        /** @var JobModel&MockObject $jobModel */
        $jobModel = $this->makeMock(
            class: JobModel::class,
            onlyMethods: [
                'update',
            ],
        );

        $job = $this->makeJobResource([
            'id' => 123,
        ]);

        $jobModel
            ->expects($this->once())
            ->method('update')
            ->with(
                $job->id,
                $this->callback(function (array $data) {
                    return $data['status'] === Status::COMPLETE->value
                        && array_key_exists('worker_id', $data)
                        && $data['worker_id'] === null
                        && isset($data['finished']);
                }),
            )
            ->willReturn(true);

        $manager = $this->makeManager(jobModel: $jobModel);

        // Act
        $ok = $manager->markJobAsComplete($job);

        // Assert
        self::assertTrue($ok);
    }

    /**
     * markJobAsFailed: appends the error to the job, sets status to FAILED, and records finish time.
     *
     * @covers \Nails\Queue\Service\Manager::markJobAsFailed
     */
    public function test_mark_job_as_failed_appends_error_and_sets_status()
    {
        // Arrange
        /** @var JobModel&MockObject $jobModel */
        $jobModel = $this->makeMock(
            class: JobModel::class,
            onlyMethods: [
                'update',
            ]
        );

        $job = $this->makeJobResource([
            'errors' => json_encode([
                ['simulated error'],
            ]),
        ]);

        $jobModel
            ->expects($this->once())
            ->method('update')
            ->with(
                $job->id,
                $this->callback(function (array $data) {
                    $errors = json_decode($data['errors'], true);
                    return $data['status'] === Status::FAILED->value
                        && array_key_exists('worker_id', $data)
                        && $data['worker_id'] === null
                        && isset($data['finished'])
                        && is_array($errors)
                        && count($errors) === 2
                        && isset($errors[1]['message']);
                }),
            )
            ->willReturn(true);

        $manager = $this->makeManager(jobModel: $jobModel);

        // Act
        $ok = $manager
            ->markJobAsFailed(
                $job,
                new \Exception('boom')
            );

        // Assert
        self::assertTrue($ok);
    }

    /**
     * retryJob: sets next available time using backoff strategy and increments attempts; preserves errors array.
     *
     * @covers \Nails\Queue\Service\Manager::retryJob
     */
    public function test_retry_job_sets_next_available_and_increments_attempts()
    {
        // Arrange
        $backoffSeconds = 15;
        $now            = new DateTime('2025-01-01 00:00:00');
        $expected       = (clone $now)->add(new DateInterval('PT' . $backoffSeconds . 'S'));
        /** @var JobModel&MockObject $jobModel */
        $jobModel = $this->makeMock(
            class: JobModel::class,
            onlyMethods: [
                'update',
                'getById',
            ]
        );

        $job = $this->makeJobResource([
            'attempts' => 0,
        ]);

        $jobModel
            ->expects($this->once())
            ->method('update')
            ->with(
                $job->id,
                $this->callback(function (array $data) use ($expected) {
                    $errors = json_decode($data['errors'], true);
                    return $data['status'] === Status::PENDING->value
                        && array_key_exists('worker_id', $data)
                        && $data['worker_id'] === null
                        && array_key_exists('started', $data)
                        && $data['started'] === null
                        && array_key_exists('finished', $data)
                        && $data['finished'] === null
                        && isset($data['available_at'])
                        && $data['available_at'] === $expected->format('Y-m-d H:i:s')
                        && $data['attempts'] === 1
                        && count($errors) === 1;
                }),
            )
            ->willReturn(true);

        $jobModel
            ->expects($this->once())
            ->method('getById')
            ->with($job->id)
            ->willReturn($job);

        $manager = $this->makeManager(
            jobModel: $jobModel,
            backoffSeconds: $backoffSeconds
        );

        // Act
        $manager->retryJob($job, new \Exception('nope'));

        // Assert
        // No assertions here; asserted above
    }

    /**
     * resetStuckJobs: finds RUNNING jobs stuck beyond threshold, resets them to PENDING, and returns the affected set.
     *
     * @covers \Nails\Queue\Service\Manager::resetStuckJobs
     */
    public function test_reset_stuck_jobs_updates_and_returns()
    {
        // Arrange
        /** @var JobModel&MockObject $jobModel */
        $jobModel = $this->makeMock(
            class: JobModel::class,
            onlyMethods: [
                'getAll',
                'updateMany',
                'getByIds',
            ]
        );

        $stuckJobs = [
            $this->makeJobResource([
                'id'      => 1,
                'status'  => Status::RUNNING->value,
                'started' => '2024-02-01 00:00:00',
            ]),
            $this->makeJobResource([
                'id'      => 2,
                'status'  => Status::RUNNING->value,
                'started' => '2024-02-01 00:00:00',
            ]),
        ];

        $stuckJobIds = array_column($stuckJobs, 'id');

        $jobModel
            ->method('getAll')
            ->with(
                $this->callback(function (array $data) {
                    foreach ($data as $datum) {
                        if ($datum instanceof Where) {
                            [$column, $value] = $datum->compile();
                            if ($column === 'status' && $value !== Status::RUNNING->value) {
                                return false;
                            } elseif ($column === 'worker_id' && $value !== null) {
                                return false;
                            } elseif ($column === 'started !=' && $value !== null) {
                                return false;
                            } elseif ($column === 'finished' && $value !== null) {
                                return false;
                            }
                        } else {
                            return false;
                        }
                    }

                    return true;
                }),
            )
            ->willReturn($stuckJobs);

        $jobModel
            ->expects($this->once())
            ->method('updateMany')
            ->with(
                $stuckJobIds,
                [
                    'status'  => Status::PENDING->value,
                    'started' => null,
                ]
            )
            ->willReturn(true);

        $jobModel
            ->method('getByIds')
            ->with($stuckJobIds)
            ->willReturn($stuckJobs);

        $manager = $this->makeManager(jobModel: $jobModel);

        // Act
        $out = $manager->resetStuckJobs();

        // Assert
        self::assertCount(2, $out);
    }

    /**
     * rotateOldJobs: deletes old COMPLETE/FAILED jobs and returns the unique set of affected jobs.
     *
     * @covers \Nails\Queue\Service\Manager::rotateOldJobs
     */
    public function test_rotate_old_jobs_deletes_and_returns_unique_set()
    {
        // Arrange
        $retentionComplete = 7;
        $retentionFailed   = 30;

        $now                   = new DateTime('2025-01-01 00:00:00');
        $retentionCompleteDate = (clone $now)->sub(new DateInterval('P' . $retentionComplete . 'D'));
        $retentionFailedDate   = (clone $now)->sub(new DateInterval('P' . $retentionFailed . 'D'));

        /** @var JobModel&MockObject $jobModel */
        $jobModel = $this->makeMock(
            class: JobModel::class,
            onlyMethods: [
                'getAll',
                'deleteMany',
            ]
        );

        $completeOldJobs = [
            $this->makeJobResource([
                'id'       => 10,
                'status'   => Status::COMPLETE->value,
                'finished' => $retentionCompleteDate->format('Y-m-d H:i:s'),
            ]),
        ];

        $failedOldJobs = [
            $this->makeJobResource([
                'id'       => 10,
                'status'   => Status::FAILED->value,
                'finished' => $retentionFailedDate->format('Y-m-d H:i:s'),
            ]),
            $this->makeJobResource([
                'id'       => 20,
                'status'   => Status::FAILED->value,
                'finished' => $retentionFailedDate->format('Y-m-d H:i:s'),
            ]),
            $this->makeJobResource([
                'id'       => 30,
                'status'   => Status::FAILED->value,
                'finished' => $retentionFailedDate->format('Y-m-d H:i:s'),
            ]),
        ];

        $uniqueOldJobsIds = array_values(
            array_unique(
                array_column(
                    array_merge($completeOldJobs, $failedOldJobs),
                    'id'
                )
            )
        );

        $callWithIndex   = 0;
        $callReturnIndex = 0;
        $jobModel
            ->expects($this->exactly(2))
            ->method('getAll')
            ->with(
                $this->callback(function (array $data) use (&$callWithIndex, $retentionCompleteDate, $retentionFailedDate) {
                    $callWithIndex++;
                    foreach ($data as $datum) {
                        if ($datum instanceof Where) {
                            [$column, $value] = $datum->compile();

                            //  Changes depending on iteration
                            if ($callWithIndex === 1) {
                                if ($column === 'status' && $value !== Status::COMPLETE->value) {
                                    return false;
                                } elseif ($column === 'finished <' && $value !== $retentionCompleteDate->format('Y-m-d H:i:s')) {
                                    return false;
                                }
                            } elseif ($callWithIndex === 2) {
                                if ($column === 'status' && $value !== Status::FAILED->value) {
                                    return false;
                                } elseif ($column === 'finished <' && $value !== $retentionFailedDate->format('Y-m-d H:i:s')) {
                                    return false;
                                }
                            }

                            //  Common on every iteration
                            if ($column === 'finished !=' && $value !== null) {
                                return false;
                            }
                        } else {
                            return false;
                        }
                    }

                    return true;
                }),
            )
            ->willReturnCallback(function () use (&$callReturnIndex, $completeOldJobs, $failedOldJobs) {
                $callReturnIndex++;
                return match ($callReturnIndex) {
                    1 => $completeOldJobs,
                    2 => $failedOldJobs,
                };
            });

        $jobModel
            ->expects($this->once())
            ->method('deleteMany')
            ->with($this->callback(function (array $ids) use ($uniqueOldJobsIds) {
                return $ids === $uniqueOldJobsIds;
            }))
            ->willReturn(true);

        $manager = $this->makeManager(jobModel: $jobModel, now: $now);

        // Act
        $out = $manager->rotateOldJobs();

        // Assert
        self::assertCount(3, $out);
        $ids = array_map(fn($j) => $j->id, $out);
        sort($ids);
        self::assertSame($uniqueOldJobsIds, $ids);
    }
}

//  @todo (Pablo 2025-11-19) - getQueues
//  @todo (Pablo 2025-11-19) - countJobs
//  @todo (Pablo 2025-11-19) - countPendingJobs
//  @todo (Pablo 2025-11-19) - countScheduledJobs
//  @todo (Pablo 2025-11-19) - countRunningJobs
//  @todo (Pablo 2025-11-19) - countCompleteJobs
//  @todo (Pablo 2025-11-19) - countFailedJobs
