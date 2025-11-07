<?php
/** @var array $overview */
/** @var string $generated_at */

$overview = $overview ?? [];
$kpis     = $overview['kpis'] ?? [];
$queues   = $overview['queues'] ?? [];
$workers  = $overview['workers'] ?? [];
$failures = $overview['recent_failures'] ?? [];
$notes    = $overview['notes']['placeholders'] ?? null;

function kpi($label, $value, $hint = null)
{
    $value    = $value === null ? '—' : (string) $value;
    $hintAttr = $hint ? ' title="' . htmlspecialchars($hint) . '"' : '';
    echo '<div class="kpi"' . $hintAttr . '>';
    echo '  <div class="kpi__label">' . htmlspecialchars($label) . '</div>';
    echo '  <div class="kpi__value">' . htmlspecialchars($value) . '</div>';
    echo '</div>';
}

?>

<style>
    .kpis {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin: 10px 0 20px;
    }

    .kpi {
        border: 1px solid #e3e3e3;
        border-radius: 6px;
        padding: 10px 12px;
        min-width: 140px;
        background: #fafafa;
    }

    .kpi__label {
        font-size: 12px;
        color: #666;
    }

    .kpi__value {
        font-size: 20px;
        font-weight: 600;
    }

    .section {
        margin: 24px 0;
    }

    .section h3 {
        margin: 0 0 10px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th, td {
        text-align: left;
        padding: 8px;
        border-bottom: 1px solid #eee;
    }

    .actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .btn {
        display: inline-block;
        padding: 6px 10px;
        border: 1px solid #ccc;
        border-radius: 4px;
        text-decoration: none;
        color: #222;
        background: #f5f5f5;
    }

    .btn--primary {
        border-color: #1b6ec2;
        background: #2d8fe2;
        color: #fff;
    }

    .btn--danger {
        border-color: #c23a3a;
        background: #e24d4d;
        color: #fff;
    }

    .btn[disabled] {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .muted {
        color: #777;
    }

    .mono {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }
</style>

<div class="section">
    <div class="muted">Generated at: <?=htmlspecialchars($generated_at ?? date('Y-m-d H:i:s'))?></div>
    <div class="kpis">
        <?php
        kpi('Workers (total)', $kpis['workers_total'] ?? null);
        kpi('Workers (active)', $kpis['workers_active'] ?? null, 'Heartbeat within threshold, e.g., last 60s');
        kpi('Workers (stale)', $kpis['workers_stale'] ?? null, 'No heartbeat beyond threshold; safe to clean up');
        kpi('Jobs (total)', $kpis['jobs_total'] ?? null);
        kpi('Jobs queued', $kpis['jobs_pending'] ?? null, "Status = PENDING and (available_at IS NULL or available_at <= now)");
        kpi('Jobs running', $kpis['jobs_running'] ?? null, 'Status = RUNNING');
        kpi('Jobs failed', $kpis['jobs_failed'] ?? null, 'Status = FAILED');
        kpi('Jobs complete', $kpis['jobs_complete'] ?? null, 'Status = COMPLETE');
        kpi('Jobs scheduled', $kpis['jobs_scheduled'] ?? null, 'PENDING and available_at > now');
        kpi('Oldest queued age', $kpis['oldest_queued_age'] ?? null, 'now - MIN(available_at) over ready PENDING jobs');
        kpi('Avg. latency (recent)', $kpis['avg_latency_recent'] ?? null, 'AVG(started - available_at) over last N started jobs');
        kpi('Avg. duration (recent)', $kpis['avg_duration_recent'] ?? null, 'AVG(finished - started) over last N finished jobs');
        kpi('Throughput (1h)', $kpis['throughput_1h'] ?? null, 'COUNT(finished in last hour) where status in COMPLETE/FAILED');
        kpi('Throughput (24h)', $kpis['throughput_24h'] ?? null, 'COUNT(finished in last 24h) where status in COMPLETE/FAILED');
        ?>
    </div>
    <?php if ($notes): ?>
        <p class="muted">Note: <?=htmlspecialchars($notes)?></p>
    <?php endif; ?>
</div>

<div class="section">
    <h3>Queues</h3>
    <?php if (empty($queues)): ?>
        <p class="muted">No queue breakdown data available yet. Consider populating with per-queue counts and oldest/newest job ages.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Queue</th>
                    <th>Queued</th>
                    <th>Oldest</th>
                    <th>Newest</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($queues as $q): ?>
                    <tr>
                        <td class="mono"><?=htmlspecialchars($q['queue'] ?? '')?></td>
                        <td><?=htmlspecialchars((string) ($q['queued'] ?? '—'))?></td>
                        <td><?=htmlspecialchars($q['oldest'] ?? '—')?></td>
                        <td><?=htmlspecialchars($q['newest'] ?? '—')?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="section">
    <h3>Workers</h3>
    <?php if (empty($workers)): ?>
        <p class="muted">No workers registered or no data available.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Token</th>
                    <th>Queues</th>
                    <th>Last heartbeat</th>
                    <th>Jobs processed</th>
                    <th>Current job</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($workers as $w): ?>
                    <tr>
                        <td><?=htmlspecialchars((string) ($w['id'] ?? ''))?></td>
                        <td class="mono"><?=htmlspecialchars($w['token_short'] ?? '—')?></td>
                        <td><?=htmlspecialchars(implode(', ', $w['queues'] ?? []))?></td>
                        <td><?=htmlspecialchars($w['last_heartbeat'] ?? '—')?></td>
                        <td><?=htmlspecialchars((string) ($w['jobs_processed'] ?? '—'))?></td>
                        <td><?=htmlspecialchars($w['current_job'] ?? '—')?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="section">
    <h3>Recent failures</h3>
    <?php if (empty($failures)): ?>
        <p class="muted">No failed jobs found in the recent window.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Queue</th>
                    <th>Task</th>
                    <th>Failed at</th>
                    <th>Attempts</th>
                    <th>Last error</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($failures as $f): ?>
                    <tr>
                        <td><?=htmlspecialchars((string) ($f['id'] ?? ''))?></td>
                        <td class="mono"><?=htmlspecialchars($f['queue'] ?? '')?></td>
                        <td class="mono"><?=htmlspecialchars($f['task'] ?? '')?></td>
                        <td><?=htmlspecialchars($f['failed_at'] ?? '—')?></td>
                        <td><?=htmlspecialchars((string) ($f['attempts'] ?? '—'))?></td>
                        <td><?=htmlspecialchars($f['last_error'] ?? '—')?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="section">
    <h3>Actions</h3>
    <div class="actions">
        <button class="btn btn--primary" disabled title="Wire this to an endpoint which calls Manager->deleteStaleWorkers()">Clean stale workers</button>
        <button class="btn" disabled title="Wire to retry all FAILED jobs (or selected)">Retry failed jobs</button>
        <button class="btn btn--danger" disabled title="Wire to purge COMPLETE jobs older than a threshold">Purge old complete jobs</button>
        <button class="btn" disabled title="Wire to a dry-run which prints what would be done">Dry-run maintenance</button>
    </div>
    <p class="muted">Actions are placeholders and are disabled until endpoints are implemented.</p>
</div>
