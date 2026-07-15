<?php

namespace Nails\Queue\Admin\Dashboard\Alert;

use Nails\Admin\Interfaces\Dashboard\Alert;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Factory;
use Nails\Queue\Constants;
use Nails\Queue\Interface\Queue;
use Nails\Queue\Model;
use Nails\Queue\Resource;
use Nails\Queue\Service;

/**
 * Class NoWorkers
 *
 * @package Nails\Queue\Admin\Dashboard\Alert
 */
class NoWorkers implements Alert
{
    static ?array $unworkedQueues = null;

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getTitle(): ?string
    {
        return 'Some queues are not being processed';
    }

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getBody(): ?string
    {
        $unworkedQueues = $this->getUnworkedQueues();
        return match (true) {
            count($unworkedQueues) === 0 => 'There are no queue workers running at the moment. Jobs are not being processed.',
            count($unworkedQueues) > 0 => sprintf(
                'There are no queue workers running for the following queues: %s',
                '<br>- ' . implode('<br>- ', array_map(fn($queue) => $queue::class, $unworkedQueues))
            ),
            default => null,
        };
    }

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getSeverity(): string
    {
        return static::SEVERITY_WARNING;
    }

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     * @throws FactoryException
     * @throws ModelException
     */
    public function isAlerting(): bool
    {
        return !empty($this->getUnworkedQueues());
    }

    // --------------------------------------------------------------------------

    /**
     * @return array<int, Queue>
     * @throws FactoryException
     * @throws ModelException
     */
    private function getUnworkedQueues(): array
    {
        if (static::$unworkedQueues !== null) {
            return static::$unworkedQueues;
        }

        /** @var Service\Manager $manager */
        $manager = Factory::service('Manager', Constants::MODULE_SLUG);
        /** @var Model\Worker $workerModel */
        $workerModel = Factory::model('Worker', Constants::MODULE_SLUG);

        $queues = $manager->getQueues();
        if (empty($queues)) {
            //  No queues, no need to worry if there are workers
            return static::$unworkedQueues = [];
        }

        /** @var Resource\Worker[] $workers */
        $workers = $workerModel->getAll();
        $workers = array_filter($workers, fn($worker) => !$worker->isStale());

        if (empty($workers)) {
            //  No active workers at all, all queues are unworked
            return static::$unworkedQueues = $queues;
        }

        //  Check each queue has at least one worker
        $map = [];
        foreach ($queues as $queue) {
            $map[$queue::class] = (object) ['queue' => $queue, 'workerCount' => 0];
            foreach ($workers as $worker) {
                foreach ($worker->queues as $workerQueue) {
                    if ($workerQueue === $queue::class) {
                        $map[$queue::class]->workerCount++;
                    }
                }
            }
        }

        $map = array_filter($map, fn($map) => $map->workerCount === 0);
        $map = array_map(fn($map) => $map->queue, $map);
        $map = array_values($map);

        return static::$unworkedQueues = $map;
    }
}
