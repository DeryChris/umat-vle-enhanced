// chart.umd.js — Placeholder for Chart.js UMD build
//
// Download the actual Chart.js UMD bundle from:
// https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js
//
// Then replace the contents of this file with the downloaded file.
//
// NOTE: This stub exists so Moodle's AMD module loader can resolve
// the dependency. It will throw at runtime until replaced with the
// real Chart.js UMD bundle.

if (typeof define === 'function' && define.amd) {
    define([], function () {
        'use strict';
        throw new Error(
            'Chart.js UMD bundle not installed. Download from ' +
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js ' +
            'and replace ' + window.location.pathname.match(/.*\/amd\/src\//)[0] + 'chart.umd.js'
        );
    });
}
