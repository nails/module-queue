<?php

namespace Nails\Queue\Console\Command;

use DateInvalidOperationException;
use DateMalformedIntervalStringException;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Console\Command\Base;
use Nails\Factory;
use Nails\Queue\Constants;
use Nails\Queue\Service\Manager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Clean extends Base
{
    protected Manager $manager;

    /**
     * @throws FactoryException
     */
    public function __construct(?string $name = null)
    {
        parent::__construct($name);

        $this->manager = Factory::service('Manager', Constants::MODULE_SLUG);
    }

    protected function configure()
    {
        $this
            ->setName('queue:clean')
            ->setDescription('Performs cleanup of stale workers, stuck jobs, and rotates old jobs');
    }

    /**
     * @throws DateInvalidOperationException
     * @throws DateMalformedIntervalStringException
     * @throws FactoryException
     * @throws ModelException
     */
    protected function execute(InputInterface $oInput, OutputInterface $oOutput)
    {
        parent::execute($oInput, $oOutput);
        $this->banner('Nails Queue Worker Cleanup');
        $this->cleanStaleWorkers();
        $oOutput->writeln('');
        $this->resetStuckJobs();
        $oOutput->writeln('');
        $this->rotateOldJobs();
        $oOutput->writeln('');
        return self::EXIT_CODE_SUCCESS;
    }

    /**
     * @throws DateInvalidOperationException
     * @throws DateMalformedIntervalStringException
     * @throws FactoryException
     * @throws ModelException
     */
    private function cleanStaleWorkers(): void
    {
        $this->oOutput->writeln('Cleaning stale workers:');
        $deletedWorkers = $this->manager->deleteStaleWorkers();
        if (!empty($deletedWorkers)) {
            $this->oOutput->writeln(sprintf('Deleting %d workers:', count($deletedWorkers)));
            foreach ($deletedWorkers as $worker) {
                $this->oOutput->writeln(sprintf(
                    '- <info>#%s</info>:<info>%s</info> (queues: %s)',
                    $worker->id,
                    $worker->token,
                    implode(', ', $worker->queues)
                ));
            }
        } else {
            $this->oOutput->writeln('- No stale workers');
        }
    }

    /**
     * @throws FactoryException
     * @throws ModelException
     */
    private function resetStuckJobs(): void
    {
        $this->oOutput->writeln('Resetting stuck jobs:');
        $resetJobs = $this->manager->resetStuckJobs();
        if (!empty($resetJobs)) {
            $this->oOutput->writeln(sprintf('Resetting %d jobs:', count($resetJobs)));
            foreach ($resetJobs as $job) {
                $this->oOutput->writeln(sprintf(
                    '- <comment>%s</comment>:#<comment>%s</comment>',
                    $job->task::class,
                    $job->id
                ));
            }
        } else {
            $this->oOutput->writeln('- No stuck jobs');
        }
    }

    /**
     * @throws DateInvalidOperationException
     * @throws ModelException
     * @throws FactoryException
     */
    private function rotateOldJobs(): void
    {
        $this->oOutput->writeln('Rotating old jobs:');
        $rotatedJobs = $this->manager->rotateOldJobs();
        if (!empty($rotatedJobs)) {
            $this->oOutput->writeln(sprintf('Rotating %d old jobs:', count($rotatedJobs)));
            foreach ($rotatedJobs as $job) {
                $this->oOutput->writeln(sprintf(
                    '- <comment>%s</comment>:#<comment>%s</comment>',
                    $job->task::class,
                    $job->id
                ));
            }
        } else {
            $this->oOutput->writeln('- No old jobs to rotate');
        }
    }
}
