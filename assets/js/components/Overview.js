import OverviewVue from '../components-vue/Overview.vue';

class Overview {
    constructor(adminController) {
        this.adminController = adminController;
        this.adminController.log('Constructing');
        this.initializeWhenVueReady();
    }

    async initializeWhenVueReady() {

        const mountPoint = document.querySelector('#nails-module-queue-overview');
        if (!mountPoint) {
            this.adminController.error('Mount point not found');
            return;
        }

        try {

            this.adminController.log('Mounting Overview');
            const mod = await import('vue');
            const app = mod.createApp(OverviewVue, {});
            app.mount('#nails-module-queue-overview');

        } catch (error) {
            this.adminController.log('Error initializing Vue:', error);
        }
    }
}

export default Overview;
