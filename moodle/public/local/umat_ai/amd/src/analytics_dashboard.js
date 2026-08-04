/**
 * Lecturer Insights Dashboard — 5-zone actionable insights.
 *
 * Zone 0: Executive Summary (AI-powered briefing)
 * Zone 1: Priority Briefing (what needs attention)
 * Zone 2: Struggle Map (where students are confused)
 * Zone 3: Student Dossiers (who needs help and why)
 * Zone 4: Question Radar (what students are asking)
 * Zone 5: Course Vitals (how course is performing)
 *
 * Dual mode: single course (cid > 0) or all-courses overview (cid === 0).
 */
define(['core/ajax', 'core/str', 'local_umat_ai/umatshared'], function(Ajax, Str, Shared) {
    'use strict';

    var cid = 0;
    var currentDetailUid = 0;
    var currentDrawerAction = '';
    var allStudentNarratives = [];
    var filterMode = 'all';
    var activeStream = null;

    function streamConfig() {
        if (window._umatChatStream) return window._umatChatStream;
        return { url: '', sesskey: '' };
    }

    // ── Initialisation (dual-mode: single course or all-courses) ──
    function init(courseId) {
        cid = parseInt(courseId) || 0;
        if (!cid) {
            loadAllCourses();
            return;
        }
        loadData();
    }

    // ── Data loading (single course) ──
    function loadData() {
        showSkeleton(true);
        Ajax.call([{
            methodname: 'local_umat_ai_get_struggle_insights',
            args: { courseid: cid, days: 60 }
        }])[0].done(function(insights) {
            renderAll(insights);
            showSkeleton(false);
        }).fail(function() {
            showSkeleton(false);
            renderEmpty();
        });
    }

    // ── Data loading (all courses) ──
    function loadAllCourses() {
        showSkeleton(true);
        Ajax.call([{
            methodname: 'local_umat_ai_get_struggle_insights',
            args: { courseid: 0, days: 60 }
        }])[0].done(function(insights) {
            renderAllCourses(insights);
            showSkeleton(false);
        }).fail(function() {
            showSkeleton(false);
            renderAllCourses({});
        });
    }

    function renderAllCourses(data) {
        data = data || {};
        var mode = data.mode || 'single';
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
        // For all-courses, show a card grid
        var courses = data.courses || [];
        var el = document.querySelector('.umat-insights-pane');
        if (!el) return;
        if (!courses.length) {
            el.innerHTML = '<div class="ins-empty" style="padding:60px 20px;text-align:center;">' +
                '<span class="material-symbols-outlined" style="font-size:48px;color:var(--u-ol);display:block;margin-bottom:12px;">bar_chart</span>' +
                '<p>No course analytics available yet. Insights will appear once the AI cron processes course data.</p></div>';
            return;
        }
        el.innerHTML = '<div class="ins-all-courses-grid" id="ins-all-courses-grid">' +
            courses.map(function(c) {
                var grade = c.health_grade || '—';
                var gradeColor = grade === 'A' ? '#22c55e' : grade === 'B' ? '#16a34a' :
                    grade === 'C' ? '#f59e0b' : grade === 'D' ? '#f97316' : '#dc2626';
                return '<a class="ins-course-card" href="' + c.url + '">' +
                    '<div class="ins-course-card-grade" style="background:' + gradeColor + '">' + grade + '</div>' +
                    '<div class="ins-course-card-body">' +
                    '<div class="ins-course-card-name">' + escapeHtml(c.fullname) + '</div>' +
                    '<div class="ins-course-card-meta">' +
                    '<span>' + (c.enrolled || '—') + ' students</span>' +
                    '<span>' + (c.at_risk || '—') + ' at risk</span>' +
                    '</div>' +
                    '<div class="ins-course-card-summary">' + escapeHtml(c.executive_summary || '') + '</div>' +
                    '</div></a>';
            }).join('') + '</div>';
    }

    function showSkeleton(show) {
        var sk = document.getElementById('ins-skeleton');
        var pane = document.querySelector('.umat-insights-pane');
        if (sk) sk.style.display = show ? 'flex' : 'none';
        if (pane) pane.style.opacity = show ? '0.3' : '1';
    }

    function renderEmpty() {
        var el = document.getElementById('ins-priority-actions');
        if (el) el.innerHTML = '<div class="ins-empty">No data available yet. The hourly cron will populate insights soon.</div>';
    }

    // ── Master renderer (single course) ──
    function renderAll(data) {
        if (!data) { renderEmpty(); return; }
        renderExecutiveSummary(data);
        renderPriorityActions(data.priority_actions || []);
        renderStruggleAreas(data.struggle_areas || []);
        renderSectionStruggle(data.section_struggle || []);
        renderMaterialStruggle(data.material_struggle || []);
        renderStudentDossiers(data.student_narratives || []);
        renderQuestionRadar(data.common_questions || []);
        renderCoursePulse(data.course_pulse || {}, data.metric_explanations || {});
    }

    // ════════════════════════════════════════════════════════════════
    // ZONE 0: Executive Summary
    // ════════════════════════════════════════════════════════════════
    function renderExecutiveSummary(data) {
        var gradeEl = document.getElementById('ins-exs-grade-letter');
        var labelEl = document.getElementById('ins-exs-grade-label');
        var summaryEl = document.getElementById('ins-exs-summary');
        var recEl = document.getElementById('ins-exs-top-rec');
        var goingWellEl = document.getElementById('ins-exs-going-well-items');
        var needsAttnEl = document.getElementById('ins-exs-needs-attention-items');

        if (gradeEl) {
            var grade = data.health_grade || '—';
            gradeEl.textContent = grade;
            var gradeColors = { A: '#22c55e', B: '#16a34a', C: '#f59e0b', D: '#f97316', E: '#ef4444', F: '#dc2626' };
            gradeEl.style.background = gradeColors[grade] || '#94a3b8';
        }
        if (labelEl) {
            labelEl.textContent = data.health_label || 'Course Health';
        }
        if (summaryEl) {
            summaryEl.textContent = data.executive_summary || 'Executive summary not available.';
        }
        if (recEl && data.top_recommendation) {
            recEl.innerHTML = '<span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle;">auto_awesome</span> ' +
                escapeHtml(data.top_recommendation);
            recEl.style.display = 'block';
        } else if (recEl) {
            recEl.style.display = 'none';
        }

        if (goingWellEl && data.going_well && data.going_well.length) {
            goingWellEl.innerHTML = data.going_well.map(function(g) {
                return '<span class="ins-exs-chip ins-exs-chip-good">' + escapeHtml(g) + '</span>';
            }).join('');
        } else if (goingWellEl) {
            goingWellEl.innerHTML = '<span class="ins-exs-chip ins-exs-chip-good">No data yet</span>';
        }

        if (needsAttnEl && data.needs_attention && data.needs_attention.length) {
            needsAttnEl.innerHTML = data.needs_attention.map(function(n) {
                return '<span class="ins-exs-chip ins-exs-chip-warn">' + escapeHtml(n) + '</span>';
            }).join('');
        } else if (needsAttnEl) {
            needsAttnEl.innerHTML = '<span class="ins-exs-chip ins-exs-chip-warn">No issues detected</span>';
        }
    }

    // ════════════════════════════════════════════════════════════════
    // ZONE 1: Priority Briefing
    // ════════════════════════════════════════════════════════════════
    function renderPriorityActions(actions) {
        var el = document.getElementById('ins-priority-actions');
        if (!el) return;
        if (!actions || !actions.length) {
            el.innerHTML = '<div class="ins-priority-card urgency-low">' +
                '<span class="material-symbols-outlined ins-priority-icon">check_circle</span>' +
                '<div><div class="ins-priority-text">Everything looks good!</div>' +
                '<div class="ins-priority-details">No urgent issues detected. Check the sections below for detailed insights.</div></div></div>';
            return;
        }
        el.innerHTML = actions.map(function(a) {
            var itemsHtml = '';
            if (a.items && a.items.length) {
                itemsHtml = '<ul class="ins-priority-items">' +
                    a.items.map(function(item) {
                        var detail = item.name || '';
                        if (item.students) detail += ' — ' + item.students + ' students';
                        if (item.pct) detail += ' (' + item.pct + '%)';
                        if (item.trend) detail += ', ' + item.trend;
                        if (item.days) detail += ' — ' + item.days + ' days ago';
                        return '<li>' + escapeHtml(detail) + '</li>';
                    }).join('') + '</ul>';
            }
            var actionBtn = '';
            if (a.action_label) {
                actionBtn = '<button class="ins-priority-btn" onclick="window.analyticsDashboard.handlePriorityAction(\'' + escapeHtml(a.type) + '\')">' +
                    escapeHtml(a.action_label) + '</button>';
            }
            return '<div class="ins-priority-card urgency-' + escapeHtml(a.urgency) + '">' +
                '<span class="material-symbols-outlined ins-priority-icon">' + escapeHtml(a.icon) + '</span>' +
                '<div class="ins-priority-body">' +
                '<div class="ins-priority-text">' + escapeHtml(a.text) + '</div>' +
                itemsHtml +
                '<div class="ins-priority-suggestion"><span class="material-symbols-outlined">auto_awesome</span> ' +
                escapeHtml(a.suggestion) + '</div>' +
                actionBtn +
                '</div></div>';
        }).join('');
    }

    // ════════════════════════════════════════════════════════════════
    // ZONE 2: Struggle Map
    // ════════════════════════════════════════════════════════════════
    function renderStruggleAreas(areas) {
        var el = document.getElementById('ins-struggle-areas');
        if (!el) return;
        if (!areas || !areas.length) {
            el.innerHTML = '<div class="ins-empty">No struggle data yet. Student questions will populate this section.</div>';
            return;
        }
        el.innerHTML = areas.map(function(a) {
            var severityLabel = a.severity === 'critical' ? 'Critical' :
                (a.severity === 'attention' ? 'Needs Attention' : 'Watch');
            var severityIcon = a.severity === 'critical' ? 'error' :
                (a.severity === 'attention' ? 'warning' : 'info');

            // Trend indicator
            var trendHtml = '';
            if (a.trend === 'up') {
                trendHtml = '<span class="ins-trend ins-trend-up"><span class="material-symbols-outlined">trending_up</span> +' + a.trend_pct + '%</span>';
            } else if (a.trend === 'down') {
                trendHtml = '<span class="ins-trend ins-trend-down"><span class="material-symbols-outlined">trending_down</span> ' + a.trend_pct + '%</span>';
            } else {
                trendHtml = '<span class="ins-trend ins-trend-stable"><span class="material-symbols-outlined">trending_flat</span> Stable</span>';
            }

            // Sample questions
            var sqHtml = '';
            if (a.sample_questions && a.sample_questions.length) {
                sqHtml = '<div class="ins-struggle-questions">' +
                    a.sample_questions.slice(0, 2).map(function(q) {
                        return '<div class="ins-struggle-sample-q">"' + escapeHtml(q) + '"</div>';
                    }).join('') + '</div>';
            }

            // Materials
            var matHtml = '';
            if (a.materials && a.materials.length) {
                matHtml = '<div class="ins-struggle-materials">' +
                    a.materials.map(function(m) {
                        return '<span class="ins-material-tag">' + escapeHtml(m.name) + '</span>';
                    }).join('') + '</div>';
            }

            return '<div class="ins-struggle-card severity-' + escapeHtml(a.severity) + '">' +
                '<div class="ins-struggle-header">' +
                '<div class="ins-struggle-topic">' +
                '<span class="material-symbols-outlined ins-severity-icon">' + severityIcon + '</span>' +
                '<strong>' + escapeHtml(a.topic) + '</strong>' +
                '<span class="ins-severity-label">' + severityLabel + '</span>' +
                '</div>' +
                trendHtml +
                '</div>' +
                '<div class="ins-struggle-narrative">' + escapeHtml(a.description) + '</div>' +
                '<div class="ins-struggle-evidence">' +
                '<span class="ins-evidence"><span class="material-symbols-outlined">quiz</span> ' + a.question_count + ' questions</span>' +
                '<span class="ins-evidence"><span class="material-symbols-outlined">people</span> ' + a.student_count + ' of ' + a.total_students + ' students (' + a.student_pct + '%)</span>' +
                '</div>' +
                sqHtml +
                '<div class="ins-struggle-suggestion"><span class="material-symbols-outlined">auto_awesome</span> ' +
                escapeHtml(a.suggestion) + '</div>' +
                matHtml +
                '</div>';
        }).join('');
    }

    function renderSectionStruggle(sections) {
        var el = document.getElementById('ins-section-breakdown');
        if (!el) return;
        if (!sections || !sections.length) return;
        el.innerHTML = '<h4 class="ins-sub-title">Course Sections</h4>' +
            sections.map(function(s) {
                var color = s.severity === 'critical' ? '#dc2626' :
                    (s.severity === 'attention' ? '#f59e0b' : '#16a34a');
                return '<div class="ins-section-bar">' +
                    '<div class="ins-section-bar-header">' +
                    '<span class="ins-section-name">' + escapeHtml(s.section_name) + '</span>' +
                    '<span class="ins-section-pct" style="color:' + color + '">' + s.struggle_pct + '% struggling (' + s.student_count + ' students)</span>' +
                    '</div>' +
                    '<div class="ins-section-track"><div class="ins-section-fill" style="width:' + s.struggle_pct + '%;background:' + color + '"></div></div>' +
                    '<div class="ins-section-hint">' + escapeHtml(s.hint) + '</div>' +
                    '</div>';
            }).join('');
    }

    function renderMaterialStruggle(materials) {
        var el = document.getElementById('ins-material-struggle');
        if (!el) return;
        if (!materials || !materials.length) return;
        el.innerHTML = '<h4 class="ins-sub-title">Materials Needing Attention</h4>' +
            materials.map(function(m, i) {
                return '<div class="ins-material-item">' +
                    '<span class="ins-material-rank">#' + (i + 1) + '</span>' +
                    '<div class="ins-material-info">' +
                    '<div class="ins-material-name">' + escapeHtml(m.material_name) + ' <span class="ins-material-count">' + m.question_count + ' questions</span></div>' +
                    '<div class="ins-material-suggestion">' + escapeHtml(m.suggestion) + '</div>' +
                    '</div></div>';
            }).join('');
    }

    // ════════════════════════════════════════════════════════════════
    // ZONE 3: Student Dossiers
    // ════════════════════════════════════════════════════════════════
    function renderStudentDossiers(students) {
        allStudentNarratives = students || [];
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
            var riskLabel = riskClass === 'high' ? 'High Risk' :
                (riskClass === 'medium' ? 'Medium Risk' : 'Low Risk');

            // Topic chips
            var topicChips = '';
            if (s.struggle_topics && s.struggle_topics.length) {
                topicChips = '<div class="ins-student-topics">' +
                    s.struggle_topics.slice(0, 3).map(function(t) {
                        return '<span class="ins-topic-chip">' + escapeHtml(t) + '</span>';
                    }).join('') + '</div>';
            }

            // Stats line
            var stats = [];
            if (s.question_count > 0) stats.push(s.question_count + ' questions');
            if (s.ai_queries > 0) stats.push(s.ai_queries + ' AI queries');
            if (s.quiz_failures > 0) stats.push(s.quiz_failures + ' quiz fails');
            var statsHtml = stats.length ? '<div class="ins-student-stats">' + stats.join(' · ') + '</div>' : '';

            // Suggestion
            var sugHtml = s.suggestion ? '<div class="ins-student-suggestion"><span class="material-symbols-outlined">auto_awesome</span> ' + escapeHtml(s.suggestion) + '</div>' : '';

            // Use AI narrative as primary summary; fall back to PHP-generated summary
            var studentSummary = s.ai_narrative || s.summary || '';

            return '<div class="ins-student-card" data-uid="' + s.userid + '" onclick="window.analyticsDashboard.loadStudentDetail(' + s.userid + ')">' +
                '<div class="ins-student-header">' +
                '<div class="ins-student-name-row">' +
                '<img class="ins-student-avatar" src="' + escapeHtml(s.profileimageurl || '') + '" alt="" onerror="this.style.display=\'none\'">' +
                '<strong class="ins-student-name">' + escapeHtml(s.fullname) + '</strong>' +
                '</div>' +
                '<span class="ins-pill ins-pill-' + riskClass + '">' + riskLabel + '</span>' +
                '</div>' +
                '<div class="ins-student-summary">' + escapeHtml(studentSummary) + '</div>' +
                topicChips +
                statsHtml +
                '<div class="ins-student-meta">Last active: ' + escapeHtml(s.last_active) + '</div>' +
                sugHtml +
                '</div>';
        }).join('');
    }

    function applyFilter(students) {
        if (filterMode === 'all') return students;
        return students.filter(function(s) {
            if (filterMode === 'disengaged') {
                return (s.days_since_last_login || 0) >= 3;
            }
            if (filterMode === 'struggling') {
                return s.risk_score >= 40 && s.question_count >= 5;
            }
            if (filterMode === 'failing') {
                return (s.quiz_failures || 0) > 0 || s.risk_score >= 60;
            }
            if (filterMode === 'issues') {
                return (s.issue_reports || 0) > 0;
            }
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
    // ZONE 4: Question Radar
    // ════════════════════════════════════════════════════════════════
    function renderQuestionRadar(questions) {
        var el = document.getElementById('ins-common-questions');
        if (!el) return;
        if (!questions || !questions.length) {
            el.innerHTML = '<div class="ins-empty">No common questions yet.</div>';
            return;
        }
        el.innerHTML = questions.slice(0, 8).map(function(q) {
            var displayText = (q.text || '').replace(/^\[Referencing:\s*[^\]]+\]\s*/i, '');
            return '<div class="ins-question-card">' +
                '<div class="ins-question-text">"' + escapeHtml(displayText) + '"</div>' +
                '<div class="ins-question-meta">' + q.student_count + ' student' + (q.student_count !== 1 ? 's' : '') +
                ' · ' + q.ask_count + ' time' + (q.ask_count !== 1 ? 's' : '') +
                ' · Topic: <strong>' + escapeHtml(q.topic) + '</strong></div>' +
                '<div class="ins-question-suggestion"><span class="material-symbols-outlined">auto_awesome</span> ' +
                escapeHtml(q.suggestion) + '</div>' +
                '</div>';
        }).join('');
    }

    // ════════════════════════════════════════════════════════════════
    // ZONE 5: Course Vitals (metric labels with tooltip explanations)
    // ════════════════════════════════════════════════════════════════
    function renderCoursePulse(pulse, metricExplanations) {
        var el = document.getElementById('ins-course-pulse');
        if (!el) return;
        if (!pulse || !pulse.total_students) {
            el.innerHTML = '<div class="ins-empty">No pulse data available.</div>';
            return;
        }

        metricExplanations = metricExplanations || {};

        function trendIcon(trend, pct) {
            if (trend === 'up') return '<span class="ins-pulse-trend up">↑' + (pct || '') + '%</span>';
            if (trend === 'down') return '<span class="ins-pulse-trend down">↓' + Math.abs(pct || 0) + '%</span>';
            return '<span class="ins-pulse-trend stable">—</span>';
        }

        function tooltipLabel(label, explanation) {
            if (explanation) {
                return '<div class="ins-pulse-label" title="' + escapeHtml(explanation) + '">' + label +
                    '<span class="material-symbols-outlined ins-pulse-info" style="font-size:12px;vertical-align:middle;margin-left:2px;">info</span></div>';
            }
            return '<div class="ins-pulse-label">' + label + '</div>';
        }

        // --- Avg Performance (was: Avg Quiz) ---
        var html = '<div class="ins-pulse-card" title="' + escapeHtml(metricExplanations.avg_performance || 'Average student quiz score across all quizzes in this course') + '">' +
            '<div class="ins-pulse-val">' + (pulse.avg_quiz || 0) + '%</div>' +
            tooltipLabel('Avg Performance', metricExplanations.avg_performance) +
            trendIcon(pulse.quiz_trend, pulse.quiz_trend_pct) +
            '<div class="ins-pulse-source">' + (pulse.avg_quiz_source === 'actual_quiz_grades' ? 'from quiz grades' : '') + '</div>' +
            '</div>';

        // --- Students at Risk (was: At Risk) ---
        html += '<div class="ins-pulse-card" title="' + escapeHtml(metricExplanations.at_risk || 'Students flagged as high or medium risk based on engagement, quiz performance, and AI activity') + '">' +
            '<div class="ins-pulse-val">' + (pulse.at_risk_count || 0) + '</div>' +
            tooltipLabel('Students at Risk', metricExplanations.at_risk) +
            '<div class="ins-pulse-sub">of ' + pulse.total_students + '</div>' +
            '</div>';

        // --- Active This Week (was: Active This Week) ---
        html += '<div class="ins-pulse-card" title="' + escapeHtml(metricExplanations.active_week || 'Students who logged in or interacted with the course in the last 7 days') + '">' +
            '<div class="ins-pulse-val">' + (pulse.active_this_week || 0) + '</div>' +
            tooltipLabel('Active This Week', metricExplanations.active_week) +
            '<div class="ins-pulse-sub">of ' + pulse.total_students + '</div>' +
            '</div>';

        // --- Questions This Week (was: Top Struggle) ---
        html += '<div class="ins-pulse-card" title="' + escapeHtml(metricExplanations.questions_week || 'Total AI questions asked by students in the last 7 days') + '">' +
            '<div class="ins-pulse-val">' + (pulse.questions_this_week || 0) + '</div>' +
            tooltipLabel('N Questions', metricExplanations.questions_week) +
            '<div class="ins-pulse-sub">' + escapeHtml(pulse.top_struggle_trend || '') + '</div>' +
            '</div>';

        el.innerHTML = html;
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
        body.innerHTML = '<div class="ins-empty">Loading profile…</div>';

        // Find student from cache
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
            var detailSummary = s ? (s.ai_narrative || s.summary || '') : '';
            body.innerHTML =
                '<div class="ins-detail-narrative">' + escapeHtml(detailSummary) + '</div>' +
                '<div class="ins-detail-grid">' +
                '<div class="ins-detail-stat"><div class="ins-detail-stat-val">' + (data.risk_score || 0) + '</div><div class="ins-detail-stat-lbl">Risk Score</div></div>' +
                '<div class="ins-detail-stat"><div class="ins-detail-stat-val">' + (data.total_logins || 0) + '</div><div class="ins-detail-stat-lbl">Logins</div></div>' +
                '<div class="ins-detail-stat"><div class="ins-detail-stat-val">' + (data.avg_quiz || 0).toFixed(1) + '%</div><div class="ins-detail-stat-lbl">Avg Quiz</div></div>' +
                '<div class="ins-detail-stat"><div class="ins-detail-stat-val">' + (data.ai_queries || 0) + '</div><div class="ins-detail-stat-lbl">AI Queries</div></div>' +
                '</div>';
            if (s && s.suggestion) {
                body.innerHTML += '<div class="ins-detail-suggestion"><span class="material-symbols-outlined">auto_awesome</span> ' + escapeHtml(s.suggestion) + '</div>';
            }
            if (data.interventions && data.interventions.length) {
                body.innerHTML += '<div class="ins-detail-section-title">Recent Interventions</div>';
                body.innerHTML += data.interventions.slice(0, 5).map(function(inv) {
                    return '<div class="ins-detail-intervention">' +
                        '<span>' + escapeHtml(inv.action || '') + '</span>' +
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

    // ════════════════════════════════════════════════════════════════
    // Priority Action Handler
    // ════════════════════════════════════════════════════════════════
    function handlePriorityAction(type) {
        if (type === 'disengagement') {
            setFilter('disengaged', document.querySelector('[data-filter="disengaged"]'));
        } else if (type === 'recap_needed') {
            setFilter('struggling', document.querySelector('[data-filter="struggling"]'));
        } else if (type === 'issues') {
            setFilter('issues', document.querySelector('[data-filter="issues"]'));
        }
        // Scroll to student section
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
        if (student) {
            if (recipient) recipient.textContent = 'To: ' + student.fullname + ' (' + student.risk_level + ')';
        }

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

    // ════════════════════════════════════════════════════════════════
    // Utilities
    // ════════════════════════════════════════════════════════════════
    function escapeHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    return {
        init: init,
        loadData: loadData,
        loadAllCourses: loadAllCourses,
        setFilter: setFilter,
        loadStudentDetail: loadStudentDetail,
        closeDetail: closeDetail,
        handlePriorityAction: handlePriorityAction,
        openActionDrawer: openActionDrawer,
        closeActionDrawer: closeActionDrawer,
        sendIntervention: sendIntervention
    };
});
