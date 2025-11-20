'use strict';

import Overview from './components/Overview.js';

(function() {
    window.NAILS.ADMIN.registerPlugin(
        'nails/module-queue',
        'Overview',
        function(controller) {
            return new Overview(controller);
        }
    );
})();
