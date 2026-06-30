define(['core/ajax', 'core/str', 'local_umat_ai/chart', 'core/notification', 'local_umat_ai/umatshared'], function(Ajax, Str, Chart, Notification, Shared) {
    'use strict';

    var cid = 0;
    var chartInstances = {};
    var studentData = [];
    var selectedStudentIds = {};
    var activeStream = null;

    function streamConfig() {
        if (window._umatChatStream) {
            return window._umatChatStream;
        }
        var root = document.querySelector('.sd-dashboard');
        if (root && root.dataset.streamUrl) {
            return { url: root.dataset.streamUrl, sesskey: root.dataset.sesskey || '' };
        }
        return { url: '', sesskey: '' };
    }

    function streamQuestion(question, targetId, onStart, onDone) {
        var cfg = streamConfig();
        if (!cfg.url || !cid) {
            Notification.addNotification({ message: 'Open a course before asking AI.', type: 'warning' });
            return null;
        }
        if (activeStream && activeStream.abort) {
            activeStream.abort();
        }
        if (typeof onStart === 'function') {
            onStart();
        }
        activeStream = Shared._umatStreamInline({
            url: cfg.url,
            sesskey: cfg.sesskey,
            courseid: cid,
            question: question,
            session_key: 'sd_nlq_' + cid,
            targetId: targetId,
            onDone: function() {
                activeStream = null;
                if (typeof onDone === 'function') {
                    onDone();
                }
            },
            onError: function(err) {
                activeStream = null;
                var el = document.getElementById(targetId);
                if (el) {
                    el.classList.remove('is-streaming');
                    el.innerHTML = '<p class="sd-stream-error">' + escapeHtml(err.message || 'AI unavailable.') + '</p>';
                }
                if (typeof onDone === 'function') {
                    onDone();
                }
            }
        });
        return activeStream;
    }

    function init(courseId) {
        cid = parseInt(courseId) || 0;
        if (!cid) {
            showSkeleton(false);
            var dashboard = document.querySelector('.sd-dashboard');
            if (dashboard) {
                dashboard.innerHTML = '<div class="umat-empty" style="padding:60px 20px;text-align:center;">' +
                    '<span class="material-symbols-outlined" style="font-size:48px;color:var(--u-olv);">menu_book</span>' +
                    '<p style="color:var(--u-ol);font-size:15px;margin-top:12px;">Select a course using the button above to view the struggle dashboard.</p>' +
                    '</div>';
            }
            return;
        }
        bindNlqBar();
        showSkeleton(true);
        loadData();
    }

    function showSkeleton(show) {
        var sk = document.getElementById('sd-skeleton');
        if (sk) sk.style.display = show ? 'flex' : 'none';
    }

    function loadData() {
        Ajax.call([{
            methodname: 'local_umat_ai_get_struggle_dashboard_data',
            args: {courseid: cid, days: 60}
        }])[0].done(function(data) {
            renderKpiRibbon(data.kpis);
            renderScatterPlot(data.scatter_plot_data);
            renderTopicMastery(data.topic_mastery);
            renderStudentTable(data.at_risk_students);
            renderMaterialHealth(data.material_health);
            renderQuestionsFeed(data.common_questions);
            renderTopicsFeed(data.scatter_plot_data);
            renderHealthReport(data.course_health);
            showSkeleton(false);
        }).fail(function() {
            showSkeleton(false);
            Notification.addNotification({
                message: 'Failed to load struggle dashboard data.',
                type: 'error'
            });
        });
    }

    /* ── KPI Ribbon ── */
    function renderKpiRibbon(kpis) {
        if (!kpis) return;

        var pctEl = document.getElementById('sd-eng-pct');
        if (pctEl) pctEl.textContent = kpis.engagement_score + '%';

        // Engagement sparkline
        var sparkCanvas = document.getElementById('sd-eng-sparkline');
        if (sparkCanvas && kpis.engagement_trend && kpis.engagement_trend.length) {
            destroyChart('sparkline');
            chartInstances.sparkline = new Chart(sparkCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: kpis.engagement_trend.map(function(v, i) {
                        var d = new Date();
                        d.setDate(d.getDate() - (kpis.engagement_trend.length - 1 - i));
                        return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric' });
                    }),
                    datasets: [{
                        data: kpis.engagement_trend,
                        borderColor: '#006b2f',
                        backgroundColor: 'rgba(0,107,47,0.08)',
                        borderWidth: 2,
                        fill: true,
                        pointRadius: 0,
                        tension: 0.4,
                    }]
                },
                options: {
                    responsive: false,
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    scales: { x: { display: false }, y: { display: false, min: 0, max: 100 } },
                    animation: false,
                }
            });
            // Delta
            var trend = kpis.engagement_trend;
            var delta = trend.length > 1 ? (trend[trend.length - 1] - trend[0]) : 0;
            var deltaEl = document.getElementById('sd-eng-delta');
            if (deltaEl) {
                deltaEl.textContent = (delta >= 0 ? '+ ' : '- ') + Math.abs(Math.round(delta)) + '% vs. Last Week';
                deltaEl.className = 'sd-kpi-delta ' + (delta >= 0 ? 'positive' : 'negative');
            }
        }

        // At-risk count
        var riskEl = document.getElementById('sd-atrisk-count');
        if (riskEl) riskEl.textContent = kpis.at_risk_count || 0;

        // Avatars
        var avatarStack = document.getElementById('sd-atrisk-avatars');
        if (avatarStack && kpis.at_risk_avatars) {
            avatarStack.innerHTML = kpis.at_risk_avatars.map(function(a) {
                return a.avatar || '<div class="sd-avatar-sm" title="' + escapeHtml(a.name) + '">' + escapeHtml(a.name.charAt(0)) + '</div>';
            }).join('');
        }

        // Topic gauge
        if (kpis.top_topic) {
            var topicName = document.getElementById('sd-topic-name');
            if (topicName) topicName.textContent = kpis.top_topic.name;

            var topicInsight = document.getElementById('sd-topic-insight');
            if (topicInsight) topicInsight.textContent = kpis.top_topic.ai_insight || '';

            var gaugeCanvas = document.getElementById('sd-topic-gauge');
            if (gaugeCanvas) {
                destroyChart('gauge');
                var val = Math.min(100, Math.max(0, kpis.top_topic.gauge_value || 0));
                chartInstances.gauge = new Chart(gaugeCanvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        datasets: [{
                            data: [val, 100 - val],
                            backgroundColor: val >= 70 ? '#a5304d' : val >= 40 ? '#f59e0b' : '#006b2f',
                            borderWidth: 0,
                            circumference: 180,
                            rotation: 270,
                        }]
                    },
                    options: {
                        responsive: false,
                        cutout: '75%',
                        plugins: { legend: { display: false }, tooltip: { enabled: false } },
                        animation: false,
                    }
                });
            }
        }

        // Top material
        if (kpis.top_material) {
            var matName = document.getElementById('sd-mat-name');
            if (matName) matName.textContent = kpis.top_material.name;

            var weekdayCanvas = document.getElementById('sd-mat-weekday-chart');
            if (weekdayCanvas && kpis.top_material.weekday_volume) {
                destroyChart('weekday');
                chartInstances.weekday = new Chart(weekdayCanvas.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                        datasets: [{
                            data: kpis.top_material.weekday_volume,
                            backgroundColor: '#a5304d',
                            borderRadius: 2,
                            borderWidth: 0,
                            barThickness: 6,
                        }]
                    },
                    options: {
                        responsive: false,
                        plugins: { legend: { display: false }, tooltip: { enabled: false } },
                        scales: {
                            x: { display: false },
                            y: { display: false, beginAtZero: true }
                        },
                        animation: false,
                    }
                });
            }
        }
    }

    /* ── Scatter Plot ── */
    function renderScatterPlot(data) {
        var canvas = document.getElementById('sd-scatter-plot');
        if (!canvas) return;
        if (!data || !data.length) {
            canvas.parentElement.innerHTML = '<div class="sd-empty">No scatter data yet. Questions will appear once students start asking.</div>';
            return;
        }

        destroyChart('scatter');

        var colors = {critical: '#a5304d', moderate: '#f59e0b', minor: '#006b2f', healthy: '#4ade80'};
        var bubbles = data.map(function(d) {
            return {
                x: d.volume,
                y: d.friction,
                r: d.impact_size > 0 ? d.impact_size / 2 : 8,
                label: d.topic,
                backgroundColor: colors[d.severity] || '#999',
                borderColor: 'rgba(255,255,255,0.6)',
                borderWidth: 1,
            };
        });

        var parent = canvas.parentElement;
        var width = parent.clientWidth || 400;
        var height = parent.clientHeight || 280;

        canvas.style.width = width + 'px';
        canvas.style.height = height + 'px';

        chartInstances.scatter = new Chart(canvas.getContext('2d'), {
            type: 'bubble',
            data: { datasets: [{ data: bubbles, label: 'Topics' }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var d = ctx.raw;
                                return d.label + ': Vol ' + d.x + ', Friction ' + d.y;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        title: { display: true, text: 'Question Volume', color: '#666', font: {size: 11} },
                        min: 0, max: data.length ? Math.ceil(Math.max.apply(null, data.map(function(d) { return d.volume; })) / 10) * 10 * 1.2 : 70,
                        grid: { display: false }
                    },
                    y: {
                        title: { display: true, text: 'Friction Score', color: '#666', font: {size: 11} },
                        min: 0, max: data.length ? Math.ceil(Math.max.apply(null, data.map(function(d) { return d.friction; })) / 10) * 10 * 1.2 : 60,
                        grid: { display: false }
                    }
                },
                animation: false,
            }
        });
    }

    /* ── Topic Mastery List ── */
    function renderTopicMastery(data) {
        var list = document.getElementById('sd-topic-mastery-list');
        if (!list) return;
        if (!data || !data.length) {
            list.innerHTML = '<div class="sd-empty">No topic data yet.</div>';
            return;
        }

        list.innerHTML = data.map(function(t, idx) {
            var pct = t.total_students > 0 ? Math.round(t.students_mastered / t.total_students * 100) : 0;
            var ringColor = t.difficulty === 'critical' ? '#a5304d' : t.difficulty === 'moderate' ? '#f59e0b' : '#006b2f';
            var ringHtml = '<svg class="sd-progress-ring" viewBox="0 0 36 36">' +
                '<circle cx="18" cy="18" r="15.9" fill="none" stroke="#e5e7eb" stroke-width="2.8"/>' +
                '<circle cx="18" cy="18" r="15.9" fill="none" stroke="' + ringColor + '" stroke-width="2.8" ' +
                'stroke-dasharray="100" stroke-dashoffset="' + (100 - pct) + '" transform="rotate(-90,18,18)" stroke-linecap="round"/>' +
                '<text x="18" y="18" text-anchor="middle" dominant-baseline="central" font-size="8" font-weight="700" fill="' + ringColor + '">' + pct + '%</text></svg>';

            var questionsHtml = '';
            if (t.expand_questions && t.expand_questions.length) {
                questionsHtml = '<div class="sd-expand-questions" id="sd-eq-' + idx + '">' +
                    t.expand_questions.map(function(q) { return '<div>• ' + escapeHtml(q) + '</div>'; }).join('') + '</div>';
            }

            return '<div class="sd-mastery-row">' +
                ringHtml +
                '<div class="sd-mastery-name">' + escapeHtml(t.topic) + '</div>' +
                '<span class="sd-diff-badge ' + t.difficulty + '">' + t.difficulty + '</span>' +
                '<button class="sd-expand-btn" onclick="window.struggleDashboard.toggleQuestions(' + idx + ')">Expand</button>' +
                questionsHtml +
                '</div>';
        }).join('');
    }

    function toggleQuestions(idx) {
        var el = document.getElementById('sd-eq-' + idx);
        if (el) el.classList.toggle('open');
    }

    /* ── Student Triage Table ── */
    function renderStudentTable(students) {
        var tbody = document.getElementById('sd-student-tbody');
        if (!tbody) return;
        studentData = students || [];
        selectedStudentIds = {};

        renderTableRows();
        wireTableSorting();
        wireSelectAll();
    }

    function renderTableRows() {
        var tbody = document.getElementById('sd-student-tbody');
        if (!tbody) return;
        if (!studentData.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="sd-empty">No at-risk students.</td></tr>';
            return;
        }

        tbody.innerHTML = studentData.map(function(s) {
            return '<tr>' +
                '<td><input type="checkbox" class="sd-student-cb" data-uid="' + s.id + '" ' + (selectedStudentIds[s.id] ? 'checked' : '') + '></td>' +
                '<td>' + (s.avatar || '') + '<span class="sd-student-name">' + escapeHtml(s.name) + '</span></td>' +
                '<td><span class="sd-risk-badge ' + s.risk + '">' + s.risk + '</span></td>' +
                '<td><span class="sd-struggle-trunc" title="' + escapeHtml(s.struggle_area) + '">' + escapeHtml(s.struggle_area) + '</span></td>' +
                '<td>' + escapeHtml(s.last_active) + '</td>' +
                '<td><div class="sd-action-icons">' +
                '<button class="sd-action-icon mail" title="Send message" onclick="window.struggleDashboard.actionStudent(' + s.id + ',\'mail\')"><span class="material-symbols-outlined">mail</span></button>' +
                '<button class="sd-action-icon video" title="Start BBB meeting" onclick="window.struggleDashboard.actionStudent(' + s.id + ',\'video\')"><span class="material-symbols-outlined">videocam</span></button>' +
                '<button class="sd-action-icon trash" title="Flag intervention" onclick="window.struggleDashboard.actionStudent(' + s.id + ',\'trash\')"><span class="material-symbols-outlined">flag</span></button>' +
                '</div></td>' +
                '</tr>';
        }).join('');

        // Wire checkboxes
        document.querySelectorAll('.sd-student-cb').forEach(function(cb) {
            cb.addEventListener('change', function() {
                var uid = parseInt(this.dataset.uid);
                if (this.checked) selectedStudentIds[uid] = true;
                else delete selectedStudentIds[uid];
            });
        });
    }

    function wireTableSorting() {
        var headers = document.querySelectorAll('.sd-sortable');
        headers.forEach(function(h) {
            h.addEventListener('click', function() {
                var col = this.dataset.col;
                var isAsc = this.classList.contains('sd-sort-asc');
                headers.forEach(function(x) { x.classList.remove('sd-sort-asc', 'sd-sort-desc'); });
                this.classList.add(isAsc ? 'sd-sort-desc' : 'sd-sort-asc');

                studentData.sort(function(a, b) {
                    var va = (a[col] || '').toString().toLowerCase();
                    var vb = (b[col] || '').toString().toLowerCase();
                    if (col === 'risk') {
                        va = a.risk === 'Critical' ? 2 : 1;
                        vb = b.risk === 'Critical' ? 2 : 1;
                    }
                    if (va < vb) return isAsc ? 1 : -1;
                    if (va > vb) return isAsc ? -1 : 1;
                    return 0;
                });
                renderTableRows();
            });
        });
    }

    function wireSelectAll() {
        var selAll = document.getElementById('sd-select-all');
        if (!selAll) return;
        selAll.addEventListener('change', function() {
            var checked = this.checked;
            studentData.forEach(function(s) {
                if (checked) selectedStudentIds[s.id] = true;
                else delete selectedStudentIds[s.id];
            });
            document.querySelectorAll('.sd-student-cb').forEach(function(cb) {
                cb.checked = checked;
            });
        });
    }

    /* ── Material Health Chart ── */
    function renderMaterialHealth(data) {
        var canvas = document.getElementById('sd-material-health-chart');
        if (!canvas) return;
        destroyChart('matHealth');
        if (!data || !data.length) {
            canvas.parentElement.innerHTML = '<div class="sd-empty">No material health data yet.</div>';
            return;
        }

        var labels = data.map(function(m) { return m.name.length > 25 ? m.name.substring(0, 22) + '...' : m.name; });

        chartInstances.matHealth = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: '% Complete', data: data.map(function(m) { return m.pct_complete; }), backgroundColor: '#006b2f', borderRadius: 2, barThickness: 10 },
                    { label: '% Questions', data: data.map(function(m) { return m.pct_questions; }), backgroundColor: '#f59e0b', borderRadius: 2, barThickness: 10 },
                    { label: '% Correct', data: data.map(function(m) { return m.pct_correct; }), backgroundColor: '#a5304d', borderRadius: 2, barThickness: 10 },
                ]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { boxWidth: 12, font: {size: 10}, padding: 8 } },
                },
                scales: {
                    x: { min: 0, max: 80, grid: { display: false }, title: { display: true, text: 'Percentage', font: {size: 10} } },
                    y: { grid: { display: false }, ticks: { font: {size: 9} } }
                },
                animation: false,
            }
        });
    }

    /* ── Questions Feed ── */
    function renderQuestionsFeed(data) {
        var feed = document.getElementById('sd-questions-feed');
        if (!feed) return;
        if (!data || !data.length) {
            feed.innerHTML = '<div class="sd-empty">No questions yet.</div>';
            return;
        }
        feed.innerHTML = data.slice(0, 15).map(function(q) {
            return '<div class="sd-feed-item">' +
                '<div class="sd-feed-text">' + escapeHtml(q.text) + '</div>' +
                '<div class="sd-feed-meta">' +
                '<span class="sd-feed-count">' + q.count + 'x</span>' +
                (q.source_material ? ' · ' + escapeHtml(q.source_material) : '') +
                '</div></div>';
        }).join('');
    }

    /* ── Topics Feed ── */
    function renderTopicsFeed(scatterData) {
        var feed = document.getElementById('sd-topics-feed');
        if (!feed) return;
        if (!scatterData || !scatterData.length) {
            feed.innerHTML = '<div class="sd-empty">No topics yet.</div>';
            return;
        }

        var sorted = scatterData.slice().sort(function(a, b) { return b.volume - a.volume; });
        feed.innerHTML = sorted.slice(0, 15).map(function(t) {
            var color = t.severity === 'critical' ? '#a5304d' : t.severity === 'moderate' ? '#f59e0b' : '#006b2f';
            return '<div class="sd-feed-item">' +
                '<div class="sd-feed-text" style="border-left:3px solid ' + color + ';padding-left:8px;">' + escapeHtml(t.topic) + '</div>' +
                '<div class="sd-feed-meta">' + t.volume + ' questions · Friction: ' + Math.round(t.friction) + '</div></div>';
        }).join('');
    }

    /* ── Health Report ── */
    function renderHealthReport(report) {
        var reportEl = document.getElementById('sd-health-report');
        var recEl = document.getElementById('sd-recommendations');
        if (!report || !report.summary) {
            if (reportEl) reportEl.innerHTML = '<div class="sd-empty">AI course health report will appear once enough student data is available.</div>';
            if (recEl) recEl.innerHTML = '';
            return;
        }
        if (reportEl) {
            reportEl.innerHTML = escapeHtml(report.summary);
        }
        if (recEl && report && report.recommendations) {
            recEl.innerHTML = report.recommendations.map(function(r) {
                return '<span class="sd-rec-chip">' + escapeHtml(r) + '</span>';
            }).join('');
        }

        var btn = document.getElementById('sd-ai-strategy-btn');
        if (btn && !btn.dataset.bound) {
            btn.dataset.bound = '1';
            btn.addEventListener('click', function() {
                if (this.disabled) {
                    return;
                }
                this.disabled = true;
                this.innerHTML = '<span class="material-symbols-outlined umat-spin">progress_activity</span> Generating…';
                streamQuestion(
                    'Suggest tailored student outreach strategies for struggling students in this course. Include specific actions, messaging tone, and priority order.',
                    'sd-health-report',
                    null,
                    function() {
                        btn.disabled = false;
                        btn.innerHTML = '<span class="material-symbols-outlined">auto_awesome</span> Ask AI for tailored student outreach strategies';
                    }
                );
            });
        }

        bindNlqBar();
    }

    function bindNlqBar() {
        var input = document.getElementById('sd-nlq-input');
        var btn = document.getElementById('sd-nlq-btn');
        if (!input || !btn || btn.dataset.bound) {
            return;
        }
        btn.dataset.bound = '1';

        function runNlq() {
            var q = (input.value || '').trim();
            if (!q) {
                return;
            }
            btn.disabled = true;
            input.disabled = true;
            var response = document.getElementById('sd-nlq-response');
            if (response) {
                response.style.display = 'block';
            }
            streamQuestion(q, 'sd-nlq-response', null, function() {
                btn.disabled = false;
                input.disabled = false;
            });
        }

        btn.addEventListener('click', runNlq);
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                runNlq();
            }
        });
    }

    function submitNLQ() {
        var input = document.getElementById('sd-nlq-input') || document.getElementById('ins-nlq-input');
        if (!input) {
            return;
        }
        var q = (input.value || '').trim();
        if (!q) {
            return;
        }
        var targetId = document.getElementById('sd-nlq-response') ? 'sd-nlq-response' : 'ins-nlq-response';
        var response = document.getElementById(targetId);
        if (response) {
            response.style.display = 'block';
        }
        streamQuestion(q, targetId);
    }

    /* ── Student Actions ── */
    function actionStudent(uid, action) {
        var student = studentData.find(function(s) { return s.id === uid; });
        if (!student) return;

        if (action === 'mail') {
            // Open Moodle messaging
            var url = '/message/index.php?user1to=' + uid + '&id=' + cid;
            window.open(url, '_blank');
        } else if (action === 'video') {
            // Launch BBB meeting — try BBB module
            var bbbUrl = '/mod/bigbluebuttonbn/index.php?id=' + cid;
            // Show iframe overlay
            var overlay = document.createElement('div');
            overlay.className = 'sd-bbb-overlay open';
            overlay.innerHTML = '<iframe class="sd-bbb-frame" src="' + bbbUrl + '" allow="microphone; camera" sandbox="allow-scripts allow-same-origin allow-forms"></iframe>';
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) overlay.remove();
            });
            document.body.appendChild(overlay);
        } else if (action === 'trash') {
            // Flag intervention
            Ajax.call([{
                methodname: 'local_umat_ai_execute_intervention',
                args: {courseid: cid, userid: uid, action: 'flagged', message: 'Flagged for review from Struggle Dashboard'}
            }])[0].done(function() {
                Notification.addNotification({message: 'Student flagged for review.', type: 'success'});
            }).fail(function() {
                Notification.addNotification({message: 'Failed to flag student.', type: 'error'});
            });
        }
    }

    function destroyChart(key) {
        if (chartInstances[key]) {
            chartInstances[key].destroy();
            delete chartInstances[key];
        }
    }

    function escapeHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    return {
        init: init,
        toggleQuestions: toggleQuestions,
        actionStudent: actionStudent,
        loadData: loadData,
        submitNLQ: submitNLQ,
    };
});
