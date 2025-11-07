<?php

namespace Tests\Queue\Stub;

use DateInterval;
use DateTime;
use Nails\Common\Service\Database;
use Nails\Queue\Model\Job as JobModel;
use Nails\Queue\Model\Worker as WorkerModel;
use Nails\Queue\Service\Manager;

/**
 * Deterministic Manager stub for tests.
 * - Provides fixed time via getTimestamp()/getTimestampString()
 * - Provides fixed backoff via computeBackoffSeconds()
 */
class ManagerStub extends Manager
{
    /** @var DateTime */
    private DateTime $fixedNow;

    /** @var int */
    private int $fixedBackoffSeconds;

    /**
     * @param Database|null    $database            Database service (mocked by default when null)
     * @param WorkerModel|null $workerModel         Worker model (mocked by default when null)
     * @param JobModel|null    $jobModel            Job model (mocked by default when null)
     * @param DateTime|null    $fixedNow            The fixed base time for deterministic behavior
     * @param int|null         $fixedBackoffSeconds The fixed backoff seconds to return
     */
    public function __construct(
        ?Database $database = null,
        ?WorkerModel $workerModel = null,
        ?JobModel $jobModel = null,
        ?DateTime $fixedNow = null,
        ?int $fixedBackoffSeconds = null
    ) {
        $this->fixedNow            = $fixedNow ?? new DateTime('2025-01-01 00:00:00');
        $this->fixedBackoffSeconds = $fixedBackoffSeconds ?? 42;

        parent::__construct($database, $workerModel, $jobModel);
    }

    /** @inheritDoc */
    protected function getTimestamp(?DateInterval $sub = null, ?DateInterval $add = null): DateTime
    {
        $dt = clone $this->fixedNow;
        if ($sub) {
            $dt->sub($sub);
        }
        if ($add) {
            $dt->add($add);
        }
        return $dt;
    }

    /** @inheritDoc */
    protected function getTimestampString(?DateInterval $sub = null, ?DateInterval $add = null): string
    {
        return $this->getTimestamp($sub, $add)->format('Y-m-d H:i:s');
    }

    /** @inheritDoc */
    protected function computeBackoffSeconds(int $attempt): int
    {
        return $this->fixedBackoffSeconds;
    }
}
