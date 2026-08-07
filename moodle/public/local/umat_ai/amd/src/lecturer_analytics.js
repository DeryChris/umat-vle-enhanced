/**
 * Lecturer Analytics Dashboard — redesigned card-grid module.
 *
 * Renders the consolidated payload from `local_umat_ai_get_analytics_data`
 * into the `lecturer_analytics_redesign` template:
 *
 *   1. KPI strip        – 5 glass cards (students / at-risk / quiz avg / active / questions)
 *   2. Executive health – grade letter + plain-English summary + top recommendation
 *   3. NLG insights     – AI-generated "What Needs Your Attention" cards (best effort)
 *   4. Priority actions – actionable cards with item chips + suggestions
 *   5. Charts           – ECharts: performance line, risk donut, topic heatmap
 *   6. At-risk students – filterable student cards (all / critical / warning)
 *   7. Secondary grid   – common questions, quiz, recordings, resources
 *   (The NLQ bar was removed — "Ask About Your Students" lives in the
 *   Ask AI Assistant FAB mini panel, wired in umat_lecturer.js.)
 *
 * Progressive rendering: every section renders as soon as its data is
 * available; skeleton placeholders are removed per-section.
 *
 * Exposes `init(courseId)` and mirrors itself on `window.lecturerAnalytics`
 * (plus a `window.analyticsDashboard` alias so the overlay's existing
 * fallback chain keeps working).
 */
define([
    'core/ajax',
    'local_umat_ai/echarts'
], function(Ajax, EChartsPromise) {
    'use strict';

    var cid = 0;
    var requestedCid = 0;   // course requested at fetch time (fast-switch detection)
    var state = {
        data: null,
        filter: 'all',
        charts: {},          // elementId -> echarts instance
        inFlight: false,
        lastLoaded: 0,
        students: [],
        cache: {},           // courseId -> payload (instant course switching)
        cacheTs: {},         // courseId -> fetch timestamp
        cacheTtl: 60000,     // 60s: render from cache instantly, refresh behind
        prefetching: {}      // courseId -> true (in-flight prefetch)
    };

    var PALETTE = {
        brand: '#006b2f',
        good: '#15803d',
        warn: '#b45309',
        crit: '#b91c1c',
        ink: '#171d17',
        soft: '#5b665a',
        grid: 'rgba(0,0,0,0.07)'
    };

    /* ── Small helpers ─────────────────────────────────────────────── */

    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function num(v, dflt) {
        var n = parseFloat(v);
        return isNaN(n) ? (dflt !== undefined ? dflt : 0) : n;
    }

    function byId(id) {
        return document.getElementById(id);
    }

    function hideSkeletons() {
        document.querySelectorAll('#la-dashboard [data-skel]').forEach(function(el) {
            el.style.display = 'none';
        });
    }

    function showError(msg) {
        hideSkeletons();
        var err = byId('la-error');
        if (err) {
            byId('la-error-text').textContent = msg || 'Failed to load analytics data.';
            err.style.display = 'flex';
        }
    }

    function setCourseLabel() {
        var lbl = byId('la-course-label');
        if (!lbl) return;
        var name = '';
        if (cid === 0) {
            name = 'All Courses';
        } else if (window.CN) {
            name = window.CN;
        } else {
            var insLbl = byId('ins-cs-label');
            if (insLbl && insLbl.textContent && insLbl.textContent !== 'All Courses') {
                name = insLbl.textContent;
            }
        }
        if (name) lbl.textContent = '· ' + name;
        else lbl.style.display = 'none';
    }

    function trendClass(trend) {
        if (trend === 'up' || trend === 'improving' || trend === 'increasing') return 'la-trend-up';
        if (trend === 'down' || trend === 'declining' || trend === 'decreasing' || trend === 'worsening') return 'la-trend-down';
        return 'la-trend-flat';
    }

    function trendIcon(trend) {
        if (trend === 'up' || trend === 'improving' || trend === 'increasing') return 'arrow_upward';
        if (trend === 'down' || trend === 'declining' || trend === 'decreasing' || trend === 'worsening') return 'arrow_downward';
        return 'trending_flat';
    }

    /* ── Data loading (cache-first, deduped, prefetch-aware) ───────── */

    function coursesFromGlobals() {
        var list = (window.UD && window.UD.courses) || [];
        return list.filter(function(c) { return c && c.id; });
    }

    function courseTag(c) {
        var t = c.shortname || c.fullname || '';
        return t ? String(t).trim().split(/\s+/)[0] : 'Course ' + c.id;
    }

    function fetchPayload(courseId) {
        return Ajax.call([{
            methodname: 'local_umat_ai_get_analytics_data',
            args: { courseid: courseId, days: 30 }
        }])[0];
    }

    function loadData(force) {
        var target = cid;
        requestedCid = cid;
        if (!target) {
            loadComposite();
            return;
        }

        // 1. Cache fast path: paint immediately if we have any copy.
        var cached = state.cache[target];
        if (cached) {
            state.data = cached;
            renderAll(cached);
        }
        // 2. Skip the network only while the copy is still fresh.
        if (cached && !force && state.cacheTs[target] && (Date.now() - state.cacheTs[target]) < state.cacheTtl) {
            return;
        }
        // 3. Dedupe: one fetch at a time; on completion re-dispatch for the
        //    currently selected course (so fast switching mid-flight works).
        if (state.inFlight) return;

        state.inFlight = true;
        var errorEl = byId('la-error');
        if (errorEl) errorEl.style.display = 'none';
        fetchPayload(target).done(function(data) {
            state.inFlight = false;
            data = data || {};
            state.cache[target] = data;
            state.cacheTs[target] = Date.now();
            if (cid === 0) { loadComposite(); return; }          // composite upgrades as data lands
            if (requestedCid !== cid) { loadData(true); return; } // switched mid-flight
            state.data = data;
            renderAll(data);
            prefetchAll();
        }).fail(function() {
            state.inFlight = false;
            if (cid === 0) { loadComposite(); return; }
            if (requestedCid !== cid) { loadData(true); return; }
            if (!cached) {
                showError('Failed to load analytics data. Check that you have analytics permission for this course.');
            }
        });
    }

    /* ── "All Courses" composite view (built client-side) ──────────── */

    function loadComposite() {
        var courses = coursesFromGlobals();
        if (!courses.length) return;
        var payload = buildComposite(courses, state.cache);
        if (!payload) {
            prefetchAll(); // nothing cached yet — pull every course, then re-render
            return;
        }
        state.data = payload;
        renderAll(payload);
    }

    function buildComposite(courses, cache) {
        var payloads = [];
        courses.forEach(function(c) { if (cache[c.id]) payloads.push(cache[c.id]); });
        if (!payloads.length) return null;
        var tags = {};
        courses.forEach(function(c) { tags[c.id] = courseTag(c); });

        // KPI cards: totals + student-weighted quiz average.
        var kpis = {};
        var sumKeys = ['students', 'at_risk', 'active', 'questions'];
        var base = payloads[0].kpis || {};
        sumKeys.forEach(function(key) {
            var sum = 0;
            payloads.forEach(function(p) {
                if (p.kpis && p.kpis[key]) sum += num(p.kpis[key].value);
            });
            kpis[key] = {
                value: sum,
                label: (base[key] && base[key].label) || key,
                icon: (base[key] && base[key].icon) || 'bar_chart',
                hint: (base[key] && base[key].hint) || '',
                trend: 'flat',
                trend_pct: 0
            };
        });
        var wsum = 0, wcnt = 0;
        payloads.forEach(function(p) {
            var s = num(p.kpis && p.kpis.students && p.kpis.students.value);
            var q = num(p.kpis && p.kpis.avg_quiz && p.kpis.avg_quiz.value);
            if (q) { wsum += q * (s || 1); wcnt += (s || 1); }
        });
        kpis.avg_quiz = {
            value: wcnt ? wsum / wcnt : 0,
            label: 'Quiz Avg',
            icon: 'quiz',
            hint: 'Average quiz score across all selected courses',
            trend: 'flat',
            trend_pct: 0
        };

        // Executive health: merged highlights + generated summary line.
        var goingWell = [], needsAttention = [];
        payloads.forEach(function(p, i) {
            var tag = tags[courses[i].id];
            var h = p.health || {};
            (h.going_well || []).slice(0, 2).forEach(function(t) { goingWell.push('[' + tag + '] ' + t); });
            (h.needs_attention || []).slice(0, 2).forEach(function(t) { needsAttention.push('[' + tag + '] ' + t); });
        });
        var health = {
            grade: '',
            label: '',
            executive_summary: 'Combined view of ' + payloads.length + ' course(s): ' + num(kpis.at_risk.value) +
                ' student(s) at risk, ' + num(kpis.questions.value) + ' question(s) asked this week.',
            going_well: goingWell.slice(0, 3),
            needs_attention: needsAttention.slice(0, 3),
            top_recommendation: ''
        };

        // Priority actions: course-tagged so context stays visible.
        var actions = [];
        payloads.forEach(function(p, i) {
            var tag = tags[courses[i].id];
            (p.priority_actions || []).forEach(function(a) {
                actions.push(Object.assign({}, a, { title: '[' + tag + '] ' + (a.title || 'Action needed') }));
            });
        });

        // Trend: align by day label so course windows can differ slightly.
        var trend = { labels: [], values: [], quiz_trend: '', quiz_trend_pct: 0 };
        var seen = {};
        payloads.forEach(function(p) {
            var t = p.performance_trend || {};
            (t.labels || []).forEach(function(l, i) {
                if (!seen[l]) { seen[l] = true; trend.labels.push(l); trend.values.push(0); }
                trend.values[trend.labels.indexOf(l)] += num((t.values || [])[i]);
            });
        });

        // Risk donut.
        var risk = { good: 0, warning: 0, critical: 0 };
        payloads.forEach(function(p) {
            var r = p.risk_distribution || {};
            risk.good += num(r.good); risk.warning += num(r.warning); risk.critical += num(r.critical);
        });

        // At-risk students: capped + course-tagged so names stay unambiguous.
        var students = [];
        payloads.forEach(function(p, i) {
            var tag = tags[courses[i].id];
            (p.at_risk_students || []).forEach(function(s) {
                if (students.length >= 12) return;
                var copy = Object.assign({}, s);
                if (tag) copy.fullname = tag + ' ' + (s.fullname || 'Student');
                students.push(copy);
            });
        });

        // Topic struggle: keep per-course scores, rebuild heatmap indices.
        var topics = [], heatmap = [];
        payloads.forEach(function(p) {
            var ts = p.topic_struggle || {};
            (ts.topics || []).forEach(function(t, i) {
                if (topics.length >= 6) return;
                var h = (ts.heatmap || [])[i];
                topics.push(t);
                heatmap.push([topics.length - 1, 0, h ? num(h[2]) : 0]);
            });
        });

        // Secondary grid: common questions, quiz stats, recordings, resources.
        var questions = [];
        payloads.forEach(function(p) {
            questions = questions.concat(p.common_questions || []);
        });
        var quiz = { distribution: [] };
        var qsum = 0, qcnt = 0, avgtot = 0, hi = null, lo = null, medtot = 0, mcnt = 0, passtot = 0, pcnt = 0;
        payloads.forEach(function(p) {
            var q = p.quiz_analytics || {};
            if (q.quiz_attempts) qsum += num(q.quiz_attempts);
            if (q.average_score !== null && q.average_score !== undefined) { avgtot += num(q.average_score); qcnt++; }
            if (q.highest_score !== null && q.highest_score !== undefined) hi = hi === null ? num(q.highest_score) : Math.max(hi, num(q.highest_score));
            if (q.lowest_score !== null && q.lowest_score !== undefined) lo = lo === null ? num(q.lowest_score) : Math.min(lo, num(q.lowest_score));
            if (q.median_score !== null && q.median_score !== undefined) { medtot += num(q.median_score); mcnt++; }
            if (q.pass_rate !== null && q.pass_rate !== undefined) { passtot += num(q.pass_rate); pcnt++; }
            quiz.most_failed_questions = (quiz.most_failed_questions || []).concat(q.most_failed_questions || []);
        });
        if (qsum) quiz.quiz_attempts = qsum;
        if (qcnt) quiz.average_score = avgtot / qcnt;
        if (hi !== null) quiz.highest_score = hi;
        if (lo !== null) quiz.lowest_score = lo;
        if (mcnt) quiz.median_score = medtot / mcnt;
        if (pcnt) quiz.pass_rate = passtot / pcnt;
        if ((quiz.most_failed_questions || []).length) {
            quiz.most_failed_questions = quiz.most_failed_questions.slice(0, 3);
        } else {
            delete quiz.most_failed_questions;
        }

        var recordings = [], resources = [];
        payloads.forEach(function(p, i) {
            var tag = tags[courses[i].id];
            (p.recording_analytics || []).forEach(function(r) {
                var copy = Object.assign({}, r);
                if (tag && r.title) copy.title = '[' + tag + '] ' + r.title;
                recordings.push(copy);
            });
            (p.resource_analytics || []).forEach(function(r) {
                var copy = Object.assign({}, r);
                if (tag && r.filename) copy.filename = '[' + tag + '] ' + r.filename;
                resources.push(copy);
            });
        });

        // NLG insights: best effort, course-tagged.
        var insights = [];
        payloads.forEach(function(p, i) {
            var tag = tags[courses[i].id];
            (p.insights || []).forEach(function(ins) {
                var copy = Object.assign({}, ins);
                if (tag && ins.text) copy.text = '[' + tag + '] ' + ins.text;
                insights.push(copy);
            });
        });

        var sources = [];
        payloads.forEach(function(p) {
            ((p.meta && p.meta.data_sources) || []).forEach(function(s) {
                if (sources.indexOf(s) === -1) sources.push(s);
            });
        });

        return {
            kpis: kpis,
            health: health,
            priority_actions: actions.slice(0, 5),
            performance_trend: trend,
            risk_distribution: risk,
            at_risk_students: students,
            topic_struggle: { topics: topics, heatmap: heatmap },
            common_questions: questions.slice(0, 8),
            quiz_analytics: quiz,
            recording_analytics: recordings.slice(0, 5),
            resource_analytics: resources.slice(0, 5),
            insights: insights.slice(0, 4),
            meta: {
                courseid: 0,
                days: 30,
                generated_at: Date.now(),
                data_sources: sources,
                cached: true,
                composite: true,
                courses: courses.map(function(c) { return c.id; })
            }
        };
    }

    /* ── Prefetch: warm the cache for every lecturer course ────────── */

    function prefetchAll() {
        var courses = coursesFromGlobals();
        courses.forEach(function(c) {
            var id = parseInt(c.id, 10) || 0;
            if (!id || id === cid || state.cache[id] || state.prefetching[id]) return;
            state.prefetching[id] = true;
            fetchPayload(id).done(function(data) {
                state.prefetching[id] = false;
                if (data && typeof data === 'object') {
                    state.cache[id] = data;
                    state.cacheTs[id] = Date.now();
                }
                if (cid === 0) loadComposite(); // composite view upgrades as data lands
            }).fail(function() {
                state.prefetching[id] = false;
            });
        });
    }

    /* ── Top-level render ──────────────────────────────────────────── */

    function renderAll(data) {
        setCourseLabel();
        renderKPIs(data.kpis || {});
        renderHealth(data.health || {});
        renderInsights(data.insights || []);
        renderPriorityActions(data.priority_actions || []);
        renderCharts(data);
        state.students = data.at_risk_students || [];
        renderStudents();
        renderCommonQuestions(data.common_questions || []);
        renderQuizAnalytics(data.quiz_analytics || {});
        renderRecordings(data.recording_analytics || []);
        renderResources(data.resource_analytics || []);
        hideSkeletons();
    }

    /* ── KPI strip ─────────────────────────────────────────────────── */

    function renderKPIs(kpis) {
        var strip = byId('la-kpi-strip');
        if (!strip) return;
        var order = ['students', 'at_risk', 'avg_quiz', 'active', 'questions'];
        strip.innerHTML = order.map(function(key) {
            var k = kpis[key] || {};
            var val = num(k.value);
            var display = key === 'avg_quiz' ? Math.round(val) + '%' : String(Math.round(val)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            var trend = k.trend || '';
            var pct = parseInt(k.trend_pct, 10) || 0;
            var hint = esc(k.hint || '');
            var trendHtml = trend ?
                '<div class="la-kpi-trend ' + trendClass(trend) + '">' +
                    '<span class="material-symbols-outlined">' + trendIcon(trend) + '</span>' +
                    Math.abs(pct) + '% vs last week' +
                '</div>' : '';
            return '<div class="la-kpi-card" title="' + hint + '">' +
                '<div class="la-kpi-icon"><span class="material-symbols-outlined">' + esc(k.icon || 'bar_chart') + '</span></div>' +
                '<div class="la-kpi-value">' + display + '</div>' +
                '<div class="la-kpi-label">' + esc(k.label || key) + '</div>' +
                trendHtml +
                (hint ? '<div class="la-kpi-hint">' + hint + '</div>' : '') +
            '</div>';
        }).join('');
    }

    /* ── Executive health briefing ──────────────────────────────────── */

    function renderHealth(health) {
        var grade = byId('la-grade-letter');
        if (grade) {
            grade.textContent = health.grade || '—';
            var card = byId('la-briefing-grade');
            if (card && health.grade) {
                var g = String(health.grade).toUpperCase();
                if (g.indexOf('A') === 0) card.style.background = 'var(--la-good-bg)';
                else if (g.indexOf('B') === 0) card.style.background = 'var(--la-good-bg)';
                else if (g.indexOf('C') === 0) card.style.background = 'var(--la-warn-bg)';
                else card.style.background = 'var(--la-crit-bg)';
            }
        }
        var summary = byId('la-briefing-summary');
        if (summary) summary.textContent = health.executive_summary || 'No executive summary available yet.';
        var reco = byId('la-briefing-reco');
        if (reco) {
            if (health.top_recommendation) {
                reco.textContent = health.top_recommendation;
                reco.style.display = '';
            } else {
                reco.style.display = 'none';
            }
        }
    }

    /* ── NLG insights (AI or empty state) ──────────────────────────── */

    function renderInsights(insights) {
        var list = byId('la-insight-list');
        if (!list) return;
        var source = byId('la-insights-source');
        if (source) source.textContent = 'AI-generated';

        if (!insights || !insights.length) {
            list.innerHTML = '<div class="la-insight la-insight-info">' +
                '<span class="material-symbols-outlined">auto_awesome</span>' +
                '<div class="la-insight-body"><p class="la-insight-text">' +
                'No AI insights available yet. Insights appear once students have engaged with the course.</p></div>' +
            '</div>';
            return;
        }
        list.innerHTML = insights.map(function(ins) {
            var prio = ins.priority === 'high' ? 'high' : (ins.priority === 'low' ? 'low' : 'medium');
            var icon = ins.type === 'at_risk' || ins.type === 'quiz_drop' ? 'warning' :
                       (ins.type === 'improvement' ? 'trending_up' : 'tips_and_updates');
            var actionHtml = (ins.action && ins.action.label) ?
                '<a class="la-insight-action" href="' + esc(ins.action.url || '#') + '" target="_blank">' +
                    esc(ins.action.label) + ' →</a>' : '';
            return '<div class="la-insight la-insight-' + prio + '">' +
                '<span class="material-symbols-outlined">' + icon + '</span>' +
                '<div class="la-insight-body">' +
                    '<p class="la-insight-text">' + esc(ins.text || '') + '</p>' + actionHtml +
                '</div>' +
            '</div>';
        }).join('');
    }

    /* ── Priority actions ───────────────────────────────────────────── */

    function renderPriorityActions(actions) {
        var list = byId('la-priority-list');
        if (!list) return;
        if (!actions || !actions.length) {
            list.innerHTML = '<div class="la-action-card" style="border-left-color:var(--la-good-fg);">' +
                '<span class="material-symbols-outlined" style="color:var(--la-good-fg);">check_circle</span>' +
                '<div style="flex:1;min-width:0;">' +
                    '<p class="la-action-title">Everything looks good</p>' +
                    '<p class="la-action-text">No urgent issues detected. Check the sections below for detailed insights.</p>' +
                '</div></div>';
            return;
        }
        list.innerHTML = actions.map(function(a) {
            var items = (a.items || []).map(function(it) {
                var parts = [];
                if (it.name) parts.push(esc(it.name));
                if (it.students) parts.push(it.students + ' students');
                if (it.pct) parts.push(it.pct + '%');
                if (it.trend) parts.push(esc(it.trend));
                if (it.days) parts.push(it.days + 'd ago');
                return '<span class="la-action-item">' + parts.join(' · ') + '</span>';
            }).join('');
            return '<div class="la-action-card">' +
                '<span class="material-symbols-outlined la-action-icon">' + esc(a.icon || 'flag') + '</span>' +
                '<div style="flex:1;min-width:0;">' +
                    '<p class="la-action-title">' + esc(a.title || 'Action needed') + '</p>' +
                    (a.text ? '<p class="la-action-text">' + esc(a.text) + '</p>' : '') +
                    (items ? '<div class="la-action-items">' + items + '</div>' : '') +
                    (a.suggestion ? '<p class="la-action-suggestion">' + esc(a.suggestion) + '</p>' : '') +
                '</div>' +
            '</div>';
        }).join('');
    }

    /* ── Charts (ECharts) ───────────────────────────────────────────── */

    function chartFallback(el, msg) {
        if (!el) return;
        // Dispose any half-initialized instance so a later retry can re-init.
        var id = el.id;
        if (state.charts[id]) {
            try { state.charts[id].dispose(); } catch (e) { /* noop */ }
            delete state.charts[id];
        }
        el.innerHTML = '<div class="la-empty">' + msg + '</div>';
    }

    function chartsVisible() {
        // The dashboard may auto-init before the lecturer opens the Insights
        // pane; echarts.init on a display:none container can fail. Detect the
        // visibility of the host pane so rendering can be deferred.
        var host = byId('la-dashboard');
        if (host && host.offsetParent === null) return false;
        var pane = document.getElementById('lec-insights');
        if (pane && pane.offsetParent === null) return false;
        return true;
    }

    function watchVisibility(retries) {
        // Re-render charts once the pane becomes visible (poll-based; no
        // dependency on a specific tab framework).
        var waited = 0;
        var iv = setInterval(function() {
            waited += 1;
            if (chartsVisible()) {
                clearInterval(iv);
                renderCharts(state.data || {});
            } else if (waited > (retries || 60)) {
                clearInterval(iv);
            }
        }, 500);
    }

    function renderCharts(data) {
        EChartsPromise.then(function(echarts) {
            if (!chartsVisible()) {
                // Defer until the pane is actually shown, then re-render.
                watchVisibility();
                return;
            }
            // Each chart is rendered independently: a failure in one must
            // never blank the others or masquerade as a library failure.
            var chartErrors = [];
            try { renderTrendChart(echarts, data.performance_trend || {}); }
            catch (e) { chartErrors.push('performance_trend: ' + e.message); chartFallback(byId('la-performance-chart'), 'Chart could not be rendered.'); }
            try { renderRiskChart(echarts, data.risk_distribution || {}); }
            catch (e) { chartErrors.push('risk_distribution: ' + e.message); chartFallback(byId('la-risk-chart'), 'Chart could not be rendered.'); }
            try { renderTopicChart(echarts, data.topic_struggle || {}); }
            catch (e) { chartErrors.push('topic_struggle: ' + e.message); chartFallback(byId('la-topic-heatmap'), 'Chart could not be rendered.'); }
            if (chartErrors.length) {
                console.warn('[umat] chart render error(s):', chartErrors);
            }
            scheduleResize();
        }).catch(function(err) {
            // ECharts library itself unavailable: show readable fallbacks.
            console.warn('[umat] ECharts library unavailable:', err && err.message);
            ['la-performance-chart', 'la-risk-chart', 'la-topic-heatmap'].forEach(function(id) {
                chartFallback(byId(id), 'Chart library unavailable. See the cards below for data.');
            });
        });
    }

    function trendChartOption(data) {
        var labels = data.labels || [];
        var values = data.values || [];
        return {
            grid: { top: 24, right: 16, bottom: 28, left: 40 },
            tooltip: {
                trigger: 'axis',
                backgroundColor: 'rgba(255,255,255,0.96)',
                borderColor: 'rgba(0,0,0,0.08)',
                textStyle: { color: PALETTE.ink, fontSize: 12 }
            },
            xAxis: {
                type: 'category',
                data: labels,
                boundaryGap: false,
                axisLine: { lineStyle: { color: PALETTE.grid } },
                axisLabel: { color: PALETTE.soft, fontSize: 11 }
            },
            yAxis: {
                type: 'value',
                minInterval: 1,
                axisLabel: { color: PALETTE.soft, fontSize: 11 },
                splitLine: { lineStyle: { color: PALETTE.grid } }
            },
            series: [{
                name: 'Activity',
                type: 'line',
                smooth: true,
                symbol: 'circle',
                symbolSize: 7,
                data: values,
                lineStyle: { color: PALETTE.brand, width: 3 },
                itemStyle: { color: PALETTE.brand },
                // Plain-object gradient: avoids depending on the echarts
                // namespace inside this helper (works on any echarts build).
                areaStyle: {
                    color: {
                        type: 'linear',
                        x: 0, y: 0, x2: 0, y2: 1,
                        colorStops: [
                            { offset: 0, color: 'rgba(0, 107, 47, 0.28)' },
                            { offset: 1, color: 'rgba(0, 107, 47, 0.02)' }
                        ]
                    }
                }
            }]
        };
    }

    function renderTrendChart(echarts, trend) {
        var el = byId('la-performance-chart');
        if (!el) return;
        var caption = byId('la-trend-caption');
        if (caption) {
            var t = trend.quiz_trend || '';
            var pct = parseInt(trend.quiz_trend_pct, 10) || 0;
            caption.textContent = t ? 'Quiz trend: ' + (t === 'up' ? '↑' : t === 'down' ? '↓' : '→') + ' ' + Math.abs(pct) + '%' : '';
        }
        if (!(trend.labels || []).length || !(trend.values || []).length) {
            if (state.charts['la-performance-chart']) {
                state.charts['la-performance-chart'].dispose();
                delete state.charts['la-performance-chart'];
            }
            el.innerHTML = '<div class="la-empty">No activity data in this window yet.</div>';
            return;
        }
        var chart = getChart(echarts, el);
        chart.setOption(trendChartOption(trend));
    }

    function renderRiskChart(echarts, dist) {
        var el = byId('la-risk-chart');
        if (!el) return;
        var total = num(dist.good) + num(dist.warning) + num(dist.critical);
        if (!total) {
            if (state.charts['la-risk-chart']) {
                state.charts['la-risk-chart'].dispose();
                delete state.charts['la-risk-chart'];
            }
            el.innerHTML = '<div class="la-empty">No risk data yet.</div>';
            return;
        }
        var chart = getChart(echarts, el);
        chart.setOption({
            tooltip: {
                trigger: 'item',
                backgroundColor: 'rgba(255,255,255,0.96)',
                borderColor: 'rgba(0,0,0,0.08)',
                textStyle: { color: PALETTE.ink, fontSize: 12 }
            },
            legend: {
                bottom: 0,
                textStyle: { color: PALETTE.soft, fontSize: 11 },
                itemWidth: 10,
                itemHeight: 10
            },
            series: [{
                type: 'pie',
                radius: ['48%', '70%'],
                center: ['50%', '44%'],
                avoidLabelOverlap: false,
                itemStyle: { borderRadius: 8, borderColor: '#fff', borderWidth: 3 },
                label: { show: false },
                emphasis: { label: { show: true, fontSize: 15, fontWeight: 'bold' } },
                data: [
                    { value: num(dist.good), name: 'On Track', itemStyle: { color: '#22c55e' } },
                    { value: num(dist.warning), name: 'At Risk', itemStyle: { color: '#f59e0b' } },
                    { value: num(dist.critical), name: 'Critical', itemStyle: { color: '#ef4444' } }
                ]
            }]
        });
    }

    function renderTopicChart(echarts, struggle) {
        var el = byId('la-topic-heatmap');
        if (!el) return;
        var topics = struggle.topics || [];
        var heat = struggle.heatmap || [];
        if (!topics.length || !heat.length) {
            if (state.charts['la-topic-heatmap']) {
                state.charts['la-topic-heatmap'].dispose();
                delete state.charts['la-topic-heatmap'];
            }
            el.innerHTML = '<div class="la-empty">No topic struggle data yet.</div>';
            return;
        }
        var chart = getChart(echarts, el);
        // Heatmap data is [topicIndex, 0, score] — y-axis order must match
        // the raw topic order (no reverse, or indices would misalign).
        chart.setOption({
            grid: { top: 10, right: 60, bottom: 46, left: 90 },
            tooltip: {
                formatter: function(p) {
                    var topicName = topics[p.value[0]] || p.name || '';
                    return '<b>' + esc(topicName) + '</b><br/>Struggle score: ' + p.value[2];
                },
                backgroundColor: 'rgba(255,255,255,0.96)',
                borderColor: 'rgba(0,0,0,0.08)',
                textStyle: { color: PALETTE.ink, fontSize: 12 }
            },
            xAxis: {
                type: 'category',
                data: ['Struggle Level'],
                axisLabel: { color: PALETTE.soft, fontSize: 11 }
            },
            yAxis: {
                type: 'category',
                data: topics,
                axisLabel: {
                    color: PALETTE.soft,
                    fontSize: 11,
                    width: 80,
                    overflow: 'truncate'
                }
            },
            visualMap: {
                min: 0,
                max: 100,
                calculable: false,
                orient: 'vertical',
                right: 0,
                top: 'center',
                textStyle: { color: PALETTE.soft, fontSize: 10 },
                inRange: { color: ['#dcfce7', '#fef3c7', '#fee2e2', '#fca5a5'] }
            },
            series: [{
                type: 'heatmap',
                data: heat,
                label: {
                    show: true,
                    color: '#111',
                    fontSize: 11,
                    formatter: function(p) { return p.value[2]; }
                },
                emphasis: {
                    itemStyle: { shadowBlur: 10, shadowColor: 'rgba(0,0,0,0.4)' }
                }
            }]
        });
    }

    function getChart(echarts, el) {
        var existing = state.charts[el.id];
        if (existing) return existing;
        var chart = echarts.init(el);
        state.charts[el.id] = chart;
        return chart;
    }

    function disposeCharts() {
        Object.keys(state.charts).forEach(function(id) {
            try { state.charts[id].dispose(); } catch (e) { /* noop */ }
        });
        state.charts = {};
    }

    function scheduleResize() {
        setTimeout(resizeCharts, 250);
        setTimeout(resizeCharts, 800);
    }

    function resizeCharts() {
        Object.keys(state.charts).forEach(function(id) {
            var chart = state.charts[id];
            var el = byId(id);
            if (chart && el && el.offsetWidth > 0 && el.offsetHeight > 0) {
                try { chart.resize(); } catch (e) { /* noop */ }
            }
        });
    }

    /* ── At-risk students (filterable) ──────────────────────────────── */

    function studentLevel(student) {
        var level = String(student.risk_level || student.trend || '').toLowerCase();
        if (level === 'critical' || level === 'high' || level === 'failing' || level === 'urgent') return 'critical';
        if (level === 'medium' || level === 'low' || level === 'warning' || level === 'attention' || level === 'watch') return 'warning';
        return 'good';
    }

    function studentAvatar(student) {
        if (student.profileimageurl) {
            return '<img src="' + esc(student.profileimageurl) + '" alt="" loading="lazy"/>';
        }
        var initials = (student.fullname || '?').split(/\s+/).map(function(w) { return w.charAt(0); })
            .slice(0, 2).join('').toUpperCase();
        return '<span class="la-avatar-fallback">' + esc(initials || '?') + '</span>';
    }

    function renderStudents() {
        var list = byId('la-student-list');
        if (!list) return;
        var badge = byId('la-at-risk-count');
        if (badge) badge.textContent = state.students.length;

        var filtered = state.students.filter(function(s) {
            if (state.filter === 'all') return true;
            if (state.filter === 'critical') return studentLevel(s) === 'critical';
            return studentLevel(s) === 'warning';
        });

        if (!filtered.length) {
            list.innerHTML = '<div class="la-empty">' +
                (state.students.length ? 'No students in this filter.' : 'No at-risk students yet — great job!') + '</div>';
            return;
        }

        list.innerHTML = filtered.map(function(s) {
            var level = studentLevel(s);
            var levelLabel = level === 'critical' ? 'Critical' : (level === 'warning' ? 'Watch' : 'On track');
            var meta = [];
            if (s.avg_quiz !== null && s.avg_quiz !== undefined) meta.push('Quiz: <b>' + Math.round(num(s.avg_quiz)) + '%</b>');
            if (s.question_count) meta.push('<b>' + s.question_count + '</b> Qs');
            if (s.days_since_last_login !== null && s.days_since_last_login !== undefined) {
                meta.push('Inactive <b>' + s.days_since_last_login + 'd</b>');
            }
            var actions = (s.quick_actions || []).slice(0, 2).map(function(qa) {
                return '<button type="button" class="la-btn la-btn-icon" data-uid="' + esc(s.userid) + '" ' +
                    'data-qact="' + esc(qa.action || '') + '" title="' + esc(qa.label || qa.action || '') + '" aria-label="' + esc(qa.label || '') + '">' +
                    '<span class="material-symbols-outlined">' + esc(qa.icon || 'arrow_forward') + '</span></button>';
            }).join('');
            var narrative = s.summary || s.ai_narrative || s.suggestion || '';
            return '<div class="la-student" data-uid="' + esc(s.userid) + '" data-level="' + level + '">' +
                '<div class="la-student-avatar">' + studentAvatar(s) + '</div>' +
                '<div class="la-student-info">' +
                    '<div class="la-student-name">' + esc(s.fullname || 'Student') +
                        '<span class="la-status-pill la-status-' + level + '">' + levelLabel + '</span></div>' +
                    '<div class="la-student-meta">' + meta.join(' · ') + '</div>' +
                    (narrative ? '<div class="la-student-narrative">' + esc(narrative) + '</div>' : '') +
                '</div>' +
                (actions ? '<div class="la-student-actions">' + actions + '</div>' : '') +
            '</div>';
        }).join('');
    }

    function setFilter(f) {
        state.filter = f || 'all';
        document.querySelectorAll('#la-filters .la-chip').forEach(function(chip) {
            chip.classList.toggle('la-chip-active', chip.getAttribute('data-filter') === state.filter);
        });
        renderStudents();
    }

    /* ── Secondary grid ─────────────────────────────────────────────── */

    function renderCommonQuestions(questions) {
        var list = byId('la-common-questions');
        if (!list) return;
        if (!questions.length) {
            list.innerHTML = '<div class="la-empty">No student questions yet.</div>';
            return;
        }
        list.innerHTML = questions.slice(0, 8).map(function(q) {
            var sub = [];
            if (q.topic) sub.push(esc(q.topic));
            if (q.student_count) sub.push(q.student_count + ' students');
            if (q.suggestion) sub.push(esc(q.suggestion));
            return '<div class="la-question">' +
                '<span class="la-question-count">×' + esc(q.ask_count || q.student_count || '?') + '</span>' +
                '<div><p class="la-question-text">&ldquo;' + esc(q.text || '') + '&rdquo;</p>' +
                (sub.length ? '<p class="la-question-sub">' + sub.join(' · ') + '</p>' : '') +
                '</div></div>';
        }).join('');
    }

    function renderQuizAnalytics(quiz) {
        var box = byId('la-quiz-analytics');
        if (!box) return;
        if (!quiz || (!quiz.quiz_attempts && !quiz.average_score)) {
            box.innerHTML = '<div class="la-empty">No quiz analytics yet.</div>';
            return;
        }
        var html = '';
        var rows = [
            ['Attempts', quiz.quiz_attempts, null, ''],
            ['Average', quiz.average_score !== null && quiz.average_score !== undefined ? Math.round(quiz.average_score) + '%' : '—', quiz.average_score, 'score'],
            ['Highest', quiz.highest_score !== null && quiz.highest_score !== undefined ? Math.round(quiz.highest_score) + '%' : '—', quiz.highest_score, 'score'],
            ['Lowest', quiz.lowest_score !== null && quiz.lowest_score !== undefined ? Math.round(quiz.lowest_score) + '%' : '—', quiz.lowest_score, 'score'],
            ['Median', quiz.median_score !== null && quiz.median_score !== undefined ? Math.round(quiz.median_score) + '%' : '—', quiz.median_score, 'score'],
            ['Pass rate', quiz.pass_rate !== null && quiz.pass_rate !== undefined ? Math.round(quiz.pass_rate) + '%' : '—', quiz.pass_rate, 'score']
        ];
        rows.forEach(function(r) {
            var pct = r[2] !== null && r[2] !== undefined ? Math.max(0, Math.min(100, Math.round(r[2]))) : null;
            html += '<div class="la-quiz-score-row">' +
                '<span class="la-quiz-score-label">' + r[0] + '</span>' +
                '<span class="la-quiz-bar-track">' +
                    (pct !== null ? '<span class="la-quiz-bar-fill" style="width:' + pct + '%"></span>' : '') +
                '</span>' +
                '<span class="la-quiz-score-val">' + r[1] + '</span></div>';
        });

        var dist = quiz.distribution || [];
        if (dist.length) {
            var max = 1;
            dist.forEach(function(d) { max = Math.max(max, num(d.count)); });
            html += '<div class="la-quiz-dist">' + dist.map(function(d) {
                var h = Math.round(num(d.count) / max * 100);
                return '<div class="la-dist-cell"><div class="la-dist-bar">' +
                    '<div class="la-dist-fill" style="height:' + h + '%"></div></div>' +
                    '<div class="la-dist-grade">' + esc(d.grade) + '</div></div>';
            }).join('') + '</div>';
        }

        var failed = quiz.most_failed_questions || [];
        if (failed.length) {
            html += '<div class="la-quiz-reco"><b>Most missed:</b> ' +
                failed.slice(0, 3).map(function(q) {
                    return esc(q.question || '') + ' (' + Math.round(num(q.wrong_pct)) + '% wrong)';
                }).join('; ') + '</div>';
        }
        box.innerHTML = html;
    }

    function renderRecordings(recordings) {
        var list = byId('la-recording-analytics');
        if (!list) return;
        if (!recordings.length) {
            list.innerHTML = '<div class="la-empty">No recording analytics yet.</div>';
            return;
        }
        list.innerHTML = recordings.slice(0, 5).map(function(r) {
            var meta = [];
            if (r.views !== null && r.views !== undefined) meta.push('<b>' + r.views + '</b> views');
            if (r.completion_rate !== null && r.completion_rate !== undefined) meta.push('<b>' + Math.round(r.completion_rate) + '%</b> completion');
            if (r.avg_watch_duration_min !== null && r.avg_watch_duration_min !== undefined) meta.push(Math.round(r.avg_watch_duration_min) + 'm avg watch');
            if (r.never_watched_count) meta.push('<b>' + r.never_watched_count + '</b> never watched');
            return '<div class="la-recording">' +
                '<p class="la-item-title">' + esc(r.title || 'Recording') + '</p>' +
                '<div class="la-item-meta">' + meta.join(' · ') + '</div>' +
                (r.recommendation ? '<p class="la-item-reco">' + esc(r.recommendation) + '</p>' : '') +
            '</div>';
        }).join('');
    }

    function renderResources(resources) {
        var list = byId('la-resource-analytics');
        if (!list) return;
        if (!resources.length) {
            list.innerHTML = '<div class="la-empty">No resource analytics yet.</div>';
            return;
        }
        list.innerHTML = resources.slice(0, 5).map(function(r) {
            var meta = [];
            if (r.unique_viewers !== null && r.unique_viewers !== undefined) meta.push('<b>' + r.unique_viewers + '</b> viewers');
            if (r.downloads) meta.push('<b>' + r.downloads + '</b> downloads');
            if (r.avg_reading_time_min !== null && r.avg_reading_time_min !== undefined) meta.push(Math.round(r.avg_reading_time_min) + 'm reading');
            if (r.students_never_opened) meta.push('<b>' + r.students_never_opened + '</b> never opened');
            return '<div class="la-resource">' +
                '<p class="la-item-title">' + esc(r.filename || 'Resource') + '</p>' +
                '<div class="la-item-meta">' + meta.join(' · ') + '</div>' +
                (r.recommendation ? '<p class="la-item-reco">' + esc(r.recommendation) + '</p>' : '') +
            '</div>';
        }).join('');
    }

    /* ── Events ─────────────────────────────────────────────────────── */

    function bindEvents() {
        var refresh = byId('la-refresh-btn');
        if (refresh) {
            refresh.addEventListener('click', function() {
                state.lastLoaded = 0; // force refetch
                loadData(true);
            });
        }
        document.querySelectorAll('#la-filters .la-chip').forEach(function(chip) {
            chip.addEventListener('click', function() {
                setFilter(chip.getAttribute('data-filter'));
            });
        });
        var retry = byId('la-error-retry');
        if (retry) retry.addEventListener('click', function() { loadData(true); });
        // Student quick actions: reveal the student's suggestion.
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('[data-qact]');
            if (!btn) return;
            var uid = btn.getAttribute('data-uid');
            var student = null;
            state.students.forEach(function(s) {
                if (String(s.userid) === String(uid)) student = s;
            });
            if (!student) return;
            var card = btn.closest('.la-student');
            if (!card) return;
            var existing = card.querySelector('.la-student-narrative');
            var text = student.suggestion || student.ai_narrative || student.summary || '';
            if (!text) return;
            if (existing) {
                existing.remove();
            } else {
                var div = document.createElement('div');
                div.className = 'la-student-narrative';
                div.textContent = text;
                card.querySelector('.la-student-info').appendChild(div);
            }
        });
        // Reflow charts when the pane becomes visible (tab switch / overlay open).
        if (window.ResizeObserver) {
            try {
                var obs = new ResizeObserver(function() { resizeCharts(); });
                var pane = document.getElementById('lec-insights') || document.getElementById('la-dashboard');
                if (pane) obs.observe(pane);
            } catch (e) { /* noop */ }
        }
        window.addEventListener('resize', resizeCharts);
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) resizeCharts();
        });
    }

    /* ── Public API ─────────────────────────────────────────────────── */

    function init(courseId, cfg) {
        if (courseId && typeof courseId === 'object') {
            cfg = courseId;
            courseId = cfg.courseId;
        }
        // 0 == "All Courses" (client-side composite); any other id == course.
        cid = parseInt(courseId, 10) || 0;
        loadData(false);
    }

    function refresh() {
        loadData(true);
    }

    // Hook up once.
    bindEvents();

    var module = {
        init: init,
        refresh: refresh,
        setFilter: setFilter,
        resize: resizeCharts
    };

    // Mirror for the overlay's existing call sites; also used as a fallback
    // alias so switchPane's `window.analyticsDashboard` checks keep working.
    if (window) {
        window.lecturerAnalytics = module;
        if (!window.analyticsDashboard) window.analyticsDashboard = module;
    }

    return module;
});
