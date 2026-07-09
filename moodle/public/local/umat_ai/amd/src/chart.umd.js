// Named AMD wrapper for Chart.js UMD build (v4.5.0)
// Load the actual chart.umd.min.js in production.
// This stub exists so Moodle's AMD module loader can resolve the dependency.
define("local_umat_ai/chart.umd", [], function() {
    'use strict';
    var d = define;
    define = void 0;
    try {
        // Chart.js is loaded via chart.umd.min.js — this stub is only used
        // when the minified version is not available (dev mode)
        if (typeof window.Chart !== 'undefined') return window.Chart;
        throw new Error(
            'Chart.js not loaded. Ensure amd/build/chart.umd.min.js exists.'
        );
    } finally {
        define = d;
    }
});
