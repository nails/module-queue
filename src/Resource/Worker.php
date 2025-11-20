<?php

namespace Nails\Queue\Resource;

use Nails\Common\Model\Base;
use Nails\Common\Resource\DateTime;
use Nails\Common\Resource\Entity;
use Nails\Config;
use Nails\Factory;
use stdClass;

class Worker extends Entity
{
    public string   $token;
    public array    $queues;
    public DateTime $heartbeat;

    public function __construct(array|Entity|stdClass $resource = [], ?Base $model = null)
    {
        $resource->queues    = json_decode($resource->queues);
        $resource->heartbeat = Factory::resource('DateTime', null, ['raw' => $resource->heartbeat]);
        parent::__construct($resource, $model);
    }

    public function isStale(): bool
    {
        /** @var \DateTime $now */
        $now  = Factory::factory('DateTime');
        $diff = (int) $now->format('U') - (int) $this->heartbeat->format('U');
        return $diff > Config::get('QUEUE_WORKER_HEARTBEAT_STALE', 300);
    }
}
