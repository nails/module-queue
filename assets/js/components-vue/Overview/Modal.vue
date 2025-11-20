<template>
    <div
        v-if="show"
        class="modal modal--processed"
        :class="{'modal--open': show}"
        @click.self="onRequestClose"
    >
        <div class="modal__inner">
            <div class="modal__close" @click="onRequestClose">&times;</div>
            <div class="modal__title" v-if="title">{{ title }}</div>
            <div class="modal__body">
                <div v-if="bodyHtml" v-html="String(bodyHtml)"></div>
                <p v-else-if="bodyText">{{ String(bodyText) }}</p>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: 'Modal',
    props: {
        show: {type: Boolean, default: false},
        title: {type: String, default: ''},
        bodyHtml: {type: [String], default: ''},
        bodyText: {type: [String], default: ''},
        closeOnEsc: {type: Boolean, default: true},
    },
    data() {
        return {
            escHandler: null,
        };
    },
    computed: {},
    watch: {
        show: {
            immediate: true,
            handler(val) {
                this.toggleBodyScroll(val);
                this.toggleEscHandler(val);
            }
        }
    },
    beforeUnmount() {
        // Clean up just in case
        this.toggleEscHandler(false);
        this.toggleBodyScroll(false);
    },
    methods: {
        onRequestClose() {
            this.$emit('close');
        },
        toggleBodyScroll(enabled) {
            try {
                const cls = 'noscroll';
                if (enabled) {
                    document.body.classList.add(cls);
                } else {
                    document.body.classList.remove(cls);
                }
            } catch (_) { /* noop */ }
        },
        toggleEscHandler(enabled) {
            if (!this.closeOnEsc) return;
            if (enabled) {
                if (!this.escHandler) {
                    this.escHandler = (e) => {
                        if (e.key === 'Escape') {
                            this.onRequestClose();
                            e.preventDefault();
                            e.stopPropagation();
                        }
                    };
                    document.addEventListener('keyup', this.escHandler);
                }
            } else {
                if (this.escHandler) {
                    document.removeEventListener('keyup', this.escHandler);
                    this.escHandler = null;
                }
            }
        },
    }
}
</script>

<style scoped>
/* No additional styles; relies on existing admin modal styles via class names */
</style>
