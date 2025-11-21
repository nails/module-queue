<?php

namespace Nails\Queue\Api\Controller;

use ApiRouter;
use DateInterval;
use DateInvalidOperationException;
use DateTime;
use Nails\Api\Constants;
use Nails\Api\Controller\Base;
use Nails\Api\Factory\ApiResponse;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Helper\Inflector;
use Nails\Common\Helper\Model\Condition;
use Nails\Common\Helper\Model\Limit;
use Nails\Common\Helper\Model\Sort;
use Nails\Common\Helper\Model\Where;
use Nails\Common\Helper\Strings;
use Nails\Common\Service\HttpCodes;
use Nails\Factory;
use Nails\Queue;
use Nails\Queue\Admin\Permission;
use Nails\Queue\Enum\Job\Status;
use Nails\Queue\Model;
use Nails\Queue\Resource;
use Nails\Queue\Service\Manager;
use Random\RandomException;

class Overview extends Base
{
    const REQUIRE_AUTH = true;

    protected Manager      $manager;
    protected Model\Worker $workerModel;
    protected Model\Job    $jobModel;

    public function __construct(ApiRouter $oApiRouter)
    {
        parent::__construct($oApiRouter);

        $this->manager     = Factory::service('Manager', Queue\Constants::MODULE_SLUG);
        $this->workerModel = Factory::model('Worker', Queue\Constants::MODULE_SLUG);
        $this->jobModel    = Factory::model('Job', Queue\Constants::MODULE_SLUG);
    }

    public static function isAuthenticated($sHttpMethod = '', $sMethod = ''): bool
    {
        return parent::isAuthenticated($sHttpMethod, $sMethod) && userHasPermission(Permission\Overview\View::class);
    }

    /**
     * @throws DateInvalidOperationException
     * @throws FactoryException
     * @throws ModelException
     * @throws ValidationException
     */
    public function getIndex(): ApiResponse
    {
        /** @var ApiResponse $response */
        $response = Factory::factory('ApiResponse', Constants::MODULE_SLUG);
        return $response->setData([
            'kpis'    => $this->getKpis(),
            'queues'  => $this->getQueues(),
            'workers' => $this->getWorkers(),
            'failed'  => $this->getFailed(),
        ]);
    }

    /**
     * @throws DateInvalidOperationException
     * @throws FactoryException
     * @throws ModelException
     * @throws ValidationException
     * @throws RandomException
     */
    public function postRetry(): ApiResponse
    {
        $data = $this->getRequestData();
        if (empty($data['job_id'])) {
            throw new ValidationException('`job_id` is a required field.', HttpCodes::STATUS_BAD_REQUEST);
        }

        /** @var Resource\Job|null $job */
        $job = $this->jobModel->getById($data['job_id']);
        if (empty($job)) {
            throw new ValidationException('Job not found.', HttpCodes::STATUS_NOT_FOUND);
        } elseif ($job->status !== Status::FAILED) {
            throw new ValidationException('Job must be in a FAILED state in order to retry.', HttpCodes::STATUS_BAD_REQUEST);
        }

        $this->manager->retryJob($job);

        /** @var ApiResponse $response */
        $response = Factory::factory('ApiResponse', Constants::MODULE_SLUG);
        return $response;
    }

    /**
     * @throws DateInvalidOperationException
     * @throws FactoryException
     * @throws ModelException
     */
    protected function getKpis(): array
    {
        $workers = $this->workerModel->getAll();

        /** @var Resource\Job $oldestJob */
        $oldestJob = $this->jobModel->getFirst([
            new Where('status', Status::PENDING->value),
            new Condition('`available_at` IS NULL OR `available_at` <= NOW()'),
            new Sort('available_at', Sort::ASC),
        ]);

        return [
            [
                'label' => 'Workers (total)',
                'value' => count($workers),
                'hint'  => null,
                'type'  => 'number',
            ],
            [
                'label' => 'Workers (active)',
                'value' => count(array_filter(
                    $workers,
                    fn(Resource\Worker $worker) => !$worker->isStale()
                )),
                'hint'  => 'Heartbeat within threshold',
                'type'  => 'number',
            ],
            [
                'label' => 'Workers (stale)',
                'value' => count(array_filter(
                    $workers,
                    fn(Resource\Worker $worker) => $worker->isStale()
                )),
                'hint'  => 'No heartbeat beyond threshold; safe to clean up',
                'type'  => 'number',
            ],
            [
                'label' => 'Jobs (total)',
                'value' => $this->manager->countJobs(),
                'hint'  => 'Total number of jobs across all queues, in any state',
                'type'  => 'number',
            ],
            [
                'label' => 'Jobs pending',
                'value' => $this->manager->countPendingJobs(),
                'hint'  => 'Available for workers to process',
                'type'  => 'number',
            ],
            [
                'label' => 'Jobs scheduled',
                'value' => $this->manager->countScheduledJobs(),
                'hint'  => 'Pending jobs not yet available for workers to process',
                'type'  => 'number',
            ],
            [
                'label' => 'Jobs running',
                'value' => $this->manager->countRunningJobs(),
                'hint'  => 'Jobs actively being worked on',
                'type'  => 'number',
            ],
            [
                'label' => 'Jobs complete',
                'value' => $this->manager->countCompleteJobs(),
                'hint'  => 'Completed jobs without errors',
                'type'  => 'number',
            ],
            [
                'label' => 'Jobs failed',
                'value' => $this->manager->countFailedJobs(),
                'hint'  => 'Jobs which have reached maximum number of retry attempts and will no longer be processed',
                'type'  => 'number',
            ],
            [
                'label' => 'Oldest queued age',
                'value' => ((int) $oldestJob?->available_at?->format('U')) ?: null,
                'hint'  => 'The age of the oldest queued job',
                'type'  => 'age',
            ],
            [
                'label' => 'Avg. latency (24h)',
                'value' => $this->manager->getQueueAverageLatency(new DateInterval('PT24H')),
                'hint'  => 'Average time between when a job became available and when a worker picks it up',
                'type'  => 'duration',
            ],
            [
                'label' => 'Avg. duration (24h)',
                'value' => $this->manager->getQueueAverageDuration(new DateInterval('PT24H')),
                'hint'  => 'Average length of time jobs take to complete',
                'type'  => 'duration',
            ],
            [
                'label' => 'Throughput (1h)',
                'value' => $this->manager->getQueueThroughput(new DateInterval('PT1H')),
                'hint'  => 'Number of jobs finished (both completed and failed) over the past 1h',
                'type'  => 'number',
            ],
            [
                'label' => 'Throughput (24h)',
                'value' => $this->manager->getQueueThroughput(new DateInterval('PT24H')),
                'hint'  => 'Number of jobs finished (both completed and failed) over the past 24h',
                'type'  => 'number',
            ],
        ];
    }

    /**
     * @throws FactoryException
     * @throws ModelException
     * @throws DateInvalidOperationException
     */
    protected function getQueues(): array
    {
        $queues = $this->manager->getQueues();

        return array_map(
            fn(Queue\Interface\Queue $queue) => [
                'label'      => $queue::class,
                'jobs'       => [
                    [
                        'label' => 'Pending',
                        'value' => $this->manager->countPendingJobs(queues: [$queue]),
                        'class' => 'warning',
                    ],
                    [
                        'label' => 'Scheduled',
                        'value' => $this->manager->countScheduledJobs(queues: [$queue]),
                        'class' => 'warning',
                    ],
                    [
                        'label' => 'Running',
                        'value' => $this->manager->countRunningJobs(queues: [$queue]),
                        'class' => 'info',
                    ],
                    [
                        'label' => 'Complete',
                        'value' => $this->manager->countCompleteJobs(queues: [$queue]),
                        'class' => 'success',
                    ],
                    [
                        'label' => 'Failed',
                        'value' => $this->manager->countFailedJobs(queues: [$queue]),
                        'class' => 'danger',
                    ],
                ],
                'latency'    => [
                    [
                        'label' => '1h',
                        'value' => $this->manager->getQueueAverageLatency(new DateInterval('PT1H'), queues: [$queue]),
                    ],
                ],
                'duration'   => [
                    [
                        'label' => '1h',
                        'value' => $this->manager->getQueueAverageDuration(new DateInterval('PT1H'), queues: [$queue]),
                    ],
                ],
                'throughput' => [
                    [
                        'label' => '1h',
                        'value' => $this->manager->getQueueThroughput(new DateInterval('PT1H'), queues: [$queue]),
                    ],
                    [
                        'label' => '24h',
                        'value' => $this->manager->getQueueThroughput(new DateInterval('PT24H'), queues: [$queue]),
                    ],
                ],
            ],
            $queues,
        );
    }

    /**
     * @throws ModelException
     */
    protected function getWorkers(): array
    {
        $workers = $this->workerModel->getAll();

        return array_map(
            fn(Resource\Worker $worker) => [
                'id'        => $worker->id,
                'token'     => $worker->token,
                'queues'    => $worker->queues,
                'created'   => [
                    'unix' => (int) $worker->created->format('U'),
                    'user' => $worker->created->formatted,
                ],
                'heartbeat' => [
                    'unix' => (int) $worker->heartbeat->format('U'),
                    'user' => $worker->heartbeat->formatted,
                ],
            ],
            $workers,
        );
    }

    /**
     * @throws ModelException
     */
    protected function getFailed(): array
    {
        return array_map(
            fn(Resource\Job $job) => [
                'id'       => $job->id,
                'queue'    => $job->queue::class,
                'task'     => $job->task::class,
                'payload'  => $job->data->get(),
                'finished' => [
                    'unix' => (int) $job->finished->format('U'),
                    'user' => $job->finished->formatted,
                ],
                'attempts' => $job->attempts,
                'errors'   => $job->errors,
            ],
            $this->jobModel->getAll([
                new Where('status', Status::FAILED->value),
                new Limit(100),
                new Sort('available_at', Sort::DESC),
            ])
        );
    }

    /**
     * Returns a human-friendly diff string comparing now with $datetime
     *
     * @throws FactoryException
     */
    protected function age(\Nails\Common\Resource\DateTime $dateTime): string
    {
        /** @var DateTime $now */
        $now        = Factory::factory('DateTime');
        $diff       = $now->diff($dateTime->getDateTimeObject());
        $components = [
            'year'   => $diff->y,
            'month'  => $diff->m,
            'day'    => $diff->d,
            'hour'   => $diff->h,
            'minute' => $diff->i,
            'second' => $diff->s,
        ];

        $trim = true;
        $out  = [];
        foreach ($components as $label => $value) {
            if ($trim && !$value) {
                continue;
            }
            $out[] = sprintf('%d %s', $value, Inflector::pluralise($value, $label));
            $trim  = false;
        }

        return Strings::replaceLastOccurrence(', ', ' and ', implode(', ', $out));
    }
}
