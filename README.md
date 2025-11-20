# Queue Module for Nails

![license](https://img.shields.io/badge/license-MIT-green.svg)
[![tests](https://github.com/nails/module-queue/actions/workflows/build_and_test.yml/badge.svg )](https://github.com/nails/module-queue/actions)

A lightweight, database-backed job queue for the Nails framework. It provides a simple API to enqueue tasks, a worker to process them, retry/backoff handling, and maintenance commands.

- Storage: MySQL tables managed by migrations
- Concurrency: safe reservation using `SELECT ... FOR UPDATE SKIP LOCKED`
- Ordering: strict FIFO per queue (lowest `id` first among `available_at <= NOW()`)
- Retries: per-task maximum with exponential backoff + jitter
- Extensibility: plug-in queues via an interface with optional `setup`/`refresh` hooks


## Installation

Install via composer:

```bash
composer require nails/module-queue
```

Setup required tables using migrations as normal:
```bash
nails db:migrate
```

Tables created:
- `nails_queue_worker`: active workers and their heartbeats
- `nails_queue_job`: queued jobs and their lifecycle


## High-level Overview

Core concepts:
- Queue: a logical stream of jobs. Implemented as a class that can run `setup` and `refresh` hooks. There are built-in queues `DefaultQueue` and `PriorityQueue`.
- Job: a unit of work defined by a `Task` class and a `Data` payload. Jobs transition through `PENDING → RUNNING → COMPLETE | FAILED`.
- Worker: a long-running process which polls one or more queues and executes jobs. Each worker registers itself and sends periodic heartbeats.

Flow:
1. Application enqueues a job: `Manager->push(Task, Data, Queue|alias|class, availableAt)`
2. Worker (`nails queue:work`) reserves a job atomically
3. Worker runs the `Task->run(Data)` method
4. On success → `COMPLETE`; on exception → either `FAILED` or scheduled for retry depending on `Task::getMaxRetries()`
5. Maintenance (`nails queue:clean`) resets stuck jobs, removes stale workers, and rotates old job rows


## Configuration

Set via configuration class (e.g. environment variables, `Config::get()`). Defaults are shown in parentheses.

- `QUEUE_WORKER_WAIT_TIME` (500): Initial wait time in milliseconds between polls when no work is found. Doubles up to 5000ms; a small random jitter is added each sleep.
- `QUEUE_WORKER_REFRESH_INTERVAL` (300): Seconds between invoking `Queue::refresh()` for each active queue (approx; called in the idle loop). This is effectively "~5 minutes" when jobs are running back-to-back, as the loop only checks between jobs; when idle, it can be called more frequently.
- `QUEUE_WORKER_HEARTBEAT_STALE` (300): Seconds after which a worker without heartbeat is considered stale and eligible for deletion by `queue:clean`.
- `QUEUE_JOB_ROTATE_COMPLETE_DAYS` (7): Days to retain `COMPLETE` jobs. Set 0 to disable deletion.
- `QUEUE_JOB_ROTATE_FAILED_DAYS` (30): Days to retain `FAILED` jobs. Set 0 to disable deletion.


## Interfaces

Implement these in user-land to add new queues and jobs.

### Queue
```php
namespace Nails\Queue\Interface;

use Nails\Queue\Resource\Worker;

interface Queue
{
    /** Called once on worker startup for each queue */
    public static function setup(Worker $worker): void;

    /** Periodically called by the worker (~every refresh interval) */
    public static function refresh(Worker $worker): void;
}
```

Built-in queues:
- `Nails\Queue\Queue\Queues\DefaultQueue`
- `Nails\Queue\Queue\Queues\PriorityQueue`

These are registered under the aliases `default` and `priority` respectively.

### Task
```php
namespace Nails\Queue\Interface;

interface Task
{
    /** Maximum number of retries for this task */
    public static function getMaxRetries(): int;

    /** Perform the work */
    public function run(Data $data): void;
}
```

Retry behaviour:
- On exception, if `attempts < getMaxRetries()`, the job is rescheduled to `PENDING` with an `available_at` delay computed by exponential backoff (+/- 20% jitter):
  - base 5s, factor 2, capped at 5 minutes
- When attempts exceed max, the job is marked `FAILED` and error is recorded.

### Data
```php
namespace Nails\Queue\Interface;

use stdClass;

interface Data
{
    /** Construct with the payload to persist */
    public function __construct(array|string|int|float|bool|stdClass|null $data);

    /** Retrieve the raw payload */
    public function get(): array|string|int|float|bool|stdClass|null;

    /** JSON representation persisted to DB */
    public function toJson(): string;
}
```

The module provides a simple factory to rehydrate `Data` from JSON when jobs are loaded.


## Manager Service API (selected)

```php
use Nails\Queue\Service\Manager;
use Nails\Queue\Interface\Task;
use Nails\Queue\Interface\Data;
use Nails\Queue\Interface\Queue;
use Nails\Queue\Resource\Job;

// Factory::service('Manager', \Nails\Queue\Constants::MODULE_SLUG)

// Queue alias registration (defaults provided for 'default' and 'priority')
Manager::addAlias(string $alias, Queue $queue): self
Manager::resolveQueue(string|Queue $alias): Queue

// Job lifecycle
Manager::push(Task $task, Data $data, Queue|string|null $queue = null, ?DateTimeInterface $availableAt = null): Job
Manager::getNextJob(array $queues, Resource\Worker $worker): ?Job
Manager::markJobAsComplete(Job $job): bool
Manager::markJobAsFailed(Job $job, Throwable $e): bool
Manager::retryJob(Job $job, Throwable $e): DateTime   // schedules next attempt

// Worker lifecycle & maintenance
Manager::registerWorker(array $queues): Resource\Worker
Manager::touchWorker(Resource\Worker $worker): Resource\Worker
Manager::unregisterWorker(Resource\Worker $worker): void
Manager::deleteStaleWorkers(): Resource\Worker[]
Manager::resetStuckJobs(): Job[]
Manager::rotateOldJobs(): Job[]
```

Notes:
- Passing `null` or `'default'` as the queue argument uses the default queue alias; you can also pass a fully-qualified queue class name (FQCN) or an instance.
- FIFO is approximated by selecting the smallest available `id` where `available_at <= NOW()`.


## Creating user-land queues and jobs

### 1) Define a Queue (optional)
If you need custom startup or periodic maintenance, implement your own queue class and register an alias at bootstrap.

You can place your own Queues wherever you like, but for auto-discovery you should place them in the `App\Queue\Queues` namespace.

```php
namespace App\Queue\Queues;

use Nails\Queue\Interface\Queue;
use Nails\Queue\Resource\Worker;

class Reports implements Queue
{
    public static function setup(Worker $worker): void
    {
        // e.g. ensure storage directories exist
    }

    public static function refresh(Worker $worker): void
    {
        // e.g. rotate temp files, refresh API tokens, etc.
    }
}
```

Optionally, register an alias:
```php
$manager = \Nails\Factory::service('Manager', \Nails\Queue\Constants::MODULE_SLUG);
$manager->addAlias('reports', new \App\Queue\Queues\Reports());
```

### 2) Define a Task
```php
namespace App\Queue\Tasks\Reports;

use Nails\Queue\Interface\Task;
use Nails\Queue\Interface\Data;

class Generate implements Task
{
    public static function getMaxRetries(): int
    {
        return 3;
    }

    public function run(Data $data): void
    {
        $payload = $data->get();
        // do the work using $payload
    }
}
```

### 3) Define a Data class
The supplied `Data` factory is usually sufficient for defining the payload for each job:
```php
use Nails\Factory;
use Nails\Queue\Constants;

$payload = (object) ['foo' => 'bar'];
$data    = Factory::factory('Data', onstants::MODULE_SLUG, $payload);
```
However, you are free to use your own implementation if you wish. In the following example we ensure that our payload is in the right shape and also validate that our headers match our rows:
```php
namespace App\Queue\Data\Reports;

use Nails\Queue\Factory\Data;

class ReportTable extends Data
{
    public static function make(string $name, array $header, array $rows)
    {
        $firstRow = reset($rows);
        if ($firstRow && count($header) !== count($rows)) {
            throw new \InvalidArgumentException(
                'Header and rows must be the same length'
            )
        } 
    
        return new self((object) [
            'name'   => $name,
            'header' => $header,
            'rows'   => $rows,
        ]);
    }
}

// ReportTable::make('My Table', ['Foo', 'Bar'], [['Fizz', 'Buzz']])
```

### 4) Enqueue a job
```php
use App\Queue\Data;
use App\Queue\Tasks;
use Nails\Factory;
use Nails\Queue\Constants;
use Nails\Queue\Service\Manager;

/** @var Manager $queue */
$manager = Factory::service('Manager', Constants::MODULE_SLUG);

$rows   = [['Fizz', 'Buzz']];
$header = ['Foo', 'Bar'];

$job = $manager->push(
    // The task to execute
    task: new Tasks\Reports\Generate(),
    //  The data payload
    data: Data\Reports\ReportTable::make('My Table', $header, $rows),
    // alias, class name, instance, or null for default
    queue: new \App\Queue\Queues\Reports(),           
    // optional delay                       
    availableAt: (new DateTimeImmutable('+2 minutes'))
);
```


## Running the worker

Start a worker to process jobs from one or more queues.

Basic usage (current dir is the web root):
```bash
nails queue:work
```

Options:
- `--queue=<QueueFQCN-or-alias>`: Process a specific queue. Repeat to listen to multiple queues. Defaults to the default queue if not provided.

Examples:
```bash
# Default queue
nails queue:work

# By alias (multiple queues)
nails queue:work --queue=default --queue=priority

# By FQCN
nails queue:work --queue=App\\Queue\\Queues\\Reports
```

What the worker does:
- Registers itself and prints its token/ID
- Calls `Queue::setup()` once per queue at startup
- Event loop:
  - Attempts to reserve the next job (`PENDING`, `available_at <= NOW()`) using SKIP LOCKED
  - On success: marks `RUNNING`, executes `Task->run(Data)`, then marks `COMPLETE` or schedules retry / marks `FAILED`
  - On idle: sleeps with exponential backoff (ms) and jitter up to 5000ms, then polls again
  - Periodically calls `Queue::refresh()` per queue (approx every `QUEUE_WORKER_REFRESH_INTERVAL` seconds when idle)
  - Updates its heartbeat and flushes DB cache each iteration

Graceful shutdown:
- Where possible, the worker unregisters itself on destruct. When a process is terminated the worker may be left registered. This is cleaned up using the `queue:clean` command, detailed below.


## Maintenance command

Clean up workers and jobs:
```bash
nails queue:clean
```
Performs:
- Delete stale workers whose heartbeat is older than `QUEUE_WORKER_HEARTBEAT_STALE` seconds
- Reset stuck jobs: `RUNNING` with no `worker_id` and no `finished` back to `PENDING`
- Rotate old jobs:
  - Remove `COMPLETE` older than `QUEUE_JOB_ROTATE_COMPLETE_DAYS`
  - Remove `FAILED` older than `QUEUE_JOB_ROTATE_FAILED_DAYS`

This command auto registers itself into cron and runs every 5 minutes.


## Daemonising the worker (supervisord example)

Keep the queue proccess running in the background (with auto-restart) using a process manager. Common examples:

### `systemd`
`/etc/systemd/system/nails-queue.service`
```
[Unit]
Description=Nails Queue Worker
After=network.target

[Service]
User=www-data
Restart=always
ExecStart=/bin/bash -c 'cd /path/to/project && /path/to/nails queue:work --queue="default" --queue="priority"'

[Install]
WantedBy=multi-user.target
```

Commands:
```
systemctl daemon-reload
systemctl enable nails-queue
systemctl start nails-queue
systemctl status nails-queue
```

### `supervisord`
`/etc/supervisor/conf.d/queue-worker.conf`:
```
[program:nails-queue]
command=/bin/bash -c 'cd /path/to/project && /path/to/nails queue:work --queue="default" --queue="priority"'
process_name=%(program_name)s_%(process_num)02d
numprocs=2
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopsignal=TERM
startretries=3
startsecs=5
redirect_stderr=true
stdout_logfile=/var/log/nails-queue.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
environment=APP_ENV="PRODUCTION"
```

Commands:
```
supervisorctl reread
supervisorctl update
supervisorctl status nails-queue:*
```

Tips:
- Run one program block per queue, or a single process handling multiple queues with repeated `--queue` flags
- Ensure your application’s environment is loaded for the worker process (env vars, config, DB access)
