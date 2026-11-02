# Queue Module for Nails

![license](https://img.shields.io/badge/license-MIT-green.svg)
[![tests](https://github.com/nails/module-queue/actions/workflows/build_and_test.yml/badge.svg )](https://github.com/nails/module-queue/actions)

A lightweight, database-backed job queue for Nails. Push a task with a payload, run it in a long-lived worker, and retry it with exponential backoff when it fails.

- Storage: MySQL tables managed by migrations (`nails_queue_job`, `nails_queue_worker`)
- Concurrency: jobs are reserved with `SELECT ... FOR UPDATE SKIP LOCKED`, so MySQL 8.0+ or MariaDB 10.6+ is required
- Ordering: first in, first out by job `id` among jobs that are due
- Retries: per-task maximum, backoff from 5 seconds doubling to a 5 minute cap, with 20% jitter
- Admin: an overview screen and a dashboard warning when a queue has no live worker

Console commands:

- `queue:work` runs a worker. Repeat `--queue` to work several queues. It defaults to `default`.
- `queue:run <job>` runs one job's task in the current process, without changing the job
- `queue:clean` removes dead workers, resets stuck jobs, and deletes old jobs. Cron runs it every 5 minutes.

Install with Composer, then migrate:

```bash
composer require nails/module-queue
nails db:migrate
```

A worker must be running for anything to happen, and it should be kept alive by a process manager such as systemd or Supervisor. `default` and `priority` are separate queues with no special treatment: the app has to run a separate worker for `priority`.

See [the documentation](https://docs.nailsapp.co.uk/modules/queue) for writing tasks, defining queues, configuration, running workers under systemd or Supervisor, and the admin screen.
