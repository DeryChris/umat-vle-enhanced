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
    var attendanceData = null;

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

    // ── Request de-duplication ──────────────────────────────────────────
    // get_struggle_insights is expensive: it makes several blocking calls to
    // the AI service. Two things used to trigger redundant fetches — duplicate
    // tab-switch bindings, and re-entering the Insights tab — so the same
    // payload was requested up to five times in one sitting. A request is
    // skipped when an identical one is in flight, or when the last successful
    // response for that course is still within the server's own cache window.
    var inFlight = {};          // courseid -> true while a request is open
    var lastLoadedAt = {};      // courseid -> epoch ms of last success
    var lastPayload = {};       // courseid -> last response, for instant re-render
    var FRESH_MS = 120000;      // matches the 120s server-side cache TTL

    function isFresh(courseId) {
        var at = lastLoadedAt[courseId];
        return !!at && (Date.now() - at) < FRESH_MS;
    }

    // ── Initialisation (dual-mode: single course or all-courses) ──
    function init(courseId) {
        cid = parseInt(courseId) || 0;

        if (inFlight[cid]) {
            return; // Identical request already open.
        }
        if (isFresh(cid) && lastPayload[cid]) {
            // Re-render from the cached payload rather than refetching.
            if (cid) { renderAll(lastPayload[cid]); } else { renderAllCourses(lastPayload[cid]); }
            return;
        }

        if (!cid) {
            loadAllCourses();
            return;
        }
        loadData();
    }

    // ── Data loading ──
    function loadData(cb) {
        var requestCid = cid;
        inFlight[requestCid] = true;
        showSkeleton(true);
        Ajax.call([{
            methodname: 'local_umat_ai_get_struggle_insights',
            args: { courseid: requestCid, days: 60 }
        }])[0].done(function(insights) {
            inFlight[requestCid] = false;
            lastLoadedAt[requestCid] = Date.now();
            lastPayload[requestCid] = insights;
            renderAll(insights);
            showSkeleton(false);
            if (cb) cb();
        }).fail(function(err) {
            inFlight[requestCid] = false;
            showSkeleton(false);
            renderError(err);
            if (cb) cb();
        });
    }

    // Visible error state — the dashboard used to render an empty layout on
    // failure, which is indistinguishable from "no data yet".
    function renderError(err) {
        var msg = (err && err.message) ? err.message :
            'Insights could not be loaded. Check that the AI service is running.';
        ['ins-student-list', 'ins-topic-heatmap', 'ins-common-questions'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.innerHTML = '<div class="ins-empty ins-error">' +
                    '<span class="material-symbols-outlined">error_outline</span> ' + esc(msg) + '</div>';
            }
        });
    }

    function showSkeleton(show) {
        var sk = document.getElementById('ins-skeleton');
        var pane = document.querySelector('.umat-insights-pane');
        if (sk) sk.style.display = show ? 'flex' : 'none';
        if (pane) pane.style.opacity = show ? '0.3' : '1';
    }

    // ── All-Courses Mode ──
    function loadAllCourses(cb) {
        inFlight[0] = true;
        showSkeleton(true);
        Ajax.call([{
            methodname: 'local_umat_ai_get_struggle_insights',
            args: { courseid: 0, days: 60 }
        }])[0].done(function(insights) {
            inFlight[0] = false;
            lastLoadedAt[0] = Date.now();
            lastPayload[0] = insights;
            renderAllCourses(insights);
            showSkeleton(false);
            if (cb) cb();
        }).fail(function(err) {
            inFlight[0] = false;
            showSkeleton(false);
            renderError(err);
            if (cb) cb();
        });
    }

    function renderAllCourses(data) {
        if (!data) data = {};
        var mode = data.mode || 'single';

        // Clear any existing course-specific header/selector visual state
        var header = document.querySelector('.umat-insights-header');
        if (header) {
            var modeBadge = header.querySelector('.ins-mode-badge');
            if (!modeBadge) {
                modeBadge = document.createElement('span');
                modeBadge.className = 'ins-mode-badge';
                header.appendChild(modeBadge);
            }
            modeBadge.textContent = mode === 'all_courses' ? 'All Courses' : '';
            modeBadge.style.display = mode === 'all_courses' ? 'inline-block' : 'none';
        }

        // ── Cross-cutting insight banner ──
        var insightBanner = document.getElementById('ins-cross-cutting');
        if (!insightBanner) {
            insightBanner = document.createElement('div');
            insightBanner.id = 'ins-cross-cutting';
            insightBanner.className = 'ins-cross-cutting-banner';
            var container = document.querySelector('.umat-insights-pane');
            if (container) container.insertBefore(insightBanner, container.firstChild);
        }
        var crossInsight = data.all_courses_summary || data.cross_cutting_insight || '';
        insightBanner.innerHTML = crossInsight ?
            '<span class="material-symbols-outlined">insights</span> ' + esc(crossInsight) :
            '<span class="material-symbols-outlined">insights</span> Aggregate view across all your courses.';

        // ── Course cards grid ──
        var cardsContainer = document.getElementById('ins-course-cards');
        if (!cardsContainer) {
            cardsContainer = document.createElement('div');
            cardsContainer.id = 'ins-course-cards';
            cardsContainer.className = 'ins-course-cards-grid';
            var pane = document.querySelector('.umat-insights-pane');
            if (pane) pane.insertBefore(cardsContainer, pane.querySelector('.ins-metrics-row'));
        }

        var courses = data.courses_summary || [];
        if (!courses.length) {
            cardsContainer.innerHTML = '<div class="ins-empty">No course data available yet.</div>';
        } else {
            cardsContainer.innerHTML = courses.map(function(c) {
                var atRisk = c.at_risk || 0;
                var students = c.students || 1;
                var riskColor = atRisk > 5 ? COLORS.critical :
                    (atRisk > 2 ? COLORS.attention : COLORS.watch);
                var trendIcon = c.trend === 'up' ? '↑' : (c.trend === 'down' ? '↓' : '→');
                var trendColor = c.trend === 'up' ? COLORS.critical :
                    (c.trend === 'down' ? COLORS.low : COLORS.muted);
                // Compute health_pct from at_risk if not provided
                var healthPct = c.health_pct || Math.max(5, Math.min(100, Math.round((1 - atRisk / students) * 100)));
                return '<div class="ins-course-card" data-courseid="' + c.id + '" onclick="window.struggleDashboard.selectCourse(' + (c.id || 0) + ')">' +
                    '<div class="ins-course-card-header">' +
                    '<span class="ins-course-card-name">' + esc(c.fullname || 'Course') + '</span>' +
                    '<span class="ins-course-card-trend" style="color:' + trendColor + '">' + trendIcon + '</span>' +
                    '</div>' +
                    '<div class="ins-course-card-stats">' +
                    '<div class="ins-course-card-stat"><span class="ins-course-stat-val">' + students + '</span><span class="ins-course-stat-lbl">Students</span></div>' +
                    '<div class="ins-course-card-stat"><span class="ins-course-stat-val" style="color:' + riskColor + '">' + atRisk + '</span><span class="ins-course-stat-lbl">At Risk</span></div>' +
                    '<div class="ins-course-card-stat"><span class="ins-course-stat-val">' + (c.questions || 0) + '</span><span class="ins-course-stat-lbl">Questions</span></div>' +
                    '</div>' +
                    (c.top_topic ? '<div class="ins-course-card-topic">Top: ' + esc(c.top_topic) + '</div>' : '') +
                    '<div class="ins-course-card-bar"><div class="ins-course-card-bar-fill" style="width:' + healthPct + '%;background:' + riskColor + '"></div></div>' +
                    '</div>';
            }).join('');
        }

        // ── Stats tiles ──
        renderStatTiles(data.course_pulse || {});

        // ── Course pulse mini-sparklines (aggregate) ──
        var pulseZone = document.getElementById('ins-course-pulse');
        if (!pulseZone) {
            pulseZone = document.createElement('div');
            pulseZone.id = 'ins-course-pulse';
            pulseZone.className = 'ins-pulse-grid';
            var pane = document.querySelector('.umat-insights-pane');
            if (pane) pane.insertBefore(pulseZone, pane.querySelector('.ins-charts-row'));
        }
        var pulses = data.course_pulses || [];
        if (pulses.length) {
            pulseZone.innerHTML = pulses.map(function(p) {
                return '<div class="ins-pulse-card">' +
                    '<div class="ins-pulse-name">' + esc(p.name || '') + '</div>' +
                    '<div class="ins-pulse-mini sparkline" data-values="' + esc(p.trend_values || '') + '">' +
                    '</div></div>';
            }).join('');
        } else {
            pulseZone.style.display = 'none';
        }

        // ── Teaching Brief (new v2) ──
        renderTeachingBrief(data);

        // ── Struggle areas (aggregated) ──
        renderTopicHeatmap(data.struggle_areas || []);
        renderV2TopicInsights(data.v2_topic_insights || []);

        // ── Students and questions (aggregated) ──
        renderAtRiskStudents(data.student_narratives || []);
        renderCommonQuestions(data.common_questions || []);
    }

    function selectCourse(courseId) {
        if (courseId > 0) {
            // Update the course selector UI
            var labelEl = document.getElementById('ins-cs-label');
            var list = document.getElementById('ins-cs-list');
            if (list) {
                list.querySelectorAll('.umat-cs-item').forEach(function(it) {
                    var active = parseInt(it.dataset.cid) === courseId;
                    it.classList.toggle('umat-cs-item-active', active);
                    if (active && labelEl) {
                        labelEl.textContent = it.querySelector('.umat-cs-item-name')?.textContent || 'Course';
                    }
                });
            }
            // Reload insights for that course
            init(courseId);
        }
    }

    // ── Master renderer (dual-mode) ──
    function renderAll(data) {
        if (!data) data = {};
        ensureRefreshBtn();
        // If API returned all-courses mode, delegate to renderAllCourses
        if (data.mode === 'all_courses') {
            renderAllCourses(data);
            return;
        }

        // ── HERO: Teaching Brief at the top ──
        renderTeachingBrief(data);

        // ── At-a-glance stats ──
        renderStatTiles(data.course_pulse || {});

        // ── Supporting charts (kept — the template reserves space for them) ──
        renderRiskDonut(data.student_narratives || []);
        renderQuestionSparkline(data.course_pulse || {});

        // ── Topics: what's struggling ──
        renderTopicHeatmap(data.struggle_areas || []);
        renderV2TopicInsights(data.v2_topic_insights || []);

        // ── Students needing attention ──
        renderAtRiskStudents(data.student_narratives || []);
        renderCommonQuestions(data.common_questions || []);

        // ── Provenance: which window was analysed, and when ──
        renderDataProvenance(data.meta || null);
    }

    // Analytics date range and last-updated time. Without these a lecturer
    // cannot tell whether they are looking at today's picture or a cached one.
    function renderDataProvenance(meta) {
        var host = document.querySelector('.umat-insights-pane');
        if (!host) return;

        var el = document.getElementById('ins-data-provenance');
        if (!el) {
            el = document.createElement('div');
            el.id = 'ins-data-provenance';
            el.className = 'ins-provenance';
            host.insertBefore(el, host.firstChild);
        }

        if (!meta) {
            el.style.display = 'none';
            return;
        }
        el.style.display = '';

        var fmt = function(ts) {
            return new Date(ts * 1000).toLocaleDateString(undefined,
                { day: 'numeric', month: 'short', year: 'numeric' });
        };
        var generated = new Date((meta.generated_at || 0) * 1000);
        var ageMin = Math.max(0, Math.round((Date.now() - generated.getTime()) / 60000));
        var stale = ageMin > 60;

        el.innerHTML =
            '<span class="material-symbols-outlined">calendar_month</span>' +
            '<span class="ins-provenance-range">' +
                esc(fmt(meta.date_from)) + ' – ' + esc(fmt(meta.date_to)) +
                ' (' + (meta.window_days || 0) + ' days)' +
            '</span>' +
            '<span class="ins-provenance-sep">·</span>' +
            '<span class="ins-provenance-updated' + (stale ? ' ins-provenance-stale' : '') + '">' +
                'Updated ' + (ageMin < 1 ? 'just now' : ageMin + ' min ago') +
                (stale ? ' — may be out of date' : '') +
            '</span>';
    }

    // ── Refresh button (topbar) ──

    // ── Refresh button (topbar) ──
    function ensureRefreshBtn() {
        // Button exists in topbar (overlay_helper.php) — just verify it's wired
        var btn = document.getElementById('ins-refresh-btn');
        if (!btn) {
            // Fallback: not in overlay context, create one dynamically
            var target = document.getElementById('ins-stat-tiles') || document.querySelector('.umat-insights-pane');
            if (!target) return;
            btn = document.createElement('button');
            btn.id = 'ins-refresh-btn';
            btn.className = 'umat-content-hdr-btn ins-topbar-refresh';
            btn.type = 'button';
            btn.title = 'Refresh insights';
            btn.setAttribute('aria-label', 'Refresh insights');
            btn.innerHTML = '<span class="material-symbols-outlined" id="ins-refresh-icon">refresh</span>';
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                refresh();
            });
            target.parentNode.insertBefore(btn, target.nextSibling);
        }
    }

    function refresh() {
        // An explicit refresh always refetches: clear the freshness marker so
        // the de-duplication guard does not short-circuit the user's request.
        lastLoadedAt[cid] = 0;
        var btn = document.getElementById('ins-refresh-btn');
        var icon = document.getElementById('ins-refresh-icon');
        if (btn) btn.disabled = true;
        if (icon) {
            icon.textContent = 'sync';
            icon.classList.add('ins-spin');
        }
        var doneTimer = function() {
            if (icon) {
                icon.textContent = 'check';
                icon.classList.remove('ins-spin');
            }
            if (btn) btn.disabled = false;
            setTimeout(function() {
                if (icon) icon.textContent = 'refresh';
            }, 1500);
        };
        if (cid) {
            loadDataWithCallback(doneTimer);
        } else {
            loadAllCoursesWithCallback(doneTimer);
        }
    }

    // Thin aliases retained so existing callers keep working. The loaders
    // themselves already accept a completion callback, so the duplicated
    // request bodies that used to live here have been removed.
    function loadDataWithCallback(cb) { loadData(cb); }
    function loadAllCoursesWithCallback(cb) { loadAllCourses(cb); }

    // ════════════════════════════════════════════════════════════════
    // STAT TILES
    // ════════════════════════════════════════════════════════════════
    function renderStatTiles(pulse) {
        var totalStudents = pulse.total_students || 0;
        var atRisk = pulse.at_risk_count || 0;
        var active = pulse.active_this_week || 0;

        // Students: enrolment, not a growth rate. This tile used to render the
        // QUESTION-volume trend percentage next to the student head-count,
        // which is where "+957%" came from — 11 questions this week against 1
        // last week. Enrolment is a count; it has no weekly trend worth showing.
        setTileValue('ins-stat-students', totalStudents,
            '<span class="ins-trend-sub">enrolled</span>');

        // At risk: medium or above on the risk model, stated as a share of the
        // class rather than as an invented trend.
        setTileValue('ins-stat-at-risk', atRisk,
            totalStudents ? '<span class="ins-trend-sub">of ' + totalStudents + '</span>' : '');

        // Avg quiz: real graded attempts, or an em dash when there are none.
        // It used to be 100 minus the average risk score.
        if (pulse.avg_quiz_available) {
            var attempts = pulse.quiz_attempts || 0;
            setTileValue('ins-stat-quiz', (pulse.avg_quiz || 0) + '%',
                '<span class="ins-trend-sub">' + attempts +
                ' attempt' + (attempts === 1 ? '' : 's') + '</span>');
        } else {
            setTileValue('ins-stat-quiz', '—',
                '<span class="ins-trend-sub">no graded attempts</span>');
        }

        setTileValue('ins-stat-active', active,
            totalStudents ? '<span class="ins-trend-sub">of ' + totalStudents + ' this week</span>' : '');

        // BBB Attendance tile
        if (pulse.bbb_available && pulse.bbb_total_sessions > 0) {
            var bbbRate = Math.round((pulse.bbb_avg_attendance_rate || 0) * 100);
            var attendedCount = pulse.bbb_attended_count || 0;
            setTileValue('ins-stat-attendance', bbbRate + '%',
                '<span class="ins-trend-sub">' + attendedCount + ' attended</span>');
        } else {
            setTileValue('ins-stat-attendance', '—',
                '<span class="ins-trend-sub">no BBB sessions</span>');
        }
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

        // The risk model emits critical/high/medium/low; the donut shows three
        // bands, so critical folds into the high segment.
        var counts = { high: 0, medium: 0, low: 0 };
        students.forEach(function(s) {
            var level = (s.v2_risk ? s.v2_risk.risk_level : s.risk_level) || 'low';
            if (level === 'critical') level = 'high';
            if (!(level in counts)) level = 'low';
            counts[level]++;
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
    // Academic question activity. Shows absolute counts and the comparison
    // period; the percentage appears only when the previous period is large
    // enough to divide by (the server returns questions_trend_comparable).
    // It also states what a change means, rather than leaving a bare arrow.
    function renderQuestionSparkline(pulse) {
        var el = document.getElementById('ins-question-sparkline');
        if (!el) return;

        var thisWeek = pulse.questions_this_week || 0;
        var lastWeek = pulse.questions_last_week || 0;
        var trend = pulse.questions_trend || 'stable';
        var comparable = !!pulse.questions_trend_comparable;
        var trendPct = pulse.questions_trend_pct;

        if (!thisWeek && !lastWeek) {
            el.innerHTML = '<div class="ins-empty">No academic questions in this period.</div>';
            return;
        }

        var maxVal = Math.max(thisWeek, lastWeek, 1);
        var lastH = Math.round((lastWeek / maxVal) * 80);
        var thisH = Math.round((thisWeek / maxVal) * 80);

        // Rising question volume is not automatically bad — it is only a
        // warning sign when performance is weak at the same time.
        var quizWeak = pulse.avg_quiz_available && (pulse.avg_quiz || 0) < 50;
        var reading;
        if (trend === 'up' && quizWeak) {
            reading = 'More questions while quiz scores stay low — this reads as confusion, not curiosity.';
        } else if (trend === 'up') {
            reading = 'More questions with performance holding up — this reads as engagement.';
        } else if (trend === 'down' && comparable) {
            reading = 'Fewer questions than last week. Worth checking whether students still have access.';
        } else {
            reading = 'Question activity is steady.';
        }

        var pctChip = (comparable && trendPct !== null && trendPct !== undefined)
            ? '<span class="ins-sparkline-trend" style="color:' +
              (trend === 'up' ? COLORS.blue : (trend === 'down' ? COLORS.attention : COLORS.muted)) + '">' +
              (trendPct > 0 ? '+' : '') + trendPct + '%</span>'
            : '<span class="ins-sparkline-trend" style="color:' + COLORS.muted + '"' +
              ' title="Too few questions last week to express this as a percentage">—</span>';

        var excluded = (pulse.messages_greeting || 0) + (pulse.messages_command || 0) +
            (pulse.messages_filler || 0);

        el.innerHTML = '<div class="ins-sparkline-container">' +
            '<div class="ins-bars-pair">' +
            '<div class="ins-bar-col">' +
            '<div class="ins-bar-fill" style="height:' + lastH + 'px;background:' + COLORS.muted + '"></div>' +
            '<div class="ins-bar-label">Last week</div>' +
            '<div class="ins-bar-val">' + lastWeek + '</div>' +
            '</div>' +
            '<div class="ins-bar-col">' +
            '<div class="ins-bar-fill" style="height:' + thisH + 'px;background:' + COLORS.blue + '"></div>' +
            '<div class="ins-bar-label">This week</div>' +
            '<div class="ins-bar-val">' + thisWeek + '</div>' +
            '</div>' +
            '</div>' +
            '<div class="ins-sparkline-meta">' +
            pctChip +
            '<span class="ins-sparkline-sub">' + esc(reading) + '</span>' +
            (excluded ? '<span class="ins-sparkline-sub ins-sparkline-note">' + excluded +
                ' greeting/command message' + (excluded === 1 ? '' : 's') + ' excluded</span>' : '') +
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

        // Filter out non-academic topics (test issues, login issues, etc.)
        var nonAcademicTopics = /^(test issue|login issue report|login issue)$/i;
        var filtered = areas.filter(function(a) {
            var topic = a.topic || a.topic_name || '';
            return !nonAcademicTopics.test(topic);
        });

        if (!filtered.length) {
            el.innerHTML = '<div class="ins-empty">No academic struggle topics yet.</div>';
            return;
        }

        // Sort by struggle_score descending
        var sorted = filtered.slice().sort(function(a, b) {
            return (b.struggle_score || 0) - (a.struggle_score || 0);
        });

        el.innerHTML = sorted.map(function(a) {
            var score = a.struggle_score || 0;
            var color = a.severity === 'critical' ? COLORS.critical :
                (a.severity === 'attention' ? COLORS.attention : COLORS.watch);

            var trendIcon = a.trend === 'up' ? '<span class="material-symbols-outlined" style="color:' + COLORS.critical + '">trending_up</span>' :
                (a.trend === 'down' ? '<span class="material-symbols-outlined" style="color:' + COLORS.low + '">trending_down</span>' :
                '<span class="material-symbols-outlined" style="color:' + COLORS.muted + '">trending_flat</span>');

            // A topic that affects nobody has no struggle percentage to show.
            var affected = a.student_count || 0;
            var scoreCell = affected > 0
                ? '<div class="ins-heatmap-bar" style="width:' + score + '%;background:' + color + '">' +
                  '<span class="ins-heatmap-bar-val">' + score + '</span></div>'
                : '<div class="ins-heatmap-bar ins-heatmap-bar-empty">' +
                  '<span class="ins-heatmap-bar-val">no students affected</span></div>';

            // Spelled out: "131 Qs" and "1 F" meant nothing to a reader.
            var qCount = a.question_count || 0;

            return '<div class="ins-heatmap-row">' +
                '<div class="ins-heatmap-label">' +
                '<span class="ins-heatmap-topic">' + esc(a.topic || a.topic_name || 'Untitled') + '</span>' +
                '<span class="ins-heatmap-students">' + affected + ' of ' + (a.total_students || 0) +
                    ' student' + ((a.total_students || 0) === 1 ? '' : 's') + ' affected</span>' +
                '</div>' +
                '<div class="ins-heatmap-bar-wrap">' + scoreCell + '</div>' +
                '<div class="ins-heatmap-meta">' +
                trendIcon +
                '<span class="ins-heatmap-qcount">' + qCount + ' question' + (qCount === 1 ? '' : 's') + '</span>' +
                '</div>' +
                '</div>';
        }).join('');
    }

    // ════════════════════════════════════════════════════════════════
    // V2 TOPIC INSIGHTS (from analytics/topic_insight_builder)
    // Shows accurate student counts per topic with struggle scores
    // ════════════════════════════════════════════════════════════════
    function renderV2TopicInsights(topics) {
        var el = document.getElementById('ins-topic-v2');
        if (!el) return;
        if (!topics || !topics.length) {
            // Hide the v2 section if no data
            if (el) el.style.display = 'none';
            return;
        }
        if (el) el.style.display = '';

        // Sort by struggle_score descending
        var sorted = topics.slice().sort(function(a, b) {
            return (b.struggle_score || 0) - (a.struggle_score || 0);
        });

        var maxScore = Math.max.apply(null, sorted.map(function(t) { return t.struggle_score || 0; }));
        maxScore = maxScore || 1; // avoid division by zero

        el.innerHTML = '<h4 class="ins-card-title">' +
            '<span class="material-symbols-outlined">forum</span> Recurring lines of enquiry' +
            '</h4>' +
            '<p class="ins-card-note">Clusters of academic questions students actually asked, ' +
            'grouped by intent. Greetings, quiz commands and support issues are excluded.</p>' +
            sorted.map(function(t) {
                var score = t.struggle_score || 0;
                var pctOfMax = Math.round((score / maxScore) * 100);
                var color = score >= 70 ? COLORS.critical :
                    (score >= 40 ? COLORS.attention : COLORS.watch);
                var barWidth = Math.min(100, pctOfMax);
                var students = t.student_count || 0;
                var questions = t.question_count || 0;

                return '<div class="ins-v2-topic-row">' +
                    '<div class="ins-v2-topic-info">' +
                    '<span class="ins-v2-topic-name">' + esc(t.topic_name || t.topic || 'Unknown') + '</span>' +
                    '<span class="ins-v2-topic-count">' +
                        students + ' student' + (students === 1 ? '' : 's') + ' · ' +
                        questions + ' question' + (questions === 1 ? '' : 's') +
                    '</span>' +
                    '</div>' +
                    '<div class="ins-v2-topic-bar-wrap">' +
                    '<div class="ins-v2-topic-bar" style="width:' + barWidth + '%;background:' + color + '">' +
                    '</div>' +
                    '<span class="ins-v2-topic-pct" title="Combined breadth and persistence score">' +
                        score + '</span>' +
                    '</div>' +
                    '</div>';
            }).join('');
    }

    // ════════════════════════════════════════════════════════════════
    // TEACHING BRIEF — the hero element. Actionable, contextual, trend-aware.
    // ════════════════════════════════════════════════════════════════
    function renderTeachingBrief(data) {
        var el = document.getElementById('ins-teaching-brief');
        if (!el) return;

        var pulse = data.course_pulse || {};
        var recommendations = (data.priority_actions || []).slice(0, 4);
        var students = data.student_narratives || [];
        var totalStudents = pulse.total_students || 0;
        var atRisk = students.filter(function(s) {
            var rl = s.v2_risk ? s.v2_risk.risk_level : s.risk_level;
            return rl === 'critical' || rl === 'high';
        });
        var highRisk = students.filter(function(s) {
            var rl = s.v2_risk ? s.v2_risk.risk_level : s.risk_level;
            return rl === 'critical';
        });
        var moderateRisk = students.filter(function(s) {
            var rl = s.v2_risk ? s.v2_risk.risk_level : s.risk_level;
            return rl === 'medium';
        });

        // Compute course health summary
        var healthPct = totalStudents > 0 ? Math.round(((totalStudents - atRisk.length) / totalStudents) * 100) : 100;
        var overallHealth = healthPct >= 80 ? 'good' : (healthPct >= 50 ? 'moderate' : 'poor');

        // Trend comparison from priority_actions
        var hasTrend = false;
        recommendations.forEach(function(r) {
            if (r.urgency === 'critical' || r.urgency === 'high') hasTrend = true;
        });

        // Hide if completely empty
        if (!recommendations.length && atRisk.length === 0 && !data.v2_topic_insights?.length) {
            el.style.display = 'none';
            return;
        }
        el.style.display = '';

        var urgencyColors = { critical: '#dc2626', high: '#f59e0b', medium: '#3b82f6', low: '#22c55e' };
        var healthColor = overallHealth === 'good' ? COLORS.low : (overallHealth === 'moderate' ? COLORS.attention : COLORS.critical);

        var briefHtml = '<div class="ins-brief-header">' +
            '<span class="material-symbols-outlined">psychology</span> AI Teaching Brief' +
            '<span class="ins-health-pill" style="background:' + healthColor + '20;color:' + healthColor + '">' +
            healthPct + '% healthy' +
            '</span>' +
            '</div>';

        // Priority actions
        if (recommendations.length) {
            briefHtml += '<div class="ins-brief-actions">';
            recommendations.forEach(function(r, idx) {
                var color = urgencyColors[r.urgency] || COLORS.muted;
                var icon = r.priority === 'critical' ? 'error' :
                    (r.priority === 'high' ? 'warning' :
                    (r.priority === 'medium' ? 'info' : 'check_circle'));
                briefHtml += '<div class="ins-priority-card" style="border-left-color:' + color + '">' +
                    '<div class="ins-priority-badge" style="background:' + color + '">' + (idx + 1) + '</div>' +
                    '<div class="ins-priority-body">' +
                    '<strong>' + esc(r.title) + '</strong>' +
                    '<p>' + esc(r.text) + '</p>';
                if (r.items && r.items.length) {
                    briefHtml += '<div class="ins-priority-evidence">';
                    r.items.forEach(function(item) {
                        if (item.detail) {
                            briefHtml += '<span class="ins-evidence-chip">' + esc(item.detail) + ' (' + (item.count || 0) + ')</span>';
                        }
                    });
                    briefHtml += '</div>';
                }
                briefHtml += '</div></div>';
            });
            briefHtml += '</div>';
        }

        // Student intervention targets
        if (atRisk.length > 0) {
            briefHtml += '<div class="ins-brief-students">';
            atRisk.slice(0, 3).forEach(function(s) {
                var v2 = s.v2_risk || {};
                var sRiskLevel = v2.risk_level || s.risk_level || 'low';
                var sRiskScore = v2.risk_score || s.risk_score || 0;
                var trendDir = '';
                if (v2.trends && v2.trends.risk) {
                    trendDir = v2.trends.risk.direction === 'declining' ? ' ⬆' :
                        (v2.trends.risk.direction === 'improving' ? ' ⬇' : '');
                }
                var topFactor = (v2.evidence || [])[0];
                var reason = topFactor ? topFactor.detail : (s.struggle_topics && s.struggle_topics.length ? s.struggle_topics.slice(0,2).join(', ') : '');

                briefHtml += '<div class="ins-brief-student">' +
                    '<span class="ins-brief-student-name">' + esc(s.fullname) + '</span>' +
                    '<span class="ins-pill ins-pill-' + sRiskLevel + '">' + sRiskScore + '/100' + trendDir + '</span>' +
                    '<span class="ins-brief-student-reason">' + esc(reason) + '</span>' +
                    '</div>';
            });
            if (atRisk.length > 3) {
                briefHtml += '<div class="ins-brief-more">' + (atRisk.length - 3) + ' more at risk</div>';
            }
            briefHtml += '</div>';
        }

        // Lecture/recording insight
        if (pulse.avg_quiz !== undefined && pulse.total_students > 0) {
            var quizNote = '';
            if (pulse.avg_quiz < 40) {
                quizNote = 'Assessment scores are low — consider a brief recap.';
            } else if (pulse.avg_quiz < 60) {
                quizNote = 'Assessment scores are moderate — some students may need reinforcement.';
            }
            if (quizNote) {
                briefHtml += '<div class="ins-brief-insight">' +
                    '<span class="material-symbols-outlined">school</span> ' +
                    esc(quizNote) +
                    '</div>';
            }
        }

        el.innerHTML = briefHtml;
    }

    // ════════════════════════════════════════════════════════════════
    // AT-RISK STUDENTS (compact list)
    // ════════════════════════════════════════════════════════════════
    function riskLevelOf(s) {
        return (s && s.v2_risk ? s.v2_risk.risk_level : (s ? s.risk_level : 'low')) || 'low';
    }

    function renderAtRiskStudents(students) {
        allStudentNarratives = students || [];

        // Everyone at medium or above. 'critical' was previously omitted from
        // this count, so the most serious cases were excluded from the badge.
        var atRiskCount = allStudentNarratives.filter(function(s) {
            return ['critical', 'high', 'medium'].indexOf(riskLevelOf(s)) !== -1;
        }).length;
        var badge = document.getElementById('ins-at-risk-count');
        if (badge) badge.textContent = atRiskCount;

        applyFilterAndRender();
    }

    // Track expanded student cards
    var expandedStudentCards = {};

    function applyFilterAndRender() {
        var el = document.getElementById('ins-student-list');
        if (!el) return;
        var filtered = applyFilter(allStudentNarratives);

        if (!filtered.length) {
            el.innerHTML = '<div class="ins-empty">No students match the current filter.</div>';
            return;
        }

        el.innerHTML = filtered.map(function(s) {
            var cardId = 'ins-student-' + (s.userid || 'unknown');
            var isExpanded = expandedStudentCards[cardId] || false;

            // Use v2 risk data when available, fall back to legacy fields
            var v2Risk = s.v2_risk || null;
            var riskClass = v2Risk ? v2Risk.risk_level : (s.risk_level || 'low');
            var riskScore = v2Risk ? v2Risk.risk_score : (s.risk_score || 0);
            var confidence = v2Risk ? v2Risk.confidence : null;
            var classification = v2Risk ? v2Risk.classification : 'monitoring';
            var classificationLabel = classification.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });

            var riskLabel = riskClass === 'critical' ? 'Critical Risk' :
                (riskClass === 'high' ? 'High Risk' :
                (riskClass === 'medium' ? 'Medium Risk' : 'Low Risk'));
            var riskColor = riskClass === 'critical' || riskClass === 'high' ? '#dc2626' :
                (riskClass === 'medium' ? '#f59e0b' : '#22c55e');

            // v2 confidence is a 0–1 fraction. This badge used to print it raw,
            // so a fully-evidenced student showed "1% conf".
            var confidenceBadge = '';
            if (confidence !== null && confidence !== undefined) {
                var confPct = Math.round(confidence * 100);
                var confColor = confPct >= 70 ? COLORS.low :
                    (confPct >= 40 ? COLORS.attention : COLORS.critical);
                confidenceBadge = '<span class="ins-confidence-badge" style="border-color:' + confColor + '"' +
                    ' title="How complete the evidence is, not how severe the risk is">' +
                    confPct + '% confidence</span>';
            }

            var trendHtml = '';
            if (v2Risk && v2Risk.trends) {
                var trendKeys = Object.keys(v2Risk.trends).slice(0, 3);
                trendHtml = trendKeys.map(function(k) {
                    var t = v2Risk.trends[k];
                    if (!t || !t.direction) return '';
                    var dir = t.direction === 'improving' ? '↑' : (t.direction === 'declining' ? '↓' : '→');
                    var tColor = t.direction === 'improving' ? COLORS.low :
                        (t.direction === 'declining' ? COLORS.critical : COLORS.muted);
                    return '<span class="ins-trend-chip" style="color:' + tColor + '">' +
                        k + ' ' + dir + '</span>';
                }).join(' ');
            }

            var topics = '';
            if (s.struggle_topics && s.struggle_topics.length) {
                topics = '<span class="ins-student-topics-inline">' +
                    s.struggle_topics.slice(0, 3).map(function(t) {
                        return esc(t);
                    }).join(', ') + '</span>';
            }

            // Collapsed row
            var collapsedRow = '<div class="ins-student-row-collapsed" onclick="event.stopPropagation(); window.struggleDashboard.toggleStudentCard(\'' + esc(cardId) + '\')">' +
                '<img class="ins-student-avatar-sm" src="' + esc(s.profileimageurl || '') + '" alt="" onerror="this.style.display=\'none\'">' +
                '<div class="ins-student-row-left">' +
                    '<strong class="ins-student-row-name">' + esc(s.fullname) + '</strong>' +
                    '<span class="ins-student-classification">' + esc(classificationLabel) + '</span>' +
                '</div>' +
                '<div class="ins-student-row-center">' +
                    '<span class="ins-pill ins-pill-' + riskClass + '">' + riskLabel + '</span>' +
                    '<span class="ins-risk-score-badge ' + riskClass + '">' + riskScore + '</span>' +
                    confidenceBadge +
                '</div>' +
                '<div class="ins-student-row-trends">' + trendHtml + '</div>' +
                '<div class="ins-student-row-right">' +
                    '<span class="ins-student-last-active">' + esc(s.last_active || '') + '</span>' +
                    ((s.days_since_last_login || 0) > 0 ? '<span class="ins-inactive-chip">' + (s.days_since_last_login) + 'd inactive</span>' : '') +
                '</div>' +
                '<span class="material-symbols-outlined ins-expand-icon">' + (isExpanded ? 'expand_less' : 'expand_more') + '</span>' +
            '</div>';

            // Expanded content - only rendered when expanded
            var expandedContent = '';
            if (isExpanded) {
                expandedContent = renderStudentExpandedContent(s);
            }

            return '<div class="ins-student-expandable" data-student-id="' + esc(s.userid || '') + '">' +
                collapsedRow +
                '<div class="ins-student-expanded" id="' + esc(cardId) + '" style="display:' + (isExpanded ? 'block' : 'none') + ';">' +
                    expandedContent +
                '</div>' +
            '</div>';
        }).join('');
    }

    function renderStudentExpandedContent(s) {
        var v2Risk = s.v2_risk || null;
        var riskClass = v2Risk ? v2Risk.risk_level : (s.risk_level || 'low');
        var riskScore = v2Risk ? v2Risk.risk_score : (s.risk_score || 0);

        var html = '';

        // Risk score bar
        html += '<div class="ins-expanded-section">' +
            '<h5><span class="material-symbols-outlined">analytics</span> Risk Assessment</h5>' +
            '<div class="ins-risk-score-bar">' +
                '<div class="ins-risk-score-track">' +
                    '<div class="ins-risk-score-fill ' + riskClass + '" style="width:' + Math.min(100, riskScore) + '%"></div>' +
                '</div>' +
                '<div class="ins-risk-score-label">Risk Score: <strong>' + riskScore + '/100</strong>' +
                (v2Risk && v2Risk.confidence != null ? ' · <span class="ins-confidence">conf: ' + Math.round(v2Risk.confidence * 100) + '%</span>' : '') +
            '</div>' +
        '</div>';

        // Classification chip
        if (v2Risk && v2Risk.classification) {
            var classLabel = v2Risk.classification.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
            html += '<div class="ins-classification-chip ins-pill ins-pill-' + riskClass + '">' + esc(classLabel) + '</div>';
        }

        // v2 Evidence (structured)
        if (v2Risk && v2Risk.evidence && v2Risk.evidence.length) {
            html += '<div class="ins-expanded-section">' +
                '<h5><span class="material-symbols-outlined">search</span> Evidence</h5>' +
                '<div class="ins-evidence-table">' +
                v2Risk.evidence.map(function(e) {
                    return '<div class="ins-evidence-row">' +
                        '<span class="ins-evidence-factor">' + esc(e.factor) + '</span>' +
                        '<span class="ins-evidence-detail">' + esc(e.detail) + '</span>' +
                        '<span class="ins-evidence-score">' + e.points_earned + '/' + e.points_max + ' pts</span>' +
                        '</div>';
                }).join('') +
                '</div>' +
            '</div>';
        } else if (s.evidence && s.evidence.length) {
            html += '<div class="ins-expanded-section">' +
                '<h5><span class="material-symbols-outlined">search</span> Evidence</h5>' +
                '<ul class="ins-evidence-list">' +
                    s.evidence.map(function(e) { return '<li>' + esc(e) + '</li>'; }).join('') +
                '</ul>' +
            '</div>';
        }

        // v2 Trends
        if (v2Risk && v2Risk.trends) {
            var trendKeys = Object.keys(v2Risk.trends).filter(function(k) {
                var t = v2Risk.trends[k];
                return t && t.direction && t.direction !== 'stable';
            });
            if (trendKeys.length) {
                html += '<div class="ins-expanded-section">' +
                    '<h5><span class="material-symbols-outlined">trending_up</span> Trends</h5>' +
                    '<div class="ins-trends-grid">';
                trendKeys.forEach(function(k) {
                    var t = v2Risk.trends[k];
                    var dir = t.direction === 'improving' ? '↑ improving' :
                        (t.direction === 'declining' ? '↓ declining' : '→ stable');
                    var tColor = t.direction === 'improving' ? COLORS.low :
                        (t.direction === 'declining' ? COLORS.critical : COLORS.muted);
                    html += '<div class="ins-trend-item" style="color:' + tColor + '">' +
                        '<span class="ins-trend-label">' + esc(k) + '</span>' +
                        '<span class="ins-trend-dir">' + dir + '</span>' +
                        '</div>';
                });
                html += '</div></div>';
            }
        }

        // AI Summary (v2)
        if (v2Risk && v2Risk.summary) {
            html += '<div class="ins-expanded-section ins-explanation">' +
                '<h5><span class="material-symbols-outlined">psychology</span> AI Analysis</h5>' +
                '<p>' + esc(v2Risk.summary) + '</p>' +
            '</div>';
        } else if (s.explanation) {
            html += '<div class="ins-expanded-section ins-explanation">' +
                '<h5><span class="material-symbols-outlined">psychology</span> AI Analysis</h5>' +
                '<p>' + esc(s.explanation) + '</p>' +
                '<span class="ins-confidence">Confidence: ' + (s.confidence || 0) + '%</span>' +
            '</div>';
        }

        // Recommendations (use v2 from priority_actions or legacy)
        if (s.quick_actions && s.quick_actions.length) {
            html += '<div class="ins-expanded-section">' +
                '<h5><span class="material-symbols-outlined">bolt</span> Quick Actions</h5>' +
                '<div class="ins-quick-actions">' +
                    s.quick_actions.map(function(a) {
                        return '<button class="ins-action-chip" onclick="event.stopPropagation(); window.struggleDashboard.handleStudentAction(\'' +
                            esc(a.action) + '\', ' + (s.userid || 0) + ', ' + (cid || 0) + ')">' +
                            '<span class="material-symbols-outlined">' + esc(a.icon) + '</span> ' +
                            esc(a.label) + '</button>';
                    }).join('') +
                '</div>' +
            '</div>';
        }

        // Lazy-loaded Activity Timeline
        html += '<div class="ins-expanded-section" id="ins-timeline-' + (s.userid || '') + '">' +
            '<h5><span class="material-symbols-outlined">history</span> Activity Timeline</h5>' +
            '<div class="ins-timeline-loading">' +
                '<span class="material-symbols-outlined ins-spin">progress_activity</span> Loading timeline...' +
            '</div>' +
        '</div>';

        return html;
    }

    function toggleStudentCard(cardId) {
        var expanded = document.getElementById(cardId);
        var card = expanded ? expanded.closest('.ins-student-expandable') : null;
        if (!expanded || !card) return;

        var isExpanded = expanded.style.display === 'block';
        if (isExpanded) {
            expanded.style.display = 'none';
            expandedStudentCards[cardId] = false;
            var icon = card.querySelector('.ins-expand-icon');
            if (icon) icon.textContent = 'expand_more';
        } else {
            expanded.style.display = 'block';
            expandedStudentCards[cardId] = true;
            var icon = card.querySelector('.ins-expand-icon');
            if (icon) icon.textContent = 'expand_less';

            // Lazy-load timeline
            var studentId = card.dataset.studentId;
            if (studentId) {
                loadStudentTimeline(parseInt(studentId));
            }
        }
    }

    function loadStudentTimeline(userid) {
        var container = document.getElementById('ins-timeline-' + userid);
        if (!container || container.dataset.loaded) return;
        container.dataset.loaded = '1';

        Ajax.call([{
            methodname: 'local_umat_ai_get_student_profile',
            args: { courseid: cid, userid: userid }
        }])[0].done(function(profile) {
            var html = '';

            if (profile.total_logins !== undefined) {
                html += '<div class="ins-timeline-stat">Total Logins: <strong>' + profile.total_logins + '</strong></div>';
            }
            if (profile.avg_quiz !== undefined) {
                html += '<div class="ins-timeline-stat">Avg Quiz: <strong>' + profile.avg_quiz + '%</strong></div>';
            }
            if (profile.ai_queries !== undefined) {
                html += '<div class="ins-timeline-stat">AI Queries: <strong>' + profile.ai_queries + '</strong></div>';
            }

            // v2 risk summary
            if (profile.v2_risk) {
                var v2 = profile.v2_risk;
                html += '<div class="ins-v2-risk-summary">';
                html += '<strong>Risk: ' + (v2.risk_score || 0) + '/100</strong>';
                if (v2.classification) {
                    html += ' · ' + esc(v2.classification.replace(/_/g, ' '));
                }
                if (v2.confidence != null) {
                    html += ' · conf: ' + Math.round(v2.confidence * 100) + '%';
                }
                html += '</div>';
                if (v2.summary) {
                    html += '<p class="ins-v2-summary">' + esc(v2.summary) + '</p>';
                }
                if (v2.evidence && v2.evidence.length) {
                    html += '<ul class="ins-evidence-list">';
                    v2.evidence.forEach(function(e) {
                        html += '<li><strong>' + esc(e.factor) + ':</strong> ' + esc(e.detail) + ' (' + e.points_earned + '/' + e.points_max + ' pts)</li>';
                    });
                    html += '</ul>';
                }
                if (v2.trends) {
                    var trendKeys = Object.keys(v2.trends).filter(function(k) {
                        var t = v2.trends[k];
                        return t && t.direction && t.direction !== 'stable';
                    });
                    if (trendKeys.length) {
                        html += '<div class="ins-trends-grid">';
                        trendKeys.forEach(function(k) {
                            var t = v2.trends[k];
                            var dir = t.direction === 'improving' ? '↑ improving' :
                                (t.direction === 'declining' ? '↓ declining' : '→ stable');
                            var tColor = t.direction === 'improving' ? COLORS.low :
                                (t.direction === 'declining' ? COLORS.critical : COLORS.muted);
                            html += '<div class="ins-trend-item" style="color:' + tColor + '">' +
                                '<span class="ins-trend-label">' + esc(k) + '</span>' +
                                '<span class="ins-trend-dir">' + dir + '</span>' +
                                '</div>';
                        });
                        html += '</div>';
                    }
                }
            }

            if (profile.interventions && profile.interventions.length) {
                html += '<ul class="ins-timeline-events">';
                profile.interventions.forEach(function(inv) {
                    var dt = new Date(inv.timecreated * 1000);
                    html += '<li>' +
                        '<span class="ins-timeline-dot ' + (inv.status === 'sent' ? 'sent' : 'pending') + '"></span>' +
                        '<span class="ins-timeline-text">' + esc(inv.action) + ' — ' + esc(inv.status) + '</span>' +
                        '<span class="ins-timeline-time">' + dt.toLocaleDateString() + '</span>' +
                    '</li>';
                });
                html += '</ul>';
            }

            if (!html) {
                html = '<div class="ins-empty" style="font-size:12px">No detailed timeline available.</div>';
            }

            container.innerHTML = '<h5><span class="material-symbols-outlined">history</span> Activity Timeline</h5>' + html;
        }).fail(function() {
            container.innerHTML = '<h5><span class="material-symbols-outlined">history</span> Activity Timeline</h5>' +
                '<div class="ins-empty" style="font-size:12px">Could not load timeline.</div>';
        });
    }

    // Filters now follow the risk model's classification rather than
    // re-deriving categories from question counts in the browser. That is what
    // let a student be "struggling" purely because they asked five questions.
    function applyFilter(students) {
        if (filterMode === 'all') return students;
        return students.filter(function(s) {
            var cls = (s.v2_risk && s.v2_risk.classification) || '';
            if (filterMode === 'disengaged') return cls === 'disengaged';
            if (filterMode === 'struggling') return cls === 'academically_struggling';
            if (filterMode === 'failing') return cls === 'assessment_risk';
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

        // Filter out greetings and non-academic commands
        var greetingPatterns = /^(hi|hello|hey|good\s*(morning|afternoon|evening)|thanks|thank\s*you|ok|okay|yes|no|sure|please|excuse|sorry|how are you|how r u|quiz\s*me|conduct\s*a\s*quiz|start\s*a\s*quiz|give\s*me\s*a\s*quiz|conduct quiz for me)/i;

        var filtered = questions.filter(function(q) {
            var text = (q.text || '').replace(/^\[Referencing:\s*[^\]]+\]\s*/i, '').trim();
            return text.length >= 5 && !greetingPatterns.test(text);
        });

        if (!filtered.length) {
            el.innerHTML = '<div class="ins-empty">No academic questions yet.</div>';
            return;
        }

        el.innerHTML = filtered.slice(0, 6).map(function(q, i) {
            var displayText = (q.text || '').replace(/^\[Referencing:\s*[^\]]+\]\s*/i, '');
            if (displayText.length > 100) displayText = displayText.substring(0, 100) + '...';

            var topic = q.topic || '';
            var meta = '';
            if (q.interpretation) {
                meta += '<span class="ins-question-interpretation">' + esc(q.interpretation) + '</span>';
            }
            if (q.recommendation) {
                meta += '<span class="ins-question-recommendation">' + esc(q.recommendation) + '</span>';
            }
            if (!meta) {
                meta = '<span class="ins-question-count">' + q.student_count + ' students · ' + q.ask_count + ' times</span>';
            }

            return '<div class="ins-question-row">' +
                '<div class="ins-question-rank">' + (i + 1) + '</div>' +
                '<div class="ins-question-body">' +
                '<div class="ins-question-text">' + esc(displayText) + '</div>' +
                '<div class="ins-question-meta">' +
                '<span class="ins-question-tag">' + esc(topic) + '</span>' +
                meta +
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

        // Close the attendance panel if open.
        var attPanel = document.getElementById('ins-attendance-panel');
        if (attPanel) attPanel.style.display = 'none';

        if (!cid) {
            panel.style.display = 'block';
            body.innerHTML = '<div class="ins-empty">Select a specific course to view student details.</div>';
            return;
        }

        var s = null;
        for (var i = 0; i < allStudentNarratives.length; i++) {
            if (allStudentNarratives[i].userid === uid) { s = allStudentNarratives[i]; break; }
        }
        if (s) {
            nameEl.textContent = s.fullname;
            riskBadge.textContent = s.risk_level === 'critical' ? 'Critical Risk' : (s.risk_level === 'high' ? 'High Risk' : (s.risk_level === 'medium' ? 'Medium Risk' : 'Low Risk'));
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
    function handleStudentAction(action, userid, courseId) {
        if (action === 'send_message') {
            currentDetailUid = userid;
            openActionDrawer('encouragement');
            return;
        }
        if (action === 'recommend_resource') {
            currentDetailUid = userid;
            var drawer = document.getElementById('ins-action-drawer');
            var recipient = document.getElementById('ins-drawer-recipient');
            var message = document.getElementById('ins-drawer-message');
            if (drawer) drawer.style.display = 'flex';
            if (recipient) recipient.textContent = 'To: Student #' + userid;
            if (message) message.value = 'Hi, I noticed you might need additional resources. Please check the course materials page for supplementary readings and practice exercises tailored to your current topics.';
            var title = document.getElementById('ins-drawer-title');
            if (title) title.textContent = 'Recommend Resources';
            var status = document.getElementById('ins-drawer-status');
            if (status) status.style.display = 'none';
            return;
        }
        if (action === 'view_activity') {
            var cardId = 'ins-student-' + userid;
            var card = document.getElementById(cardId);
            if (card) {
                card.classList.add('ins-student-expanded');
                setTimeout(function() {
                    var timelineEl = document.getElementById('ins-timeline-' + userid);
                    if (timelineEl) timelineEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 300);
            }
            return;
        }
        if (action === 'view_quiz_history') {
            loadStudentDetail(userid);
            return;
        }
    }

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
        if (!q) return;
        if (!cid) {
            response.style.display = 'block';
            response.innerHTML = '<span style="color:var(--u-muted);">Please select a specific course to use the AI assistant.</span>';
            return;
        }

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
    // Attendance Panel
    // ════════════════════════════════════════════════════════════════
    function openAttendancePanel() {
        var panel = document.getElementById('ins-attendance-panel');
        var body = document.getElementById('ins-attendance-body');
        var summaryEl = document.getElementById('ins-attendance-summary');
        if (!panel || !body) return;

        // Close the student detail panel if open.
        var studentPanel = document.getElementById('ins-detail-panel');
        if (studentPanel) studentPanel.style.display = 'none';

        panel.style.display = 'block';
        body.innerHTML = '<div class="ins-empty">Loading attendance data...</div>';

        if (!cid) {
            body.innerHTML = '<div class="ins-empty">Select a course to view attendance.</div>';
            return;
        }

        Ajax.call([{
            methodname: 'local_umat_ai_get_session_attendance',
            args: { courseid: cid }
        }])[0].done(function(data) {
            attendanceData = data;
            if (!data || !data.sessions || !data.sessions.length) {
                body.innerHTML = '<div class="ins-empty">No BBB attendance data for this course.</div>';
                summaryEl.textContent = 'No sessions';
                return;
            }

            var rate = data.avg_attendance_rate || 0;
            var ratePct = Math.round(rate * 100);
            summaryEl.textContent = data.total_sessions + ' sessions \u00B7 ' + ratePct + '% avg';

            var html = '<div class="ins-attendance-summary-row">' +
                '<span><strong>' + data.total_sessions + '</strong> sessions</span>' +
                '<span><strong>' + ratePct + '%</strong> avg attendance</span>' +
                '<span><strong>' + data.never_attended_count + '</strong> never attended</span>' +
                '</div>';

            html += '<div class="ins-session-list">';
            data.sessions.forEach(function(sess) {
                var startDate = new Date(sess.start_time * 1000);
                var dateStr = startDate.toLocaleDateString(undefined, {
                    weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'
                });
                var timeStr = startDate.toLocaleTimeString(undefined, {
                    hour: '2-digit', minute: '2-digit'
                });

                var presentRows = sess.present_students.length
                    ? sess.present_students.map(function(s) {
                        var dur = s.duration_min !== null ? ' &middot; ' + s.duration_min + ' min' : '';
                        return '<div class="ins-session-student">' +
                            '<span class="ins-session-student-name">' + esc(s.fullname) + '</span>' +
                            '<span class="ins-session-student-email">' + esc(s.email) + '</span>' +
                            '<span class="ins-session-student-dur">' + dur + '</span>' +
                            '</div>';
                    }).join('')
                    : '<div class="ins-session-empty">No students present</div>';

                var absentRows = sess.absent_students.length
                    ? sess.absent_students.map(function(s) {
                        return '<div class="ins-session-student">' +
                            '<span class="ins-session-student-name">' + esc(s.fullname) + '</span>' +
                            '<span class="ins-session-student-email">' + esc(s.email) + '</span>' +
                            '</div>';
                    }).join('')
                    : '<div class="ins-session-empty">All students attended</div>';

                html += '<div class="ins-session-accordion">' +
                    '<div class="ins-session-header" onclick="window.struggleDashboard.toggleSession(this)">' +
                    '<span class="ins-session-arrow">&#9654;</span>' +
                    '<span class="ins-session-name">' + esc(sess.activity_name) + '</span>' +
                    '<span class="ins-session-date">' + dateStr + ' ' + timeStr + '</span>' +
                    '<span class="ins-session-counts">' +
                    '<span class="ins-session-present">' + sess.present_count + '</span>' +
                    '<span class="ins-session-sep">/</span>' +
                    '<span class="ins-session-absent">' + sess.absent_count + '</span>' +
                    '</span>' +
                    '</div>' +
                    '<div class="ins-session-detail" style="display:none;">' +
                    '<div class="ins-session-columns">' +
                    '<div class="ins-session-col">' +
                    '<div class="ins-session-col-header ins-session-col-present">Present (' + sess.present_count + ')</div>' +
                    presentRows +
                    '</div>' +
                    '<div class="ins-session-col">' +
                    '<div class="ins-session-col-header ins-session-col-absent">Absent (' + sess.absent_count + ')</div>' +
                    absentRows +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>';
            });
            html += '</div>';
            body.innerHTML = html;
        }).fail(function() {
            body.innerHTML = '<div class="ins-empty">Failed to load attendance data.</div>';
        });
    }

    function toggleSession(headerEl) {
        var detail = headerEl.nextElementSibling;
        var arrow = headerEl.querySelector('.ins-session-arrow');
        if (!detail) return;
        var isOpen = detail.style.display !== 'none';
        detail.style.display = isOpen ? 'none' : 'block';
        if (arrow) arrow.textContent = isOpen ? '\u25B6' : '\u25BC';
    }

    function closeAttendancePanel() {
        var panel = document.getElementById('ins-attendance-panel');
        if (panel) panel.style.display = 'none';
    }

    function downloadBlob(content, filename, mimeType) {
        var blob = new Blob([content], { type: mimeType });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function() { URL.revokeObjectURL(url); }, 5000);
    }

    function downloadBase64Data(base64, filename, mimeType) {
        var binaryStr = atob(base64);
        var len = binaryStr.length;
        var bytes = new Uint8Array(len);
        for (var i = 0; i < len; i++) {
            bytes[i] = binaryStr.charCodeAt(i);
        }
        var blob = new Blob([bytes], { type: mimeType });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function() { URL.revokeObjectURL(url); }, 5000);
    }

    function showExportNotice(msg, isError) {
        var body = document.getElementById('ins-attendance-body');
        if (!body) return;
        var notice = document.createElement('div');
        notice.className = 'ins-export-notice' + (isError ? ' ins-export-error' : '');
        notice.textContent = msg;
        body.insertBefore(notice, body.firstChild);
        setTimeout(function() { if (notice.parentNode) notice.parentNode.removeChild(notice); }, 4000);
    }

    function exportAttendance(format) {
        var fmtLabel = format.toUpperCase();

        Ajax.call([{
            methodname: 'local_umat_ai_export_attendance',
            args: { courseid: cid, format: format }
        }])[0].done(function(result) {
            if (!result || !result.success || !result.data) {
                showExportNotice(fmtLabel + ' export failed.', true);
                return;
            }
            if (format === 'csv') {
                downloadBlob(result.data, result.filename, result.mimetype || 'text/csv');
            } else {
                downloadBase64Data(result.data, result.filename, result.mimetype);
            }
        }).fail(function(err) {
            var msg = (err && err.message) ? err.message : fmtLabel + ' export failed.';
            showExportNotice(msg, true);
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
        loadAllCourses: loadAllCourses,
        selectCourse: selectCourse,
        refresh: refresh,
        setFilter: setFilter,
        loadStudentDetail: loadStudentDetail,
        closeDetail: closeDetail,
        handlePriorityAction: handlePriorityAction,
        handleStudentAction: handleStudentAction,
        openActionDrawer: openActionDrawer,
        closeActionDrawer: closeActionDrawer,
        sendIntervention: sendIntervention,
        submitNLQ: submitNLQ,
        toggleStudentCard: toggleStudentCard,
        loadStudentTimeline: loadStudentTimeline,
        openAttendancePanel: openAttendancePanel,
        closeAttendancePanel: closeAttendancePanel,
        toggleSession: toggleSession,
        exportAttendance: exportAttendance
    };
});
