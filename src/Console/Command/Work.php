<?php

namespace Nails\Queue\Console\Command;

use DateInvalidOperationException;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Factory\Logger;
use Nails\Common\Service\Database;
use Nails\Config;
use Nails\Console\Command\Base;
use Nails\Factory;
use Nails\Queue\Constants;
use Nails\Queue\Interface\Queue;
use Nails\Queue\Queues\DefaultQueue;
use Nails\Queue\Resource\Job;
use Nails\Queue\Resource\Worker;
use Nails\Queue\Service\Manager;
use Random\RandomException;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Work extends Base implements SignalableCommandInterface
{
    protected Logger   $logger;
    protected Database $database;
    protected Manager  $manager;
    protected ?Worker  $worker            = null;
    protected bool     $shutdownRequested = false;

    /**
     * @throws FactoryException
     */
    public function __construct(?string $name = null)
    {
        parent::__construct($name);

        $this->logger   = Factory::factory('Logger');
        $this->database = Factory::service('Database');
        $this->manager  = Factory::service('Manager', Constants::MODULE_SLUG);

        $this->setLogFile();
    }

    /**
     * @throws FactoryException
     * @throws ModelException
     */
    public function __destruct()
    {
        $this->unregisterWorker();
    }

    public function getSubscribedSignals(): array
    {
        return extension_loaded('pcntl') ? [
            SIGINT,
            SIGTERM,
        ] : [];
    }

    public function handleSignal(int $signal): int|false
    {
        if (in_array($signal, $this->getSubscribedSignals(), true)) {
            $this->shutdownRequested = true;
            $this->logLn('<comment>Signal received</comment>: requesting graceful shutdown...');
        }
        //  False returns allows command to continue and gracefully shut down itself
        return false;
    }

    protected function unregisterWorker(): void
    {
        if ($this->worker) {
            $this->logLn('<comment>Shutdown</comment>: Unregistering worker');
            $this->manager->unregisterWorker($this->worker);
            $this->worker = null;
        }
    }

    protected function configure()
    {
        $this
            ->setName('queue:work')
            ->setDescription('Processes queue jobs')
            ->addOption('queue', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Queue(s) to work');
    }

    /**
     * Composite logger writes to console outut as well as current log file
     *
     * @throws FactoryException
     */
    protected function log(string $line = ''): self
    {
        if ($this->oOutput) {
            $this->oOutput->write($line);
        }

        if ($this->logger) {
            $this->logger->line(
                preg_replace(
                    '/<\/?(info|comment|error)>/',
                    '',
                    sprintf('[worker:#%s] %s', $this->worker->id ?? 'no-worker', $line)
                )
            );
        }

        return $this;
    }

    protected function logLn(string $line = ''): self
    {
        return $this->log($line . PHP_EOL);
    }

    /**
     * Sets the log file to the current date, called repeatedly in the loop to ensure the file handler moves on
     *
     * @throws FactoryException
     */
    protected function setLogFile(): self
    {
        /** @var \DateTime $now */
        $now = Factory::factory('DateTime');
        $this->logger->setFile(sprintf('queue-worker-%s.php', $now->format('Y-m-d')));
        return $this;
    }

    /**
     * @throws DateInvalidOperationException
     * @throws FactoryException
     * @throws ModelException
     * @throws RandomException
     */
    protected function execute(InputInterface $oInput, OutputInterface $oOutput)
    {
        parent::execute($oInput, $oOutput);

        $this->banner('Nails Queue Worker');

        $queues       = $this->resolveQueues();
        $this->worker = $this->manager->registerWorker($queues);

        $this->logln('Worker ID:');
        $this->logln(sprintf(
            '<info>#%s</info>:<info>%s</info>',
            $this->worker->id,
            $this->worker->token
        ));

        $this->logln('Working queues:');
        foreach ($queues as $queue) {
            $this->logln('<info>' . $queue::class . '</info>');
        }

        $this->logln('Running Setup:');
        foreach ($queues as $queue) {
            $this->log('<info>' . $queue::class . '</info> ... ');
            $setupTimerStart = microtime(true);
            try {

                $queue::setup($this->worker);
                $this->logStringWithTimer(
                    '<info>done</info>',
                    $setupTimerStart
                );

            } catch (\Throwable $e) {
                $this->logStringWithTimer(
                    sprintf('<error>error: %s</error>', $e->getMessage()),
                    $setupTimerStart
                );
            }
        }

        $this->startEventLoop($queues);
        $this->unregisterWorker();

        return static::EXIT_CODE_SUCCESS;
    }

    /**
     * @return Queue[]
     */
    protected function resolveQueues(): array
    {
        $queues = $this->oInput->getOption('queue') ?: [DefaultQueue::class];
        return array_map(
            fn(string $queue) => $this->manager->resolveQueue($queue),
            $queues
        );
    }

    /**
     * @param Queue[] $queues
     *
     * @throws DateInvalidOperationException
     * @throws FactoryException
     * @throws ModelException
     */
    protected function startEventLoop(array $queues): void
    {
        $waitTime        = Config::get('QUEUE_WORKER_WAIT_TIME', 500);
        $refreshInterval = Config::get('QUEUE_WORKER_REFRESH_INTERVAL', 300); // seconds (~5 minutes)

        // Keep a list of queue class names and track last refresh time per queue
        $queueClasses = array_map(
            fn(Queue $queue) => $queue::class,
            $queues
        );
        $lastRefresh  = [];
        $now          = time();
        foreach ($queueClasses as $q) {
            // Initialise to now so first refresh happens after ~interval
            $lastRefresh[$q] = $now;
        }

        $this->logln('Waiting for jobs:');
        while ($this->runLoop()) {
            $job = null;
            try {

                $job = $this->manager->getNextJob($queueClasses, $this->worker);
                if ($job) {

                    $this->jobLog($job, '<info>RUNNING</info>');

                    try {

                        $timerStart = microtime(true);
                        $job->run();
                        $this->manager->markJobAsComplete($job);
                        $logMessage = '<info>COMPLETE</info>';

                    } catch (\Throwable $e) {

                        $maxRetries   = max(0, (int) $job->task::getMaxRetries());
                        $currentTries = (int) ($job->attempts ?? 0);
                        if ($currentTries < $maxRetries) {
                            $this->manager->retryJob($job, $e);
                            $logMessage = sprintf(
                                '<comment>RETRY %d/%d</comment> (next at %s)',
                                $currentTries + 1,
                                $maxRetries,
                                $job->available_at->format('H:i:s')
                            );
                        } else {
                            $logMessage = '<info>FAILED</info>';
                            $this->manager->markJobAsFailed($job, $e);
                        }

                    } finally {
                        $this->jobLog(
                            $job,
                            sprintf(
                                '%s [<comment>%ss</comment>]',
                                $logMessage,
                                round(microtime(true) - $timerStart, 4)
                            )
                        );
                        $waitTime = Config::get('QUEUE_WORKER_WAIT_TIME', 500);
                    }

                } else {
                    // Sleep with jitter to reduce thundering herd
                    // Chunked to catch signals more quickly
                    $remainingMs = $waitTime + random_int(0, 200);
                    while ($remainingMs > 0 && !$this->shutdownRequested) {
                        $chunk = min(100, $remainingMs); // 100ms chunks
                        usleep($chunk * 1000);
                        $remainingMs -= $chunk;
                    }
                }

            } catch (\Throwable $e) {
                $this->logln(sprintf(
                    '<error>Error: %s</error>',
                    $e->getMessage()
                ));
            } finally {
                if ($job) {
                    unset($job);
                }

                // Periodically refresh queues (~every $refreshInterval seconds)
                $now = time();
                foreach ($queueClasses as $q) {
                    if (($now - ($lastRefresh[$q] ?? 0)) >= $refreshInterval) {
                        $this->log(sprintf('<comment>Refreshing</comment> <info>%s</info> ... ', $q));
                        $refreshTimerStart = microtime(true);
                        try {

                            $q::refresh($this->worker);
                            $this->logStringWithTimer(
                                '<info>done</info>',
                                $refreshTimerStart
                            );

                            //  Take the opportunity to refresh the log file path
                            $this->setLogFile();

                        } catch (\Throwable $e) {
                            $this->logStringWithTimer(
                                sprintf('<error>error: %s</error>', $e->getMessage()),
                                $refreshTimerStart
                            );
                        }
                        $lastRefresh[$q] = $now;
                    }
                }

                $this->manager->touchWorker($this->worker);
                $this->database->flushCache();
            }
        }
    }

    protected function runLoop(): bool
    {
        if ($this->shutdownRequested) {
            $this->logLn('<comment>Shutdown</comment>: Stopping job loop');
            return false;
        }
        return true;
    }

    protected function jobLog(Job $job, string $message): void
    {
        $this->logln(
            sprintf(
                '[<comment>%s</comment>:#<comment>%s</comment>] ......... %s',
                $job->task::class,
                $job->id,
                $message
            )
        );
    }

    protected function logStringWithTimer(string $message, $timerStart): void
    {
        $this->logln(sprintf(
            '%s [<comment>%ss</comment>]',
            $message,
            round(microtime(true) - $timerStart, 4)
        ));
    }
}
