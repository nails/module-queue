<?php

namespace Nails\Queue\Console\Command;

use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Console\Command\Base;
use Nails\Factory;
use Nails\Queue\Constants;
use Nails\Queue\Model\Job;
use Nails\Queue\Model\Worker;
use Nails\Queue\Service\Manager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Run extends Base
{
    protected Manager $manager;
    protected Job     $jobModel;
    protected Worker  $workerModel;

    /**
     * @throws FactoryException
     */
    public function __construct(?string $name = null)
    {
        parent::__construct($name);

        $this->manager     = Factory::service('Manager', Constants::MODULE_SLUG);
        $this->jobModel    = Factory::model('Job', Constants::MODULE_SLUG);
        $this->workerModel = Factory::model('Worker', Constants::MODULE_SLUG);
    }

    protected function configure()
    {
        $this
            ->setName('queue:run')
            ->addArgument('job', InputArgument::REQUIRED, 'The ID of the job to execute')
            ->setDescription('Forcibly executes a specific job');
    }

    /**
     * @throws FactoryException
     * @throws ModelException
     */
    protected function execute(InputInterface $oInput, OutputInterface $oOutput)
    {
        parent::execute($oInput, $oOutput);
        $this->banner('Nails Queue Job');

        /** @var \Nails\Queue\Resource\Job|null $job */
        $job = $this->jobModel->getById($oInput->getArgument('job'));
        if (empty($job)) {
            throw new \InvalidArgumentException('Job not found');
        }

        $this->keyValueList([
            'ID'    => $job->id,
            'Queue' => $job->queue::class,
            'Task'  => $job->task::class,
            'Data'  => $job->data->toJson(),
        ], bPadTop: false);

        try {

            $oOutput->write('Creating worker... ');
            $worker = $this->manager->registerWorker([
                $job->queue,
            ]);
            $oOutput->writeln('<info>done</info>');

            $oOutput->write('Setting up queue... ');
            $job->queue::setup($worker);
            $oOutput->writeln('<info>done</info>');

            $oOutput->write('Running job... ');
            $job->run();
            $oOutput->writeln('<info>done</info>');

        } catch (\Throwable $e) {
            $oOutput->writeln('<error>ERROR: ' . $e->getMessage() . '</error>');

        } finally {
            if (!empty($worker)) {
                $oOutput->write('Unregistering worker... ');
                $this->manager->unregisterWorker($worker);
                $oOutput->writeln('<info>done</info>');
            }
        }

        $oOutput->writeln('');

        return self::EXIT_CODE_SUCCESS;
    }
}
