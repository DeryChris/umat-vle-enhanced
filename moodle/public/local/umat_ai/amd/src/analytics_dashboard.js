define(['core/ajax', 'core/str', 'local_umat_ai/umatshared'], function(Ajax, Str, Shared) {
    'use strict';

    var cid = 0;
    var currentDetailUid = 0;
    var currentDrawerAction = '';
    var studentCache = {};
    var filterMode = 'all';
    var activeStream = null;

    function streamConfig() {
        if (window._umatChatStream) {
            return window._umatChatStream;
        }
        return { url: '', sesskey: '' };
    }

    function init(courseId) {
        cid = parseInt(courseId) || 0;
        if (!cid) return;
        populateCourseSel();
        loadData();
    }

    function populateCourseSel() {
        var sel = document.getElementById('ins-course-select');
        if (!sel) return;
        var val = sel.value;
        var opts = sel.querySelectorAll('option');
        for (var i = 0; i < opts.length; i++) {
            if (parseInt(opts[i].value) === cid) {
                sel.value = cid;
                return;
            }
        }
    }

    function loadData() {
        showSkeleton(true);
        var requests = Ajax.call([
            {
                methodname: 'local_umat_ai_get_dashboard_summary',
                args: {courseid: cid}
            },
            {
                methodname: 'local_umat_ai_get_struggle_insights',
                args: {courseid: cid, days: 60}
            }
        ]);
        requests[0].done(function(summary) {
            renderHeadsUp(summary);
        }).fail(function() {
            renderHeadsUp(null);
        });
        requests[1].done(function(insights) {
            renderLegacy(insights);
            showSkeleton(false);
        }).fail(function() {
            showSkeleton(false);
        });
        loadHealthReport();
    }

    function showSkeleton(show) {
        var sk = document.getElementById('ins-skeleton');
        var pane = document.querySelector('.umat-insights-pane');
        if (sk) sk.style.display = show ? 'flex' : 'none';
        if (pane) pane.style.opacity = show ? '0.3' : '1';
    }

    function renderHeadsUp(data) {
        var ring = document.getElementById('ins-ring-progress');
        var pctEl = document.getElementById('ins-engagement-pct');
        var atRiskEl = document.getElementById('ins-atrisk-count');
        var trackedEl = document.getElementById('ins-tracked-count');
        var insightEl = document.getElementById('ins-global-insight');

        var engagement = data ? data.engagement_score : 0;
        var atRisk = data ? data.at_risk_count : 0;
        var total = data ? data.total_students : 0;

        if (ring) {
            var circ = 2 * Math.PI * 52;
            var offset = circ - (engagement / 100) * circ;
            ring.style.strokeDasharray = circ;
            ring.style.strokeDashoffset = offset;
            ring.style.stroke = engagement > 60 ? 'var(--u-p)' : engagement > 40 ? '#f59e0b' : 'var(--u-ter)';
        }
        if (pctEl) pctEl.textContent = engagement + '%';
        if (atRiskEl) atRiskEl.textContent = atRisk;
        if (trackedEl) trackedEl.textContent = total + ' students';
        if (insightEl && !data) {
            insightEl.textContent = 'No student data available yet. The hourly cron will populate metrics soon.';
        }
        if (atRisk > 0) {
            var card = document.getElementById('ins-atrisk-card');
            if (card) card.style.display = 'flex';
        }
    }

    function loadHealthReport() {
        var healthRow = document.getElementById('ins-health-row');
        var healthText = document.getElementById('ins-health-text');
        if (!healthText) return;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'http://localhost:8000/api/v1/health', true);
        xhr.timeout = 5000;
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var d = JSON.parse(xhr.responseText);
                    healthText.textContent = 'Healthy';
                    healthText.style.color = 'var(--u-p)';
                    if (healthRow) healthRow.style.display = 'flex';
                    var detail = document.getElementById('ins-health-detail');
                    if (detail) detail.textContent = 'Status: ' + (d.status || 'ok') + ' | Last checked: ' + new Date().toLocaleTimeString();
                } catch(e) {
                    healthText.textContent = 'Error parsing';
                    healthText.style.color = 'var(--u-ter)';
                }
            } else {
                healthText.textContent = 'Unreachable';
                healthText.style.color = 'var(--u-ter)';
                if (healthRow) healthRow.style.display = 'flex';
            }
        };
        xhr.onerror = function() {
            healthText.textContent = 'Unreachable';
            healthText.style.color = 'var(--u-ter)';
            if (healthRow) healthRow.style.display = 'flex';
        };
        xhr.send();
    }

    function renderLegacy(insights) {
        if (!insights) return;
        var summary = insights.summary || {};
        var data = insights.data || {};

        renderTopicGrid(data.topic_matrix || []);
        renderMaterialList(data.material_breakdown || []);
        renderQuestions(data.top_questions || []);
        renderStudentList(data.students || []);
    }

    function renderTopicGrid(topics) {
        var grid = document.getElementById('ins-topic-grid');
        if (!grid) return;
        if (!topics || !topics.length) {
            grid.innerHTML = '<div class="umat-empty"><span class="material-symbols-outlined">search</span><p>No topic data yet.</p></div>';
            return;
        }

        var critical = topics.filter(function(t) { return (t.struggle_score || 0) >= 70; });
        var moderate = topics.filter(function(t) { var s = t.struggle_score || 0; return s >= 40 && s < 70; });
        var minor = topics.filter(function(t) { return (t.struggle_score || 0) < 40; });

        var html = '';
        if (critical.length) {
            html += '<div class="topic-section severity-critical"><h3><span class="material-symbols-outlined">warning</span>Critical Struggle Areas (' + critical.length + ')</h3><div class="topic-grid">' +
                critical.map(function(t) { return renderTopicCard(t, 'critical'); }).join('') + '</div></div>';
        }
        if (moderate.length) {
            html += '<div class="topic-section severity-moderate"><h3><span class="material-symbols-outlined">info</span>Moderate Struggle Areas (' + moderate.length + ')</h3><div class="topic-grid">' +
                moderate.map(function(t) { return renderTopicCard(t, 'moderate'); }).join('') + '</div></div>';
        }
        if (minor.length) {
            html += '<div class="topic-section severity-minor"><h3><span class="material-symbols-outlined">check_circle</span>Minor Struggle Areas (' + minor.length + ')</h3><div class="topic-grid">' +
                minor.map(function(t) { return renderTopicCard(t, 'minor'); }).join('') + '</div></div>';
        }
        grid.innerHTML = html;
    }

    function renderTopicCard(t, sev) {
        var score = t.struggle_score || 0;
        var color = sev === 'critical' ? '#dc2626' : (sev === 'moderate' ? '#f59e0b' : '#16a34a');
        var ringHtml = '<svg class="stru-svg-ring" width="36" height="36" viewBox="0 0 36 36"><circle cx="18" cy="18" r="15.9" fill="none" stroke="#e5e7eb" stroke-width="2.8"/><circle cx="18" cy="18" r="15.9" fill="none" stroke="' + color + '" stroke-width="2.8" stroke-dasharray="100" stroke-dashoffset="' + (100 - score) + '" transform="rotate(-90,18,18)" stroke-linecap="round"/><text x="18" y="18" text-anchor="middle" dominant-baseline="central" font-size="10" font-weight="800" fill="' + color + '">' + score + '</text></svg>';

        var trendHtml = '';
        if (t.trend === 'up') trendHtml = '<span class="struggle-trend trend-up"><span class="material-symbols-outlined">trending_up</span> +' + (t.trend_pct || 0) + '%</span>';
        else if (t.trend === 'down') trendHtml = '<span class="struggle-trend trend-down"><span class="material-symbols-outlined">trending_down</span> ' + (t.trend_pct || 0) + '%</span>';
        else trendHtml = '<span class="struggle-trend trend-stable"><span class="material-symbols-outlined">trending_flat</span></span>';

        var matChips = (t.materials || []).slice(0, 4).map(function(m) {
            return '<span class="tag">' + escapeHtml(m.name || m || '') + (m.question_count ? ' (' + m.question_count + ')' : '') + '</span>';
        }).join('');

        var sqHtml = '';
        if (t.sample_questions && t.sample_questions.length) {
            sqHtml = '<div class="sample-questions"><strong>Student Questions:</strong><ul>' +
                t.sample_questions.slice(0, 3).map(function(q) { return '<li>' + escapeHtml(q) + '</li>'; }).join('') + '</ul></div>';
        }

        var matHtml = '';
        if (matChips) {
            matHtml = '<div class="related-materials"><strong>Related Materials:</strong><div class="tags">' + matChips + '</div></div>';
        }

        var aiHtml = '';
        if (t.ai_recommendation) {
            aiHtml = '<div class="struggle-topic-ai"><span class="material-symbols-outlined" style="color:var(--u-p);font-size:16px;">auto_awesome</span><span>' + escapeHtml(t.ai_recommendation) + '</span></div>';
        }

        return '<div class="struggle-topic-card struggle-' + sev + '">' +
            '<div class="struggle-topic-header"><div class="struggle-topic-name"><strong>' + escapeHtml(t.topic || '') + '</strong></div>' + ringHtml + '</div>' +
            '<div class="struggle-topic-body"><span class="stru-topic-stat"><span class="material-symbols-outlined">quiz</span> <strong>' + (t.question_count || 0) + '</strong></span>' +
            '<span class="stru-topic-stat"><span class="material-symbols-outlined">people</span> <strong>' + (t.student_count || 0) + '</strong></span>' +
            '<span class="stru-topic-stat">' + trendHtml + '</span></div>' +
            matHtml + sqHtml + aiHtml +
            '<div class="progress-bar"><div class="progress-fill" style="width:' + score + '%;background:' + color + '"></div></div></div>';
    }

    function renderMaterialList(materials) {
        var list = document.getElementById('ins-material-list');
        if (!list) return;
        if (!materials.length) {
            list.innerHTML = '<div class="insights-empty">No material data yet.</div>';
            return;
        }
        list.innerHTML = materials.map(function(m) {
            return '<div class="insights-mat-item">' +
                '<span class="insights-mat-name">' + escapeHtml(m.name || 'Unknown') + '</span>' +
                '<span class="insights-mat-count">' + (m.question_count || 0) + ' questions</span>' +
                '</div>';
        }).join('');
    }

    function renderQuestions(questions) {
        var list = document.getElementById('ins-question-list');
        if (!list) return;
        if (!questions.length) {
            list.innerHTML = '<div class="insights-empty">No questions yet.</div>';
            return;
        }
        list.innerHTML = questions.map(function(q) {
            return '<div class="insights-q-item">' +
                '<span class="insights-q-text">' + escapeHtml(q.text || q) + '</span>' +
                '<span class="insights-q-count">' + (q.count || q.ask_count || 1) + 'x</span>' +
                '</div>';
        }).join('');
    }

    function renderStudentList(students) {
        studentCache = {};
        var list = document.getElementById('ins-student-list');
        if (!list) return;
        if (!students.length) {
            list.innerHTML = '<div class="insights-empty">No students found in this period.</div>';
            return;
        }
        var filtered = applyFilter(students);
        if (!filtered.length) {
            list.innerHTML = '<div class="insights-empty">No students match the current filter.</div>';
            return;
        }
        list.innerHTML = filtered.map(function(s) {
            var uid = s.userid || s.user_id || 0;
            studentCache[uid] = s;
            var risk = s.risk_score || 0;
            var sev = risk >= 60 ? 'high' : risk >= 40 ? 'medium' : 'low';
            return '<div class="insights-student-item" data-uid="' + uid + '" onclick="window.analyticsDashboard.loadStudentDetail(' + uid + ')">' +
                '<div class="insights-student-info">' +
                '<img class="insights-student-avatar" src="M.util.image_url(\'u/f1\')" alt="" onerror="this.style.display=\'none\'">' +
                '<div><div class="insights-student-name">' + escapeHtml(s.firstname || '') + ' ' + escapeHtml(s.lastname || '') + '</div>' +
                '<div class="insights-student-meta">' + (s.total_logins || 0) + ' logins · ' + (s.ai_queries || 0) + ' AI queries</div></div></div>' +
                '<span class="insights-pill-risk ' + sev + '">Risk: ' + risk + '</span>' +
                '</div>';
        }).join('');
    }

    function applyFilter(students) {
        if (filterMode === 'all') return students;
        return students.filter(function(s) {
            var risk = s.risk_score || 0;
            if (filterMode === 'at_risk') return risk >= 60;
            if (filterMode === 'struggling') return risk >= 40 && risk < 60;
            if (filterMode === 'engaged') return risk < 40;
            return true;
        });
    }

    function setFilter(mode, btnEl) {
        filterMode = mode;
        var chips = document.querySelectorAll('.insights-chip[data-risk]');
        for (var i = 0; i < chips.length; i++) {
            chips[i].classList.remove('insights-chip-active');
        }
        if (btnEl) btnEl.classList.add('insights-chip-active');
        renderStudentList(Object.values(studentCache));
    }

    function loadStudentDetail(uid) {
        currentDetailUid = uid;
        var panel = document.getElementById('ins-detail-panel');
        var body = document.getElementById('ins-detail-body');
        var nameEl = document.getElementById('ins-detail-name');
        var riskBadge = document.getElementById('ins-detail-risk-badge');
        if (!panel || !body) return;

        panel.style.display = 'block';
        body.innerHTML = '<div class="insights-empty">Loading profile…</div>';

        var s = studentCache[uid];
        if (s) {
            nameEl.textContent = (s.firstname || '') + ' ' + (s.lastname || '');
            var risk = s.risk_score || 0;
            riskBadge.textContent = 'Risk ' + risk;
            riskBadge.className = 'insights-pill-risk ' + (risk >= 60 ? 'high' : risk >= 40 ? 'medium' : 'low');
        }

        Ajax.call([{
            methodname: 'local_umat_ai_get_student_profile',
            args: {courseid: cid, userid: uid}
        }])[0].done(function(data) {
            if (!data) return;
            body.innerHTML =
                '<div class="insights-stat-grid">' +
                '<div class="insights-stat-box"><div class="insights-stat-val">' + (data.risk_score || 0) + '</div><div class="insights-stat-lbl">Risk Score</div></div>' +
                '<div class="insights-stat-box"><div class="insights-stat-val">' + (data.total_logins || 0) + '</div><div class="insights-stat-lbl">Logins</div></div>' +
                '<div class="insights-stat-box"><div class="insights-stat-val">' + (data.avg_quiz || 0).toFixed(1) + '</div><div class="insights-stat-lbl">Avg Quiz</div></div>' +
                '<div class="insights-stat-box"><div class="insights-stat-val">' + (data.ai_queries || 0) + '</div><div class="insights-stat-lbl">AI Queries</div></div>' +
                '</div>';
            if (data.interventions && data.interventions.length) {
                body.innerHTML += '<div style="font-size:12px;font-weight:600;color:var(--u-ol);margin-bottom:4px;">Recent Interventions</div>';
                body.innerHTML += '<div style="display:flex;flex-direction:column;gap:4px;">' +
                    data.interventions.slice(0, 5).map(function(inv) {
                        return '<div style="display:flex;justify-content:space-between;font-size:11px;padding:4px 8px;background:var(--u-sfl);border-radius:6px;">' +
                            '<span>' + escapeHtml(inv.action || '') + '</span>' +
                            '<span style="color:var(--u-ol);">' + (inv.status || '') + ' · ' + new Date((inv.timecreated || 0) * 1000).toLocaleDateString() + '</span>' +
                            '</div>';
                    }).join('') + '</div>';
            } else {
                body.innerHTML += '<div style="font-size:11px;color:var(--u-ol);margin-top:8px;">No interventions recorded.</div>';
            }
        }).fail(function() {
            body.innerHTML = '<div class="insights-empty">Failed to load profile.</div>';
        });
    }

    function closeDetail() {
        var panel = document.getElementById('ins-detail-panel');
        if (panel) panel.style.display = 'none';
        currentDetailUid = 0;
    }

    function toggleLegacySection(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
    }

    function openActionDrawer(action) {
        currentDrawerAction = action;
        var drawer = document.getElementById('ins-action-drawer');
        var title = document.getElementById('ins-drawer-title');
        var recipient = document.getElementById('ins-drawer-recipient');
        var message = document.getElementById('ins-drawer-message');
        if (!drawer) return;

        var actionNames = {encouragement: 'Send Encouragement', meeting: 'Schedule 1:1', remedial_quiz: 'Assign Remedial Quiz'};
        if (title) title.textContent = actionNames[action] || 'Send Intervention';

        if (currentDetailUid && studentCache[currentDetailUid]) {
            var s = studentCache[currentDetailUid];
            if (recipient) recipient.textContent = 'To: ' + (s.firstname || '') + ' ' + (s.lastname || '') + ' (Risk: ' + (s.risk_score || 0) + ')';
        }

        var templates = {
            encouragement: 'Hi {{name}}, I noticed you might be struggling with the course material. Remember that I\'m here to help — don\'t hesitate to reach out or use the AI assistant for extra support. Keep going!',
            meeting: 'Hi {{name}}, would you like to schedule a 1:1 meeting this week? I\'d love to discuss how we can help you get back on track. Please let me know what times work for you.',
            remedial_quiz: 'Hi {{name}}, I\'ve prepared some additional practice questions to help reinforce the key concepts. Check your course page for the remedial quiz. Let me know if you have questions!'
        };
        var name = studentCache[currentDetailUid] ? (studentCache[currentDetailUid].firstname || 'Student') : 'Student';
        if (message) message.value = (templates[action] || '').replace('{{name}}', name);

        drawer.style.display = 'flex';
        var status = document.getElementById('ins-drawer-status');
        if (status) status.style.display = 'none';
    }

    function closeActionDrawer() {
        var drawer = document.getElementById('ins-action-drawer');
        if (drawer) drawer.style.display = 'none';
        currentDrawerAction = '';
    }

    function sendIntervention() {
        var btn = document.getElementById('ins-drawer-send-btn');
        var status = document.getElementById('ins-drawer-status');
        var message = document.getElementById('ins-drawer-message');
        if (!btn || !status || !message) return;
        if (!currentDetailUid || !currentDrawerAction) return;

        btn.disabled = true;
        status.style.display = 'block';
        status.textContent = 'Sending…';
        status.style.color = 'var(--u-ol)';

        Ajax.call([{
            methodname: 'local_umat_ai_execute_intervention',
            args: {
                courseid: cid,
                userid: currentDetailUid,
                action: currentDrawerAction,
                message: message.value
            }
        }])[0].done(function(resp) {
            if (resp.status === 'sent') {
                status.textContent = '✓ Message sent successfully!';
                status.style.color = 'var(--u-p)';
                setTimeout(closeActionDrawer, 2000);
            } else if (resp.status === 'cooldown') {
                status.textContent = '⏳ Already sent within 24h. Please wait.';
                status.style.color = '#f59e0b';
            } else {
                status.textContent = '✗ Failed: ' + (resp.message || 'Unknown error');
                status.style.color = 'var(--u-ter)';
            }
            btn.disabled = false;
        }).fail(function() {
            status.textContent = '✗ Connection error. Please try again.';
            status.style.color = 'var(--u-ter)';
            btn.disabled = false;
        });
    }

    function submitNLQ() {
        var input = document.getElementById('ins-nlq-input');
        var response = document.getElementById('ins-nlq-response');
        var spinner = document.getElementById('ins-nlq-spinner');
        if (!input || !response) {
            return;
        }

        var q = input.value.trim();
        if (!q || !cid) {
            return;
        }

        if (activeStream && activeStream.abort) {
            activeStream.abort();
        }

        response.style.display = 'block';
        if (spinner) {
            spinner.style.display = 'inline-block';
        }
        input.disabled = true;

        var cfg = streamConfig();
        activeStream = Shared._umatStreamInline({
            url: cfg.url,
            sesskey: cfg.sesskey,
            courseid: cid,
            question: q,
            session_key: 'ins_nlq_' + cid,
            targetId: 'ins-nlq-response',
            onDone: function() {
                activeStream = null;
                if (spinner) {
                    spinner.style.display = 'none';
                }
                input.disabled = false;
            },
            onError: function(err) {
                activeStream = null;
                if (spinner) {
                    spinner.style.display = 'none';
                }
                input.disabled = false;
                response.innerHTML = '<span style="color:var(--u-ter);">' + escapeHtml(err.message || 'Failed to query AI service.') + '</span>';
            }
        });
    }

    function escapeHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    return {
        init: init,
        setFilter: setFilter,
        loadStudentDetail: loadStudentDetail,
        closeDetail: closeDetail,
        toggleLegacySection: toggleLegacySection,
        openActionDrawer: openActionDrawer,
        closeActionDrawer: closeActionDrawer,
        sendIntervention: sendIntervention,
        submitNLQ: submitNLQ,
        loadData: loadData,
        escapeHtml: escapeHtml
    };
});