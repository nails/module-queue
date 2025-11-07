<?php

namespace Nails\Admin\Queue;

use Nails\Admin\Constants;
use Nails\Admin\Controller\Base;
use Nails\Admin\Factory\Nav;
use Nails\Admin\Helper;
use Nails\Factory;
use Nails\Queue\Enum\Job\Status as JobStatus;

class Overview extends Base
{
    use \App\Traits\Admin\VirtualAdviser;

    // --------------------------------------------------------------------------

    /**
     * Announces this controller's navGroups
     *
     * @return \stdClass
     */
    public static function announce(): Nav|array|null
    {
        /** @var Nav $navGroup */
        $navGroup = Factory::factory('Nav', Constants::MODULE_SLUG);
        $navGroup->setLabel('Queue');
        if (userHasPermission('admin:queue:overview:view')) {
            $navGroup->addAction('Overview');
        }

        return $navGroup;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns an array of extra permissions for this controller
     *
     * @return array
     */
    public static function permissions(): array
    {
        $aPermissions = parent::permissions();

        $aPermissions['view'] = 'Can view queue overview';

        return $aPermissions;
    }

    // --------------------------------------------------------------------------

    /**
     * Queue Overview
     *
     * @return void
     */
    public function index()
    {
        if (!userHasPermission('admin:queue:overview:view')) {
            unauthorised();
        }

        // --------------------------------------------------------------------------
        // Models (may not be used for now – placeholders are acceptable)
        $workerModel = Factory::model('Worker', \Nails\Queue\Constants::MODULE_SLUG);
        $jobModel    = Factory::model('Job', \Nails\Queue\Constants::MODULE_SLUG);

        // --------------------------------------------------------------------------
        // Build an overview dataset. Where fast to compute, populate; otherwise, leave placeholders.
        $now = new \DateTimeImmutable();

        /**
         * Metrics & how to calculate them (wire later):
         *
         * - Jobs scheduled:
         *   Definition: jobs which are not yet available to be picked up.
         *   Condition: available_at > NOW() (or available_at != NULL AND in the future) and status = PENDING.
         *   Example (SQL-ish): COUNT(*) FROM queue_job WHERE status='PENDING' AND available_at IS NOT NULL AND available_at > NOW();
         *
         * - Oldest queued age:
         *   Definition: how long the oldest ready-to-run job has been waiting in the queue.
         *   Formula: NOW() - MIN(available_at) over jobs which are ready now.
         *   Ready condition: status = PENDING AND (available_at IS NULL OR available_at <= NOW()).
         *   Example: SELECT TIMESTAMPDIFF(SECOND, MIN(available_at), NOW()) ...; Render nicely (e.g., 5m 12s).
         *
         * - Avg. latency (recent):
         *   Definition: average time between when a job became available and when a worker actually started it.
         *   Formula per job: started - available_at.
         *   Window: pick a recent slice to keep it cheap and responsive, e.g., last 1,000 jobs with started IS NOT NULL in the last 24h.
         *   Example: AVG(UNIX_TIMESTAMP(started) - UNIX_TIMESTAMP(available_at)) FROM queue_job WHERE started IS NOT NULL AND started >= NOW() - INTERVAL 24 HOUR;
         *   Notes: ignore rows where either timestamp is NULL; guard against negative values (clock skew) by MAX(diff, 0).
         *
         * - Avg. duration (recent):
         *   Definition: average time workers spend running jobs.
         *   Formula per job: finished - started.
         *   Window: last N finished jobs (e.g., 1,000) or last 24h WHERE status IN ('COMPLETE','FAILED') AND finished IS NOT NULL.
         *   Example: AVG(UNIX_TIMESTAMP(finished) - UNIX_TIMESTAMP(started)) ...;
         *   Notes: exclude RUNNING jobs (finished is NULL); clamp negatives to 0.
         *
         * - Throughput (1h / 24h):
         *   Definition: number of jobs finished within the time window.
         *   Formula: COUNT(*) WHERE finished BETWEEN NOW()-INTERVAL X AND NOW() AND status IN ('COMPLETE','FAILED').
         *   Example: COUNT(*) FROM queue_job WHERE finished >= NOW() - INTERVAL 1 HOUR AND finished <= NOW() AND status IN ('COMPLETE','FAILED');
         *   Tip: you can also compute per-queue throughput with GROUP BY queue.
         *
         * Implementation tips:
         * - Indexes: create an index on (status), (available_at), (started), (finished), and composite indexes like (status, available_at) and (status, finished) if tables are large.
         * - Windows: prefer a bounded window (time-based or last N rows) to keep queries fast for the Admin UI.
         * - Time zones: store timestamps in UTC; render using site/user tz.
         * - Retries: treat each attempt as one job row (as per current schema) or, if you reuse the row, attempts are fine — the metrics above still apply.
         * - Scheduled vs queued now: scheduled = available_at in the future; queued now = available_at null or in the past and still PENDING.
         */
        $overview = [
            'kpis'            => [
                'workers_total'       => null, // placeholder: total number of registered workers
                'workers_active'      => null, // active (fresh heartbeat)
                'workers_stale'       => null, // stale workers to be cleaned
                'jobs_total'          => null,
                'jobs_pending'        => null,
                'jobs_running'        => null,
                'jobs_failed'         => null,
                'jobs_complete'       => null,
                'jobs_scheduled'      => null, // available_at > now
                'oldest_queued_age'   => null, // human friendly string
                'avg_latency_recent'  => null, // started - available_at over recent window
                'avg_duration_recent' => null, // finished - started over recent window
                'throughput_1h'       => null,
                'throughput_24h'      => null,
            ],
            'queues'          => [
                // Each item: ['queue' => Fully\\Qualified\\Queue::class, 'queued' => int, 'oldest' => '5m', 'newest' => '10s']
            ],
            'workers'         => [
                // Each item: ['id'=>int,'token_short'=>'abc…','queues'=>['Default','Priority'],'last_heartbeat'=>null,'jobs_processed'=>null,'current_job'=>null]
            ],
            'recent_failures' => [
                // Each item: ['id'=>int,'queue'=>class,'task'=>class,'failed_at'=>string,'attempts'=>int,'last_error'=>string]
            ],
            'notes'           => [
                'placeholders' => 'Some figures are placeholders. Wire these up to model queries as needed.',
            ],
        ];

        // --------------------------------------------------------------------------
        // Try to populate a few common values safely if model APIs are available.
        try {
            if (method_exists($workerModel, 'countAll')) {
                $overview['kpis']['workers_total'] = $workerModel->countAll();
            }
            if (method_exists($jobModel, 'countAll')) {
                $overview['kpis']['jobs_total'] = $jobModel->countAll();
                // Count by status if supported via where() + countAll() or a countWhere()
                if (method_exists($jobModel, 'countAll')) {
                    $overview['kpis']['jobs_pending']  = $jobModel->countAll(['status' => JobStatus::PENDING->value]);
                    $overview['kpis']['jobs_running']  = $jobModel->countAll(['status' => JobStatus::RUNNING->value]);
                    $overview['kpis']['jobs_failed']   = $jobModel->countAll(['status' => JobStatus::FAILED->value]);
                    $overview['kpis']['jobs_complete'] = $jobModel->countAll(['status' => JobStatus::COMPLETE->value]);
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore; placeholders remain and the view will explain.
        }

        // --------------------------------------------------------------------------
        $this->data['overview']     = $overview;
        $this->data['page']->title  = 'Queue &rsaquo; Overview';
        $this->data['generated_at'] = $now->format('Y-m-d H:i:s');

        Helper::loadView('index');
    }
}
