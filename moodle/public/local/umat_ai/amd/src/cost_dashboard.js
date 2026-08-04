// AMD module: local_umat_ai/cost_dashboard
// Transcription Cost Dashboard — fetches cost data from the web service
// and renders interactive charts using Chart.js.
define(['core/ajax', 'local_umat_ai/chart.umd'], function(A, Chart) {
    'use strict';

    // ─── State ───────────────────────────────────────── //
    var _courseId = 0;
    var _data = null;

    // ─── Helpers ─────────────────────────────────────── //
    function _esc(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function _fmtCurrency(cents) {
        var v = parseFloat(cents) || 0;
        if (v >= 1) return '$' + v.toFixed(2);
        if (v >= 0.001) return '$' + v.toFixed(4);
        if (v > 0) return '$' + v.toFixed(6);
        return '$0.00';
    }

    function _fmtDuration(secs) {
        secs = parseFloat(secs) || 0;
        var h = Math.floor(secs / 3600);
        var m = Math.floor((secs % 3600) / 60);
        if (h > 0) return h + 'h ' + m + 'm';
        return m + 'm';
    }

    function _fmtDurationShort(secs) {
        secs = parseFloat(secs) || 0;
        var h = Math.floor(secs / 3600);
        var m = Math.floor((secs % 3600) / 60);
        if (h > 0) return h + 'h ' + m + 'm';
        return m + 'm';
    }

    function _providerColor(prov) {
        prov = (prov || '').toLowerCase();
        if (prov === 'openai') return '#4ade80';
        if (prov === 'openrouter') return '#fbbf24';
        if (prov === 'local') return '#9ca3af';
        return '#6b7280';
    }

    function _providerBadgeHtml(prov) {
        prov = (prov || '').toLowerCase();
        var cls = 'prov-unknown';
        if (prov === 'openai') cls = 'prov-openai';
        else if (prov === 'openrouter') cls = 'prov-openrouter';
        else if (prov === 'local') cls = 'prov-local';
        return '<span class="umat-cd-prov-badge ' + cls + '"><span class="material-symbols-outlined" style="font-size:14px;">'
            + ((prov === 'openai' || prov === 'openrouter') ? 'auto_awesome' : 'mic') + '</span>'
            + _esc(prov) + '</span>';
    }

    // ─── Fetch data ──────────────────────────────────── //
    function _loadData(courseId, callback) {
        _courseId = courseId || 0;
        A.call([{
            methodname: 'local_umat_ai_get_transcription_costs',
            args: { courseid: _courseId }
        }])[0].done(function(resp) {
            _data = resp;
            if (typeof callback === 'function') callback(null, resp);
        }).fail(function(err) {
            if (typeof callback === 'function') callback(err || 'Failed to load cost data');
        });
    }

    // ─── Render KPI cards ────────────────────────────── //
    function _renderKpis(data) {
        var totalCost = parseFloat(data.total_cost) || 0;
        var totalRecs = parseInt(data.total_recordings) || 0;
        var totalDur = parseFloat(data.total_duration_secs) || 0;
        var transcribed = parseInt(data.transcribed_count) || 0;
        var avgCost = totalRecs > 0 ? totalCost / totalRecs : 0;

        document.getElementById('cd-kpi-total-cost').textContent = _fmtCurrency(totalCost);
        document.getElementById('cd-kpi-recordings').textContent = totalRecs;
        document.getElementById('cd-kpi-transcribed-of').textContent = transcribed + ' with provider data';
        document.getElementById('cd-kpi-duration').textContent = _fmtDurationShort(totalDur);
        document.getElementById('cd-kpi-avg-cost').textContent = _fmtCurrency(avgCost);
    }

    // ─── Chart: Course costs bar chart ───────────────── //
    function _renderCourseChart(data) {
        var canvas = document.getElementById('cd-chart-course-costs');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var courses = data.per_course || [];
        if (!courses.length) {
            canvas.parentNode.innerHTML = '<div class="umat-cd-empty"><span class="material-symbols-outlined">bar_chart</span><p>No course cost data available.</p></div>';
            return;
        }

        // Limit to top 10 for readability.
        var top = courses.slice(0, 10);
        var labels = top.map(function(c) { return c.course_name.length > 25 ? c.course_name.substring(0, 22) + '...' : c.course_name; });
        var costs = top.map(function(c) { return parseFloat(c.total_cost) || 0; });
        var colors = top.map(function() { return '#006b2f'; });

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Total Cost (USD)',
                    data: costs,
                    backgroundColor: 'rgba(0,107,47,0.7)',
                    borderColor: 'rgba(0,107,47,1)',
                    borderWidth: 1,
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) { return '$' + ctx.parsed.y.toFixed(4); }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(v) { return '$' + v.toFixed(2); }
                        }
                    },
                    x: {
                        ticks: { maxRotation: 30 }
                    }
                }
            }
        });
    }

    // ─── Chart: Provider share donut ────────────────── //
    function _renderProviderChart(data) {
        var canvas = document.getElementById('cd-chart-providers');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var provs = data.provider_breakdown || [];
        if (!provs.length) {
            canvas.parentNode.innerHTML = '<div class="umat-cd-empty"><span class="material-symbols-outlined">donut_small</span><p>No provider data available.</p></div>';
            return;
        }

        var labels = provs.map(function(p) { return p.provider || 'unknown'; });
        var counts = provs.map(function(p) { return parseInt(p.recording_count) || 0; });
        var colors = provs.map(function(p) { return _providerColor(p.provider); });

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: counts,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#ffffff',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 12, usePointStyle: true, font: { size: 11 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                var pct = total > 0 ? (ctx.parsed / total * 100).toFixed(1) : 0;
                                return ctx.label + ': ' + ctx.parsed + ' recordings (' + pct + '%)';
                            }
                        }
                    }
                },
                cutout: '65%',
            }
        });
    }

    // ─── Chart: Monthly trend ───────────────────────── //
    function _renderMonthlyChart(data) {
        var canvas = document.getElementById('cd-chart-monthly');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var months = data.monthly_trend || [];
        if (!months.length) {
            canvas.parentNode.innerHTML = '<div class="umat-cd-empty"><span class="material-symbols-outlined">trending_up</span><p>No monthly data available yet.</p></div>';
            return;
        }

        var labels = months.map(function(m) { return m.month; });
        var costs = months.map(function(m) { return parseFloat(m.total_cost) || 0; });

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Monthly Cost',
                    data: costs,
                    borderColor: '#006b2f',
                    backgroundColor: 'rgba(0,107,47,0.1)',
                    fill: true,
                    tension: 0.3,
                    pointBackgroundColor: '#006b2f',
                    pointRadius: 3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) { return '$' + ctx.parsed.y.toFixed(4); }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(v) { return '$' + v.toFixed(2); }
                        }
                    }
                }
            }
        });
    }

    // ─── Full monthly trend chart (trend tab) ───────── //
    function _renderMonthlyFullChart(data) {
        var canvas = document.getElementById('cd-chart-monthly-full');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var months = data.monthly_trend || [];
        if (!months.length) {
            canvas.parentNode.innerHTML = '<div class="umat-cd-empty"><span class="material-symbols-outlined">calendar_month</span><p>No monthly data.</p></div>';
            return;
        }

        var labels = months.map(function(m) { return m.month; });
        var costs = months.map(function(m) { return parseFloat(m.total_cost) || 0; });
        var counts = months.map(function(m) { return parseInt(m.recording_count) || 0; });

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Cost (USD)',
                        data: costs,
                        backgroundColor: 'rgba(0,107,47,0.7)',
                        borderColor: 'rgba(0,107,47,1)',
                        borderWidth: 1,
                        borderRadius: 4,
                        yAxisID: 'y',
                        order: 2,
                    },
                    {
                        label: 'Recordings',
                        data: counts,
                        type: 'line',
                        borderColor: '#7c3aed',
                        backgroundColor: 'rgba(124,58,237,0.1)',
                        pointBackgroundColor: '#7c3aed',
                        pointRadius: 3,
                        tension: 0.3,
                        fill: false,
                        yAxisID: 'y1',
                        order: 1,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                if (ctx.datasetIndex === 0) return 'Cost: $' + ctx.parsed.y.toFixed(4);
                                return 'Recordings: ' + ctx.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        ticks: { callback: function(v) { return '$' + v.toFixed(2); } }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: { precision: 0 }
                    }
                }
            }
        });
    }

    // ─── Provider cost share donut ──────────────────── //
    function _renderProviderCostChart(data) {
        var canvas = document.getElementById('cd-chart-provider-cost');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var provs = data.provider_breakdown || [];
        if (!provs.length) {
            canvas.parentNode.innerHTML = '<div class="umat-cd-empty"><span class="material-symbols-outlined">donut_small</span><p>No provider data.</p></div>';
            return;
        }

        var labels = provs.map(function(p) { return p.provider || 'unknown'; });
        var costs = provs.map(function(p) { return parseFloat(p.total_cost) || 0; });
        var colors = provs.map(function(p) { return _providerColor(p.provider); });

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: costs,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#ffffff',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 12, usePointStyle: true, font: { size: 11 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                var pct = total > 0 ? (ctx.parsed / total * 100).toFixed(1) : 0;
                                return ctx.label + ': $' + ctx.parsed.toFixed(4) + ' (' + pct + '%)';
                            }
                        }
                    }
                },
                cutout: '65%',
            }
        });
    }

    // ─── Course table ───────────────────────────────── //
    function _renderCourseTable(data) {
        var tbody = document.getElementById('cd-course-tbody');
        if (!tbody) return;
        var courses = data.per_course || [];
        if (!courses.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="umat-cd-empty"><span class="material-symbols-outlined">menu_book</span><p>No course data available.</p></td></tr>';
            return;
        }

        tbody.innerHTML = courses.map(function(c) {
            var cost = parseFloat(c.total_cost) || 0;
            var avg = parseFloat(c.avg_cost_per_recording) || 0;
            var dur = parseFloat(c.total_duration_secs) || 0;
            return '<tr>' +
                '<td><strong>' + _esc(c.course_name) + '</strong></td>' +
                '<td>' + (parseInt(c.recording_count) || 0) + '</td>' +
                '<td>' + (parseInt(c.transcribed_count) || 0) + '</td>' +
                '<td class="umat-cd-cost">' + _fmtCurrency(cost) + '</td>' +
                '<td>' + _fmtCurrency(avg) + '</td>' +
                '<td class="umat-cd-duration">' + _fmtDuration(dur) + '</td>' +
                '<td>' + _providerBadgeHtml(c.preferred_provider || '') + '</td>' +
                '</tr>';
        }).join('');
    }

    // ─── Monthly table ──────────────────────────────── //
    function _renderMonthlyTable(data) {
        var tbody = document.getElementById('cd-monthly-tbody');
        if (!tbody) return;
        var months = data.monthly_trend || [];
        if (!months.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="umat-cd-empty"><span class="material-symbols-outlined">calendar_month</span><p>No monthly data.</p></td></tr>';
            return;
        }

        tbody.innerHTML = months.map(function(m) {
            var cost = parseFloat(m.total_cost) || 0;
            var dur = parseFloat(m.total_duration_secs) || 0;
            return '<tr>' +
                '<td><strong>' + _esc(m.month) + '</strong></td>' +
                '<td>' + (parseInt(m.recording_count) || 0) + '</td>' +
                '<td class="umat-cd-cost">' + _fmtCurrency(cost) + '</td>' +
                '<td class="umat-cd-duration">' + _fmtDuration(dur) + '</td>' +
                '</tr>';
        }).join('');
    }

    // ─── Provider table ─────────────────────────────── //
    function _renderProviderTable(data) {
        var tbody = document.getElementById('cd-provider-tbody');
        if (!tbody) return;
        var provs = data.provider_breakdown || [];
        if (!provs.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="umat-cd-empty"><span class="material-symbols-outlined">auto_awesome</span><p>No provider data.</p></td></tr>';
            return;
        }

        tbody.innerHTML = provs.map(function(p) {
            var cost = parseFloat(p.total_cost) || 0;
            var dur = parseFloat(p.total_duration_secs) || 0;
            var count = parseInt(p.recording_count) || 0;
            var avg = count > 0 ? cost / count : 0;
            return '<tr>' +
                '<td>' + _providerBadgeHtml(p.provider) + '</td>' +
                '<td>' + count + '</td>' +
                '<td class="umat-cd-cost">' + _fmtCurrency(cost) + '</td>' +
                '<td class="umat-cd-duration">' + _fmtDuration(dur) + '</td>' +
                '<td>' + _fmtCurrency(avg) + '</td>' +
                '</tr>';
        }).join('');
    }

    // ─── Full render ────────────────────────────────── //
    function _renderAll(data) {
        if (!data) {
            document.getElementById('cd-loading').style.display = 'none';
            document.getElementById('cd-content').style.display = '';
            document.getElementById('cd-content').innerHTML = '<div class="umat-cd-empty"><span class="material-symbols-outlined">error_outline</span><p>No cost data available.</p></div>';
            return;
        }

        _renderKpis(data);
        _renderCourseChart(data);
        _renderProviderChart(data);
        _renderMonthlyChart(data);
        _renderMonthlyFullChart(data);
        _renderProviderCostChart(data);
        _renderCourseTable(data);
        _renderMonthlyTable(data);
        _renderProviderTable(data);
    }

    // ─── Tab switching ──────────────────────────────── //
    function _setupTabs() {
        var tabs = document.getElementById('cd-tabs');
        if (!tabs) return;
        tabs.addEventListener('click', function(e) {
            var btn = e.target.closest('.umat-cd-tab');
            if (!btn) return;
            var tabName = btn.dataset.tab;
            if (!tabName) return;

            // Update tab active state.
            tabs.querySelectorAll('.umat-cd-tab').forEach(function(t) { t.classList.remove('active'); });
            btn.classList.add('active');

            // Show corresponding panel.
            document.querySelectorAll('.cd-tab-panel').forEach(function(p) { p.style.display = 'none'; });
            var panel = document.getElementById('cd-panel-' + tabName);
            if (panel) panel.style.display = '';
        });
    }

    // ─── Course selector ────────────────────────────── //
    function _setupCourseSel() {
        var sel = document.getElementById('cd-course-sel');
        if (!sel) return;
        sel.addEventListener('change', function() {
            var cid = parseInt(this.value) || 0;
            // Reload data.
            document.getElementById('cd-loading').style.display = 'flex';
            document.getElementById('cd-content').style.display = 'none';
            _loadData(cid, function(err, data) {
                document.getElementById('cd-loading').style.display = 'none';
                if (err) {
                    document.getElementById('cd-content').innerHTML = '<div class="umat-cd-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load cost data: ' + _esc(err) + '</p></div>';
                    document.getElementById('cd-content').style.display = '';
                    return;
                }
                document.getElementById('cd-content').style.display = '';
                _renderAll(data);
            });
        });
    }

    // ─── Init ───────────────────────────────────────── //
    function init(courseId) {
        _courseId = courseId || 0;
        _setupTabs();
        _setupCourseSel();

        _loadData(_courseId, function(err, data) {
            document.getElementById('cd-loading').style.display = 'none';
            if (err) {
                document.getElementById('cd-content').innerHTML = '<div class="umat-cd-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load transcription cost data: ' + _esc(err) + '</p></div>';
                document.getElementById('cd-content').style.display = '';
                console.error('[cost_dashboard] Error:', err);
                return;
            }
            document.getElementById('cd-content').style.display = '';
            _renderAll(data);
        });
    }

    return { init: init };
});
