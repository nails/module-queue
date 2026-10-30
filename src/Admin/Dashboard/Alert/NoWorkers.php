<?php

namespace Nails\Queue\Admin\Dashboard\Alert;

use Nails\Admin\Interfaces\Dashboard\Alert;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Factory;
use Nails\Queue\Constants;
use Nails\Queue\Interface\Queue;
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

        return static::$unworkedQueues = $manager->unworkedQueues();
    }
}
