/**
 * @deprecated Since v2.2.0 — This module is dead code and is never loaded.
 * Retained for reference only. Do not modify or import.
 *
 * Lecturer Teaching Intelligence Dashboard — AI-powered actionable insights.
 *
 * Zone 1: Priority Recommendations (what to do first)
 * Zone 2: Students At Risk (with drill-down evidence)
 * Zone 3: Topic Struggle (with student lists, quiz questions, AI questions)
 * Zone 4: Quiz Analytics (with question-level detail)
 * Zone 5: Lecture Recording Analytics (with engagement breakdown)
 * Zone 6: Resource Analytics
 * Zone 7: AI Learning Analytics
 * Zone 8: Student Timeline (chronological activity)
 */
define(['core/ajax', 'core/str', 'local_umat_ai/umatshared'], function(Ajax, Str, Shared) {
    'use strict';

    var cid = 0;
    var currentDetailUid = 0;
    var currentDrawerAction = '';
    var allStudentNarratives = [];
    var allTeachingIntelligence = null;
    var filterMode = 'all';
    var activeStream = null;
    var expandedCards = {};

    function streamConfig() {
        if (window._umatChatStream) return window._umatChatStream;
        return { url: '', sesskey: '' };
    }

    function init(courseId) {
        cid = parseInt(courseId) || 0;
        if (!cid) return;
        loadData();
    }

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

    function loadTeachingIntelligence() {
        if (!cid) return;
        Ajax.call([{
            methodname: 'local_umat_ai_get_teaching_intelligence',
            args: { courseid: cid, days: 60 }
        }])[0].done(function(data) {
            allTeachingIntelligence = data;
            renderPriorityRecommendations(data.priority_recommendations || []);
            renderStudentsAtRisk(data.students_at_risk || []);
            renderTopicStruggles(data.topic_struggles || []);
            renderQuizAnalytics(data.quiz_analytics || {});
            renderRecordingAnalytics(data.recording_analytics || []);
            renderResourceAnalytics(data.resource_analytics || []);
            renderAILearningAnalytics(data.ai_learning_analytics || {});
        }).fail(function() {
            // Fallback to existing data
        });
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

    function renderAll(data) {
        if (!data) { renderEmpty(); return; }
        renderPriorityActions(data.priority_actions || []);
        renderStruggleAreas(data.struggle_areas || []);
        renderSectionStruggle(data.section_struggle || []);
        renderMaterialStruggle(data.material_struggle || []);
        renderQuestionRadar(data.common_questions || []);
        renderCoursePulse(data.course_pulse || {});
        loadTeachingIntelligence();
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

            return '<div class="ins-student-card" data-uid="' + s.userid + '" onclick="window.analyticsDashboard.loadStudentDetail(' + s.userid + ')">' +
                '<div class="ins-student-header">' +
                '<div class="ins-student-name-row">' +
                '<img class="ins-student-avatar" src="' + escapeHtml(s.profileimageurl || '') + '" alt="" onerror="this.style.display=\'none\'">' +
                '<strong class="ins-student-name">' + escapeHtml(s.fullname) + '</strong>' +
                '</div>' +
                '<span class="ins-pill ins-pill-' + riskClass + '">' + riskLabel + '</span>' +
                '</div>' +
                '<div class="ins-student-summary">' + escapeHtml(s.summary) + '</div>' +
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
    // ZONE 5: Course Vitals
    // ════════════════════════════════════════════════════════════════
    function renderCoursePulse(pulse) {
        var el = document.getElementById('ins-course-pulse');
        if (!el) return;
        if (!pulse || !pulse.total_students) {
            el.innerHTML = '<div class="ins-empty">No pulse data available.</div>';
            return;
        }

        function trendIcon(trend, pct) {
            if (trend === 'up') return '<span class="ins-pulse-trend up">↑' + (pct || '') + '%</span>';
            if (trend === 'down') return '<span class="ins-pulse-trend down">↓' + Math.abs(pct || 0) + '%</span>';
            return '<span class="ins-pulse-trend stable">—</span>';
        }

        var html = '<div class="ins-pulse-card">' +
            '<div class="ins-pulse-val">' + (pulse.avg_quiz || 0) + '%</div>' +
            '<div class="ins-pulse-label">Avg Quiz</div>' +
            trendIcon(pulse.quiz_trend, pulse.quiz_trend_pct) +
            '</div>';

        html += '<div class="ins-pulse-card">' +
            '<div class="ins-pulse-val">' + (pulse.at_risk_count || 0) + '</div>' +
            '<div class="ins-pulse-label">At Risk</div>' +
            '<div class="ins-pulse-sub">of ' + pulse.total_students + ' students</div>' +
            '</div>';

        html += '<div class="ins-pulse-card">' +
            '<div class="ins-pulse-val ins-pulse-topic">' + escapeHtml(pulse.top_struggle_topic || '—') + '</div>' +
            '<div class="ins-pulse-label">Top Struggle</div>' +
            '<div class="ins-pulse-sub">' + escapeHtml(pulse.top_struggle_trend || '') + '</div>' +
            '</div>';

        html += '<div class="ins-pulse-card">' +
            '<div class="ins-pulse-val">' + (pulse.active_this_week || 0) + '</div>' +
            '<div class="ins-pulse-label">Active This Week</div>' +
            '<div class="ins-pulse-sub">of ' + pulse.total_students + ' students</div>' +
            '</div>';

        el.innerHTML = html;
    }

    // ════════════════════════════════════════════════════════════════
    // TEACHING INTELLIGENCE ZONE: Priority Recommendations
    // ════════════════════════════════════════════════════════════════
    function renderPriorityRecommendations(recommendations) {
        var el = document.getElementById('ins-priority-recommendations');
        if (!el) return;
        if (!recommendations || !recommendations.length) {
            el.innerHTML = '<div class="ins-empty">No priority recommendations yet.</div>';
            return;
        }
        el.innerHTML = recommendations.map(function(r) {
            var urgencyClass = r.priority === 1 ? 'urgency-critical' :
                (r.priority === 2 ? 'urgency-high' :
                (r.priority === 3 ? 'urgency-medium' : 'urgency-low'));
            return '<div class="ins-priority-card ' + urgencyClass + '">' +
                '<div class="ins-priority-header">' +
                '<span class="ins-priority-badge">P' + r.priority + '</span>' +
                '<strong>' + escapeHtml(r.title) + '</strong>' +
                '<span class="ins-confidence">Confidence: ' + (r.confidence || 0) + '%</span>' +
                '</div>' +
                '<div class="ins-priority-evidence">' + escapeHtml(r.evidence || '') + '</div>' +
                '<div class="ins-priority-suggestion"><span class="material-symbols-outlined">auto_awesome</span> ' +
                escapeHtml(r.suggestion || '') + '</div>' +
                (r.action_label ? '<button class="ins-priority-btn" onclick="window.analyticsDashboard.handlePriorityAction(\'' +
                    escapeHtml(r.action || '') + '\', ' + (r.userid || 0) + ')">' +
                    escapeHtml(r.action_label) + '</button>' : '') +
                '</div>';
        }).join('');
    }

    // ════════════════════════════════════════════════════════════════
    // TEACHING INTELLIGENCE ZONE: Students At Risk (expandable)
    // ════════════════════════════════════════════════════════════════
    function renderStudentsAtRisk(students) {
        var el = document.getElementById('ins-students-at-risk');
        if (!el) return;

        var badge = document.getElementById('ins-at-risk-count');
        if (badge) {
            badge.textContent = students.length;
        }

        if (!students || !students.length) {
            el.innerHTML = '<div class="ins-empty">No students at risk.</div>';
            return;
        }

        allStudentNarratives = students;

        el.innerHTML = students.map(function(s, idx) {
            var cardId = 'ins-risk-' + (s.userid || idx);
            var isExpanded = expandedCards[cardId] || false;
            var riskLabel = s.risk_level === 'high' ? 'High Risk' :
                (s.risk_level === 'medium' ? 'Medium Risk' : 'Low Risk');
            var riskColor = s.risk_level === 'high' ? '#dc2626' :
                (s.risk_level === 'medium' ? '#f59e0b' : '#22c55e');
            var riskClass = s.risk_level === 'high' ? 'ins-risk-high' :
                (s.risk_level === 'medium' ? 'ins-risk-medium' : 'ins-risk-low');

            var classificationLabel = {
                'disengaged': 'Disengaged',
                'academically_struggling': 'Academically Struggling',
                'failing_assessments': 'Failing Assessments',
                'monitoring': 'Monitoring'
            }[s.classification] || 'Monitoring';

            var trendIcon = s.trend === 'getting_worse' ? 'trending_down' :
                (s.trend === 'improving' ? 'trending_up' : 'trending_flat');
            var trendColor = s.trend === 'getting_worse' ? '#dc2626' :
                (s.trend === 'improving' ? '#22c55e' : '#6b7280');

            return '<div class="ins-risk-panel" data-student-id="' + s.userid + '">' +
                // ── Collapsed Row ──
                '<div class="ins-risk-row" onclick="window.analyticsDashboard.toggleCard(\'' + escapeHtml(cardId) + '\')">' +
                    '<div class="ins-risk-row-left">' +
                        '<img class="ins-student-avatar" src="' + escapeHtml(s.profileimageurl || '') + '" alt="" onerror="this.style.display=\'none\'">' +
                        '<div class="ins-risk-name-col">' +
                            '<strong class="ins-risk-name">' + escapeHtml(s.fullname || 'Unknown') + '</strong>' +
                            '<span class="ins-risk-classification">' + escapeHtml(classificationLabel) + '</span>' +
                        '</div>' +
                        '<div class="ins-risk-pill-col">' +
                            '<span class="ins-pill" style="background:' + riskColor + '">' + riskLabel + '</span>' +
                            '<span class="ins-risk-score-badge ' + riskClass + '">' + (s.risk_score || 0) + '</span>' +
                        '</div>' +
                        '<div class="ins-risk-activity-col">' +
                            '<span class="ins-risk-last-active">' +
                                '<span class="material-symbols-outlined">schedule</span> ' +
                                escapeHtml(s.last_active || 'unknown') +
                            '</span>' +
                            (s.days_inactive > 0 ? '<span class="ins-risk-inactive-chip">' + s.days_inactive + 'd inactive</span>' : '') +
                        '</div>' +
                        '<div class="ins-risk-reason-col">' +
                            '<span class="ins-risk-primary-reason">' + escapeHtml(s.primary_reason || '') + '</span>' +
                        '</div>' +
                        '<span class="material-symbols-outlined ins-expand-icon">' + (isExpanded ? 'expand_less' : 'expand_more') + '</span>' +
                    '</div>' +
                '</div>' +
                // ── Expanded Panel ──
                '<div class="ins-expandable-body" id="' + escapeHtml(cardId) + '" style="display:' + (isExpanded ? 'block' : 'none') + ';">' +
                    '<div class="ins-risk-expanded">' +
                        // Risk Score Bar
                        '<div class="ins-risk-score-bar">' +
                            '<div class="ins-risk-score-track">' +
                                '<div class="ins-risk-score-fill ' + riskClass + '" style="width:' + Math.min(100, s.risk_score || 0) + '%"></div>' +
                            '</div>' +
                            '<div class="ins-risk-score-label">Risk Score: <strong>' + (s.risk_score || 0) + '/100</strong></div>' +
                        '</div>' +
                        // Evidence List
                        (s.evidence && s.evidence.length ?
                            '<div class="ins-card-section">' +
                                '<h5><span class="material-symbols-outlined">search</span> Evidence</h5>' +
                                '<ul class="ins-evidence-list">' +
                                    s.evidence.map(function(e) { return '<li>' + escapeHtml(e) + '</li>'; }).join('') +
                                '</ul>' +
                            '</div>' : '') +
                        // Risk Factors Breakdown
                        (s.risk_factors && s.risk_factors.length ?
                            '<div class="ins-card-section">' +
                                '<h5><span class="material-symbols-outlined">analytics</span> Risk Factors</h5>' +
                                '<div class="ins-risk-factors-grid">' +
                                    s.risk_factors.map(function(f) {
                                        return '<div class="ins-risk-factor">' +
                                            '<span class="ins-rf-name">' + escapeHtml(f.name || '') + '</span>' +
                                            '<span class="ins-rf-value">' + (f.value || 0) + '</span>' +
                                            '<span class="ins-rf-contribution">+' + (f.contribution || 0) + ' pts</span>' +
                                            '<span class="ins-rf-source">' + escapeHtml(f.source || '') + '</span>' +
                                        '</div>';
                                    }).join('') +
                                '</div>' +
                            '</div>' : '') +
                        // AI Explanation
                        (s.explanation ?
                            '<div class="ins-card-section ins-explanation">' +
                                '<h5><span class="material-symbols-outlined">psychology</span> AI Analysis</h5>' +
                                '<p>' + escapeHtml(s.explanation) + '</p>' +
                                '<span class="ins-confidence">Confidence: ' + (s.confidence || 0) + '%</span>' +
                            '</div>' : '') +
                        // Recommendations
                        (s.recommendation && s.recommendation.length ?
                            '<div class="ins-card-section">' +
                                '<h5><span class="material-symbols-outlined">recommend</span> Recommendations</h5>' +
                                '<ul class="ins-recommendation-list">' +
                                    s.recommendation.map(function(r) { return '<li>' + escapeHtml(r) + '</li>'; }).join('') +
                                '</ul>' +
                            '</div>' : '') +
                        // Quick Actions
                        (s.quick_actions && s.quick_actions.length ?
                            '<div class="ins-card-section">' +
                                '<h5><span class="material-symbols-outlined">bolt</span> Quick Actions</h5>' +
                                '<div class="ins-quick-actions">' +
                                    s.quick_actions.map(function(a) {
                                        return '<button class="ins-action-chip" onclick="event.stopPropagation(); window.analyticsDashboard.handleStudentAction(\'' +
                                            escapeHtml(a.action) + '\', ' + s.userid + ')">' +
                                            '<span class="material-symbols-outlined">' + escapeHtml(a.icon) + '</span> ' +
                                            escapeHtml(a.label) + '</button>';
                                    }).join('') +
                                '</div>' +
                            '</div>' : '') +
                        // Lazy-loaded Activity Timeline placeholder
                        '<div class="ins-card-section" id="ins-timeline-' + s.userid + '">' +
                            '<h5><span class="material-symbols-outlined">history</span> Activity Timeline</h5>' +
                            '<div class="ins-timeline-loading">' +
                                '<span class="material-symbols-outlined ins-spin">progress_activity</span> Loading timeline...' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';
        }).join('');

        // Load timelines for expanded cards
        students.forEach(function(s, idx) {
            var cardId = 'ins-risk-' + (s.userid || idx);
            if (expandedCards[cardId]) {
                loadStudentTimeline(s.userid);
            }
        });
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

            if (profile.interventions && profile.interventions.length) {
                html += '<ul class="ins-timeline-events">';
                profile.interventions.forEach(function(inv) {
                    var dt = new Date(inv.timecreated * 1000);
                    html += '<li>' +
                        '<span class="ins-timeline-dot ' + (inv.status === 'sent' ? 'sent' : 'pending') + '"></span>' +
                        '<span class="ins-timeline-text">' + escapeHtml(inv.action) + ' — ' + escapeHtml(inv.status) + '</span>' +
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

    // ════════════════════════════════════════════════════════════════
    // TEACHING INTELLIGENCE ZONE: Topic Struggles (expandable)
    // ════════════════════════════════════════════════════════════════
    function renderTopicStruggles(topics) {
        var el = document.getElementById('ins-topic-struggles');
        if (!el) return;
        if (!topics || !topics.length) {
            el.innerHTML = '<div class="ins-empty">No topic struggle data yet.</div>';
            return;
        }
        el.innerHTML = topics.map(function(t, idx) {
            var cardId = 'ins-topic-card-' + idx;
            var isExpanded = expandedCards[cardId] || false;
            var severityColor = t.severity === 'critical' ? '#dc2626' :
                (t.severity === 'attention' ? '#f59e0b' : '#22c55e');
            var severityLabel = t.severity === 'critical' ? 'Critical' :
                (t.severity === 'attention' ? 'Needs Attention' : 'Watch');

            var studentsHtml = '';
            if (t.students_struggling && t.students_struggling.length) {
                studentsHtml = '<div class="ins-card-section"><h5>Students Struggling (' + t.students_struggling.length + ')</h5>' +
                    '<div class="ins-student-mini-list">' +
                    t.students_struggling.map(function(s) {
                        return '<div class="ins-student-mini-item">' +
                            '<img src="' + escapeHtml(s.picture || '') + '" alt="" onerror="this.style.display=\'none\'">' +
                            '<span>' + escapeHtml(s.name) + '</span></div>';
                    }).join('') + '</div></div>';
            }

            var quizHtml = '';
            if (t.related_quiz_fails && t.related_quiz_fails.length) {
                quizHtml = '<div class="ins-card-section"><h5>Related Quiz Questions</h5><ul>' +
                    t.related_quiz_fails.map(function(q) {
                        return '<li>' + escapeHtml(q.question) + '</li>';
                    }).join('') + '</ul></div>';
            }

            var aiQHtml = '';
            if (t.ai_questions && t.ai_questions.length) {
                aiQHtml = '<div class="ins-card-section"><h5>AI Questions on This Topic</h5><ul>' +
                    t.ai_questions.map(function(q) {
                        return '<li>"' + escapeHtml(q.text) + '" (' + q.ask_count + ' times)</li>';
                    }).join('') + '</ul></div>';
            }

            var explanationHtml = '';
            if (t.ai_explanation) {
                explanationHtml = '<div class="ins-card-section ins-explanation"><h5>AI Explanation</h5>' +
                    '<p>' + escapeHtml(t.ai_explanation) + '</p></div>';
            }

            var recHtml = '';
            if (t.recommendation) {
                recHtml = '<div class="ins-card-section"><h5>Teaching Recommendation</h5>' +
                    '<p>' + escapeHtml(t.recommendation) + '</p>' +
                    '<span class="ins-confidence">Suggestion type: ' + escapeHtml(t.suggestion_type || 'recap') + '</span></div>';
            }

            var expandedContent = isExpanded ? (studentsHtml + quizHtml + aiQHtml + explanationHtml + recHtml) : '';

            return '<div class="ins-expandable-card" data-card-id="' + escapeHtml(cardId) + '">' +
                '<div class="ins-expandable-header" onclick="window.analyticsDashboard.toggleCard(\'' +
                    escapeHtml(cardId) + '\')">' +
                '<div class="ins-topic-card-header">' +
                '<span class="material-symbols-outlined ins-severity-icon" style="color:' + severityColor + '">' +
                (t.severity === 'critical' ? 'error' : (t.severity === 'attention' ? 'warning' : 'info')) + '</span>' +
                '<strong>' + escapeHtml(t.topic) + '</strong>' +
                '<span class="ins-severity-label" style="color:' + severityColor + '">' + severityLabel + '</span>' +
                '<span class="ins-struggle-score">' + (t.struggle_score || 0) + '%</span>' +
                '<span class="material-symbols-outlined ins-expand-icon">' + (isExpanded ? 'expand_less' : 'expand_more') + '</span>' +
                '</div>' +
                '<div class="ins-topic-card-summary">' +
                '<span class="ins-topic-stat">' + (t.student_count || 0) + ' students</span>' +
                '<span class="ins-topic-stat">' + (t.question_count || 0) + ' questions</span>' +
                '<span class="ins-topic-stat">Trend: ' + escapeHtml(t.trend || 'stable') + '</span>' +
                '</div>' +
                '</div>' +
                '<div class="ins-expandable-body" id="' + escapeHtml(cardId) + '" style="display:' + (isExpanded ? 'block' : 'none') + ';">' +
                expandedContent +
                '</div>' +
                '</div>';
        }).join('');
    }

    // ════════════════════════════════════════════════════════════════
    // TEACHING INTELLIGENCE ZONE: Quiz Analytics (expandable)
    // ════════════════════════════════════════════════════════════════
    function renderQuizAnalytics(quizData) {
        var el = document.getElementById('ins-quiz-analytics');
        if (!el) return;
        if (!quizData || Object.keys(quizData).length === 0) {
            el.innerHTML = '<div class="ins-empty">No quiz analytics data yet.</div>';
            return;
        }

        var html = '<div class="ins-quiz-summary">';
        html += '<div class="ins-quiz-stat"><span class="ins-quiz-val">' + (quizData.average_score || 0) + '%</span><span class="ins-quiz-lbl">Avg Score</span></div>';
        html += '<div class="ins-quiz-stat"><span class="ins-quiz-val">' + (quizData.highest_score || 0) + '%</span><span class="ins-quiz-lbl">Highest</span></div>';
        html += '<div class="ins-quiz-stat"><span class="ins-quiz-val">' + (quizData.lowest_score || 0) + '%</span><span class="ins-quiz-lbl">Lowest</span></div>';
        html += '<div class="ins-quiz-stat"><span class="ins-quiz-val">' + (quizData.median_score || 0) + '%</span><span class="ins-quiz-lbl">Median</span></div>';
        html += '<div class="ins-quiz-stat"><span class="ins-quiz-val">' + (quizData.pass_rate || 0) + '%</span><span class="ins-quiz-lbl">Pass Rate</span></div>';
        html += '</div>';

        if (quizData.most_failed_questions && quizData.most_failed_questions.length) {
            html += '<h5>Most Failed Questions</h5>';
            html += '<div class="ins-question-list">';
            quizData.most_failed_questions.forEach(function(q, i) {
                html += '<div class="ins-question-item">' +
                    '<span class="ins-q-num">#' + (i + 1) + '</span>' +
                    '<span class="ins-q-text">' + escapeHtml(q.question || '') + '</span>' +
                    '<span class="ins-q-wrong" style="color:' + (q.wrong_pct > 50 ? '#dc2626' : '#f59e0b') + '">' +
                    (q.wrong_pct || 0) + '% wrong</span>' +
                    '</div>';
                if (q.ai_analysis) {
                    html += '<div class="ins-q-ai"><span class="material-symbols-outlined">auto_awesome</span> ' +
                        escapeHtml(q.ai_analysis) + '</div>';
                }
            });
            html += '</div>';
        }

        if (quizData.ambiguous_questions && quizData.ambiguous_questions.length) {
            html += '<h5>Questions with Ambiguous Wording</h5>';
            html += '<div class="ins-question-list">';
            quizData.ambiguous_questions.forEach(function(q) {
                html += '<div class="ins-question-item ins-ambiguous">' +
                    '<span class="ins-q-text">' + escapeHtml(q.question || '') + '</span>' +
                    '<span class="ins-q-reason">' + escapeHtml(q.reason || '') + '</span>' +
                    '</div>';
            });
            html += '</div>';
        }

        if (quizData.ai_recommendation) {
            html += '<div class="ins-card-section ins-explanation"><h5>AI Recommendation</h5>' +
                '<p>' + escapeHtml(quizData.ai_recommendation) + '</p></div>';
        }

        el.innerHTML = html;
    }

    // ════════════════════════════════════════════════════════════════
    // TEACHING INTELLIGENCE ZONE: Recording Analytics (expandable)
    // ════════════════════════════════════════════════════════════════
    function renderRecordingAnalytics(recordings) {
        var el = document.getElementById('ins-recording-analytics');
        if (!el) return;
        if (!recordings || !recordings.length) {
            el.innerHTML = '<div class="ins-empty">No recording analytics data yet.</div>';
            return;
        }
        el.innerHTML = recordings.map(function(r, idx) {
            var cardId = 'ins-recording-card-' + idx;
            var isExpanded = expandedCards[cardId] || false;
            var completionColor = (r.completion_rate || 0) >= 60 ? '#22c55e' :
                ((r.completion_rate || 0) >= 30 ? '#f59e0b' : '#dc2626');

            var expandedContent = isExpanded ? (
                '<div class="ins-card-section"><h5>Engagement Breakdown</h5>' +
                '<div class="ins-recording-stats">' +
                '<div class="ins-rec-stat"><span class="ins-rec-val">' + (r.views || 0) + '</span><span class="ins-rec-lbl">Views</span></div>' +
                '<div class="ins-rec-stat"><span class="ins-rec-val">' + (r.avg_watch_duration_min || 0) + ' min</span><span class="ins-rec-lbl">Avg Duration</span></div>' +
                '<div class="ins-rec-stat"><span class="ins-rec-val" style="color:' + completionColor + '">' + (r.completion_rate || 0) + '%</span><span class="ins-rec-lbl">Completion</span></div>' +
                '<div class="ins-rec-stat"><span class="ins-rec-val">' + (r.duration_min || 0) + ' min</span><span class="ins-rec-lbl">Total Duration</span></div>' +
                '<div class="ins-rec-stat"><span class="ins-rec-val">' + (r.never_watched_count || 0) + '</span><span class="ins-rec-lbl">Never Watched</span></div>' +
                '</div></div>' +
                '<div class="ins-card-section ins-explanation"><h5>AI Insight</h5>' +
                '<p>' + escapeHtml(r.recommendation || '') + '</p></div>'
            ) : '';

            return '<div class="ins-expandable-card" data-card-id="' + escapeHtml(cardId) + '">' +
                '<div class="ins-expandable-header" onclick="window.analyticsDashboard.toggleCard(\'' +
                    escapeHtml(cardId) + '\')">' +
                '<div class="ins-recording-header">' +
                '<strong>' + escapeHtml(r.title || 'Recording') + '</strong>' +
                '<span class="material-symbols-outlined ins-expand-icon">' + (isExpanded ? 'expand_less' : 'expand_more') + '</span>' +
                '</div>' +
                '<div class="ins-recording-summary">' +
                '<span class="ins-rec-stat"><span class="ins-rec-val">' + (r.views || 0) + '</span> views</span>' +
                '<span class="ins-rec-stat"><span class="ins-rec-val" style="color:' + completionColor + '">' + (r.completion_rate || 0) + '%</span> completion</span>' +
                '<span class="ins-rec-stat">' + (r.avg_watch_duration_min || 0) + ' min avg</span>' +
                '</div>' +
                '</div>' +
                '<div class="ins-expandable-body" id="' + escapeHtml(cardId) + '" style="display:' + (isExpanded ? 'block' : 'none') + ';">' +
                expandedContent +
                '</div>' +
                '</div>';
        }).join('');
    }

    // ════════════════════════════════════════════════════════════════
    // TEACHING INTELLIGENCE ZONE: Resource Analytics (expandable)
    // ════════════════════════════════════════════════════════════════
    function renderResourceAnalytics(resources) {
        var el = document.getElementById('ins-resource-analytics');
        if (!el) return;
        if (!resources || !resources.length) {
            el.innerHTML = '<div class="ins-empty">No resource analytics data yet.</div>';
            return;
        }
        el.innerHTML = resources.map(function(r, idx) {
            var cardId = 'ins-resource-card-' + idx;
            var isExpanded = expandedCards[cardId] || false;

            var expandedContent = isExpanded ? (
                '<div class="ins-card-section"><h5>Engagement Details</h5>' +
                '<div class="ins-resource-stats">' +
                '<div class="ins-res-stat"><span class="ins-res-val">' + (r.downloads || 0) + '</span><span class="ins-res-lbl">Downloads</span></div>' +
                '<div class="ins-res-stat"><span class="ins-res-val">' + (r.unique_viewers || 0) + '</span><span class="ins-res-lbl">Unique Viewers</span></div>' +
                '<div class="ins-res-stat"><span class="ins-res-val">' + (r.avg_reading_time_min || 0) + ' min</span><span class="ins-res-lbl">Avg Reading Time</span></div>' +
                '<div class="ins-res-stat"><span class="ins-res-val">' + (r.students_never_opened || 0) + '</span><span class="ins-res-lbl">Never Opened</span></div>' +
                '</div></div>' +
                '<div class="ins-card-section ins-explanation"><h5>AI Insight</h5>' +
                '<p>' + escapeHtml(r.recommendation || '') + '</p></div>'
            ) : '';

            return '<div class="ins-expandable-card" data-card-id="' + escapeHtml(cardId) + '">' +
                '<div class="ins-expandable-header" onclick="window.analyticsDashboard.toggleCard(\'' +
                    escapeHtml(cardId) + '\')">' +
                '<div class="ins-resource-header">' +
                '<strong>' + escapeHtml(r.filename || 'Resource') + '</strong>' +
                '<span class="material-symbols-outlined ins-expand-icon">' + (isExpanded ? 'expand_less' : 'expand_more') + '</span>' +
                '</div>' +
                '<div class="ins-resource-summary">' +
                '<span class="ins-res-stat"><span class="ins-res-val">' + (r.downloads || 0) + '</span> downloads</span>' +
                '<span class="ins-res-stat"><span class="ins-res-val">' + (r.unique_viewers || 0) + '</span> viewers</span>' +
                '</div>' +
                '</div>' +
                '<div class="ins-expandable-body" id="' + escapeHtml(cardId) + '" style="display:' + (isExpanded ? 'block' : 'none') + ';">' +
                expandedContent +
                '</div>' +
                '</div>';
        }).join('');
    }

    // ════════════════════════════════════════════════════════════════
    // TEACHING INTELLIGENCE ZONE: AI Learning Analytics
    // ════════════════════════════════════════════════════════════════
    function renderAILearningAnalytics(aiData) {
        var el = document.getElementById('ins-ai-learning-analytics');
        if (!el) return;
        if (!aiData || Object.keys(aiData).length === 0) {
            el.innerHTML = '<div class="ins-empty">No AI learning analytics yet.</div>';
            return;
        }

        var html = '';

        if (aiData.most_discussed_topics && Object.keys(aiData.most_discussed_topics).length) {
            html += '<div class="ins-card-section"><h5>Most Discussed Topics</h5><ul>';
            Object.entries(aiData.most_discussed_topics).forEach(function(entry) {
                html += '<li><strong>' + escapeHtml(entry[0]) + '</strong> — ' + entry[1] + ' mentions</li>';
            });
            html += '</ul></div>';
        }

        if (aiData.students_heavily_relying_on_ai && Object.keys(aiData.students_heavily_relying_on_ai).length) {
            html += '<div class="ins-card-section"><h5>Students Relying Heavily on AI</h5><ul>';
            Object.entries(aiData.students_heavily_relying_on_ai).forEach(function(entry) {
                html += '<li>Student ' + entry[0] + ' — ' + entry[1] + ' AI queries</li>';
            });
            html += '</ul></div>';
        }

        if (aiData.frequently_asked_concepts && aiData.frequently_asked_concepts.length) {
            html += '<div class="ins-card-section"><h5>Frequently Asked Concepts</h5><ul>';
            aiData.frequently_asked_concepts.forEach(function(c) {
                html += '<li>' + escapeHtml(c) + '</li>';
            });
            html += '</ul></div>';
        }

        if (aiData.topics_generating_confusion && aiData.topics_generating_confusion.length) {
            html += '<div class="ins-card-section"><h5>Topics Generating Confusion</h5><ul>';
            aiData.topics_generating_confusion.forEach(function(c) {
                html += '<li>' + escapeHtml(c) + '</li>';
            });
            html += '</ul></div>';
        }

        el.innerHTML = html || '<div class="ins-empty">No AI learning analytics data.</div>';
    }

    // ════════════════════════════════════════════════════════════════
    // Card Toggle (expand/collapse)
    // ════════════════════════════════════════════════════════════════
    function toggleCard(cardId) {
        var body = document.getElementById(cardId);
        var card = body ? body.closest('.ins-expandable-card, .ins-risk-panel') : null;
        if (!body || !card) return;

        if (expandedCards[cardId]) {
            body.style.display = 'none';
            expandedCards[cardId] = false;
            var icon = card.querySelector('.ins-expand-icon');
            if (icon) icon.textContent = 'expand_more';
        } else {
            body.style.display = 'block';
            expandedCards[cardId] = true;
            var icon = card.querySelector('.ins-expand-icon');
            if (icon) icon.textContent = 'expand_less';

            // Lazy-load timeline for student risk panels
            var studentId = card.dataset.studentId;
            if (studentId) {
                loadStudentTimeline(parseInt(studentId));
            }
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Student Action Handler
    // ════════════════════════════════════════════════════════════════
    function handleStudentAction(action, userid) {
        if (action === 'send_message') {
            openActionDrawer('encouragement');
            return;
        }
        if (action === 'recommend_resource') {
            // Load recommended resources for this student
            var student = null;
            if (allTeachingIntelligence && allTeachingIntelligence.students_at_risk) {
                for (var i = 0; i < allTeachingIntelligence.students_at_risk.length; i++) {
                    if (allTeachingIntelligence.students_at_risk[i].userid === userid) {
                        student = allTeachingIntelligence.students_at_risk[i];
                        break;
                    }
                }
            }
            if (student && student.struggle_topics && student.struggle_topics.length) {
                var topic = student.struggle_topics[0];
                var msg = 'Hi, I noticed you might be struggling with ' + topic + '. I recommend reviewing the lecture materials and trying the practice quiz for that topic. Let me know if you need help!';
                var drawer = document.getElementById('ins-action-drawer');
                var recipient = document.getElementById('ins-drawer-recipient');
                var message = document.getElementById('ins-drawer-message');
                if (drawer) drawer.style.display = 'flex';
                if (recipient) recipient.textContent = 'To: Student #' + userid;
                if (message) message.value = msg;
            }
            return;
        }
        if (action === 'view_activity') {
            // Expand the card and scroll to the timeline
            var cardId = 'ins-risk-' + userid;
            if (!expandedCards[cardId]) {
                toggleCard(cardId);
            }
            setTimeout(function() {
                var timelineEl = document.getElementById('ins-timeline-' + userid);
                if (timelineEl) timelineEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 300);
            return;
        }
        if (action === 'view_quiz_history') {
            // Show quiz history for this student
            loadStudentQuizHistory(userid);
            return;
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Student Quiz History
    // ════════════════════════════════════════════════════════════════
    function loadStudentQuizHistory(userid) {
        // Placeholder - would fetch quiz attempt data
        var panel = document.getElementById('ins-detail-panel');
        var body = document.getElementById('ins-detail-body');
        if (!panel || !body) return;

        panel.style.display = 'block';
        body.innerHTML = '<div class="ins-empty">Loading quiz history…</div>';
    }

    // ════════════════════════════════════════════════════════════════
    // Expand All Cards
    // ════════════════════════════════════════════════════════════════
    function expandAllCards() {
        var cards = document.querySelectorAll('.ins-expandable-card, .ins-risk-panel');
        cards.forEach(function(card) {
            var cardId = card.getAttribute('data-card-id') || card.querySelector('.ins-expandable-body')?.id;
            if (cardId && !expandedCards[cardId]) {
                toggleCard(cardId);
            }
        });
    }

    function closeDetail() {
        var panel = document.getElementById('ins-detail-panel');
        if (panel) panel.style.display = 'none';
        currentDetailUid = 0;
    }

    function loadStudentDetail(uid) {
        var cardId = 'ins-risk-' + uid;
        if (!expandedCards[cardId]) {
            toggleCard(cardId);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Priority Action Handler
    // ════════════════════════════════════════════════════════════════
    function handlePriorityAction(type, userid) {
        if (type === 'disengagement') {
            setFilter('disengaged', document.querySelector('[data-filter="disengaged"]'));
        } else if (type === 'recap_needed') {
            setFilter('struggling', document.querySelector('[data-filter="struggling"]'));
        } else if (type === 'issues') {
            setFilter('issues', document.querySelector('[data-filter="issues"]'));
        } else if (type === 'contact_student' && userid) {
            // Open action drawer for this student
            currentDetailUid = userid;
            openActionDrawer('encouragement');
            return;
        } else if (type === 'review_topic') {
            // Scroll to topic struggle section
            var zone = document.getElementById('ins-topic-struggles');
            if (zone) zone.scrollIntoView({ behavior: 'smooth' });
            return;
        } else if (type === 'view_recording') {
            var zone = document.getElementById('ins-recording-analytics');
            if (zone) zone.scrollIntoView({ behavior: 'smooth' });
            return;
        }
        // Scroll to student section
        var zone = document.getElementById('ins-students-at-risk');
        if (zone) zone.scrollIntoView({ behavior: 'smooth' });
    }

    // ════════════════════════════════════════════════════════════════
    // Action Drawer (reuse existing)
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
        if (!student && allTeachingIntelligence && allTeachingIntelligence.students_at_risk) {
            for (var j = 0; j < allTeachingIntelligence.students_at_risk.length; j++) {
                if (allTeachingIntelligence.students_at_risk[j].userid === currentDetailUid) {
                    student = allTeachingIntelligence.students_at_risk[j];
                    break;
                }
            }
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
                response.innerHTML = '<span style="color:var(--u-ter);">' + escapeHtml(err.message || 'Failed to query AI service.') + '</span>';
            }
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
        loadTeachingIntelligence: loadTeachingIntelligence,
        setFilter: setFilter,
        loadStudentDetail: loadStudentDetail,
        closeDetail: closeDetail,
        handlePriorityAction: handlePriorityAction,
        handleStudentAction: handleStudentAction,
        toggleCard: toggleCard,
        expandAllCards: expandAllCards,
        openActionDrawer: openActionDrawer,
        closeActionDrawer: closeActionDrawer,
        sendIntervention: sendIntervention,
        submitNLQ: submitNLQ
    };
});
