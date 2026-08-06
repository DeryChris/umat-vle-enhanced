// This file wraps ECharts for the lecturer analytics dashboard.
//
// Strategy: local-first with layered fallbacks. The plugin ships ECharts,
// so the dashboard must render charts even when the machine is offline or
// the CDN is blocked/unreachable (e.g. campus networks).
//
//   1. If ECharts is already loaded globally (window.echarts), use it.
//   2. Otherwise inject the VENDORED GLOBAL BUILD (amd/build/echarts.global.js)
//      with a plain <script> tag - no RequireJS involved, so it cannot be
//      affected by AMD module timeouts, config quirks or module-mapping issues.
//   3. If that fails, fall back to the CDN build (jsdelivr).
//   4. Last resort: the RequireJS-named UMD module (local_umat_ai/echarts.umd).
//
// Every step logs its outcome to the console so chart-load failures are
// diagnosable. The module resolves to the ECharts namespace (the object with
// `init`, `graphic`, `registerTheme`, etc.), so consumers do:
//     require(['local_umat_ai/echarts'], function(echartsPromise) {
//         echartsPromise.then(function(echarts) { ... });
//     });
define(['core/config'], function(Config) {
    'use strict';

    var GLOBAL_BUILD = 'local/umat_ai/amd/build/echarts.global.js';
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

    function loadGlobalBuild() {
        // Plain script tag: no AMD dependency at all.
        var src = (Config.wwwroot || '') + '/' + GLOBAL_BUILD;
        return loadScript(src, 15000).then(function(ECharts) {
            if (ECharts && ECharts.init) {
                return ECharts;
            }
            throw new Error('Global build loaded but window.echarts missing');
        });
    }

    function loadCdn() {
        return loadScript(CDN_URL, 8000).then(function(ECharts) {
            if (ECharts && ECharts.init) {
                return ECharts;
            }
            throw new Error('CDN loaded but window.echarts missing');
        });
    }

    function loadFallbackModule() {
        return new Promise(function(resolve, reject) {
            require([FALLBACK_MODULE], function(ECharts) {
                if (ECharts && ECharts.init) {
                    resolve(ECharts);
                } else {
                    reject(new Error('UMD module resolved without echarts.init'));
                }
            }, function(err) {
                reject(new Error('ECharts fallback module load failed: ' + err));
            });
        });
    }

    return new Promise(function(resolve, reject) {
        if (window.echarts && window.echarts.init) {
            console.log('[echarts] already loaded globally');
            resolve(window.echarts);
            return;
        }
        loadGlobalBuild()
            .then(resolve)
            .catch(function(err1) {
                console.warn('[echarts] global build failed: ' + err1.message);
                return loadCdn().then(resolve).catch(function(err2) {
                    console.warn('[echarts] CDN failed: ' + err2.message);
                    return loadFallbackModule().then(resolve, reject);
                });
            });
    });
});
