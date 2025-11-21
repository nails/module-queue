<template>
    <div class="nails-module-queue-overview">
        <!-- Reusable Modal Component -->
        <Modal
            :show="modalVisible"
            :title="modalTitle"
            :body-html="modalBodyHtml"
            :body-text="modalBodyText"
            @close="closeModal"
        />
        <template v-if="initialised">
            <div v-if="error" class="alert alert-danger">
                <p>
                    <strong>{{ error.title }}</strong>
                    <span v-if="error.message" style="margin-top: 0.25rem;">{{ error.message }}</span>
                </p>
            </div>
            <template v-else>
                <div class="kpis">
                    <KPI
                        v-for="(kpi, i) in kpis"
                        :key="kpi.slug || kpi.label || `kpi-${i}`"
                        :label="kpi.label"
                        :value="kpi.value"
                        :hint="kpi.hint || ''"
                        :type="kpi.type || ''"
                        :now-ts="nowTs"
                    />
                </div>
                <h2>Queues</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Queue</th>
                                <th>Jobs</th>
                                <th
                                    v-for="(col, i) in queueColumns"
                                    :key="`queue-col-${i}`"
                                    class="text-center"
                                >
                                    {{ col.header }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="queues.length === 0">
                                <td :colspan="queueColspan" class="no-data">No queues registered</td>
                            </tr>
                            <tr v-for="(queue, qi) in queues" :key="`queue-${qi}`">
                                <td>
                                    <code>{{ queue.label }}</code>
                                </td>
                                <td>
                                    <div
                                        v-for="(job, ji) in queue.jobs"
                                        :key="`queue-${qi}-job-${ji}`"
                                        class="alert"
                                        :class="`alert-${job.class || 'default'}`"
                                    >
                                        <span>{{ job.label }}</span>
                                        <span class="badge">{{ formatNumber(job.value) }}</span>
                                    </div>
                                </td>
                                <td
                                    v-for="(col, ci) in queueColumns"
                                    :key="`queue-${qi}-col-${ci}`"
                                    class="text-center"
                                >
                                    <template v-if="col.type === 'latency' || col.type === 'duration'">
                                        {{ formatDuration(findMetric(queue[col.type], col.label)) }}
                                    </template>
                                    <template v-else-if="col.type === 'throughput'">
                                        {{ formatNumber(findMetric(queue.throughput, col.label)) }}
                                    </template>
                                    <template v-else>—</template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <h2>Workers</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th class="text-center">ID</th>
                                <th>Token</th>
                                <th>Queues</th>
                                <th>Uptime</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="workers.length === 0">
                                <td colspan="5" class="no-data">No Workers Registered</td>
                            </tr>
                            <tr v-for="(worker, wi) in workers" :key="`worker-${worker.id}-${wi}`">
                                <td class="text-center">{{ worker.id }}</td>
                                <td><code>{{ worker.token || '—' }}</code></td>
                                <td>
                                    <ul class="list-unstyled">
                                        <li v-for="(q, ii) in worker.queues" :key="`wq-${wi}-${ii}`">
                                            <code>{{ q }}</code></li>
                                    </ul>
                                </td>
                                <td>
                                    {{ ageFrom(worker.created?.unix) }}
                                    <small v-if="worker.created?.user" style="display:block;">Created: {{ worker.created.user }}</small>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <h2>Failed Jobs</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th class="text-center">ID</th>
                                <th>Queue</th>
                                <th>Task</th>
                                <th>Payload</th>
                                <th>Failed at</th>
                                <th class="text-center">Attempts</th>
                                <th style="max-width: 350px;">Last error</th>
                                <th class="actions" style="width:100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="failed.length === 0">
                                <td colspan="8" class="no-data">No Failed Jobs</td>
                            </tr>
                            <tr v-for="(job, fi) in failed" :key="`failed-${job.id}-${fi}`">
                                <td class="text-center">{{ job.id }}</td>
                                <td><code>{{ job.queue }}</code></td>
                                <td><code>{{ job.task }}</code></td>
                                <td>
                                    <pre>{{ pretty(job.payload) }}</pre>
                                </td>
                                <td>
                                    <div>{{ ageFrom(job.finished?.unix) }} ago</div>
                                    <small v-if="job.finished?.user" style="display:block;">Finished: {{ job.finished.user }}</small>
                                </td>
                                <td class="text-center">{{ job.attempts }}</td>
                                <td style="max-width:350px;">
                                    <div class="mono">{{ lastErrorMessage(job) || 'Unknown error message' }}</div>
                                    <hr style="margin: 0.5rem 0" />
                                    <button
                                        type="button"
                                        class="btn btn-default btn-xs btn-block"
                                        @click="openErrorTrace(job)"
                                    >
                                        Error Trace
                                    </button>
                                </td>
                                <td class="actions">
                                    <button
                                        class="btn btn-primary btn-xs hint--left"
                                        aria-label="Reset this job so it becomes available once again for processing"
                                        @click="retryJob(job)"
                                        :disabled="!!retrying[job.id]"
                                    >
                                        Retry
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </template>
    </div>
</template>

<script>

/**
 * Queue Overview
 * Renders a page of useful/notable stats. Calls API periodically to get up-to-date information.
 */

import KPI from './Overview/KPI.vue';
import Modal from './Overview/Modal.vue';

export default {
    name: 'OverviewV2',
    components: {KPI, Modal},
    props: {},
    data() {
        return {
            queueApi: {
                overview: {
                    fetch: () => `${this.siteUrl}api/queue/overview`,
                    retry: () => `${this.siteUrl}api/queue/overview/retry`,
                },
            },
            userPermissions: {},
            siteUrl: window.SITE_URL || '',
            // data
            kpis: [],
            queues: [],
            workers: [],
            failed: [],
            loading: false,
            error: null, // { title: string, message?: string } | null
            initialised: false,
            // timers
            pollTimer: null,
            tickTimer: null,
            nowTs: Math.floor(Date.now() / 1000),
            // modal state
            modalVisible: false,
            modalTitle: '',
            modalBodyHtml: '',
            modalBodyText: '',
            modalType: 'generic', // 'generic' | 'trace'
            modalJob: null,
            // retry state
            retrying: {}, // Record<jobId, true>
        }
    },

    provide() {
        return {
            queueApi: this.queueApi,
            userPermissions: this.userPermissions
        };
    },

    created() {
        this.fetchOverview();
        this.pollTimer = setInterval(this.fetchOverview, 10000);
        // tick every second so age/durations re-render
        this.tickTimer = setInterval(() => {
            this.nowTs = Math.floor(Date.now() / 1000);
        }, 1000);
    },

    beforeUnmount() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
        }
        if (this.tickTimer) {
            clearInterval(this.tickTimer);
        }
        // Ensure any open modal is closed/cleaned up
        this.closeModal();
    },

    watch: {},

    computed: {
        // Dynamic metric columns for the Queues table, based on API data
        queueColumns() {
            // Collect labels per metric type
            const types = ['latency', 'duration', 'throughput'];
            /** @type {Record<string, Set<string>>} */
            const labelsByType = {
                latency: new Set(),
                duration: new Set(),
                throughput: new Set(),
            };

            for (const q of this.queues || []) {
                for (const t of types) {
                    const list = q?.[t];
                    if (Array.isArray(list)) {
                        for (const item of list) {
                            if (item && typeof item.label === 'string') {
                                labelsByType[t].add(item.label);
                            }
                        }
                    }
                }
            }

            // Desired label order: 1h, 24h, then others alpha
            const labelSort = (a, b) => {
                const pref = {'1h': 0, '24h': 1};
                const ra = pref[a];
                const rb = pref[b];
                if (ra !== undefined || rb !== undefined) {
                    if (ra === undefined) return 1;
                    if (rb === undefined) return -1;
                    return ra - rb;
                }
                return a.localeCompare(b);
            };

            /** @type {{type: 'latency'|'duration'|'throughput', label: string, header: string}[]} */
            const cols = [];
            for (const t of types) {
                const labels = Array.from(labelsByType[t]);
                labels.sort(labelSort);
                for (const label of labels) {
                    const title = t.charAt(0).toUpperCase() + t.slice(1);
                    cols.push({type: t, label, header: `${title} (${label})`});
                }
            }
            return cols;
        },
        queueColspan() {
            // Base columns: Queue + Jobs
            return 2 + (this.queueColumns?.length || 0);
        },
    },

    methods: {
        async fetchOverview() {
            try {
                this.loading = true;
                const res = await fetch(this.queueApi.overview.fetch(), {
                    headers: {'Accept': 'application/json'},
                    credentials: 'same-origin',
                });
                // Try to parse JSON even on error responses for better error messaging
                const tryParseJson = async () => {
                    try {
                        return await res.clone().json();
                    } catch (_) {
                        return null;
                    }
                };
                if (!res.ok) {
                    const json = await tryParseJson();
                    let message = '';
                    if (json && json.error) {
                        message = typeof json.error === 'string' ? json.error : (json.error.message || '');
                    }
                    if (!message) {
                        message = res.statusText ? `${res.status} ${res.statusText}` : `HTTP ${res.status}`;
                    }
                    this.error = {title: 'Unable to load Queue overview', message};
                    return;
                }
                const json = await res.json();
                if (json && json.data) {
                    this.kpis = json.data.kpis || [];
                    this.queues = json.data.queues || [];
                    this.workers = json.data.workers || [];
                    // API names it "failed"
                    this.failed = json.data.failed || [];
                }
                // Clear any previous error on success
                this.error = null;
            } catch (e) {
                const message = e && e.message ? e.message : String(e);
                this.error = {title: 'Unable to load Queue overview', message};
                // eslint-disable-next-line no-console
                console.error('Failed to fetch overview', e);
            } finally {
                this.loading = false;
                if (!this.initialised) {
                    this.initialised = true;
                }
            }
        },
        findMetric(list, label) {
            if (!Array.isArray(list)) {
                return null;
            }
            const item = list.find((m) => m.label === label);
            return item ? item.value : null;
        },
        formatNumber(v) {
            if (v === null || v === undefined) return '—';
            if (typeof v === 'number') return v.toLocaleString();
            const n = Number(v);
            return Number.isFinite(n) ? n.toLocaleString() : String(v);
        },
        formatDuration(v) {
            if (v === null || v === undefined) return '—';
            const n = Number(v);
            if (!Number.isFinite(n)) return '—';
            return `${n.toFixed(4)}s`;
        },
        pretty(obj) {
            try {
                return JSON.stringify(obj, null, 2);
            } catch (e) {
                return String(obj);
            }
        },
        ageFrom(unixTs) {
            if (!unixTs) return '—';
            const secs = Math.max(0, this.nowTs - Number(unixTs));
            return this.formatAgeSeconds(secs);
        },
        formatAgeSeconds(totalSeconds) {
            if (!Number.isFinite(totalSeconds)) return '—';
            let s = Math.floor(totalSeconds);
            const days = Math.floor(s / 86400);
            s -= days * 86400;
            const hours = Math.floor(s / 3600);
            s -= hours * 3600;
            const mins = Math.floor(s / 60);
            s -= mins * 60;
            const parts = [];
            if (days) parts.push(`${days}d`);
            if (hours) parts.push(`${hours}h`);
            if (mins) parts.push(`${mins}m`);
            parts.push(`${s}s`);
            return parts.join(' ');
        },
        lastErrorMessage(job) {
            if (!job || !Array.isArray(job.errors) || job.errors.length === 0) return '';
            const last = job.errors[job.errors.length - 1];
            return last?.message || '';
        },
        // -------------------------------------------------------------
        // Modal helpers
        showModal(opts) {
            // Close any existing modal first
            this.closeModal();
            const {title: titleText, bodyHtml, bodyText} = opts || {};

            this.modalType = 'generic';
            this.modalJob = null;
            this.modalTitle = titleText || '';
            this.modalBodyHtml = bodyHtml || '';
            this.modalBodyText = bodyText || '';
            this.modalVisible = true;
        },
        openErrorTrace(job) {
            // Close any existing modal first
            this.closeModal();
            this.modalType = 'trace';
            this.modalJob = job || null;
            const id = job && job.id ? `#${job.id}` : '';
            this.modalTitle = `Failed Job ${id} — Error Trace`;
            // Prepare escaped JSON/trace content inside a <pre> using HTML-escaped text
            const content = this.pretty((job && (job.errors || job)) || '');
            this.modalBodyHtml = `<pre>${this.escapeHtml(String(content))}</pre>`;
            this.modalBodyText = '';
            this.modalVisible = true;
        },
        closeModal() {
            this.modalVisible = false;
            this.modalTitle = '';
            this.modalBodyHtml = '';
            this.modalBodyText = '';
            this.modalType = 'generic';
            this.modalJob = null;
        },

        // -------------------------------------------------------------
        // Retry failed job
        async retryJob(job) {
            if (!job || !job.id) {
                this.showModal({
                    title: 'Retry Failed',
                    bodyText: 'Invalid job specified.'
                });
                return;
            }

            const jobId = job.id;
            this.$set ? this.$set(this.retrying, jobId, true) : (this.retrying[jobId] = true);
            try {
                const res = await fetch(this.queueApi.overview.retry(), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({job_id: jobId}),
                });

                // Try parse json response
                let json = null;
                try {
                    json = await res.clone().json();
                } catch (_) { /* ignore */
                }

                if (!res.ok) {
                    const message = (json && (json.error?.message || json.error))
                        || res.statusText
                        || `HTTP ${res.status}`;
                    this.showModal({
                        title: 'Retry Failed',
                        bodyText: String(message || 'An unknown error occurred while retrying the job.')
                    });
                    return;
                }

                // Success — remove from failed list
                this.failed = (this.failed || []).filter((j) => j.id !== jobId);
                // Show success modal
                this.showModal({
                    title: 'Retry Successful',
                    bodyText: `Job #${jobId} has been queued for retry.`
                });

            } catch (e) {
                const message = e?.message ? e.message : String(e);
                this.showModal({
                    title: 'Retry Failed',
                    bodyText: String(message || 'An unknown error occurred while retrying the job.')
                });
            } finally {
                if (this.$delete) {
                    this.$delete(this.retrying, jobId);
                } else {
                    delete this.retrying[jobId];
                }
            }
        },

        // -------------------------------------------------------------
        // Utils
        escapeHtml(str) {
            if (str == null) return '';
            return String(str).replace(/[&<>"']/g, (ch) => {
                switch (ch) {
                    case '&': return '&amp;';
                    case '<': return '&lt;';
                    case '>': return '&gt;';
                    case '"': return '&quot;';
                    case "'": return '&#39;';
                    default: return ch;
                }
            });
        }
    },
}
</script>

<style lang="scss" scoped>
.nails-module-queue-overview {

    .kpis {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin: 10px 0 20px;
    }

    pre {
        padding: 0.5rem;
        border: 1px solid #cccccc;
    }

    code {
        padding: 0.25rem;
    }

    .mono {
        padding: 0.5rem;
        font-family: monospace;
        font-size: 1em;
        line-height: 1.428571429;
        color: #333333;
        background-color: #f5f5f5;
        border: 1px solid #cccccc;
        border-radius: 4px;
    }

    .alert {
        strong {
            display: block;
            margin-bottom: 0.5rem;
        }
    }

    table {
        .alert {
            padding: 0.25rem;
            margin: 0 0 0.25rem 0;

            &-default {
                background: #efefef;
                border-color: #e3e3e3;
                color: #6a6a6a;
            }

            .badge {
                float: right;
                position: relative;
                top: 3px;
            }
        }
    }
}
</style>
