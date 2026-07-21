/**
 * Lecturer Insights Dashboard — Card-based layout with charts.
 *
 * Cards: Stat Tiles → Risk Donut + Sparkline → Topic Heatmap → Students + Questions → NLQ
 */
define(['core/ajax', 'core/str', 'local_umat_ai/umatshared'], function(Ajax, Str, Shared) {
    'use strict';

    var cid = 0;
    var currentDetailUid = 0;
    var currentDrawerAction = '';
    var allStudentNarratives = [];
    var filterMode = 'all';
    var activeStream = null;

    var COLORS = {
        high: '#dc2626',
        medium: '#f59e0b',
        low: '#22c55e',
        critical: '#dc2626',
        attention: '#f59e0b',
        watch: '#22c55e',
        blue: '#3b82f6',
        muted: '#94a3b8'
    };

    function streamConfig() {
        if (window._umatChatStream) return window._umatChatStream;
        return { url: '', sesskey: '' };
    }

    // ── Initialisation ──
    function init(courseId) {
        cid = parseInt(courseId) || 0;
        console.log('[StruggleDashboard] init called, cid=' + cid);
        if (!cid) return;
        loadData();
    }

    // ── Data loading ──
    function loadData() {
        showSkeleton(true);
        Ajax.call([{
            methodname: 'local_umat_ai_get_struggle_insights',
            args: { courseid: cid, days: 60 }
        }])[0].done(function(insights) {
            console.log('[StruggleDashboard] API SUCCESS', insights);
            renderAll(insights);
            showSkeleton(false);
        }).fail(function() {
            console.error('[StruggleDashboard] API FAILED', Array.prototype.slice.call(arguments));
            showSkeleton(false);
            renderAll({});
        });
    }

    function showSkeleton(show) {
        var sk = document.getElementById('ins-skeleton');
        var pane = document.querySelector('.umat-insights-pane');
        if (sk) sk.style.display = show ? 'flex' : 'none';
        if (pane) pane.style.opacity = show ? '0.3' : '1';
    }

    // ── Master renderer ──
    function renderAll(data) {
        if (!data) data = {};
        renderStatTiles(data.course_pulse || {});
        renderRiskDonut(data.student_narratives || []);
        renderQuestionSparkline(data.course_pulse || {});
        renderTopicHeatmap(data.struggle_areas || []);
        renderAtRiskStudents(data.student_narratives || []);
        renderCommonQuestions(data.common_questions || []);
    }

    // ════════════════════════════════════════════════════════════════
    // STAT TILES
    // ════════════════════════════════════════════════════════════════
    function renderStatTiles(pulse) {
        var totalStudents = pulse.total_students || 0;
        var atRisk = pulse.at_risk_count || 0;
        var avgQuiz = pulse.avg_quiz || 0;
        var active = pulse.active_this_week || 0;

        var trendBadge = function(trend, pct) {
            if (trend === 'up') return '<span class="ins-trend-pill up">+' + (pct || 0) + '%</span>';
            if (trend === 'down') return '<span class="ins-trend-pill down">' + (pct || 0) + '%</span>';
            return '';
        };

        setTileValue('ins-stat-students', totalStudents,
            totalStudents ? trendBadge(pulse.questions_trend, pulse.questions_trend_pct) : '');
        setTileValue('ins-stat-at-risk', atRisk,
            atRisk ? trendBadge(pulse.at_risk_trend, pulse.at_risk_trend_delta) : '');
        setTileValue('ins-stat-quiz', avgQuiz + '%',
            avgQuiz ? trendBadge(pulse.quiz_trend, pulse.quiz_trend_pct) : '');
        setTileValue('ins-stat-active', active,
            totalStudents ? '<span class="ins-trend-sub">of ' + totalStudents + '</span>' : '');
    }

    function setTileValue(id, value, extra) {
        var el = document.getElementById(id);
        if (!el) return;
        el.innerHTML = esc(value) + (extra ? '<span class="ins-trend-extra">' + extra + '</span>' : '');
        var tile = el.closest('.ins-stat-tile');
        if (tile) tile.classList.remove('ins-stat-loading');
    }

    // ════════════════════════════════════════════════════════════════
    // RISK DONUT CHART (pure CSS conic-gradient)
    // ════════════════════════════════════════════════════════════════
    function renderRiskDonut(students) {
        var el = document.getElementById('ins-risk-donut');
        if (!el) return;
        if (!students || !students.length) {
            el.innerHTML = '<div class="ins-empty">No student data yet.</div>';
            return;
        }

        var counts = { high: 0, medium: 0, low: 0 };
        students.forEach(function(s) {
            var level = s.risk_level || 'low';
            counts[level] = (counts[level] || 0) + 1;
        });

        var total = students.length;
        var segments = [
            { label: 'High Risk', count: counts.high, color: COLORS.high },
            { label: 'Medium Risk', count: counts.medium, color: COLORS.medium },
            { label: 'Low Risk', count: counts.low, color: COLORS.low }
        ].filter(function(s) { return s.count > 0; });

        // Build conic-gradient
        var gradParts = [];
        var angle = 0;
        segments.forEach(function(seg) {
            var pct = (seg.count / total) * 360;
            gradParts.push(seg.color + ' ' + angle + 'deg ' + (angle + pct) + 'deg');
            angle += pct;
        });
        var gradient = 'conic-gradient(' + gradParts.join(', ') + ')';

        // Legend
        var legendHtml = segments.map(function(seg) {
            var pctVal = Math.round((seg.count / total) * 100);
            return '<div class="ins-donut-legend-item">' +
                '<span class="ins-donut-swatch" style="background:' + seg.color + '"></span>' +
                '<span class="ins-donut-label">' + seg.label + '</span>' +
                '<span class="ins-donut-value">' + seg.count + ' (' + pctVal + '%)</span>' +
                '</div>';
        }).join('');

        el.innerHTML = '<div class="ins-donut-container">' +
            '<div class="ins-donut-ring" style="background:' + gradient + '">' +
            '<div class="ins-donut-hole">' +
            '<div class="ins-donut-center-val">' + total + '</div>' +
            '<div class="ins-donut-center-lbl">Students</div>' +
            '</div></div>' +
            '<div class="ins-donut-legend">' + legendHtml + '</div>' +
            '</div>';
    }

    // ════════════════════════════════════════════════════════════════
    // QUESTION ACTIVITY SPARKLINE (SVG)
    // ════════════════════════════════════════════════════════════════
    function renderQuestionSparkline(pulse) {
        var el = document.getElementById('ins-question-sparkline');
        if (!el) return;

        var thisWeek = pulse.questions_this_week || 0;
        var lastWeek = pulse.questions_last_week || 0;
        var trend = pulse.questions_trend || 'stable';
        var trendPct = pulse.questions_trend_pct || 0;

        // Create a mini bar comparison: last week vs this week
        var maxVal = Math.max(thisWeek, lastWeek, 1);
        var lastH = Math.round((lastWeek / maxVal) * 80);
        var thisH = Math.round((thisWeek / maxVal) * 80);

        var trendColor = trend === 'up' ? COLORS.critical :
            (trend === 'down' ? COLORS.low : COLORS.muted);

        var trendIcon = trend === 'up' ? '↑' : (trend === 'down' ? '↓' : '→');

        el.innerHTML = '<div class="ins-sparkline-container">' +
            '<div class="ins-bars-pair">' +
            '<div class="ins-bar-col">' +
            '<div class="ins-bar-fill" style="height:' + lastH + 'px;background:' + COLORS.muted + '"></div>' +
            '<div class="ins-bar-label">Last Wk</div>' +
            '<div class="ins-bar-val">' + lastWeek + '</div>' +
            '</div>' +
            '<div class="ins-bar-col">' +
            '<div class="ins-bar-fill" style="height:' + thisH + 'px;background:' + COLORS.blue + '"></div>' +
            '<div class="ins-bar-label">This Wk</div>' +
            '<div class="ins-bar-val">' + thisWeek + '</div>' +
            '</div>' +
            '</div>' +
            '<div class="ins-sparkline-meta">' +
            '<span class="ins-sparkline-trend" style="color:' + trendColor + '">' +
            '<span class="material-symbols-outlined">trending_' + (trend === 'stable' ? 'flat' : trend) + '</span> ' +
            trendIcon + ' ' + Math.abs(trendPct) + '%</span>' +
            '<span class="ins-sparkline-sub">' + (pulse.total_students || 0) + ' students enrolled</span>' +
            '</div></div>';
    }

    // ════════════════════════════════════════════════════════════════
    // TOPIC STRUGGLE HEATMAP (horizontal bars with severity)
    // ════════════════════════════════════════════════════════════════
    function renderTopicHeatmap(areas) {
        var el = document.getElementById('ins-topic-heatmap');
        if (!el) return;
        if (!areas || !areas.length) {
            el.innerHTML = '<div class="ins-empty">No struggle data yet. Student questions will populate this.</div>';
            return;
        }

        // Sort by struggle_score descending
        var sorted = areas.slice().sort(function(a, b) {
            return (b.struggle_score || 0) - (a.struggle_score || 0);
        });

        el.innerHTML = sorted.map(function(a) {
            var score = a.struggle_score || 0;
            var color = a.severity === 'critical' ? COLORS.critical :
                (a.severity === 'attention' ? COLORS.attention : COLORS.watch);

            var trendIcon = a.trend === 'up' ? '<span class="material-symbols-outlined" style="color:' + COLORS.critical + '">trending_up</span>' :
                (a.trend === 'down' ? '<span class="material-symbols-outlined" style="color:' + COLORS.low + '">trending_down</span>' :
                '<span class="material-symbols-outlined" style="color:' + COLORS.muted + '">trending_flat</span>');

            return '<div class="ins-heatmap-row">' +
                '<div class="ins-heatmap-label">' +
                '<span class="ins-heatmap-topic">' + esc(a.topic) + '</span>' +
                '<span class="ins-heatmap-students">' + a.student_count + '/' + a.total_students + ' students</span>' +
                '</div>' +
                '<div class="ins-heatmap-bar-wrap">' +
                '<div class="ins-heatmap-bar" style="width:' + score + '%;background:' + color + '">' +
                '<span class="ins-heatmap-bar-val">' + score + '%</span>' +
                '</div></div>' +
                '<div class="ins-heatmap-meta">' +
                trendIcon +
                '<span class="ins-heatmap-qcount">' + a.question_count + ' Qs</span>' +
                '</div>' +
                '</div>';
        }).join('');
    }

    // ════════════════════════════════════════════════════════════════
    // AT-RISK STUDENTS (compact list)
    // ════════════════════════════════════════════════════════════════
    function renderAtRiskStudents(students) {
        allStudentNarratives = students || [];

        // Update badge count
        var atRiskCount = students.filter(function(s) {
            return s.risk_level === 'high' || s.risk_level === 'medium';
        }).length;
        var badge = document.getElementById('ins-at-risk-count');
        if (badge) badge.textContent = atRiskCount;

        applyFilterAndRender();
    }

    function applyFilterAndRender() {
        var el = document.getElementById('ins-student-list');
        if (!el) return;
        var filtered = applyFilter(allStudentNarratives);

        if (!filtered.length) {
            el.innerHTML = '<div class="ins-empty">No students match the current filter.</div>';
            return;
        }

        el.innerHTML = filtered.map(function(s) {
            var riskClass = s.risk_level || 'low';
            var riskLabel = riskClass === 'high' ? 'High' :
                (riskClass === 'medium' ? 'Med' : 'Low');

            var topics = '';
            if (s.struggle_topics && s.struggle_topics.length) {
                topics = '<span class="ins-student-topics-inline">' +
                    s.struggle_topics.slice(0, 2).map(function(t) {
                        return esc(t);
                    }).join(', ') + '</span>';
            }

            var stats = [];
            if (s.question_count > 0) stats.push(s.question_count + ' Qs');
            if (s.ai_queries > 0) stats.push(s.ai_queries + ' AI');
            if (s.quiz_failures > 0) stats.push(s.quiz_failures + ' F');
            var statsStr = stats.length ? ' · ' + stats.join(' · ') : '';

            return '<div class="ins-student-row" onclick="window.struggleDashboard.loadStudentDetail(' + s.userid + ')">' +
                '<img class="ins-student-avatar-sm" src="' + esc(s.profileimageurl || '') + '" alt="" onerror="this.style.display=\'none\'">' +
                '<div class="ins-student-row-body">' +
                '<div class="ins-student-row-top">' +
                '<strong class="ins-student-row-name">' + esc(s.fullname) + '</strong>' +
                '<span class="ins-pill ins-pill-' + riskClass + '">' + riskLabel + '</span>' +
                '</div>' +
                '<div class="ins-student-row-meta">' +
                topics +
                (statsStr ? '<span class="ins-student-row-stats">' + statsStr + '</span>' : '') +
                '</div>' +
                '</div>' +
                '<span class="ins-student-row-active">' + esc(s.last_active || '') + '</span>' +
                '</div>';
        }).join('');
    }

    function applyFilter(students) {
        if (filterMode === 'all') return students;
        return students.filter(function(s) {
            if (filterMode === 'disengaged') return (s.days_since_last_login || 0) >= 3;
            if (filterMode === 'struggling') return s.risk_score >= 40 && s.question_count >= 5;
            if (filterMode === 'failing') return (s.quiz_failures || 0) > 0 || s.risk_score >= 60;
            if (filterMode === 'issues') return (s.issue_reports || 0) > 0;
            return true;
        });
    }

    function setFilter(mode, btnEl) {
        filterMode = mode;
        var chips = document.querySelectorAll('.ins-chip[data-filter]');
        for (var i = 0; i < chips.length; i++) chips[i].classList.remove('ins-chip-active');
        if (btnEl) btnEl.classList.add('ins-chip-active');
        applyFilterAndRender();
    }

    // ════════════════════════════════════════════════════════════════
    // COMMON QUESTIONS (compact list)
    // ════════════════════════════════════════════════════════════════
    function renderCommonQuestions(questions) {
        var el = document.getElementById('ins-common-questions');
        if (!el) return;
        if (!questions || !questions.length) {
            el.innerHTML = '<div class="ins-empty">No common questions yet.</div>';
            return;
        }

        el.innerHTML = questions.slice(0, 6).map(function(q, i) {
            var displayText = (q.text || '').replace(/^\[Referencing:\s*[^\]]+\]\s*/i, '');
            if (displayText.length > 100) displayText = displayText.substring(0, 100) + '...';

            return '<div class="ins-question-row">' +
                '<div class="ins-question-rank">' + (i + 1) + '</div>' +
                '<div class="ins-question-body">' +
                '<div class="ins-question-text">' + esc(displayText) + '</div>' +
                '<div class="ins-question-meta">' +
                '<span class="ins-question-tag">' + esc(q.topic) + '</span>' +
                '<span class="ins-question-count">' + q.student_count + ' students · ' + q.ask_count + ' times</span>' +
                '</div></div>' +
                '</div>';
        }).join('');
    }

    // ════════════════════════════════════════════════════════════════
    // Student Detail Panel
    // ════════════════════════════════════════════════════════════════
    function loadStudentDetail(uid) {
        currentDetailUid = uid;
        var panel = document.getElementById('ins-detail-panel');
        var body = document.getElementById('ins-detail-body');
        var nameEl = document.getElementById('ins-detail-name');
        var riskBadge = document.getElementById('ins-detail-risk-badge');
        if (!panel || !body) return;

        panel.style.display = 'block';
        body.innerHTML = '<div class="ins-empty">Loading profile...</div>';

        var s = null;
        for (var i = 0; i < allStudentNarratives.length; i++) {
            if (allStudentNarratives[i].userid === uid) { s = allStudentNarratives[i]; break; }
        }
        if (s) {
            nameEl.textContent = s.fullname;
            riskBadge.textContent = s.risk_level === 'high' ? 'High Risk' : (s.risk_level === 'medium' ? 'Medium Risk' : 'Low Risk');
            riskBadge.className = 'ins-pill ins-pill-' + s.risk_level;
        }

        Ajax.call([{
            methodname: 'local_umat_ai_get_student_profile',
            args: { courseid: cid, userid: uid }
        }])[0].done(function(data) {
            if (!data) return;
            body.innerHTML =
                '<div class="ins-detail-narrative">' + esc(s ? s.summary : '') + '</div>' +
                '<div class="ins-detail-grid">' +
                '<div class="ins-detail-stat"><div class="ins-detail-stat-val">' + (data.risk_score || 0) + '</div><div class="ins-detail-stat-lbl">Risk Score</div></div>' +
                '<div class="ins-detail-stat"><div class="ins-detail-stat-val">' + (data.total_logins || 0) + '</div><div class="ins-detail-stat-lbl">Logins</div></div>' +
                '<div class="ins-detail-stat"><div class="ins-detail-stat-val">' + (data.avg_quiz || 0).toFixed(1) + '%</div><div class="ins-detail-stat-lbl">Avg Quiz</div></div>' +
                '<div class="ins-detail-stat"><div class="ins-detail-stat-val">' + (data.ai_queries || 0) + '</div><div class="ins-detail-stat-lbl">AI Queries</div></div>' +
                '</div>';
            if (s && s.suggestion) {
                body.innerHTML += '<div class="ins-detail-suggestion"><span class="material-symbols-outlined">auto_awesome</span> ' + esc(s.suggestion) + '</div>';
            }
            if (data.interventions && data.interventions.length) {
                body.innerHTML += '<div class="ins-detail-section-title">Recent Interventions</div>';
                body.innerHTML += data.interventions.slice(0, 5).map(function(inv) {
                    return '<div class="ins-detail-intervention">' +
                        '<span>' + esc(inv.action || '') + '</span>' +
                        '<span class="ins-detail-intervention-meta">' + (inv.status || '') + ' · ' + new Date((inv.timecreated || 0) * 1000).toLocaleDateString() + '</span>' +
                        '</div>';
                }).join('');
            }
        }).fail(function() {
            body.innerHTML = '<div class="ins-empty">Failed to load profile.</div>';
        });
    }

    function closeDetail() {
        var panel = document.getElementById('ins-detail-panel');
        if (panel) panel.style.display = 'none';
        currentDetailUid = 0;
    }

    function handlePriorityAction(type) {
        if (type === 'disengagement') setFilter('disengaged', document.querySelector('[data-filter="disengaged"]'));
        else if (type === 'recap_needed') setFilter('struggling', document.querySelector('[data-filter="struggling"]'));
        else if (type === 'issues') setFilter('issues', document.querySelector('[data-filter="issues"]'));
        var zone = document.getElementById('ins-students-zone');
        if (zone) zone.scrollIntoView({ behavior: 'smooth' });
    }

    // ════════════════════════════════════════════════════════════════
    // Action Drawer
    // ════════════════════════════════════════════════════════════════
    function openActionDrawer(action) {
        currentDrawerAction = action;
        var drawer = document.getElementById('ins-action-drawer');
        var title = document.getElementById('ins-drawer-title');
        var recipient = document.getElementById('ins-drawer-recipient');
        var message = document.getElementById('ins-drawer-message');
        if (!drawer) return;

        var actionNames = { encouragement: 'Send Encouragement', meeting: 'Schedule 1:1', remedial_quiz: 'Assign Remedial Quiz' };
        if (title) title.textContent = actionNames[action] || 'Send Intervention';

        var student = null;
        for (var i = 0; i < allStudentNarratives.length; i++) {
            if (allStudentNarratives[i].userid === currentDetailUid) { student = allStudentNarratives[i]; break; }
        }
        if (student && recipient) recipient.textContent = 'To: ' + student.fullname + ' (' + student.risk_level + ')';

        var templates = {
            encouragement: 'Hi {{name}}, I noticed you might be struggling with the course material. Remember that I\'m here to help — don\'t hesitate to reach out or use the AI assistant for extra support. Keep going!',
            meeting: 'Hi {{name}}, would you like to schedule a 1:1 meeting this week? I\'d love to discuss how we can help you get back on track. Please let me know what times work for you.',
            remedial_quiz: 'Hi {{name}}, I\'ve prepared some additional practice questions to help reinforce the key concepts. Check your course page for the remedial quiz. Let me know if you have questions!'
        };
        var name = student ? student.fullname.split(' ')[0] : 'Student';
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
        status.textContent = 'Sending...';
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
                status.textContent = 'Message sent successfully!';
                status.style.color = 'var(--u-p)';
                setTimeout(closeActionDrawer, 2000);
            } else if (resp.status === 'cooldown') {
                status.textContent = 'Already sent within 24h. Please wait.';
                status.style.color = '#f59e0b';
            } else {
                status.textContent = 'Failed: ' + (resp.message || 'Unknown error');
                status.style.color = 'var(--u-ter)';
            }
            btn.disabled = false;
        }).fail(function() {
            status.textContent = 'Connection error. Please try again.';
            status.style.color = 'var(--u-ter)';
            btn.disabled = false;
        });
    }

    // ════════════════════════════════════════════════════════════════
    // NLQ Search
    // ════════════════════════════════════════════════════════════════
    function submitNLQ() {
        var input = document.getElementById('ins-nlq-input');
        var response = document.getElementById('ins-nlq-response');
        var spinner = document.getElementById('ins-nlq-spinner');
        if (!input || !response) return;
        var q = input.value.trim();
        if (!q || !cid) return;

        if (activeStream && activeStream.abort) activeStream.abort();

        response.style.display = 'block';
        if (spinner) spinner.style.display = 'inline-block';
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
                if (spinner) spinner.style.display = 'none';
                input.disabled = false;
            },
            onError: function(err) {
                activeStream = null;
                if (spinner) spinner.style.display = 'none';
                input.disabled = false;
                response.innerHTML = '<span style="color:var(--u-ter);">' + esc(err.message || 'Failed to query AI service.') + '</span>';
            }
        });
    }

    // ════════════════════════════════════════════════════════════════
    // Utilities
    // ════════════════════════════════════════════════════════════════
    function esc(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    return {
        init: init,
        loadData: loadData,
        setFilter: setFilter,
        loadStudentDetail: loadStudentDetail,
        closeDetail: closeDetail,
        handlePriorityAction: handlePriorityAction,
        openActionDrawer: openActionDrawer,
        closeActionDrawer: closeActionDrawer,
        sendIntervention: sendIntervention,
        submitNLQ: submitNLQ
    };
});
