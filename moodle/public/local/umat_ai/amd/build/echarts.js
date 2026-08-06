// This file wraps ECharts for the lecturer analytics dashboard.
//
// Strategy: CDN-first with a local offline fallback (deployed online,
// but the dashboard must still render when the machine is offline).
//
//  1. If ECharts is already loaded globally (window.echarts), use it.
//  2. Otherwise inject the CDN build (jsdelivr) and resolve on load.
//  3. If the CDN fails (offline / blocked), fall back to the vendored
//     local copy (local_umat_ai/echarts.umd), which ships in the plugin.
//
// The module resolves to the ECharts namespace (the object with
// `init`, `graphic`, `registerTheme`, etc.), so consumers do:
//     require(['local_umat_ai/echarts'], function(echartsPromise) {
//         echartsPromise.then(function(echarts) { ... });
//     });
define(['core/config'], function(Config) {
    'use strict';

    var CDN_URL = 'https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js';
    var FALLBACK_MODULE = 'local_umat_ai/echarts.umd';

    function loadScript(src, timeoutMs) {
        return new Promise(function(resolve, reject) {
            var script = document.createElement('script');
            script.src = src;
            script.async = true;
            var timer = null;
            script.onload = function() {
                if (timer) { clearTimeout(timer); }
                resolve(window.echarts);
            };
            script.onerror = function() {
                if (timer) { clearTimeout(timer); }
                reject(new Error('Failed to load ECharts from ' + src));
            };
            if (timeoutMs) {
                timer = setTimeout(function() {
                    script.onerror(null);
                }, timeoutMs);
            }
            document.head.appendChild(script);
        });
    }

    function loadFallback() {
        return new Promise(function(resolve, reject) {
            require([FALLBACK_MODULE], function(ECharts) {
                resolve(ECharts);
            }, function(err) {
                reject(new Error('ECharts fallback load failed: ' + err));
            });
        });
    }

    return new Promise(function(resolve, reject) {
        if (window.echarts) {
            resolve(window.echarts);
            return;
        }
        // CDN first, with a generous timeout for slow networks.
        loadScript(CDN_URL, 8000)
            .then(function(ECharts) {
                if (ECharts && ECharts.init) {
                    resolve(ECharts);
                } else {
                    return loadFallback().then(resolve, reject);
                }
            })
            .catch(function() {
                // Offline or blocked CDN: use the vendored copy.
                loadFallback().then(resolve, reject);
            });
    });
});
