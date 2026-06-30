// AMD module: local_umat_ai/ai_dashboard
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    'use strict';

    var chartJsUrl = 'https://cdn.jsdelivr.net/npm/chart.js';
    var courseId = 0;

    function init(cid) {
        courseId = cid;
        // Dynamically load Chart.js
        require([chartJsUrl], function(Chart) {
            window.Chart = Chart; // Make it globally available for convenience
            loadData();
        }, function(err) {
            console.error('Failed to load Chart.js', err);
            // Fallback: still try to load data without charts
            loadData();
        });
    }

    function loadData() {
        Ajax.call([{
            methodname: 'local_umat_ai_get_struggle_dashboard_data',
            args: { courseid: courseId }
        }])[0].done(function(response) {
            // Check if response is string (sometimes AJAX returns stringified JSON)
            var data = typeof response === 'string' ? JSON.parse(response) : response;
            
            // If the backend isn't returning data yet, mock it for the UI demonstration
            if (!data || !data.kpis) {
                data = getMockData();
            }

            renderKPIs(data.kpis);
            if (window.Chart) {
                renderScatterPlot(data.scatter_plot_data);
                renderHealthChart(); // Mocked health chart
            }
            renderTopicMastery(data.scatter_plot_data);
            renderTriageTable(data.at_risk_students);
            renderFeeds();

        }).fail(function(ex) {
            console.warn('Dashboard API failed, using mock data for demonstration.', ex);
            var data = getMockData();
            renderKPIs(data.kpis);
            if (window.Chart) {
                renderScatterPlot(data.scatter_plot_data);
                renderHealthChart();
            }
            renderTopicMastery(data.scatter_plot_data);
            renderTriageTable(data.at_risk_students);
            renderFeeds();
        });
    }

    function renderKPIs(kpis) {
        document.getElementById('kpi-engagement-score').textContent = kpis.engagement_score + '%';
        document.getElementById('kpi-engagement-delta').textContent = '+ ' + (kpis.engagement_trend[kpis.engagement_trend.length-1] - kpis.engagement_trend[0]) + '% vs Last Week';
        
        document.getElementById('kpi-at-risk-count').textContent = kpis.at_risk_count;
        
        // Mock avatars
        var avatarHtml = '';
        for (var i = 0; i < Math.min(4, kpis.at_risk_count); i++) {
            avatarHtml += '<img src="https://ui-avatars.com/api/?name=Student+' + i + '&background=random&color=fff" alt="Student">';
        }
        document.getElementById('kpi-at-risk-avatars').innerHTML = avatarHtml;

        document.getElementById('kpi-top-topic').textContent = kpis.top_topic;
        document.getElementById('kpi-topic-insight').textContent = "Students are struggling most with core concepts in this topic.";
        
        document.getElementById('kpi-top-material').textContent = kpis.top_material;
    }

    function renderScatterPlot(scatterData) {
        var ctx = document.getElementById('friction-scatter-chart');
        if (!ctx) return;

        var datasets = scatterData.map(function(d) {
            var color = '#4ade80'; // healthy
            if (d.severity === 'critical') color = '#a5304d';
            else if (d.severity === 'warning' || d.severity === 'amber') color = '#f59e0b';
            
            return {
                label: d.topic,
                data: [{ x: d.volume, y: d.friction, r: Math.max(5, d.friction / 3) }],
                backgroundColor: color,
                borderColor: color,
                hoverBackgroundColor: color
            };
        });

        new Chart(ctx, {
            type: 'bubble',
            data: { datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { title: { display: true, text: 'Question Volume' }, min: 0, max: 70 },
                    y: { title: { display: true, text: 'Friction Score' }, min: 0, max: 60 }
                }
            }
        });
    }

    function renderHealthChart() {
        var ctx = document.getElementById('material-health-chart');
        if (!ctx) return;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Lecture 1', 'Lab Manual', 'Quiz 1', 'Assignment 1'],
                datasets: [
                    { label: '% Complete', data: [75, 40, 60, 20], backgroundColor: '#4ade80' },
                    { label: '% Questions Asked', data: [15, 55, 30, 10], backgroundColor: '#f59e0b' },
                    { label: '% Incorrect', data: [10, 45, 20, 5], backgroundColor: '#a5304d' }
                ]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: { x: { stacked: false, max: 100 }, y: { stacked: false } }
            }
        });
    }

    function renderTopicMastery(scatterData) {
        var container = document.getElementById('topic-mastery-list');
        if (!container) return;
        
        var html = '';
        scatterData.forEach(function(d) {
            var badgeClass = d.severity === 'critical' ? 'badge-critical' : (d.severity === 'warning' || d.severity === 'amber' ? 'badge-amber' : 'badge-healthy');
            html += '<div class="topic-list-item">';
            html += '<span class="topic-name">' + d.topic + '</span>';
            html += '<span class="status-badge ' + badgeClass + '">' + d.friction + ' Friction</span>';
            html += '</div>';
        });
        container.innerHTML = html;
    }

    function renderTriageTable(students) {
        var tbody = document.getElementById('triage-table-body');
        if (!tbody) return;

        var html = '';
        students.forEach(function(s) {
            var badgeClass = s.risk.toLowerCase() === 'critical' ? 'badge-critical' : 'badge-amber';
            html += '<tr>';
            html += '<td><input type="checkbox"></td>';
            html += '<td><div style="display:flex; align-items:center; gap:8px;">';
            html += '<img src="https://ui-avatars.com/api/?name=' + encodeURIComponent(s.name) + '&background=random&color=fff" style="width:24px;height:24px;border-radius:50%;">';
            html += '<span>' + s.name + '</span></div></td>';
            html += '<td><span class="status-badge ' + badgeClass + '">' + s.risk + '</span></td>';
            html += '<td>' + s.struggle + '</td>';
            html += '<td>' + s.last_active + '</td>';
            html += '<td class="action-icons">';
            html += '<span class="material-symbols-outlined" title="Message">mail</span>';
            html += '<span class="material-symbols-outlined" title="Video">videocam</span>';
            html += '</td>';
            html += '</tr>';
        });
        tbody.innerHTML = html;
    }

    function renderFeeds(questions, topics, health) {
        var qFeed = document.getElementById('common-questions-feed');
        if (qFeed && questions && questions.length > 0) {
            var qHtml = '';
            questions.forEach(function(q) {
                qHtml += '<li style="margin-bottom:8px;">"' + escHtml(q.text) + '" <span style="color:#9ca3af;font-size:11px;">(' + q.count + ')</span></li>';
            });
            qFeed.innerHTML = qHtml;
        } else if (qFeed) {
            qFeed.innerHTML = '<li>No common questions yet.</li>';
        }

        var tFeed = document.getElementById('extracted-topics-feed');
        if (tFeed && topics && topics.length > 0) {
            var tHtml = '';
            topics.slice(0, 5).forEach(function(t) {
                tHtml += '<li style="margin-bottom:8px;">' + escHtml(t.topic) + ' <span style="color:#9ca3af;font-size:11px;">(' + t.volume + ' Qs)</span></li>';
            });
            tFeed.innerHTML = tHtml;
        }

        var hSummary = document.getElementById('ai-health-summary');
        if (hSummary && health && health.summary) {
            hSummary.textContent = health.summary;
        }
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    function getMockData() {
        return {
            kpis: {
                engagement_score: 88,
                engagement_trend: [75, 80, 85, 82, 88],
                at_risk_count: 12,
                top_topic: "Academic Referencing",
                top_material: "Lab Manual: Exercises 1-3"
            },
            scatter_plot_data: [
                { topic: "Referencing Skills", volume: 45, friction: 55, severity: "critical" },
                { topic: "Hypothesis Testing", volume: 30, friction: 40, severity: "warning" },
                { topic: "Course Concepts", volume: 20, friction: 15, severity: "healthy" }
            ],
            at_risk_students: [
                { id: 101, name: "Johnson Appiah", risk: "Critical", struggle: "Referencing", last_active: "20 days" },
                { id: 102, name: "Sarah Mensah", risk: "Amber", struggle: "Hypothesis Testing", last_active: "5 days" },
                { id: 103, name: "Kwame Osei", risk: "Critical", struggle: "Lab Manual", last_active: "14 days" }
            ]
        };
    }

    return {
        init: init
    };
});
