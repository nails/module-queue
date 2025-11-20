<?php

namespace Nails\Queue\Resource;

use Nails\Common\Exception\FactoryException;
use Nails\Common\Model\Base;
use Nails\Common\Resource\DateTime;
use Nails\Common\Resource\Entity;
use Nails\Factory;
use Nails\Queue\Constants;
use Nails\Queue\Enum\Job\Status;
use Nails\Queue\Interface\Data;
use Nails\Queue\Interface\Queue;
use Nails\Queue\Interface\Task;
use stdClass;

class Job extends Entity
{
    public Queue     $queue;
    public Task      $task;
    public Data      $data;
    public Status    $status;
    public ?int      $worker_id;
    public ?Worker   $worker;
    public ?DateTime $available_at;
    public ?DateTime $started;
    public ?DateTime $finished;
    public array     $errors;
    public int       $attempts;

    /**
     * @throws FactoryException
     */
    public function __construct(array|Entity|stdClass $resource = [], ?Base $model = null)
    {
        $resource->queue = new ($resource->queue)();
        $resource->task  = new ($resource->task)();
        $resource->data  = Factory::factory('Data', Constants::MODULE_SLUG, json_decode($resource->data));

        $resource->status = Status::from($resource->status);

        $resource->available_at = $resource->available_at
            ? Factory::resource('DateTime', null, ['raw' => $resource->available_at])
            : null;

        $resource->started = $resource->started
            ? Factory::resource('DateTime', null, ['raw' => $resource->started])
            : null;

        $resource->finished = $resource->finished
            ? Factory::resource('DateTime', null, ['raw' => $resource->finished])
            : null;

        $resource->errors = json_decode($resource->errors) ?: [];

        parent::__construct($resource, $model);
    }

    public function run(): void
    {
        $this->task->run($this->data);
    }
}
