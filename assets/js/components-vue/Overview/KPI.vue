<template>
    <div class="kpi" :class="{'hint--top': !!hint}" :aria-label="hint || null">
        <div class="kpi__label">{{ label }}</div>
        <div class="kpi__value">{{ formattedValue }}</div>
    </div>
</template>

<script>

/**
 * KPI block
 * Renders a label and a value with an optional hint, optionally put through a formatter depending on its type
 *
 * properties:
 *  - label
 *  - value
 *  - hint
 *  - type
 *
 * formatters:
 * - default:
 *   render as is
 *
 * - number:
 *   expect value as an int, render as a formatted number, e.g., 1,000 not 1000
 *
 * - age:
 *   expect value as a Unix timestamp, render a human friendly "age" based on distance from now,
 *   e.g., 2 days, 1 month, and 32 seconds - this type of block should automatically tick and
 *   update the value without a reload
 *
 * - duration:
 *   expect value seconds as a float, render as a formatted number to 4 decimal places followed by unit (s)
 */

export default {
    name: 'KPI',
    props: {
        label: {
            type: String,
            required: true
        },
        value: {
            type: [Number, String],
            required: false,
            default: null
        },
        hint: {
            type: String,
            default: '',
            required: false
        },
        type: {
            type: String,
            default: '',
            required: false
        }
    },
    data() {
        return {
            tickTimer: null,
            nowTs: Math.floor(Date.now() / 1000),
        }
    },
    computed: {
        formattedValue() {
            const type = (this.type || '').toLowerCase();
            if (type === 'number') {
                return this.formatNumber(this.value);
            }
            if (type === 'duration') {
                const n = Number(this.value);
                if (!Number.isFinite(n)) return '—';
                return `${n.toFixed(4)}s`;
            }
            if (type === 'age') {
                const unixTs = Number(this.value);
                if (!Number.isFinite(unixTs)) return '—';
                const secs = Math.max(0, this.nowTs - unixTs);
                return this.formatAgeSeconds(secs);
            }
            // default passthrough
            if (this.value === null || this.value === undefined) return '—';
            return String(this.value);
        }
    },
    created() {
        // Only tick if age; keep cheap for others
        if ((this.type || '').toLowerCase() === 'age') {
            this.tickTimer = setInterval(() => {
                this.nowTs = Math.floor(Date.now() / 1000);
            }, 1000);
        }
    },
    beforeUnmount() {
        if (this.tickTimer) {
            clearInterval(this.tickTimer);
        }
    },
    methods: {
        formatNumber(v) {
            if (v === null || v === undefined) return '—';
            if (typeof v === 'number') return v.toLocaleString();
            const n = Number(v);
            return Number.isFinite(n) ? n.toLocaleString() : String(v);
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
    }
}
</script>

<style lang="scss" scoped>
.kpi {
    border: 1px solid #e3e3e3;
    border-radius: 6px;
    padding: 15px 25px;
    min-width: 150px;
    background: #ffffff;

    &__label {
        font-size: 12px;
        color: #666666;
    }

    &__value {
        font-size: 20px;
        font-weight: 600;
    }
}
</style>
