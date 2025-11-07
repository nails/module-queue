<?php

namespace Nails\Queue\Service;

use DateInterval;
use DateInvalidOperationException;
use DateMalformedIntervalStringException;
use DateTime;
use DateTimeInterface;
use InvalidArgumentException;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Helper\ArrayHelper;
use Nails\Common\Helper\Model\Where;
use Nails\Common\Service\Database;
use Nails\Config;
use Nails\Factory;
use Nails\Queue\Enum\Job\Status;
use Nails\Queue\Interface\Data;
use Nails\Queue\Interface\Queue;
use Nails\Queue\Interface\Task;
use Nails\Queue\Model;
use Nails\Queue\Queues;
use Nails\Queue\Resource;
use Random\RandomException;
use Throwable;

class Manager
{
    /**
     * @var array<string, Queue>
     */
    protected array $aliases = [];

    public function __construct(
        protected Database $database,
        protected Model\Worker $workerModel,
        protected Model\Job $jobModel
    ) {
        $this
            ->addAlias('default', new Queues\DefaultQueue())
            ->addAlias('priority', new Queues\PriorityQueue());
    }

    /**
     * Adds a named alias for a queue implementation and returns the manager for chaining.
     */
    public function addAlias(string $alias, Queue $queue): self
    {
        $this->aliases[$alias] = $queue;
        return $this;
    }

    /**
     * Resolves a queue by alias, instance, or fully-qualified class name and returns a Queue instance.
     *
     * @throws InvalidArgumentException
     */
    public function resolveQueue(string|Queue $alias): Queue
    {
        if ($alias instanceof Queue) {
            return $alias;

        } elseif (isset($this->aliases[$alias])) {
            return $this->aliases[$alias];

        } elseif (class_exists($alias)) {
            return new $alias();
        }

        throw new InvalidArgumentException(sprintf('Invalid queue "%s"', $alias));
    }

    /**
     * Registers a new worker to process the specified queues and returns the persisted worker resource.
     *
     * @param (string|Queue)[] $queues
     *
     * @throws DateInvalidOperationException
     * @throws FactoryException
     * @throws ModelException
     * @throws RandomException
     */
    public function registerWorker(array $queues): Resource\Worker
    {
        return $this->workerModel->create(
            [
                'token'     => implode(':', [
                    substr((string) gethostname(), 0, 10),
                    substr((string) getmypid(), 0, 10),
                    substr(bin2hex(random_bytes(4)), 0, 10),
                ]),
                'queues'    => json_encode(
                    array_map(
                        fn(string|Queue $queue) => $this->resolveQueue($queue)::class,
                        $queues
                    )
                ),
                'heartbeat' => $this->getTimestampString(),
            ],
            true
        );
    }

    /**
     * Updates the worker's heartbeat timestamp and returns the fresh worker resource.
     *
     * @throws DateInvalidOperationException
     * @throws FactoryException
     * @throws ModelException
     */
    public function touchWorker(Resource\Worker $worker): Resource\Worker
    {
        $now = $this->getTimestamp();
        if ($now->diff($worker->heartbeat->getDateTimeObject())->s >= Config::get('QUEUE_WORKER_HEARTBEAT_DEBOUNCE', 5)) {
            $this->workerModel->update(
                $worker->id,
                [
                    'heartbeat' => $this->getTimestampString(),
                ],
                true
            );
        }

        return $this->workerModel
            ->getById($worker->id);
    }

    /**
     * Unregisters (deletes) the given worker record from the queue system.
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function unregisterWorker(Resource\Worker $worker): void
    {
        $this->workerModel->delete($worker->id);
    }

    /**
     * Deletes workers whose heartbeat is older than the configured stale threshold and returns the removed workers.
     *
     * @return Resource\Worker[]
     * @throws DateInvalidOperationException
     * @throws DateMalformedIntervalStringException
     * @throws FactoryException
     * @throws ModelException
     */
    public function deleteStaleWorkers(): array
    {
        $boundary = $this->getTimestampString(
            sub: new DateInterval('PT' . Config::get('QUEUE_WORKER_HEARTBEAT_STALE', 300) . 'S')
        );

        $workers = $this->workerModel->getAll([
            new Where('heartbeat <', $boundary),
        ]);

        $this->workerModel->deleteMany(ArrayHelper::extract($workers, 'id'));

        return $workers;
    }

    /**
     * Enqueues a new job for the given task and payload, optionally specifying the queue and when it becomes available.
     *
     * @throws DateInvalidOperationException
     * @throws FactoryException
     * @throws ModelException
     */
    public function push(Task $task, Data $data, Queue|string|null $queue = null, ?DateTimeInterface $availableAt = null): Resource\Job
    {
        $queue = $queue ?? 'default';

        if (is_string($queue)) {
            $queue = $this->resolveQueue($queue);
        }

        return $this->jobModel->create(
            [
                'queue'        => $queue::class,
                'task'         => $task::class,
                'data'         => $data->toJson(),
                'available_at' => ($availableAt ?? $this->getTimestamp())->format('Y-m-d H:i:s'),
                'errors'       => json_encode([]),
            ],
            true
        );
    }

    /**
     * Retrieves and reserves the next available pending job across the provided queues for the given worker.
     *
     * @param string[] $queues
     *
     * @throws DateInvalidOperationException
     * @throws FactoryException
     * @throws ModelException
     */
    public function getNextJob(array $queues, Resource\Worker $worker): ?Resource\Job
    {
        $transaction = $this->database->transaction();
        $transaction->start();

        $placeholders = implode(',', array_fill(0, count($queues), '?'));
        $table        = $this->jobModel->getTableName();

        $result = $this->database->query(
            sprintf(
                <<<EOT
                SELECT `id`
                FROM `%s`
                WHERE
                    `queue` IN (%s)
                    AND `status` = ?
                    AND `worker_id` IS NULL
                    AND `id` = (
                        SELECT MIN(j2.`id`) FROM `%s` j2
                        WHERE j2.`queue` IN (%s)
                        AND j2.`status` = ?
                        AND j2.`worker_id` IS NULL
                        AND j2.`available_at` <= NOW()
                    )
                ORDER BY `id` ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
                EOT,
                $table,
                $placeholders,
                $table,
                $placeholders
            ),
            [
                ...$queues,
                Status::PENDING->value,
                ...$queues,
                Status::PENDING->value,
            ]
        );

        $row = $result->row();

        if (empty($row)) {
            $transaction->commit();
            return null;
        }

        $job = $this->jobModel->getById($row->id);
        $this->markJobAsRunning($job, $worker);
        $transaction->commit();
        return $job ?: null;
    }

    /**
     * Resets jobs stuck in the `RUNNING` status to `PENDING`.
     *
     * This method retrieves jobs with the status `RUNNING` where the associated worker ID is null,
     * and the job has started but not finished. The identified jobs are updated to have the status
     * `PENDING` and the `started` timestamp is cleared. After resetting the statuses, the method
     * returns the updated job records.
     *
     * @return Resource\Job[]
     * @throws FactoryException
     * @throws ModelException
     */
    public function resetStuckJobs(): array
    {
        $jobs = $this->jobModel->getAll([
            new Where('status', Status::RUNNING->value),
            new Where('worker_id', null),
            new Where('started !=', null),
            new Where('finished', null),
        ]);

        $jobIds = ArrayHelper::extract($jobs, 'id');

        $this->jobModel->updateMany($jobIds, [
            'status'  => Status::PENDING->value,
            'started' => null,
        ]);

        return $this->jobModel->getByIds($jobIds);
    }

    /**
     * Rotates old jobs by removing them based on their statuses and respective retention windows.
     *
     * The method retrieves and deletes jobs with statuses `COMPLETE` or `FAILED` that are older
     * than their configured retention periods. Retention periods are configurable through
     * environment variables:
     * - QUEUE_JOB_ROTATE_COMPLETE_DAYS (default: 7 days)
     * - QUEUE_JOB_ROTATE_FAILED_DAYS (default: 30 days)
     *
     * After deletion, the method returns a unique list of the removed jobs for further processing
     * or reporting purposes.
     *
     * @return Resource\Job[]
     * @throws DateInvalidOperationException
     * @throws DateMalformedIntervalStringException
     * @throws FactoryException
     * @throws ModelException
     */
    public function rotateOldJobs(): array
    {
        $jobs = [];

        // Determine retention windows (in days) for different terminal statuses
        $completeDays = (int) Config::get('QUEUE_JOB_ROTATE_COMPLETE_DAYS', 7);   // default: keep 7 days, 0 to disable
        $failedDays   = (int) Config::get('QUEUE_JOB_ROTATE_FAILED_DAYS', 30);    // default: keep 30 days, 0 to disable

        // Rotate COMPLETE jobs older than the retention window
        if ($completeDays > 0) {
            $completeBoundary = $this->getTimestampString(sub: new DateInterval('P' . $completeDays . 'D'));
            $completeJobs     = $this->jobModel->getAll([
                new Where('status', Status::COMPLETE->value),
                new Where('finished !=', null),
                new Where('finished <', $completeBoundary),
            ]);
            if (!empty($completeJobs)) {
                $jobs = array_merge($jobs, $completeJobs);
            }
        }

        // Rotate FAILED jobs older than the retention window
        if ($failedDays > 0) {
            $failedBoundary = $this->getTimestampString(sub: new DateInterval('P' . $failedDays . 'D'));
            $failedJobs     = $this->jobModel->getAll([
                new Where('status', Status::FAILED->value),
                new Where('finished !=', null),
                new Where('finished <', $failedBoundary),
            ]);
            if (!empty($failedJobs)) {
                $jobs = array_merge($jobs, $failedJobs);
            }
        }

        if (empty($jobs)) {
            return [];
        }

        // Ensure we only delete unique job IDs even if sets overlap
        $ids = array_values(array_unique(ArrayHelper::extract($jobs, 'id')));
        $this->jobModel->deleteMany($ids);

        // Return a unique set of the rotated jobs for reporting
        $seen  = [];
        $clean = [];
        foreach ($jobs as $job) {
            if (!isset($seen[$job->id])) {
                $seen[$job->id] = true;
                $clean[]        = $job;
            }
        }
        return $clean;
    }

    /**
     * Marks the given job as running and assigns it to the provided worker.
     *
     * @throws DateInvalidOperationException
     * @throws FactoryException
     * @throws ModelException
     */
    public function markJobAsRunning(Resource\Job $job, Resource\Worker $worker): bool
    {
        return $this->setJobStatus(
            $job,
            Status::RUNNING,
            [
                'worker_id' => $worker->id,
                'started'   => $this->getTimestampString(),
            ]
        );
    }

    /**
     * Marks the given job as complete and clears its worker assignment.
     *
     * @throws DateInvalidOperationException
     * @throws FactoryException
     * @throws ModelException
     */
    public function markJobAsComplete(Resource\Job $job): bool
    {
        return $this->setJobStatus(
            $job,
            Status::COMPLETE,
            [
                'worker_id' => null,
                'finished'  => $this->getTimestampString(),
            ]
        );
    }

    /**
     * Marks the given job as failed, records the error, and clears its worker assignment.
     *
     * @throws DateInvalidOperationException
     * @throws FactoryException
     * @throws ModelException
     */
    public function markJobAsFailed(Resource\Job $job, Throwable $error): bool
    {
        $errors = $this->appendError($job, $error);
        return $this->setJobStatus(
            $job,
            Status::FAILED,
            [
                'worker_id' => null,
                'finished'  => $this->getTimestampString(),
                'errors'    => json_encode($errors),
            ]
        );
    }

    /**
     * Reschedule a job for retry with backoff, preserving strict FIFO by delaying `available_at`.
     * Returns the DateTime when the job will next be available.
     *
     * @throws DateInvalidOperationException
     * @throws DateMalformedIntervalStringException
     * @throws FactoryException
     * @throws ModelException
     * @throws RandomException
     */
    public function retryJob(Resource\Job $job, Throwable $error): DateTime
    {
        $nextAttempt = ($job->attempts ?? 0) + 1;
        $delay       = $this->computeBackoffSeconds($nextAttempt);
        $availableAt = $this->getTimestamp(add: new DateInterval('PT' . $delay . 'S'));

        $errors = $this->appendError($job, $error);

        $this->jobModel->update(
            $job->id,
            [
                'status'       => Status::PENDING->value,
                'worker_id'    => null,
                'started'      => null,
                'finished'     => null,
                'available_at' => $availableAt->format('Y-m-d H:i:s'),
                'attempts'     => $nextAttempt,
                'errors'       => json_encode($errors),
            ],
            true
        );

        return $availableAt;
    }

    /**
     * Compute exponential backoff with jitter, capped to minutes (not hours).
     * Base 5s, exponential factor 2, cap 5 minutes, jitter ±20%.
     *
     * @throws RandomException
     */
    protected function computeBackoffSeconds(int $attempt): int
    {
        $base     = 5;        // seconds
        $max      = 300;      // 5 minutes cap
        $raw      = (int) round($base * pow(2, max(0, $attempt - 1)));
        $capped   = min($raw, $max);
        $jitterPc = random_int(-20, 20) / 100; // ±20%
        $withJit  = (int) round(max(1, $capped * (1 + $jitterPc)));
        return $withJit;
    }

    /**
     * Updates the job's status and persists any additional fields atomically.
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function setJobStatus(Resource\Job $job, Status $status, array $additional = []): bool
    {
        return $this->jobModel->update(
            $job->id,
            array_merge(
                $additional,
                ['status' => $status->value],
            ),
            true
        );
    }

    /**
     * Append a structured error object to the job's error array and return the updated array.
     */
    protected function appendError(Resource\Job $job, Throwable $e): array
    {
        $existing = [];
        try {
            if (!empty($job->errors)) {
                $decoded = json_decode((string) $job->errors, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $existing = $decoded;
                }
            }
        } catch (\Throwable $t) {
            // If previous value was not valid JSON, start fresh but include a marker
            $existing = [];
        }

        $existing[] = $this->buildErrorPayload($e);
        return $existing;
    }

    /**
     * Build a compact error payload from a Throwable, truncating the trace to avoid large storage.
     */
    protected function buildErrorPayload(Throwable $e): array
    {
        $trace = $e->getTraceAsString();
        $max   = 2000; // characters
        if (strlen($trace) > $max) {
            $trace = substr($trace, 0, $max) . '…';
        }
        return [
            'type'        => $e::class,
            'message'     => $e->getMessage(),
            'code'        => $e->getCode(),
            'file'        => $e->getFile(),
            'line'        => $e->getLine(),
            'trace'       => $trace,
            'occurred_at' => $this->getTimestampString(),
        ];
    }

    /**
     * Returns a DateTime for "now", optionally applying a subtraction or addition interval (mutually exclusive).
     *
     * @throws DateInvalidOperationException
     * @throws FactoryException
     */
    protected function getTimestamp(?DateInterval $sub = null, ?DateInterval $add = null): DateTime
    {
        /** @var DateTime $date */
        $date = Factory::factory('DateTime');

        if ($sub && $add) {
            throw new InvalidArgumentException('Cannot subtract and add date periods simultaneously');
        } elseif ($sub) {
            $date->sub($sub);
        } elseif ($add) {
            $date->add($add);
        }

        return $date;
    }

    /**
     * Returns a formatted timestamp string for "now", with optional subtraction/addition intervals applied (mutually exclusive).
     *
     * @throws DateInvalidOperationException
     * @throws FactoryException
     */
    protected function getTimestampString(?DateInterval $sub = null, ?DateInterval $add = null): string
    {
        return $this->getTimestamp($sub, $add)->format('Y-m-d H:i:s');
    }
}
