// AMD module: local_umat_ai/quizgen_review
// Enhanced lecturer quiz generator — academic paper review, marking scheme,
// inline editing, question regeneration, approval workflow.
define(['core/ajax'], function(Ajax) {
    'use strict';

    var _pollTimer = null;
    var _currentCid = 0;
    var _allJobsData = [];
    var _questions = [];
    var _lastJobId = 0;
    var _materials = [];
    var _reviewViewMode = 'paper';
    var _courseGroups = [];

    // Layout state for resizable split-screen.
    var _layout = {
        configWidth: 360,
        prevConfigWidth: 360,
        expanded: false,
        collapsed: false,
        isMobile: false,
        dragging: false,
        startX: 0,
        startWidth: 0,
        configScrollTop: 0,
        previewScrollTop: 0
    };
    var LAYOUT_MIN_CONFIG = 300;
    var LAYOUT_MAX_RATIO = 0.55;
    var LAYOUT_MOBILE_BREAKPOINT = 900;

    var BLOOM_LEVELS = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'];
    var DIFF_LEVELS = ['easy', 'medium', 'hard'];
    var QTYPES = ['multichoice', 'truefalse', 'shortanswer'];
    var QTYPE_LABELS = { multichoice: 'Multiple Choice', truefalse: 'True/False', shortanswer: 'Short Answer' };

    var INSTR_PRESETS = [
        { key: 'critical_thinking',        label: 'Ask critical-thinking questions',         category: 'style' },
        { key: 'application_based',        label: 'Use application-based questions',          category: 'style' },
        { key: 'scenario_based',           label: 'Create scenario-based questions',          category: 'style' },
        { key: 'case_study',               label: 'Include short case studies',               category: 'style' },
        { key: 'real_world_examples',      label: 'Use real-world examples',                  category: 'context' },
        { key: 'ghanaian_examples',        label: 'Use Ghanaian examples',                    category: 'context' },
        { key: 'industry_examples',        label: 'Use industry-specific examples',           category: 'context' },
        { key: 'problem_solving',          label: 'Test problem-solving ability',             category: 'style' },
        { key: 'comparison_justification', label: 'Require comparison and justification',     category: 'style' },
        { key: 'avoid_direct_recall',      label: 'Avoid direct recall questions',            category: 'avoid' },
        { key: 'avoid_trick_ambiguous',    label: 'Avoid trick or ambiguous questions',       category: 'avoid' },
        { key: 'include_calculations',     label: 'Include calculations where supported',     category: 'style' },
        { key: 'provide_explanations',     label: 'Provide answer explanations',              category: 'style' },
    ];

    var GROUNDING_MODES = [
        { key: 'strict',    label: 'Strictly from the material',   desc: 'Use only information explicitly stated in the material. Suitable for definitions, recall, basic comprehension.' },
        { key: 'applied',   label: 'Apply concepts from the material',  desc: 'Create new scenarios and examples, but the tested concept and correct answer must derive from the material. Recommended.', recommended: true },
        { key: 'enriched',  label: 'Enriched with general knowledge',   desc: 'Allow limited, widely accepted external context. The course material must still provide the main assessed concept.' },
    ];

    function esc(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s || ''));
        return d.innerHTML;
    }

    function init(courseId) {
        _currentCid = courseId;
        var body = document.getElementById('qgen-body');
        if (!body) return;

        body.innerHTML =
            '<div class="qgen-skeleton">' +
            '  <div class="qgen-sk-card"><div class="qgen-sk-line w60"></div><div class="qgen-sk-line w80"></div><div class="qgen-sk-line h80"></div><div class="qgen-sk-line w40"></div></div>' +
            '  <div class="qgen-sk-card"><div class="qgen-sk-line w40"></div><div class="qgen-sk-line h80"></div></div>' +
            '</div>';

        setTimeout(function() { renderFullUI(courseId); }, 50);
    }

    function renderFullUI(courseId) {
        var body = document.getElementById('qgen-body');
        if (!body) return;

        body.innerHTML =
            '<div class="qgen-tabs">' +
            '  <button class="qgen-tab active" data-tab="generate"><span class="material-symbols-outlined">auto_awesome</span> Generate</button>' +
            '  <button class="qgen-tab" data-tab="history"><span class="material-symbols-outlined">history</span> History</button>' +
            '</div>' +
            '<div id="qgen-tab-generate"></div>' +
            '<div id="qgen-tab-history" style="display:none;"></div>';

        document.querySelectorAll('.qgen-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.qgen-tab').forEach(function(t) { t.classList.remove('active'); });
                this.classList.add('active');
                var target = this.dataset.tab;
                document.getElementById('qgen-tab-generate').style.display = target === 'generate' ? '' : 'none';
                document.getElementById('qgen-tab-history').style.display = target === 'history' ? '' : 'none';
                if (target === 'history') loadHistory();
            });
        });

        if (courseId === 0) {
            var genTab = document.getElementById('qgen-tab-generate');
            if (genTab) genTab.innerHTML = '<div class="umat-empty"><span class="material-symbols-outlined">menu_book</span><p>Select a course to generate quiz questions.</p></div>';
        } else {
            renderForm(courseId);
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  CONFIG FORM (preserved)
    // ═══════════════════════════════════════════════════════════

    function renderForm(cid) {
        var c = document.getElementById('qgen-tab-generate');
        _layout.isMobile = window.innerWidth <= LAYOUT_MOBILE_BREAKPOINT;

        var layoutClass = 'qgen-layout';
        if (_layout.isMobile) layoutClass += ' qgen-layout-mobile';

        c.innerHTML =
            '<div class="' + layoutClass + '" id="qgen-layout">' +
            '  <div class="qgen-config" id="qgen-config-panel">' +
            '    <div class="qgen-config-scroll" id="qgen-config-scroll">' +
            renderSourceSection() +
            renderQuestionTypeSection() +
            renderBloomSection() +
            renderDifficultySection() +
            renderQuizDetailsSection() +
            renderScheduleSection() +
            renderAccessSecuritySection() +
            renderPlacementSection() +
            renderAdvancedSection() +
            renderDeliveryMethodSection() +
            renderDocSettingsSection() +
            renderInstructionsSection() +
            renderDestinationSection() +
            renderGenerateButton() +
            '    </div>' +
            '  </div>' +
            '  <div class="qgen-divider" id="qgen-divider"' +
            '    role="separator" aria-orientation="vertical"' +
            '    aria-label="Resize configuration and preview panels"' +
            '    aria-valuemin="300" aria-valuemax="800" aria-valuenow="' + _layout.configWidth + '" tabindex="0">' +
            '    <div class="qgen-divider-handle"></div>' +
            '  </div>' +
            '  <div class="qgen-preview" id="qgen-preview-panel">' +
            '    <div class="qgen-card" id="qgen-preview-card">' +
            '      <div class="qgen-preview-header">' +
            '        <h3><span class="material-symbols-outlined">preview</span> Preview</h3>' +
            '        <div class="qgen-preview-controls" id="qgen-preview-controls"></div>' +
            '      </div>' +
            '      <div id="qgen-preview-body">' +
            '        <div class="umat-empty"><span class="material-symbols-outlined">quiz</span><p>Configure and generate to see questions here.</p></div>' +
            '      </div>' +
            '    </div>' +
            '  </div>' +
            '</div>';

        if (!_layout.isMobile) {
            applyConfigWidth();
        }
        loadMaterials(cid);
        wireFormEvents(cid);
        initResize(cid);
        updatePreviewControls();
    }

    function renderSourceSection() {
        return '<div class="qgen-card qgen-section">' +
            '<h3><span class="material-symbols-outlined">source</span> Source Material</h3>' +
            '<div class="qgen-field">' +
            '  <label>Source Type</label>' +
            '  <select id="qgen-source" class="qgen-select">' +
            '    <option value="material">Course Materials (select one or more below)</option>' +
            '    <option value="text">Custom Text</option>' +
            '  </select>' +
            '</div>' +
            '<div id="qgen-material-field">' +
            '  <label>Select Materials</label>' +
            '  <div id="qgen-materials-list" class="qgen-materials-list"><div class="qgen-loading-sm">Loading materials\u2026</div></div>' +
            '</div>' +
            '<div id="qgen-text-field" style="display:none;">' +
            '  <label>Paste Content</label>' +
            '  <textarea id="qgen-text" class="qgen-textarea" rows="6" placeholder="Paste lecture notes, slides text, or any course content\u2026"></textarea>' +
            '</div>' +
            '</div>';
    }

    function renderQuestionTypeSection() {
        var chips = QTYPES.map(function(t) {
            return '<div class="qgen-type-row" data-type="' + t + '">' +
                '<label class="qgen-type-label">' +
                '  <input type="checkbox" class="qgen-type-check" value="' + t + '"' + (t === 'multichoice' ? ' checked' : '') + '>' +
                '  <span>' + QTYPE_LABELS[t] + '</span>' +
                '</label>' +
                '<input type="number" class="qgen-type-count" value="' + (t === 'multichoice' ? 5 : 0) + '" min="0" max="30">' +
                '</div>';
        }).join('');

        return '<div class="qgen-card qgen-section">' +
            '<h3><span class="material-symbols-outlined">checklist</span> Question Types</h3>' +
            '<p class="qgen-section-desc">Select types and set how many of each to generate.</p>' +
            '<div class="qgen-type-grid">' + chips + '</div>' +
            '<div class="qgen-total-bar">Total: <strong id="qgen-total-count">5</strong> questions</div>' +
            '</div>';
    }

    function renderBloomSection() {
        var singleOpts = BLOOM_LEVELS.map(function(l) {
            return '<option value="' + l + '">' + l.charAt(0).toUpperCase() + l.slice(1) + '</option>';
        }).join('');

        var mixedRows = BLOOM_LEVELS.map(function(l) {
            return '<div class="qgen-dist-row">' +
                '<label>' + l.charAt(0).toUpperCase() + l.slice(1) + '</label>' +
                '<input type="number" class="qgen-bloom-mix" value="0" min="0" max="30" data-level="' + l + '">' +
                '</div>';
        }).join('');

        return '<div class="qgen-card qgen-section">' +
            '<h3><span class="material-symbols-outlined">psychology</span> Bloom\'s Taxonomy</h3>' +
            '<div class="qgen-dist-toggle">' +
            '  <label class="qgen-radio-label"><input type="radio" name="qgen-bloom-mode" value="single" checked> Single level</label>' +
            '  <label class="qgen-radio-label"><input type="radio" name="qgen-bloom-mode" value="mixed"> Mixed distribution</label>' +
            '</div>' +
            '<div id="qgen-bloom-single" class="qgen-dist-single">' +
            '  <select id="qgen-bloom" class="qgen-select">' + singleOpts + '</select>' +
            '</div>' +
            '<div id="qgen-bloom-mixed" class="qgen-dist-mixed" style="display:none;">' + mixedRows + '</div>' +
            '</div>';
    }

    function renderDifficultySection() {
        var singleOpts = DIFF_LEVELS.map(function(l) {
            return '<option value="' + l + '">' + l.charAt(0).toUpperCase() + l.slice(1) + '</option>';
        }).join('');

        var mixedRows = DIFF_LEVELS.map(function(l) {
            return '<div class="qgen-dist-row">' +
                '<label>' + l.charAt(0).toUpperCase() + l.slice(1) + '</label>' +
                '<input type="number" class="qgen-diff-mix" value="0" min="0" max="30" data-level="' + l + '">' +
                '</div>';
        }).join('');

        return '<div class="qgen-card qgen-section">' +
            '<h3><span class="material-symbols-outlined">signal_cellular_alt</span> Difficulty</h3>' +
            '<div class="qgen-dist-toggle">' +
            '  <label class="qgen-radio-label"><input type="radio" name="qgen-diff-mode" value="single" checked> Single level</label>' +
            '  <label class="qgen-radio-label"><input type="radio" name="qgen-diff-mode" value="mixed"> Mixed distribution</label>' +
            '</div>' +
            '<div id="qgen-diff-single" class="qgen-dist-single">' +
            '  <select id="qgen-difficulty" class="qgen-select">' + singleOpts + '</select>' +
            '</div>' +
            '<div id="qgen-diff-mixed" class="qgen-dist-mixed" style="display:none;">' + mixedRows + '</div>' +
            '</div>';
    }

    function renderQuizDetailsSection() {
        return '<div class="qgen-card qgen-section qgen-section-collapsed" id="qgen-details-section">' +
            '<h3 class="qgen-section-toggle" id="qgen-details-toggle">' +
            '  <span class="material-symbols-outlined">tune</span> Quiz Details' +
            '  <span class="material-symbols-outlined qgen-chevron">expand_more</span>' +
            '</h3>' +
            '<div class="qgen-section-body" style="display:none;">' +
            '  <div class="qgen-field">' +
            '    <label>Quiz Name</label>' +
            '    <input type="text" id="qgen-name" class="qgen-input" placeholder="Leave blank for auto-name">' +
            '  </div>' +
            '  <div class="qgen-field">' +
            '    <label>Description <span class="qgen-hint">(shown to students before the quiz)</span></label>' +
            '    <textarea id="qgen-desc" class="qgen-textarea" rows="2" placeholder="Optional introduction for students\u2026"></textarea>' +
            '  </div>' +
            '  <div class="qgen-field-row">' +
            '    <div class="qgen-field">' +
            '      <label>Marks per Question</label>' +
            '      <input type="number" id="qgen-marks" class="qgen-input" value="1" min="0.5" max="100" step="0.5">' +
            '    </div>' +
            '    <div class="qgen-field">' +
            '      <label>Time Limit (min)</label>' +
            '      <input type="number" id="qgen-timelimit" class="qgen-input" value="0" min="0" max="600">' +
            '    </div>' +
            '    <div class="qgen-field">' +
            '      <label>Max Attempts</label>' +
            '      <input type="number" id="qgen-maxattempts" class="qgen-input" value="-1" min="-1" max="10">' +
            '    </div>' +
            '  </div>' +
            '  <div class="qgen-toggle-row">' +
            '    <label class="qgen-check-label"><input type="checkbox" id="qgen-shuffle-q"> Shuffle question order</label>' +
            '    <label class="qgen-check-label"><input type="checkbox" id="qgen-shuffle-a" checked> Shuffle answers</label>' +
            '    <label class="qgen-check-label"><input type="checkbox" id="qgen-show-fb" checked> Show feedback</label>' +
            '  </div>' +
            '  <p class="qgen-hint" style="margin-top:6px;">Time limit and max attempts can also be adjusted in Moodle after import.</p>' +
            '</div>' +
            '</div>';
    }

    function renderScheduleSection() {
        return '<div class="qgen-card qgen-section qgen-section-collapsed qgen-online-only" id="qgen-schedule-section">' +
            '<h3 class="qgen-section-toggle">' +
            '  <span class="material-symbols-outlined">schedule</span> Schedule' +
            '  <span class="material-symbols-outlined qgen-chevron">expand_more</span>' +
            '</h3>' +
            '<div class="qgen-section-body" style="display:none;">' +
            '  <p class="qgen-hint">Control when students can access this quiz. Leave empty for always available.</p>' +
            '  <div class="qgen-field-row">' +
            '    <div class="qgen-field">' +
            '      <label>Open Date &amp; Time</label>' +
            '      <input type="datetime-local" id="qgen-timeopen" class="qgen-input">' +
            '    </div>' +
            '    <div class="qgen-field">' +
            '      <label>Close Date &amp; Time (Deadline)</label>' +
            '      <input type="datetime-local" id="qgen-timeclose" class="qgen-input">' +
            '    </div>' +
            '  </div>' +
            '</div>' +
            '</div>';
    }

    function renderAccessSecuritySection() {
        return '<div class="qgen-card qgen-section qgen-section-collapsed qgen-online-only" id="qgen-access-section">' +
            '<h3 class="qgen-section-toggle">' +
            '  <span class="material-symbols-outlined">lock</span> Access &amp; Security' +
            '  <span class="material-symbols-outlined qgen-chevron">expand_more</span>' +
            '</h3>' +
            '<div class="qgen-section-body" style="display:none;">' +
            '  <div class="qgen-field">' +
            '    <label>Exam Password <span class="qgen-hint">(leave blank for none)</span></label>' +
            '    <input type="text" id="qgen-password" class="qgen-input" placeholder="Optional password for quiz access">' +
            '  </div>' +
            '  <div class="qgen-field">' +
            '    <label>Browser Security</label>' +
            '    <select id="qgen-browser-security" class="qgen-select">' +
            '      <option value="0" selected>None</option>' +
            '      <option value="1">Full screen pop-up with some JavaScript security</option>' +
            '      <option value="2">Full screen pop-up with JavaScript security and copy/paste restricted</option>' +
            '    </select>' +
            '  </div>' +
            '  <div class="qgen-field">' +
            '    <label>Group Mode</label>' +
            '    <select id="qgen-groupmode" class="qgen-select">' +
            '      <option value="0" selected>No groups (all students)</option>' +
            '      <option value="1">Separate groups</option>' +
            '      <option value="2">Visible groups</option>' +
            '    </select>' +
            '  </div>' +
            '  <div class="qgen-field" id="qgen-grouping-wrap" style="display:none;">' +
            '    <label>Restrict to Grouping <span class="qgen-hint">(leave blank for all)</span></label>' +
            '    <select id="qgen-groupingid" class="qgen-select"><option value="0">None (all groups)</option></select>' +
            '  </div>' +
            '  <div class="qgen-field" id="qgen-groups-wrap" style="display:none;">' +
            '    <label>Allowed Groups</label>' +
            '    <div id="qgen-groups-list" class="qgen-check-list"></div>' +
            '  </div>' +
            '</div>' +
            '</div>';
    }

    function renderPlacementSection() {
        return '<div class="qgen-card qgen-section qgen-section-collapsed qgen-online-only" id="qgen-placement-section">' +
            '<h3 class="qgen-section-toggle">' +
            '  <span class="material-symbols-outlined">place</span> Placement' +
            '  <span class="material-symbols-outlined qgen-chevron">expand_more</span>' +
            '</h3>' +
            '<div class="qgen-section-body" style="display:none;">' +
            '  <div class="qgen-field">' +
            '    <label>Course Section</label>' +
            '    <select id="qgen-sectionnum" class="qgen-select"><option value="0">General (top section)</option></select>' +
            '  </div>' +
            '  <div class="qgen-field">' +
            '    <label>Gradebook Category</label>' +
            '    <select id="qgen-gradecat" class="qgen-select"><option value="0">No category (default)</option></select>' +
            '  </div>' +
            '</div>' +
            '</div>';
    }

    function renderAdvancedSection() {
        return '<div class="qgen-card qgen-section qgen-section-collapsed qgen-online-only" id="qgen-advanced-section">' +
            '<h3 class="qgen-section-toggle">' +
            '  <span class="material-symbols-outlined">tune</span> Advanced Quiz Settings' +
            '  <span class="material-symbols-outlined qgen-chevron">expand_more</span>' +
            '</h3>' +
            '<div class="qgen-section-body" style="display:none;">' +
            '  <div class="qgen-field-row">' +
            '    <div class="qgen-field">' +
            '      <label>Question Behaviour</label>' +
            '      <select id="qgen-behaviour" class="qgen-select">' +
            '        <option value="deferredfeedback" selected>Deferred feedback (recommended)</option>' +
            '        <option value="adaptive">Adaptive mode</option>' +
            '        <option value="adaptive_no_penalty">Adaptive mode (no penalties)</option>' +
            '        <option value="interactive">Interactive with multiple tries</option>' +
            '        <option value="interactive_no_certificate">Interactive (no certificates)</option>' +
            '      </select>' +
            '    </div>' +
            '    <div class="qgen-field">' +
            '      <label>Grading Method</label>' +
            '      <select id="qgen-grademethod" class="qgen-select">' +
            '        <option value="1" selected>Mean of all attempts</option>' +
            '        <option value="2">Highest attempt</option>' +
            '        <option value="4">First attempt</option>' +
            '        <option value="6">Last attempt</option>' +
            '      </select>' +
            '    </div>' +
            '  </div>' +
            '  <div class="qgen-field-row">' +
            '    <div class="qgen-field">' +
            '      <label>Navigation Method</label>' +
            '      <select id="qgen-navmethod" class="qgen-select">' +
            '        <option value="free" selected>Free (students answer in any order)</option>' +
            '        <option value="sequential">Sequential (must answer in order)</option>' +
            '      </select>' +
            '    </div>' +
            '    <div class="qgen-field">' +
            '      <label>Questions per Page</label>' +
            '      <input type="number" id="qgen-ppp" class="qgen-input" value="0" min="0" max="50">' +
            '      <span class="qgen-hint">0 = all on one page</span>' +
            '    </div>' +
            '  </div>' +
            '  <div class="qgen-toggle-row">' +
            '    <label class="qgen-check-label"><input type="checkbox" id="qgen-review-attempt" checked> During attempt: attempt</label>' +
            '    <label class="qgen-check-label"><input type="checkbox" id="qgen-review-correctness" checked> During attempt: correctness</label>' +
            '    <label class="qgen-check-label"><input type="checkbox" id="qgen-review-marks" checked> During attempt: marks</label>' +
            '  </div>' +
            '  <div class="qgen-toggle-row">' +
            '    <label class="qgen-check-label"><input type="checkbox" id="qgen-review-responses" checked> After attempt: responses</label>' +
            '    <label class="qgen-check-label"><input type="checkbox" id="qgen-review-feedback" checked> After attempt: feedback</label>' +
            '    <label class="qgen-check-label"><input type="checkbox" id="qgen-review-overall" checked> After attempt: overall feedback</label>' +
            '  </div>' +
            '</div>' +
            '</div>';
    }

    function renderDeliveryMethodSection() {
        return '<div class="qgen-card qgen-section">' +
            '<h3><span class="material-symbols-outlined">send</span> Delivery Method</h3>' +
            '<p class="qgen-section-desc">How will this assessment be delivered?</p>' +
            '<div class="qgen-delivery-options">' +
            '  <label class="qgen-delivery-opt">' +
            '    <input type="radio" name="qgen-delivery" value="online" checked>' +
            '    <span class="qgen-delivery-icon material-symbols-outlined">computer</span>' +
            '    <span class="qgen-delivery-text"><strong>Online in Moodle</strong><small>Quiz in the VLE</small></span>' +
            '  </label>' +
            '  <label class="qgen-delivery-opt">' +
            '    <input type="radio" name="qgen-delivery" value="printed">' +
            '    <span class="qgen-delivery-icon material-symbols-outlined">description</span>' +
            '    <span class="qgen-delivery-text"><strong>In-person / Printed</strong><small>Word document export</small></span>' +
            '  </label>' +
            '  <label class="qgen-delivery-opt">' +
            '    <input type="radio" name="qgen-delivery" value="both">' +
            '    <span class="qgen-delivery-icon material-symbols-outlined">dynamic_form</span>' +
            '    <span class="qgen-delivery-text"><strong>Both Moodle + Printed</strong><small>Online quiz and Word document</small></span>' +
            '  </label>' +
            '</div>' +
            '</div>';
    }

    function renderDocSettingsSection() {
        var field = function(label, id, type, attrs) {
            type = type || 'text';
            attrs = attrs || '';
            if (type === 'textarea') {
                return '<div class="qgen-field"><label>' + label + '</label>' +
                    '<textarea id="' + id + '" class="qgen-textarea" rows="2" ' + attrs + '></textarea></div>';
            }
            return '<div class="qgen-field"><label>' + label + '</label>' +
                '<input type="' + type + '" id="' + id + '" class="qgen-input" ' + attrs + '></div>';
        };

        return '<div class="qgen-card qgen-section qgen-section-collapsed" id="qgen-docsettings-section" style="display:none;">' +
            '<h3 class="qgen-section-toggle" id="qgen-docsettings-toggle">' +
            '  <span class="material-symbols-outlined">description</span> Document Settings (Printed Assessment)' +
            '  <span class="material-symbols-outlined qgen-chevron">expand_more</span>' +
            '</h3>' +
            '<div class="qgen-section-body" style="display:none;">' +
            '  <div class="qgen-docsettings-grid">' +
            '    <div class="qgen-docsettings-col">' +
                    field('Assessment Title', 'qgen-doc-title', 'text', 'placeholder="e.g. End of Semester Exam"') +
                    field('Institution Name', 'qgen-doc-institution', 'text', 'placeholder="University of Mines and Technology"') +
                    field('Course Title', 'qgen-doc-coursetitle', 'text', 'placeholder="Introduction to Mining Engineering"') +
                    field('Course Code', 'qgen-doc-coursecode', 'text', 'placeholder="MENG 301"') +
                    field('Department', 'qgen-doc-dept', 'text', 'placeholder="Department of Mining Engineering"') +
            '    </div>' +
            '    <div class="qgen-docsettings-col">' +
                    field("Lecturer's Name", 'qgen-doc-lecturer', 'text', 'placeholder="Dr. Samuel Owusu"') +
                    field('Examination Date', 'qgen-doc-date', 'date', '') +
                    field('Duration (minutes)', 'qgen-doc-duration', 'number', 'value="120" min="10" max="600"') +
                    field('Total Marks', 'qgen-doc-totalmarks', 'number', 'value="100" min="1" max="1000"') +
            '    </div>' +
            '  </div>' +
            '  ' + field('Candidate Instructions', 'qgen-doc-instructions', 'textarea', 'placeholder="Answer ALL questions. Read each question carefully before answering."') +
            '  <div class="qgen-field"><label>Page Layout</label>' +
            '    <div class="qgen-toggle-row">' +
            '      <label class="qgen-radio-label"><input type="radio" name="qgen-doc-orient" value="portrait" checked> Portrait</label>' +
            '      <label class="qgen-radio-label"><input type="radio" name="qgen-doc-orient" value="landscape"> Landscape</label>' +
            '    </div>' +
            '  </div>' +
            '  <div class="qgen-toggle-row">' +
            '    <label class="qgen-check-label"><input type="checkbox" id="qgen-doc-pagenum" checked> Page numbers</label>' +
            '    <label class="qgen-check-label"><input type="checkbox" id="qgen-doc-marks" checked> Show marks beside questions</label>' +
            '    <label class="qgen-check-label"><input type="checkbox" id="qgen-doc-studentfields" checked> Student info fields</label>' +
            '  </div>' +
            '  <div class="qgen-field">' +
            '    <label>Answer Spaces (short-answer questions)</label>' +
            '    <select id="qgen-doc-answerspaces" class="qgen-select">' +
            '      <option value="0">None (compact)</option>' +
            '      <option value="3" selected>3 lines</option>' +
            '      <option value="5">5 lines</option>' +
            '      <option value="8">8 lines</option>' +
            '    </select>' +
            '  </div>' +
            '  <div class="qgen-field"><label>Version</label>' +
            '    <div class="qgen-toggle-row">' +
            '      <label class="qgen-radio-label"><input type="radio" name="qgen-doc-version" value="A" checked> Version A</label>' +
            '      <label class="qgen-radio-label"><input type="radio" name="qgen-doc-version" value="B"> Version B</label>' +
            '      <label class="qgen-radio-label"><input type="radio" name="qgen-doc-version" value="C"> Version C</label>' +
            '    </div>' +
            '  </div>' +
            '  <div class="qgen-export-types">' +
            '    <p class="qgen-section-desc" style="margin-bottom:6px;"><strong>Export Options:</strong></p>' +
            '    <div class="qgen-toggle-row">' +
            '      <label class="qgen-check-label"><input type="checkbox" class="qgen-export-type-check" value="question_paper" checked> Question Paper</label>' +
            '      <label class="qgen-check-label"><input type="checkbox" class="qgen-export-type-check" value="answer_key"> Answer Key</label>' +
            '      <label class="qgen-check-label"><input type="checkbox" class="qgen-export-type-check" value="examiner_copy"> Examiner\'s Copy</label>' +
            '    </div>' +
            '  </div>' +
            '</div>' +
            '</div>';
    }

    function renderInstructionsSection() {
        var stylePresets = INSTR_PRESETS.filter(function(p) { return p.category === 'style'; });
        var contextPresets = INSTR_PRESETS.filter(function(p) { return p.category === 'context'; });
        var avoidPresets = INSTR_PRESETS.filter(function(p) { return p.category === 'avoid'; });

        var renderPresetGroup = function(presets) {
            return presets.map(function(p) {
                return '<label class="qgen-instr-check">' +
                    '<input type="checkbox" class="qgen-instr-preset-cb" value="' + p.key + '">' +
                    '<span>' + esc(p.label) + '</span>' +
                    '</label>';
            }).join('');
        };

        var groundingRadios = GROUNDING_MODES.map(function(g) {
            var rec = g.recommended ? ' <span class="qgen-badge-rec">Recommended</span>' : '';
            return '<label class="qgen-grounding-opt">' +
                '<input type="radio" name="qgen-grounding" value="' + g.key + '"' + (g.recommended ? ' checked' : '') + '>' +
                '<div class="qgen-grounding-body">' +
                '<strong>' + g.label + '</strong>' + rec +
                '<p>' + esc(g.desc) + '</p>' +
                '</div>' +
                '</label>';
        }).join('');

        return '<div class="qgen-card qgen-section">' +
            '<h3><span class="material-symbols-outlined">record_voice_over</span> AI Instructions</h3>' +
            '<p class="qgen-section-desc">Guide how the AI constructs questions from the selected material.</p>' +

            '<div class="qgen-field">' +
            '  <label>Question Grounding Style</label>' +
            '  <div class="qgen-grounding-group">' + groundingRadios + '</div>' +
            '</div>' +

            '<div class="qgen-field">' +
            '  <label>Instruction Presets</label>' +
            '  <div class="qgen-instr-group">' +
            '    <div class="qgen-instr-group-label">Question Style</div>' +
            '    <div class="qgen-instr-grid">' + renderPresetGroup(stylePresets) + '</div>' +
            '    <div class="qgen-instr-group-label">Context</div>' +
            '    <div class="qgen-instr-grid">' + renderPresetGroup(contextPresets) + '</div>' +
            '    <div class="qgen-instr-group-label">Avoid</div>' +
            '    <div class="qgen-instr-grid">' + renderPresetGroup(avoidPresets) + '</div>' +
            '  </div>' +
            '</div>' +

            '<div class="qgen-field">' +
            '  <label>Additional AI instructions (optional)</label>' +
            '  <textarea id="qgen-instr-custom" class="qgen-textarea" rows="3" placeholder="e.g. Create practical case studies involving Ghanaian online businesses. Ask students to apply concepts rather than repeat definitions."></textarea>' +
            '</div>' +

            '<div class="qgen-field">' +
            '  <label>Active Instructions</label>' +
            '  <div id="qgen-instr-active" class="qgen-instr-active-box">' +
            '    <div class="qgen-instr-empty">No instructions selected. Questions will follow default generation.</div>' +
            '  </div>' +
            '</div>' +

            '<div id="qgen-instr-warnings" class="qgen-instr-warnings" style="display:none;"></div>' +
            '</div>';
    }

    function renderDestinationSection() {
        return '<div class="qgen-card qgen-section qgen-dest-section">' +
            '<h3><span class="material-symbols-outlined">flag</span> Destination</h3>' +
            '<div class="qgen-field">' +
            '  <label class="qgen-radio-label"><input type="radio" name="qgen-dest" value="new" checked> Create new quiz</label>' +
            '  <label class="qgen-radio-label"><input type="radio" name="qgen-dest" value="existing"> Add to existing quiz</label>' +
            '</div>' +
            '<div id="qgen-append-options" style="display:none;">' +
            '  <select id="qgen-append-job" class="qgen-select"><option value="">Select previous quiz\u2026</option></select>' +
            '</div>' +
            '</div>';
    }

    function renderGenerateButton() {
        return '<button class="umat-btn-p qgen-generate-btn-main" id="qgen-generate-btn" type="button">' +
            '<span class="material-symbols-outlined">auto_awesome</span> Generate Quiz' +
            '</button>' +
            '<div id="qgen-msg" style="margin-top:8px;font-size:12px;display:none;"></div>';
    }

    // ── Event wiring ──
    function wireFormEvents(cid) {
        document.getElementById('qgen-source').addEventListener('change', function() {
            var isMat = this.value === 'material';
            document.getElementById('qgen-material-field').style.display = isMat ? '' : 'none';
            document.getElementById('qgen-text-field').style.display = isMat ? 'none' : '';
        });

        document.querySelectorAll('.qgen-type-check').forEach(function(chk) {
            chk.addEventListener('change', function() {
                var row = this.closest('.qgen-type-row');
                var countInput = row.querySelector('.qgen-type-count');
                if (this.checked && parseInt(countInput.value) === 0) {
                    countInput.value = 1;
                }
                recalcTotal();
            });
        });

        document.querySelectorAll('.qgen-type-count').forEach(function(inp) {
            inp.addEventListener('input', function() {
                var row = this.closest('.qgen-type-row');
                var chk = row.querySelector('.qgen-type-check');
                if (parseInt(this.value) > 0 && !chk.checked) {
                    chk.checked = true;
                } else if (parseInt(this.value) === 0 && chk.checked) {
                    chk.checked = false;
                }
                recalcTotal();
            });
        });

        document.querySelectorAll('input[name="qgen-bloom-mode"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                document.getElementById('qgen-bloom-single').style.display = this.value === 'single' ? '' : 'none';
                document.getElementById('qgen-bloom-mixed').style.display = this.value === 'mixed' ? '' : 'none';
            });
        });

        document.querySelectorAll('input[name="qgen-diff-mode"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                document.getElementById('qgen-diff-single').style.display = this.value === 'single' ? '' : 'none';
                document.getElementById('qgen-diff-mixed').style.display = this.value === 'mixed' ? '' : 'none';
            });
        });

        document.querySelectorAll('input[name="qgen-delivery"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                var docSection = document.getElementById('qgen-docsettings-section');
                var destSection = document.querySelector('.qgen-dest-section');
                var showDoc = this.value === 'printed' || this.value === 'both';
                var showDest = this.value === 'online' || this.value === 'both';
                var showOnline = this.value === 'online' || this.value === 'both';
                if (docSection) docSection.style.display = showDoc ? '' : 'none';
                if (destSection) destSection.style.display = showDest ? '' : 'none';
                document.querySelectorAll('.qgen-online-only').forEach(function(el) {
                    el.style.display = showOnline ? '' : 'none';
                });
            });
        });

        var docToggle = document.getElementById('qgen-docsettings-toggle');
        if (docToggle) {
            docToggle.addEventListener('click', function() {
                var section = document.getElementById('qgen-docsettings-section');
                var body = section.querySelector('.qgen-section-body');
                var chevron = this.querySelector('.qgen-chevron');
                if (body.style.display === 'none') {
                    body.style.display = '';
                    section.classList.remove('qgen-section-collapsed');
                    chevron.textContent = 'expand_less';
                } else {
                    body.style.display = 'none';
                    section.classList.add('qgen-section-collapsed');
                    chevron.textContent = 'expand_more';
                }
            });
        }

        document.querySelectorAll('input[name="qgen-dest"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                var show = this.value === 'existing';
                document.getElementById('qgen-append-options').style.display = show ? '' : 'none';
                if (show) loadAppendableJobs(cid);
            });
        });

        document.querySelectorAll('.qgen-instr-preset-cb').forEach(function(cb) {
            cb.addEventListener('change', function() { updateInstrSummary(); checkInstrConflicts(); });
        });

        document.querySelectorAll('input[name="qgen-grounding"]').forEach(function(radio) {
            radio.addEventListener('change', function() { updateInstrSummary(); checkInstrConflicts(); });
        });

        document.getElementById('qgen-instr-custom').addEventListener('input', function() {
            updateInstrSummary();
        });

        document.getElementById('qgen-details-toggle').addEventListener('click', function() {
            var section = document.getElementById('qgen-details-section');
            var body = section.querySelector('.qgen-section-body');
            var chevron = this.querySelector('.qgen-chevron');
            if (body.style.display === 'none') {
                body.style.display = '';
                section.classList.remove('qgen-section-collapsed');
                chevron.textContent = 'expand_less';
            } else {
                body.style.display = 'none';
                section.classList.add('qgen-section-collapsed');
                chevron.textContent = 'expand_more';
            }
        });

        document.querySelectorAll('.qgen-online-only .qgen-section-toggle').forEach(function(toggle) {
            toggle.addEventListener('click', function() {
                var section = this.closest('.qgen-section');
                var body = section.querySelector('.qgen-section-body');
                var chevron = this.querySelector('.qgen-chevron');
                if (body.style.display === 'none') {
                    body.style.display = '';
                    section.classList.remove('qgen-section-collapsed');
                    chevron.textContent = 'expand_less';
                } else {
                    body.style.display = 'none';
                    section.classList.add('qgen-section-collapsed');
                    chevron.textContent = 'expand_more';
                }
            });
        });

        var groupmodeEl = document.getElementById('qgen-groupmode');
        if (groupmodeEl) {
            groupmodeEl.addEventListener('change', function() {
                var val = parseInt(this.value);
                var grpWrap = document.getElementById('qgen-grouping-wrap');
                var grpListWrap = document.getElementById('qgen-groups-wrap');
                if (grpWrap) grpWrap.style.display = val > 0 ? '' : 'none';
                if (grpListWrap) grpListWrap.style.display = val > 0 ? '' : 'none';
            });
        }

        document.getElementById('qgen-generate-btn').addEventListener('click', function() { generate(cid); });

        updateInstrSummary();
        loadCourseQuizData(cid);
    }

    function recalcTotal() {
        var total = 0;
        document.querySelectorAll('.qgen-type-count').forEach(function(inp) {
            total += parseInt(inp.value) || 0;
        });
        var el = document.getElementById('qgen-total-count');
        if (el) el.textContent = total;
    }

    function getSelectedPresets() {
        var selected = [];
        document.querySelectorAll('.qgen-instr-preset-cb:checked').forEach(function(cb) {
            selected.push(cb.value);
        });
        return selected;
    }

    function getGroundingMode() {
        var el = document.querySelector('input[name="qgen-grounding"]:checked');
        return el ? el.value : 'applied';
    }

    function updateInstrSummary() {
        var box = document.getElementById('qgen-instr-active');
        if (!box) return;
        var presets = getSelectedPresets();
        var custom = document.getElementById('qgen-instr-custom').value.trim();
        var grounding = getGroundingMode();

        if (presets.length === 0 && !custom) {
            box.innerHTML = '<div class="qgen-instr-empty">No instructions selected. Questions will follow default generation.</div>';
            return;
        }

        var html = '';
        var groundLabel = GROUNDING_MODES.find(function(g) { return g.key === grounding; });
        html += '<div class="qgen-instr-tag qgen-instr-tag-grounding"><span class="material-symbols-outlined">school</span> ' + esc(groundLabel ? groundLabel.label : grounding) + '</div>';

        presets.forEach(function(key) {
            var preset = INSTR_PRESETS.find(function(p) { return p.key === key; });
            if (preset) {
                html += '<div class="qgen-instr-tag"><span class="material-symbols-outlined">check</span> ' + esc(preset.label) + '</div>';
            }
        });

        if (custom) {
            html += '<div class="qgen-instr-tag qgen-instr-tag-custom"><span class="material-symbols-outlined">edit</span> Custom instructions active</div>';
        }

        box.innerHTML = html;
    }

    function checkInstrConflicts() {
        var warningsEl = document.getElementById('qgen-instr-warnings');
        if (!warningsEl) return;
        var warnings = [];
        var presets = getSelectedPresets();
        var grounding = getGroundingMode();

        if (grounding === 'strict' && presets.indexOf('case_study') !== -1) {
            warnings.push({ text: 'Strict grounding with case studies may limit scenario construction. Consider "Apply concepts" mode for richer case studies.', severity: 'warning' });
        }
        if (grounding === 'strict' && presets.indexOf('real_world_examples') !== -1) {
            warnings.push({ text: 'Strict grounding limits real-world examples to those explicitly in the material. Consider "Apply concepts" or "Enriched" mode.', severity: 'warning' });
        }
        if (grounding === 'strict' && presets.indexOf('ghanaian_examples') !== -1) {
            warnings.push({ text: 'Strict grounding may prevent constructing Ghanaian scenarios. Consider "Apply concepts" mode.', severity: 'warning' });
        }

        var bloomMode = document.querySelector('input[name="qgen-bloom-mode"]:checked');
        if (bloomMode && bloomMode.value === 'single') {
            var bloomVal = document.getElementById('qgen-bloom').value;
            if (bloomVal === 'remember' && presets.indexOf('critical_thinking') !== -1) {
                warnings.push({ text: 'Bloom\'s "Remember" level conflicts with "Critical thinking" instruction. Consider using Apply or Analyze.', severity: 'error' });
            }
        }

        var qtypes = [];
        document.querySelectorAll('.qgen-type-check:checked').forEach(function(cb) { qtypes.push(cb.value); });
        if (qtypes.length === 1 && qtypes[0] === 'truefalse' && presets.indexOf('case_study') !== -1) {
            warnings.push({ text: 'True/False questions are poorly suited for case studies. Consider adding Multiple Choice or Short Answer.', severity: 'warning' });
        }

        if (warnings.length > 0) {
            warningsEl.style.display = '';
            warningsEl.innerHTML = warnings.map(function(w) {
                var icon = w.severity === 'error' ? 'error' : 'warning';
                var cls = w.severity === 'error' ? 'qgen-warn-error' : 'qgen-warn-warning';
                return '<div class="qgen-warn-item ' + cls + '">' +
                    '<span class="material-symbols-outlined">' + icon + '</span> ' + esc(w.text) + '</div>';
            }).join('');
        } else {
            warningsEl.style.display = 'none';
            warningsEl.innerHTML = '';
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  RESIZABLE SPLIT LAYOUT
    // ═══════════════════════════════════════════════════════════

    function applyConfigWidth() {
        var config = document.getElementById('qgen-config-panel');
        var divider = document.getElementById('qgen-divider');
        if (!config || !divider) return;
        config.style.width = _layout.configWidth + 'px';
        config.style.flex = 'none';
        divider.setAttribute('aria-valuenow', Math.round(_layout.configWidth));
    }

    function initResize(cid) {
        var divider = document.getElementById('qgen-divider');
        if (!divider) return;

        // Pointer events (mouse + touch).
        divider.addEventListener('mousedown', function(e) {
            e.preventDefault();
            startDrag(e.clientX, cid);
        });
        divider.addEventListener('touchstart', function(e) {
            e.preventDefault();
            startDrag(e.touches[0].clientX, cid);
        }, { passive: false });

        // Keyboard events.
        divider.addEventListener('keydown', function(e) {
            var step = e.shiftKey ? 60 : 20;
            if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                e.preventDefault();
                resizeByStep(-step, cid);
            } else if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                e.preventDefault();
                resizeByStep(step, cid);
            } else if (e.key === 'Home') {
                e.preventDefault();
                _layout.configWidth = LAYOUT_MIN_CONFIG;
                applyConfigWidth();
            } else if (e.key === 'End') {
                e.preventDefault();
                var maxW = Math.floor(window.innerWidth * LAYOUT_MAX_RATIO);
                _layout.configWidth = maxW;
                applyConfigWidth();
            }
        });

        // Responsive handler.
        var resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                var wasMobile = _layout.isMobile;
                _layout.isMobile = window.innerWidth <= LAYOUT_MOBILE_BREAKPOINT;
                if (wasMobile !== _layout.isMobile) {
                    switchLayoutMode(cid);
                } else if (!_layout.isMobile && !_layout.expanded) {
                    clampConfigWidth();
                    applyConfigWidth();
                }
            }, 150);
        });
    }

    function startDrag(clientX, cid) {
        _layout.dragging = true;
        _layout.startX = clientX;
        _layout.startWidth = _layout.configWidth;
        saveScrollPositions();

        var layout = document.getElementById('qgen-layout');
        if (layout) layout.classList.add('qgen-layout-dragging');

        function onMove(e) {
            if (!_layout.dragging) return;
            var cx = e.touches ? e.touches[0].clientX : e.clientX;
            var delta = cx - _layout.startX;
            _layout.configWidth = _layout.startWidth + delta;
            clampConfigWidth();
            applyConfigWidth();
        }

        function onUp() {
            _layout.dragging = false;
            var layout = document.getElementById('qgen-layout');
            if (layout) layout.classList.remove('qgen-layout-dragging');
            restoreScrollPositions();
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onUp);
        }

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('touchend', onUp);
    }

    function resizeByStep(delta, cid) {
        saveScrollPositions();
        _layout.configWidth += delta;
        clampConfigWidth();
        applyConfigWidth();
        restoreScrollPositions();
    }

    function clampConfigWidth() {
        var maxW = Math.floor((window.innerWidth - 60) * LAYOUT_MAX_RATIO);
        if (maxW < LAYOUT_MIN_CONFIG + 200) maxW = LAYOUT_MIN_CONFIG + 200;
        _layout.configWidth = Math.max(LAYOUT_MIN_CONFIG, Math.min(_layout.configWidth, maxW));
    }

    function saveScrollPositions() {
        var cs = document.getElementById('qgen-config-scroll');
        var ps = document.getElementById('qgen-preview-body');
        if (cs) _layout.configScrollTop = cs.scrollTop;
        if (ps) _layout.previewScrollTop = ps.scrollTop;
    }

    function restoreScrollPositions() {
        var cs = document.getElementById('qgen-config-scroll');
        var ps = document.getElementById('qgen-preview-body');
        if (cs) cs.scrollTop = _layout.configScrollTop;
        if (ps) ps.scrollTop = _layout.previewScrollTop;
    }

    // ── Expand / Collapse / Restore ──

    function expandPreview() {
        saveScrollPositions();
        _layout.prevConfigWidth = _layout.configWidth;
        _layout.expanded = true;
        _layout.collapsed = false;

        var layout = document.getElementById('qgen-layout');
        var config = document.getElementById('qgen-config-panel');
        var divider = document.getElementById('qgen-divider');
        if (layout) layout.classList.add('qgen-layout-expanded');
        if (config) config.style.display = 'none';
        if (divider) divider.style.display = 'none';

        updatePreviewControls();
        restoreScrollPositions();
    }

    function restoreLayout() {
        saveScrollPositions();
        _layout.expanded = false;
        _layout.collapsed = false;
        _layout.configWidth = _layout.prevConfigWidth || 360;

        var layout = document.getElementById('qgen-layout');
        var config = document.getElementById('qgen-config-panel');
        var divider = document.getElementById('qgen-divider');
        if (layout) layout.classList.remove('qgen-layout-expanded');
        if (config) { config.style.display = ''; config.style.width = _layout.configWidth + 'px'; }
        if (divider) divider.style.display = '';

        clampConfigWidth();
        applyConfigWidth();
        updatePreviewControls();
        restoreScrollPositions();
    }

    function collapseConfig() {
        saveScrollPositions();
        _layout.collapsed = true;
        _layout.prevConfigWidth = _layout.configWidth;

        var config = document.getElementById('qgen-config-panel');
        var divider = document.getElementById('qgen-divider');
        if (config) config.style.display = 'none';
        if (divider) divider.style.display = 'none';

        updatePreviewControls();
        restoreScrollPositions();
    }

    function showConfig() {
        saveScrollPositions();
        _layout.collapsed = false;
        _layout.configWidth = _layout.prevConfigWidth || 360;

        var config = document.getElementById('qgen-config-panel');
        var divider = document.getElementById('qgen-divider');
        if (config) { config.style.display = ''; config.style.width = _layout.configWidth + 'px'; }
        if (divider) divider.style.display = '';

        clampConfigWidth();
        applyConfigWidth();
        updatePreviewControls();
        restoreScrollPositions();
    }

    function switchLayoutMode(cid) {
        var layout = document.getElementById('qgen-layout');
        if (!layout) return;

        if (_layout.isMobile) {
            layout.classList.add('qgen-layout-mobile');
            layout.classList.remove('qgen-layout-expanded');
            renderMobileTabs(cid);
        } else {
            layout.classList.remove('qgen-layout-mobile');
            removeMobileTabs();
            if (_layout.expanded) {
                expandPreview();
            } else if (_layout.collapsed) {
                collapseConfig();
            } else {
                showConfig();
            }
        }
    }

    function renderMobileTabs(cid) {
        var existing = document.getElementById('qgen-mobile-tabs');
        if (existing) existing.remove();

        var layout = document.getElementById('qgen-layout');
        if (!layout) return;

        var tabsHtml = '<div class="qgen-mobile-tabs" id="qgen-mobile-tabs">' +
            '<button class="qgen-mobile-tab active" data-view="config"><span class="material-symbols-outlined">tune</span> Configuration</button>' +
            '<button class="qgen-mobile-tab" data-view="preview"><span class="material-symbols-outlined">description</span> Question Paper</button>' +
            '<button class="qgen-mobile-tab" data-view="scheme"><span class="material-symbols-outlined">key</span> Marking Scheme</button>' +
            '</div>';
        layout.insertAdjacentHTML('beforebegin', tabsHtml);

        var config = document.getElementById('qgen-config-panel');
        var preview = document.getElementById('qgen-preview-panel');
        var divider = document.getElementById('qgen-divider');

        // Show config, hide preview.
        if (config) config.style.display = '';
        if (config) config.style.width = '';
        if (config) config.style.flex = '';
        if (preview) preview.style.display = 'none';
        if (divider) divider.style.display = 'none';

        document.querySelectorAll('.qgen-mobile-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.qgen-mobile-tab').forEach(function(t) { t.classList.remove('active'); });
                this.classList.add('active');
                var view = this.dataset.view;
                if (view === 'config') {
                    if (config) config.style.display = '';
                    if (preview) preview.style.display = 'none';
                } else {
                    if (config) config.style.display = 'none';
                    if (preview) preview.style.display = '';
                    // Switch the paper/scheme tab.
                    if (view === 'scheme') {
                        var schemeTab = document.querySelector('.qgen-paper-tab[data-view="scheme"]');
                        if (schemeTab) schemeTab.click();
                    } else {
                        var paperTab = document.querySelector('.qgen-paper-tab[data-view="paper"]');
                        if (paperTab) paperTab.click();
                    }
                }
            });
        });
    }

    function removeMobileTabs() {
        var tabs = document.getElementById('qgen-mobile-tabs');
        if (tabs) tabs.remove();
    }

    function updatePreviewControls() {
        var container = document.getElementById('qgen-preview-controls');
        if (!container) return;

        if (_layout.isMobile) {
            container.innerHTML = '';
            return;
        }

        var html = '';
        if (_layout.expanded) {
            html = '<button class="qgen-ctrl-btn" id="qgen-restore-btn" title="Restore split layout">' +
                '<span class="material-symbols-outlined">fullscreen_exit</span> Restore Layout</button>';
        } else if (_layout.collapsed) {
            html = '<button class="qgen-ctrl-btn" id="qgen-show-config-btn" title="Show configuration panel">' +
                '<span class="material-symbols-outlined">open_in_new</span> Show Configuration</button>' +
                '<button class="qgen-ctrl-btn" id="qgen-expand-btn" title="Expand preview to full width">' +
                '<span class="material-symbols-outlined">fullscreen</span> Expand Preview</button>';
        } else {
            html = '<button class="qgen-ctrl-btn" id="qgen-collapse-btn" title="Collapse configuration panel">' +
                '<span class="material-symbols-outlined">chevron_left</span> Collapse</button>' +
                '<button class="qgen-ctrl-btn" id="qgen-expand-btn" title="Expand preview to full width">' +
                '<span class="material-symbols-outlined">fullscreen</span> Expand</button>';
        }
        container.innerHTML = html;

        // Wire events.
        var restoreBtn = document.getElementById('qgen-restore-btn');
        if (restoreBtn) restoreBtn.addEventListener('click', restoreLayout);

        var expandBtn = document.getElementById('qgen-expand-btn');
        if (expandBtn) expandBtn.addEventListener('click', expandPreview);

        var collapseBtn = document.getElementById('qgen-collapse-btn');
        if (collapseBtn) collapseBtn.addEventListener('click', collapseConfig);

        var showCfgBtn = document.getElementById('qgen-show-config-btn');
        if (showCfgBtn) showCfgBtn.addEventListener('click', showConfig);
    }

    // ── Materials loading ──
    function loadMaterials(cid) {
        var list = document.getElementById('qgen-materials-list');
        if (!list) return;

        Ajax.call([{
            methodname: 'local_umat_ai_get_course_materials',
            args: { courseid: cid }
        }])[0].done(function(r) {
            _materials = r.materials || [];
            if (!_materials.length) {
                list.innerHTML = '<div class="qgen-empty-sm">No course materials found. Use "Custom Text" instead.</div>';
                document.getElementById('qgen-source').value = 'text';
                document.getElementById('qgen-source').dispatchEvent(new Event('change'));
                return;
            }
            renderMaterialList(list, cid);
        }).fail(function() {
            list.innerHTML = '<div class="qgen-empty-sm">Failed to load materials.</div>';
        });
    }

    function renderMaterialList(list, cid) {
        list.innerHTML = _materials.map(function(m) {
            var label = esc(m.filename || m.name || 'Material ' + m.id);
            var icon = getFileIcon(m.mimetype || '');
            var isReady = m.status === 'indexed';
            var isPending = m.status === 'pending';
            var isFailed = m.status === 'not_indexed';
            var checkedAttr = isReady ? '' : ' disabled';
            var statusBadge = '';
            if (isReady) {
                statusBadge = '<span class="qgen-mat-badge qgen-mat-indexed">Ready</span>';
            } else if (isPending) {
                statusBadge = '<span class="qgen-mat-badge qgen-mat-pending">Processing</span>';
            } else {
                statusBadge = '<span class="qgen-mat-badge qgen-mat-error">Not indexed</span>' +
                    '<button type="button" class="qgen-mat-retry" data-fileid="' + m.id + '" data-matid="' + m.material_id + '" data-cid="' + cid + '" title="Retry indexing">' +
                    '<span class="material-symbols-outlined">refresh</span></button>';
            }
            return '<label class="qgen-mat-item' + (isFailed ? ' qgen-mat-failed' : '') + '">' +
                '<input type="checkbox" class="qgen-mat-check" value="' + m.id + '"' + checkedAttr + '>' +
                '<span class="qgen-mat-icon material-symbols-outlined">' + icon + '</span>' +
                '<span class="qgen-mat-name">' + label + '</span>' +
                statusBadge +
                '</label>';
        }).join('');

        list.querySelectorAll('.qgen-mat-retry').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                handleMaterialRetry(this, cid);
            });
        });
    }

    function getFileIcon(mime) {
        if (mime.indexOf('pdf') !== -1) return 'picture_as_pdf';
        if (mime.indexOf('word') !== -1 || mime.indexOf('document') !== -1) return 'description';
        if (mime.indexOf('presentation') !== -1 || mime.indexOf('powerpoint') !== -1) return 'slideshow';
        if (mime.indexOf('spreadsheet') !== -1 || mime.indexOf('excel') !== -1) return 'table_chart';
        if (mime.indexOf('image') !== -1) return 'image';
        if (mime.indexOf('text') !== -1 || mime.indexOf('json') !== -1 || mime.indexOf('xml') !== -1) return 'code';
        return 'draft';
    }

    function handleMaterialRetry(btn, cid) {
        var fileId = parseInt(btn.dataset.fileid);
        var matId = parseInt(btn.dataset.matid);
        var row = btn.closest('.qgen-mat-item');
        var badge = row.querySelector('.qgen-mat-badge');
        var name = row.querySelector('.qgen-mat-name');
        var origText = badge.textContent;
        var origName = name ? name.textContent : '';

        badge.textContent = 'Indexing\u2026';
        badge.className = 'qgen-mat-badge qgen-mat-pending';
        btn.style.display = 'none';

        Ajax.call([{
            methodname: 'local_umat_ai_reindex_material',
            args: { courseid: cid, material_id: matId }
        }])[0].done(function(r) {
            if (r.success) {
                badge.textContent = 'Ready';
                badge.className = 'qgen-mat-badge qgen-mat-indexed';
                row.classList.remove('qgen-mat-failed');
                var chk = row.querySelector('.qgen-mat-check');
                if (chk) chk.disabled = false;
                showMaterialToast(origName + ' is ready for question generation.', 'success');
            } else {
                badge.textContent = 'Not indexed';
                badge.className = 'qgen-mat-badge qgen-mat-error';
                btn.style.display = '';
                showMaterialToast(origName + ' could not be indexed. Please try again or contact the administrator.', 'error');
            }
        }).fail(function() {
            badge.textContent = 'Not indexed';
            badge.className = 'qgen-mat-badge qgen-mat-error';
            btn.style.display = '';
            showMaterialToast('Reindex request failed. Please try again.', 'error');
        });
    }

    function showMaterialToast(msg, type) {
        var existing = document.getElementById('qgen-material-toast');
        if (existing) existing.remove();
        var toast = document.createElement('div');
        toast.id = 'qgen-material-toast';
        toast.className = 'qgen-material-toast qgen-toast-' + type;
        toast.innerHTML = '<span class="material-symbols-outlined">' + (type === 'success' ? 'check_circle' : 'error') + '</span> ' + esc(msg);
        document.body.appendChild(toast);
        setTimeout(function() { toast.classList.add('qgen-toast-show'); }, 10);
        setTimeout(function() {
            toast.classList.remove('qgen-toast-show');
            setTimeout(function() { toast.remove(); }, 300);
        }, 5000);
    }

    function loadAppendableJobs(cid) {
        var sel = document.getElementById('qgen-append-job');
        if (!sel) return;
        Ajax.call([{
            methodname: 'local_umat_ai_get_quiz_job_history',
            args: { courseid: cid }
        }])[0].done(function(r) {
            var jobs = r.jobs || [];
            var imported = jobs.filter(function(j) { return j.status === 'imported' && j.quiz_id > 0; });
            if (!imported.length) {
                sel.innerHTML = '<option value="">No imported quizzes to append to</option>';
                return;
            }
            sel.innerHTML = '<option value="">\u2014 Select quiz \u2014</option>' +
                imported.map(function(j) {
                    var date = new Date(j.timecreated * 1000);
                    return '<option value="' + j.job_id + '">' + esc(j.category_name) + ' (' + date.toLocaleDateString() + ')</option>';
                }).join('');
        }).fail(function() {
            sel.innerHTML = '<option value="">Failed to load history</option>';
        });
    }

    // ── Load course quiz config data ──
    function loadCourseQuizData(cid) {
        Ajax.call([{
            methodname: 'local_umat_ai_get_course_quiz_config_data',
            args: { courseid: cid }
        }])[0].done(function(r) {
            var secSel = document.getElementById('qgen-sectionnum');
            if (secSel && r.sections) {
                secSel.innerHTML = r.sections.map(function(s) {
                    return '<option value="' + s.section + '">' + esc(s.name) + (s.visible ? '' : ' (hidden)') + '</option>';
                }).join('');
            }
            var gcSel = document.getElementById('qgen-gradecat');
            if (gcSel && r.grade_categories) {
                gcSel.innerHTML = r.grade_categories.map(function(g) {
                    return '<option value="' + g.id + '">' + esc(g.name) + '</option>';
                }).join('');
            }
            var grpSel = document.getElementById('qgen-groupingid');
            if (grpSel && r.groupings) {
                grpSel.innerHTML = r.groupings.map(function(g) {
                    return '<option value="' + g.id + '">' + esc(g.name) + '</option>';
                }).join('');
            }
            var grpList = document.getElementById('qgen-groups-list');
            if (grpList && r.groups) {
                grpList.innerHTML = r.groups.map(function(g) {
                    return '<label class="qgen-check-label"><input type="checkbox" class="qgen-group-check" value="' + g.id + '"> ' + esc(g.name) + '</label>';
                }).join('');
            }
            _courseGroups = r.groups || [];
        }).fail(function() {});
    }

    // ── Gather form data ──
    function gatherFormData() {
        var sourceType = document.getElementById('qgen-source').value;
        var content = null;
        var materialIds = [];

        if (sourceType === 'material') {
            document.querySelectorAll('.qgen-mat-check:checked').forEach(function(chk) {
                materialIds.push(parseInt(chk.value));
            });
        } else {
            content = document.getElementById('qgen-text').value.trim();
        }

        var qtypes = {};
        document.querySelectorAll('.qgen-type-row').forEach(function(row) {
            var type = row.dataset.type;
            var count = parseInt(row.querySelector('.qgen-type-count').value) || 0;
            if (count > 0) qtypes[type] = count;
        });

        var bloomMode = document.querySelector('input[name="qgen-bloom-mode"]:checked').value;
        var bloom;
        if (bloomMode === 'single') {
            bloom = document.getElementById('qgen-bloom').value;
        } else {
            bloom = {};
            document.querySelectorAll('.qgen-bloom-mix').forEach(function(inp) {
                var v = parseInt(inp.value) || 0;
                if (v > 0) bloom[inp.dataset.level] = v;
            });
            if (Object.keys(bloom).length === 0) bloom = 'understand';
        }

        var diffMode = document.querySelector('input[name="qgen-diff-mode"]:checked').value;
        var difficulty;
        if (diffMode === 'single') {
            difficulty = document.getElementById('qgen-difficulty').value;
        } else {
            difficulty = {};
            document.querySelectorAll('.qgen-diff-mix').forEach(function(inp) {
                var v = parseInt(inp.value) || 0;
                if (v > 0) difficulty[inp.dataset.level] = v;
            });
            if (Object.keys(difficulty).length === 0) difficulty = 'medium';
        }

        var instrCustom = document.getElementById('qgen-instr-custom').value.trim();
        var total = 0;
        Object.values(qtypes).forEach(function(v) { total += v; });

        return {
            sourceType: sourceType,
            content: content,
            materialIds: materialIds,
            questionTypes: qtypes,
            bloomLevel: bloom,
            difficulty: difficulty,
            marksPerQuestion: parseFloat(document.getElementById('qgen-marks').value) || 1,
            categoryName: document.getElementById('qgen-name').value.trim(),
            quizDescription: document.getElementById('qgen-desc').value.trim(),
            shuffleQuestions: document.getElementById('qgen-shuffle-q').checked ? 1 : 0,
            shuffleAnswers: document.getElementById('qgen-shuffle-a').checked ? 1 : 0,
            showFeedback: document.getElementById('qgen-show-fb').checked ? 1 : 0,
            timeLimit: parseInt(document.getElementById('qgen-timelimit').value) || 0,
            maxAttempts: parseInt(document.getElementById('qgen-maxattempts').value) || -1,
            aiInstructions: instrCustom,
            groundingMode: getGroundingMode(),
            instructionPresets: getSelectedPresets(),
            total: total,

            // Schedule
            timeopen: document.getElementById('qgen-timeopen') ? document.getElementById('qgen-timeopen').value || '' : '',
            timeclose: document.getElementById('qgen-timeclose') ? document.getElementById('qgen-timeclose').value || '' : '',

            // Access & Security
            password: document.getElementById('qgen-password') ? document.getElementById('qgen-password').value.trim() : '',
            browserSecurity: parseInt(document.getElementById('qgen-browser-security') ? document.getElementById('qgen-browser-security').value : 0) || 0,
            groupmode: parseInt(document.getElementById('qgen-groupmode') ? document.getElementById('qgen-groupmode').value : 0) || 0,
            groupingid: parseInt(document.getElementById('qgen-groupingid') ? document.getElementById('qgen-groupingid').value : 0) || 0,
            groupids: (function(){ var ids = []; document.querySelectorAll('.qgen-group-check:checked').forEach(function(c){ ids.push(parseInt(c.value)); }); return ids; })(),

            // Placement
            sectionnum: parseInt(document.getElementById('qgen-sectionnum') ? document.getElementById('qgen-sectionnum').value : 0) || 0,
            gradecat: parseInt(document.getElementById('qgen-gradecat') ? document.getElementById('qgen-gradecat').value : 0) || 0,

            // Advanced
            preferredBehaviour: document.getElementById('qgen-behaviour') ? document.getElementById('qgen-behaviour').value : 'deferredfeedback',
            gradeMethod: parseInt(document.getElementById('qgen-grademethod') ? document.getElementById('qgen-grademethod').value : 1) || 1,
            navMethod: document.getElementById('qgen-navmethod') ? document.getElementById('qgen-navmethod').value : 'free',
            questionsPerPage: parseInt(document.getElementById('qgen-ppp') ? document.getElementById('qgen-ppp').value : 0) || 0,
            reviewAttempt: document.getElementById('qgen-review-attempt') ? (document.getElementById('qgen-review-attempt').checked ? 1 : 0) : 1,
            reviewCorrectness: document.getElementById('qgen-review-correctness') ? (document.getElementById('qgen-review-correctness').checked ? 1 : 0) : 1,
            reviewMarks: document.getElementById('qgen-review-marks') ? (document.getElementById('qgen-review-marks').checked ? 1 : 0) : 1,
            reviewResponses: document.getElementById('qgen-review-responses') ? (document.getElementById('qgen-review-responses').checked ? 1 : 0) : 1,
            reviewFeedback: document.getElementById('qgen-review-feedback') ? (document.getElementById('qgen-review-feedback').checked ? 1 : 0) : 1,
            reviewOverall: document.getElementById('qgen-review-overall') ? (document.getElementById('qgen-review-overall').checked ? 1 : 0) : 1,
        };
    }

    function validateForm(data) {
        if (data.sourceType === 'material' && data.materialIds.length === 0) {
            return 'Please select at least one course material.';
        }
        if (data.sourceType === 'text' && (!data.content || data.content.length < 20)) {
            return 'Please paste at least 20 characters of content.';
        }
        if (data.total <= 0) {
            return 'Please set a count of at least 1 for one question type.';
        }
        if (data.total > 50) {
            return 'Maximum 50 questions per generation.';
        }
        return null;
    }

    // ═══════════════════════════════════════════════════════════
    //  GENERATE
    // ═══════════════════════════════════════════════════════════

    function generate(cid) {
        var data = gatherFormData();
        var err = validateForm(data);
        if (err) { showMsg(err, 'var(--u-ter)'); return; }

        var btn = document.getElementById('qgen-generate-btn');
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined" style="animation:spin 1s linear infinite;">refresh</span> Generating\u2026';
        showMsg('Generating ' + data.total + ' questions\u2026 this may take 10\u201360s', '#d97706');

        Ajax.call([{
            methodname: 'local_umat_ai_generate_quiz_draft',
            args: {
                courseid: cid,
                source_type: data.sourceType,
                content: data.content,
                material_ids: JSON.stringify(data.materialIds),
                bloom_level: typeof data.bloomLevel === 'object' ? JSON.stringify(data.bloomLevel) : data.bloomLevel,
                question_types: JSON.stringify(data.questionTypes),
                difficulty: typeof data.difficulty === 'object' ? JSON.stringify(data.difficulty) : data.difficulty,
                marks_per_question: data.marksPerQuestion,
                category_name: data.categoryName,
                ai_instructions: data.aiInstructions,
                grounding_mode: data.groundingMode,
                instruction_presets: JSON.stringify(data.instructionPresets),
                quiz_description: data.quizDescription,
                shuffle_questions: data.shuffleQuestions,
                shuffle_answers: data.shuffleAnswers,
                show_feedback: data.showFeedback,
                time_limit: data.timeLimit,
                max_attempts: data.maxAttempts,
                time_open: data.timeopen || '',
                time_close: data.timeclose || '',
                password: data.password || '',
                browser_security: data.browserSecurity || 0,
                groupmode: data.groupmode || 0,
                groupingid: data.groupingid || 0,
                group_ids: JSON.stringify(data.groupids || []),
                section_num: data.sectionnum || 0,
                grade_category: data.gradecat || 0,
                preferred_behaviour: data.preferredBehaviour || 'deferredfeedback',
                grade_method: data.gradeMethod || 1,
                nav_method: data.navMethod || 'free',
                questions_per_page: data.questionsPerPage || 0,
                review_attempt: data.reviewAttempt,
                review_correctness: data.reviewCorrectness,
                review_marks: data.reviewMarks,
                review_responses: data.reviewResponses,
                review_feedback: data.reviewFeedback,
                review_overall: data.reviewOverall
            }
        }])[0].done(function(result) {
            if (result.status === 'completed' && result.questions) {
                var qs = typeof result.questions === 'string' ? JSON.parse(result.questions) : result.questions;
                if (!qs || !qs.length) { showMsg('No questions generated.', 'var(--u-ter)'); resetBtn(btn); return; }
                _lastJobId = result.job_id;
                btn.innerHTML = '<span class="material-symbols-outlined">refresh</span> Regenerate';
                btn.disabled = false;

                var complianceMsg = '';
                if (result.compliance) {
                    var c = result.compliance;
                    complianceMsg = ' | Compliance: ' + c.compliant_count + '/' + c.total_questions + ' questions follow instructions';
                }
                showMsg('Questions generated! Review the paper below, then approve when ready.' + complianceMsg, 'var(--u-sec)');
                renderReview(qs, result.job_id, cid);
            } else if (result.status === 'failed') {
                resetBtn(btn);
                showMsg('Generation failed: ' + (result.failure_reason || 'Unknown error'), 'var(--u-ter)');
                renderError(result.failure_reason);
            } else {
                showMsg('Status: ' + result.status + '\u2026', '#d97706');
                pollStatus(result.job_id, cid);
            }
        }).fail(function(e) {
            resetBtn(btn);
            showMsg(e.message || 'Failed to create job.', 'var(--u-ter)');
        });
    }

    function resetBtn(btn) {
        btn.disabled = false;
        btn.innerHTML = '<span class="material-symbols-outlined">auto_awesome</span> Generate Quiz';
    }

    function pollStatus(jobId, cid) {
        Ajax.call([{
            methodname: 'local_umat_ai_get_quiz_job_status',
            args: { jobid: jobId }
        }])[0].done(function(result) {
            if (result.status === 'completed') {
                var qs = result.questions || [];
                _lastJobId = jobId;
                var btn = document.getElementById('qgen-generate-btn');
                btn.innerHTML = '<span class="material-symbols-outlined">refresh</span> Regenerate';
                btn.disabled = false;
                showMsg('Questions generated! Review the paper below, then approve when ready.', 'var(--u-sec)');
                renderReview(qs, jobId, cid);
            } else if (result.status === 'failed') {
                resetBtn(document.getElementById('qgen-generate-btn'));
                showMsg('Generation failed: ' + (result.failure_reason || 'Unknown error'), 'var(--u-ter)');
                renderError(result.failure_reason);
            } else {
                _pollTimer = setTimeout(function() { pollStatus(jobId, cid); }, 3000);
            }
        }).fail(function() {
            _pollTimer = setTimeout(function() { pollStatus(jobId, cid); }, 5000);
        });
    }

    // ═══════════════════════════════════════════════════════════
    //  REVIEW UI — Academic Paper Format
    // ═══════════════════════════════════════════════════════════

    function renderReview(questions, jobId, cid) {
        var body = document.getElementById('qgen-preview-body');
        if (!body || !questions || !questions.length) {
            body.innerHTML = '<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>No questions were generated.</p></div>';
            return;
        }

        _questions = questions.slice();
        _lastJobId = jobId;
        _reviewViewMode = 'paper';

        var html = renderReviewToolbar() + renderReviewTabs() +
            '<div id="qgen-review-content">' + renderQuestionPaper() + '</div>' +
            renderReviewActions() +
            '<div id="qgen-finalize-msg" style="margin-top:8px;font-size:12px;display:none;"></div>';

        body.innerHTML = html;
        wireReviewEvents(cid);
        updatePreviewControls();
    }

    function renderReviewToolbar() {
        var totalMarks = 0;
        var typeCounts = {};
        _questions.forEach(function(q) {
            totalMarks += (q.marks || 1);
            typeCounts[q.type] = (typeCounts[q.type] || 0) + 1;
        });

        var badges = '<span class="qgen-review-badge qgen-badge-total">' + _questions.length + ' Questions</span>' +
            '<span class="qgen-review-badge qgen-badge-marks">' + totalMarks + ' Total Marks</span>';

        Object.keys(typeCounts).forEach(function(t) {
            badges += '<span class="qgen-review-badge qgen-badge-' + t + '">' + (QTYPE_LABELS[t] || t) + ': ' + typeCounts[t] + '</span>';
        });

        return '<div class="qgen-review-toolbar">' +
            '<div class="qgen-review-stats">' + badges + '</div>' +
            '</div>';
    }

    function renderReviewTabs() {
        return '<div class="qgen-paper-tabs">' +
            '<button class="qgen-paper-tab active" data-view="paper"><span class="material-symbols-outlined">description</span> Question Paper</button>' +
            '<button class="qgen-paper-tab" data-view="scheme"><span class="material-symbols-outlined">key</span> Marking Scheme</button>' +
            '</div>';
    }

    function renderQuestionPaper() {
        var ds = gatherDocSettings();
        var sections = groupQuestionsIntoSections(_questions);
        var totalMarks = 0;
        _questions.forEach(function(q) { totalMarks += (q.marks || 1); });

        var orientClass = ds.orientation === 'landscape' ? ' qgen-paper-landscape' : '';
        var html = '<div class="qgen-paper' + orientClass + '">';

        html += '<div class="qgen-paper-header">';
        if (ds.institution_name) {
            html += '<div class="qgen-paper-institution">' + esc(ds.institution_name) + '</div>';
        }
        html += '<div class="qgen-paper-title">' + esc(ds.assessment_title) + '</div>';

        var metaParts = [];
        if (ds.course_code || ds.course_title) {
            metaParts.push((ds.course_code ? ds.course_code + ' — ' : '') + ds.course_title);
        }
        if (ds.department) metaParts.push(ds.department);
        if (ds.lecturer_name) metaParts.push('Lecturer: ' + ds.lecturer_name);
        if (metaParts.length) {
            html += '<div class="qgen-paper-meta">' + esc(metaParts.join(' | ')) + '</div>';
        }

        if (ds.examination_date_display) {
            html += '<div class="qgen-paper-date">' + esc(ds.examination_date_display) + '</div>';
        }
        if (ds.duration) {
            html += '<div class="qgen-paper-duration">Duration: ' + ds.duration + ' Minutes</div>';
        }
        html += '<div class="qgen-paper-total">Total Marks: ' + totalMarks + '</div>';

        var stuFields = ds.student_info_fields || {};
        var hasStudentFields = stuFields.studentName || stuFields.studentId || stuFields.class ||
            stuFields.programme || stuFields.level || stuFields.signature;
        if (hasStudentFields) {
            html += '<div class="qgen-paper-fields">';
            if (stuFields.studentName) {
                html += '<div class="qgen-paper-field"><span>Name:</span> _______________________________</div>';
            }
            if (stuFields.studentId) {
                html += '<div class="qgen-paper-field"><span>Index Number:</span> _______________________________</div>';
            }
            if (stuFields.class) {
                html += '<div class="qgen-paper-field"><span>Class:</span> _______________________________</div>';
            }
            if (stuFields.programme) {
                html += '<div class="qgen-paper-field"><span>Programme:</span> _______________________________</div>';
            }
            if (stuFields.level) {
                html += '<div class="qgen-paper-field"><span>Level:</span> _______________________________</div>';
            }
            if (stuFields.signature) {
                html += '<div class="qgen-paper-field"><span>Signature:</span> _______________________________</div>';
            }
            html += '</div>';
        }

        if (ds.candidate_instructions) {
            html += '<div class="qgen-paper-instruction"><strong>Instructions:</strong> ' + esc(ds.candidate_instructions) + '</div>';
        } else {
            html += '<div class="qgen-paper-instruction"><strong>Instructions:</strong> Answer all questions. Read each question carefully before answering.</div>';
        }
        html += '</div>';

        sections.forEach(function(section) {
            var sectionMarks = 0;
            section.questions.forEach(function(q) { sectionMarks += (q.marks || 1); });

            html += '<div class="qgen-paper-section">';
            html += '<div class="qgen-paper-section-header">';
            html += '<span class="qgen-paper-section-title">' + esc(section.title) + '</span>';
            html += '<span class="qgen-paper-section-marks">(' + sectionMarks + ' marks)</span>';
            html += '</div>';

            section.questions.forEach(function(q) {
                var num = _questions.indexOf(q) + 1;
                html += renderPaperQuestion(q, num, ds);
            });

            html += '</div>';
        });

        html += '</div>';
        return html;
    }

    function renderPaperQuestion(q, num, ds) {
        var marks = q.marks || 1;
        var marksLabel = marks + ' mark' + (marks !== 1 ? 's' : '');
        var showMarks = ds && ds.show_marks;
        var answerSpaces = ds ? ds.answer_spaces : 0;

        var html = '<div class="qgen-paper-question" data-qidx="' + (num - 1) + '">';
        html += '<div class="qgen-paper-q-stem">';
        html += '<span class="qgen-paper-q-num">' + num + '.</span>';
        if (showMarks) {
            html += '<span class="qgen-paper-q-marks">[' + marksLabel + ']</span>';
        }
        html += '<span class="qgen-paper-q-text">' + esc(q.question_text) + '</span>';
        html += '</div>';

        if (q.options && q.options.length) {
            html += '<div class="qgen-paper-options">';
            q.options.forEach(function(opt, oi) {
                html += '<div class="qgen-paper-option">' +
                    '<span class="qgen-paper-opt-letter">' + String.fromCharCode(65 + oi) + '.</span> ' +
                    esc(opt) +
                    '</div>';
            });
            html += '</div>';
        }

        if (q.type === 'shortanswer' && answerSpaces > 0) {
            html += '<div class="qgen-paper-answer-spaces">';
            for (var i = 0; i < answerSpaces; i++) {
                html += '<div class="qgen-paper-answer-line"></div>';
            }
            html += '</div>';
        }

        html += '<div class="qgen-paper-q-actions">';
        html += '<button type="button" class="qgen-pa-btn qgen-pa-edit" data-action="edit" data-idx="' + (num - 1) + '" title="Edit question"><span class="material-symbols-outlined">edit</span></button>';
        html += '<button type="button" class="qgen-pa-btn qgen-pa-regen" data-action="regenerate" data-idx="' + (num - 1) + '" title="Regenerate this question"><span class="material-symbols-outlined">auto_awesome</span></button>';
        html += '<button type="button" class="qgen-pa-btn qgen-pa-delete" data-action="delete" data-idx="' + (num - 1) + '" title="Remove question"><span class="material-symbols-outlined">delete</span></button>';
        html += '</div>';

        html += '</div>';
        return html;
    }

    function renderMarkingScheme() {
        var sections = groupQuestionsIntoSections(_questions);
        var html = '<div class="qgen-scheme">';

        html += '<div class="qgen-scheme-header">';
        html += '<div class="qgen-scheme-title">Marking Scheme</div>';
        html += '<div class="qgen-scheme-subtitle">Confidential \u2014 For Examiner Use Only</div>';
        html += '</div>';

        sections.forEach(function(section) {
            var sectionMarks = 0;
            section.questions.forEach(function(q) { sectionMarks += (q.marks || 1); });

            html += '<div class="qgen-scheme-section">';
            html += '<div class="qgen-scheme-section-header">';
            html += '<span>' + esc(section.title) + '</span>';
            html += '<span>(' + sectionMarks + ' marks)</span>';
            html += '</div>';

            section.questions.forEach(function(q) {
                var num = _questions.indexOf(q) + 1;
                html += renderSchemeQuestion(q, num);
            });

            html += '</div>';
        });

        html += '</div>';
        return html;
    }

    function renderSchemeQuestion(q, num) {
        var marks = q.marks || 1;
        var marksLabel = marks + ' mark' + (marks !== 1 ? 's' : '');

        var html = '<div class="qgen-scheme-question">';
        html += '<div class="qgen-scheme-q-header">';
        html += '<span class="qgen-scheme-q-num">' + num + '.</span>';
        html += '<span class="qgen-scheme-q-marks">[' + marksLabel + ']</span>';
        html += '<span class="qgen-scheme-q-type">' + esc(QTYPE_LABELS[q.type] || q.type) + '</span>';
        html += '</div>';
        html += '<div class="qgen-scheme-q-text">' + esc(q.question_text) + '</div>';

        if (q.type === 'multichoice' || q.type === 'truefalse') {
            if (q.options && q.options.length && q.correct_answer_index !== undefined) {
                var correctIdx = q.correct_answer_index;
                html += '<div class="qgen-scheme-answer">';
                html += '<span class="qgen-scheme-answer-label">Correct Answer:</span> ';
                html += '<span class="qgen-scheme-correct">' + String.fromCharCode(65 + correctIdx) + '. ' + esc(q.options[correctIdx] || '') + '</span>';
                html += '</div>';

                html += '<div class="qgen-scheme-options">';
                q.options.forEach(function(opt, oi) {
                    var isCorrect = oi === correctIdx;
                    html += '<div class="qgen-scheme-opt' + (isCorrect ? ' correct' : '') + '">' +
                        '<span class="qgen-scheme-opt-letter">' + String.fromCharCode(65 + oi) + '.</span> ' +
                        esc(opt) +
                        (isCorrect ? ' <span class="qgen-scheme-correct-badge">\u2713</span>' : '') +
                        '</div>';
                });
                html += '</div>';
            }
        } else if (q.correct_text) {
            html += '<div class="qgen-scheme-answer">';
            html += '<span class="qgen-scheme-answer-label">Expected Answer:</span> ';
            html += '<span class="qgen-scheme-correct">' + esc(q.correct_text) + '</span>';
            html += '</div>';
        }

        if (q.feedback_correct) {
            html += '<div class="qgen-scheme-feedback">';
            html += '<span class="qgen-scheme-fb-label">Explanation:</span> ' + esc(q.feedback_correct);
            html += '</div>';
        }
        if (q.feedback_incorrect) {
            html += '<div class="qgen-scheme-feedback qgen-scheme-fb-incorrect">';
            html += '<span class="qgen-scheme-fb-label">Common Errors:</span> ' + esc(q.feedback_incorrect);
            html += '</div>';
        }

        html += '</div>';
        return html;
    }

    function renderReviewActions() {
        var delivery = document.querySelector('input[name="qgen-delivery"]:checked');
        var deliveryVal = delivery ? delivery.value : 'online';
        var showOnline = deliveryVal === 'online' || deliveryVal === 'both';
        var showPrint = deliveryVal === 'printed' || deliveryVal === 'both';

        var html = '<div class="qgen-review-actions">';

        if (showOnline) {
            html += '<button class="umat-btn-p" id="qgen-approve-btn" type="button">' +
                '<span class="material-symbols-outlined">check_circle</span> Approve & Create Quiz' +
                '</button>';
        }
        if (showPrint) {
            html += '<button class="umat-btn-p qgen-btn-print" id="qgen-export-btn" type="button">' +
                '<span class="material-symbols-outlined">download</span> Export Word Document' +
                '</button>';
        }

        html += '<button class="umat-btn-s" id="qgen-back-btn" type="button">' +
            '<span class="material-symbols-outlined">arrow_back</span> Back to Config' +
            '</button>';

        html += '<button class="umat-btn-s qgen-save-btn" id="qgen-save-btn" type="button">' +
            '<span class="material-symbols-outlined">save</span> Save Changes' +
            '</button>';

        html += '</div>';
        return html;
    }

    function wireReviewEvents(cid) {
        // View tabs.
        document.querySelectorAll('.qgen-paper-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.qgen-paper-tab').forEach(function(t) { t.classList.remove('active'); });
                this.classList.add('active');
                _reviewViewMode = this.dataset.view;
                var content = document.getElementById('qgen-review-content');
                if (content) {
                    content.innerHTML = _reviewViewMode === 'paper' ? renderQuestionPaper() : renderMarkingScheme();
                }
            });
        });

        // Question action buttons (delegated).
        var content = document.getElementById('qgen-review-content');
        console.log('[qgen] wireReviewEvents: content element =', content, 'cid =', cid);
        if (content) {
            content.addEventListener('click', function(e) {
                var btn = e.target.closest('.qgen-pa-btn');
                if (!btn) return;
                e.preventDefault();
                e.stopPropagation();
                var action = btn.dataset.action;
                var idx = parseInt(btn.dataset.idx);
                if (isNaN(idx)) return;
                handleQuestionAction(action, idx, cid);
            });
        }

        // Approve button.
        var approveBtn = document.getElementById('qgen-approve-btn');
        if (approveBtn) {
            approveBtn.addEventListener('click', function() { approveQuiz(_lastJobId, cid); });
        }

        // Export button.
        var exportBtn = document.getElementById('qgen-export-btn');
        if (exportBtn) {
            exportBtn.addEventListener('click', function() { exportWordDocument(_lastJobId, cid); });
        }

        // Back button.
        var backBtn = document.getElementById('qgen-back-btn');
        if (backBtn) {
            backBtn.addEventListener('click', function() {
                var body = document.getElementById('qgen-preview-body');
                if (body) {
                    body.innerHTML = '<div class="umat-empty"><span class="material-symbols-outlined">quiz</span><p>Configure and generate to see questions here.</p></div>';
                }
                showMsg('Returned to configuration.', 'var(--u-ol)');
            });
        }

        // Save button.
        var saveBtn = document.getElementById('qgen-save-btn');
        if (saveBtn) {
            saveBtn.addEventListener('click', function() { saveQuestions(_lastJobId, cid); });
        }

        wireDocSettingsLiveUpdate();
    }

    function refreshPreview() {
        var content = document.getElementById('qgen-review-content');
        if (content && _questions && _questions.length) {
            content.innerHTML = _reviewViewMode === 'paper' ? renderQuestionPaper() : renderMarkingScheme();
        }
    }

    function wireDocSettingsLiveUpdate() {
        var ids = [
            'qgen-doc-title', 'qgen-doc-institution', 'qgen-doc-coursetitle',
            'qgen-doc-coursecode', 'qgen-doc-dept', 'qgen-doc-lecturer',
            'qgen-doc-date', 'qgen-doc-duration', 'qgen-doc-totalmarks',
            'qgen-doc-instructions', 'qgen-doc-answerspaces'
        ];
        ids.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', refreshPreview);
                el.addEventListener('change', refreshPreview);
            }
        });

        document.querySelectorAll('input[name="qgen-doc-orient"]').forEach(function(r) {
            r.addEventListener('change', refreshPreview);
        });

        var pagenum = document.getElementById('qgen-doc-pagenum');
        if (pagenum) pagenum.addEventListener('change', refreshPreview);

        var marks = document.getElementById('qgen-doc-marks');
        if (marks) marks.addEventListener('change', refreshPreview);

        document.querySelectorAll('.qgen-stu-check').forEach(function(chk) {
            chk.addEventListener('change', refreshPreview);
        });
    }

    // ═══════════════════════════════════════════════════════════
    //  QUESTION ACTIONS
    // ═══════════════════════════════════════════════════════════

    function groupQuestionsIntoSections(questions) {
        var sections = [];
        var mcq = [];
        var tf = [];
        var short = [];

        questions.forEach(function(q) {
            if (q.type === 'multichoice') mcq.push(q);
            else if (q.type === 'truefalse') tf.push(q);
            else short.push(q);
        });

        if (mcq.length || tf.length) {
            var combined = mcq.concat(tf);
            var labels = [];
            if (mcq.length) labels.push('Multiple Choice');
            if (tf.length) labels.push('True/False');
            sections.push({
                title: 'Section A \u2014 ' + labels.join(' and '),
                questions: combined
            });
        }

        if (short.length) {
            sections.push({
                title: 'Section B \u2014 Short Answer',
                questions: short
            });
        }

        return sections;
    }

    function handleQuestionAction(action, idx, cid) {
        if (idx < 0 || idx >= _questions.length) return;

        try {
            switch (action) {
                case 'edit':
                    showEditModal(idx, cid);
                    break;
                case 'regenerate':
                    showRegenerateDialog(idx, cid);
                    break;
                case 'delete':
                    deleteQuestion(idx, cid);
                    break;
            }
        } catch (e) {
            console.error('[qgen] Action error (' + action + ', idx=' + idx + '):', e);
            showMsg('Action failed: ' + (e.message || 'See console for details'), 'var(--u-ter)');
        }
    }

    function deleteQuestion(idx, cid) {
        if (_questions.length <= 1) {
            showMsg('Cannot delete the last question.', 'var(--u-ter)');
            return;
        }
        var q = _questions[idx];
        var label = 'Question ' + (idx + 1);
        if (q && q.question_text) {
            label += ': ' + q.question_text.substring(0, 60) + (q.question_text.length > 60 ? '...' : '');
        }
        if (!window.confirm('Delete ' + label + '?\n\nThis action cannot be undone.')) {
            return;
        }
        _questions.splice(idx, 1);
        refreshReviewView(cid);
        showMsg('Question removed.', 'var(--u-ol)');
    }

    function showEditModal(idx, cid) {
        console.log('[qgen] showEditModal called: idx=', idx, 'cid=', cid, '_questions.length=', _questions.length);
        var q = _questions[idx];
        if (!q) { console.error('[qgen] showEditModal: _questions[' + idx + '] is undefined'); return; }

        var isMCQ = q.type === 'multichoice' || q.type === 'truefalse';

        var optionsHtml = '';
        if (isMCQ && q.options) {
            optionsHtml = '<div class="qgen-edit-options">';
            optionsHtml += '<label class="qgen-edit-label">Options (mark the correct one)</label>';
            q.options.forEach(function(opt, oi) {
                var isCorrect = oi === q.correct_answer_index;
                optionsHtml += '<div class="qgen-edit-opt-row">' +
                    '<input type="radio" name="qgen-edit-correct" value="' + oi + '"' + (isCorrect ? ' checked' : '') + ' class="qgen-edit-correct-radio">' +
                    '<input type="text" class="qgen-edit-opt-input" data-oi="' + oi + '" value="' + esc(opt) + '" placeholder="Option ' + String.fromCharCode(65 + oi) + '">' +
                    '</div>';
            });
            optionsHtml += '</div>';
        } else {
            optionsHtml = '<div class="qgen-edit-field">' +
                '<label class="qgen-edit-label">Expected Answer</label>' +
                '<input type="text" class="qgen-edit-input" id="qgen-edit-correct-text" value="' + esc(q.correct_text || '') + '" placeholder="The expected answer">' +
                '</div>';
        }

        var modalHtml = '<div class="qgen-modal-overlay" id="qgen-edit-overlay">' +
            '<div class="qgen-modal" id="qgen-edit-modal">' +
            '<div class="qgen-modal-header">' +
            '<h3><span class="material-symbols-outlined">edit</span> Edit Question ' + (idx + 1) + '</h3>' +
            '<button type="button" class="qgen-modal-close" id="qgen-edit-close"><span class="material-symbols-outlined">close</span></button>' +
            '</div>' +
            '<div class="qgen-modal-body">' +
            '<div class="qgen-edit-field">' +
            '<label class="qgen-edit-label">Question Text</label>' +
            '<textarea class="qgen-edit-textarea" id="qgen-edit-text" rows="3">' + esc(q.question_text) + '</textarea>' +
            '</div>' +
            optionsHtml +
            '<div class="qgen-edit-row">' +
            '<div class="qgen-edit-field">' +
            '<label class="qgen-edit-label">Marks</label>' +
            '<input type="number" class="qgen-edit-input" id="qgen-edit-marks" value="' + (q.marks || 1) + '" min="0.5" max="100" step="0.5">' +
            '</div>' +
            '</div>' +
            '<div class="qgen-edit-field">' +
            '<label class="qgen-edit-label">Feedback (correct)</label>' +
            '<textarea class="qgen-edit-textarea" id="qgen-edit-fb-correct" rows="2">' + esc(q.feedback_correct || '') + '</textarea>' +
            '</div>' +
            '<div class="qgen-edit-field">' +
            '<label class="qgen-edit-label">Feedback (incorrect)</label>' +
            '<textarea class="qgen-edit-textarea" id="qgen-edit-fb-incorrect" rows="2">' + esc(q.feedback_incorrect || '') + '</textarea>' +
            '</div>' +
            '</div>' +
            '<div class="qgen-modal-footer">' +
            '<button type="button" class="umat-btn-s" id="qgen-edit-cancel">Cancel</button>' +
            '<button type="button" class="umat-btn-p" id="qgen-edit-save"><span class="material-symbols-outlined">check</span> Save Changes</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        document.body.insertAdjacentHTML('beforeend', modalHtml);

        var overlay = document.getElementById('qgen-edit-overlay');
        if (!overlay) {
            console.error('[qgen] showEditModal: overlay not found after insert. Modal HTML length:', modalHtml.length);
            return;
        }
        console.log('[qgen] showEditModal: overlay created. Parent:', overlay.parentElement.tagName, '#id=' + overlay.parentElement.id);
        var closeBtn = document.getElementById('qgen-edit-close');
        var cancelBtn = document.getElementById('qgen-edit-cancel');
        var saveBtn = document.getElementById('qgen-edit-save');

        function closeModal() {
            if (overlay) overlay.remove();
        }

        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeModal();
        });

        if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            q.question_text = document.getElementById('qgen-edit-text').value.trim();
            q.marks = parseFloat(document.getElementById('qgen-edit-marks').value) || 1;
            q.feedback_correct = document.getElementById('qgen-edit-fb-correct').value.trim();
            q.feedback_incorrect = document.getElementById('qgen-edit-fb-incorrect').value.trim();

            if (isMCQ && q.options) {
                var newOpts = [];
                document.querySelectorAll('.qgen-edit-opt-input').forEach(function(inp) {
                    newOpts.push(inp.value.trim());
                });
                q.options = newOpts;
                var correctRadio = document.querySelector('input[name="qgen-edit-correct"]:checked');
                q.correct_answer_index = correctRadio ? parseInt(correctRadio.value) : 0;
            } else {
                q.correct_text = document.getElementById('qgen-edit-correct-text').value.trim();
            }

            closeModal();
            refreshReviewView(cid);
            showMsg('Question ' + (idx + 1) + ' updated.', 'var(--u-sec)');
        });
        }
    }

    function showRegenerateDialog(idx, cid) {
        console.log('[qgen] showRegenerateDialog called: idx=', idx, 'cid=', cid, '_questions.length=', _questions.length);
        var q = _questions[idx];
        if (!q) { console.error('[qgen] showRegenerateDialog: _questions[' + idx + '] is undefined'); return; }

        var oldQ = JSON.parse(JSON.stringify(q));
        var REGEN_ACTIONS = [
            { key: 'more_practical',       label: 'Make more practical',        icon: 'build' },
            { key: 'case_study',           label: 'Convert to a case study',    icon: 'cases' },
            { key: 'critical_thinking',    label: 'Increase critical thinking', icon: 'psychology' },
            { key: 'ghanaian_context',     label: 'Use a Ghanaian context',     icon: 'flag' },
            { key: 'reduce_recall',        label: 'Reduce direct recall',       icon: 'trending_up' },
            { key: 'simplify',             label: 'Simplify the wording',       icon: 'compress' },
            { key: 'different_scenario',   label: 'Generate different scenario', icon: 'swap_horiz' },
        ];

        var actionChips = REGEN_ACTIONS.map(function(a) {
            return '<button class="qgen-regen-action-chip" data-action="' + a.key + '">' +
                '<span class="material-symbols-outlined">' + a.icon + '</span> ' + esc(a.label) + '</button>';
        }).join('');

        var modalHtml = '<div class="qgen-modal-overlay" id="qgen-regen-overlay">' +
            '<div class="qgen-modal" id="qgen-regen-modal">' +
            '<div class="qgen-modal-header">' +
            '<h3><span class="material-symbols-outlined">auto_awesome</span> Regenerate Question ' + (idx + 1) + '</h3>' +
            '<button type="button" class="qgen-modal-close" id="qgen-regen-close"><span class="material-symbols-outlined">close</span></button>' +
            '</div>' +
            '<div class="qgen-modal-body">' +
            '<div class="qgen-regen-current">' +
            '<div class="qgen-regen-label">Current Question:</div>' +
            '<div class="qgen-regen-text">' + esc(q.question_text) + '</div>' +
            '</div>' +
            '<div class="qgen-field">' +
            '<label>Quick actions</label>' +
            '<div class="qgen-regen-actions">' + actionChips + '</div>' +
            '</div>' +
            '<div class="qgen-field">' +
            '<label>Additional regeneration instruction (optional)</label>' +
            '<textarea id="qgen-regen-instr" class="qgen-textarea" rows="2" placeholder="e.g. Use a mining industry scenario instead..."></textarea>' +
            '</div>' +
            '<div id="qgen-regen-result" style="display:none;"></div>' +
            '</div>' +
            '<div class="qgen-modal-footer">' +
            '<button type="button" class="umat-btn-s" id="qgen-regen-cancel">Cancel</button>' +
            '<button type="button" class="umat-btn-p" id="qgen-regen-start"><span class="material-symbols-outlined">auto_awesome</span> Regenerate</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        document.body.insertAdjacentHTML('beforeend', modalHtml);

        var overlay = document.getElementById('qgen-regen-overlay');
        if (!overlay) {
            console.error('[qgen] showRegenerateDialog: overlay not found after insert. Modal HTML length:', modalHtml.length);
            return;
        }
        console.log('[qgen] showRegenerateDialog: overlay created. Parent:', overlay.parentElement.tagName, '#id=' + overlay.parentElement.id);
        var closeBtn = document.getElementById('qgen-regen-close');
        var cancelBtn = document.getElementById('qgen-regen-cancel');
        var startBtn = document.getElementById('qgen-regen-regen') || document.getElementById('qgen-regen-start');

        function closeModal() { if (overlay) overlay.remove(); }
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });

        var selectedAction = '';
        overlay.querySelectorAll('.qgen-regen-action-chip').forEach(function(chip) {
            chip.addEventListener('click', function() {
                overlay.querySelectorAll('.qgen-regen-action-chip').forEach(function(c) { c.classList.remove('active'); });
                chip.classList.add('active');
                selectedAction = chip.dataset.action;
                var instrEl = document.getElementById('qgen-regen-instr');
                if (instrEl) {
                    var actionLabels = {};
                    REGEN_ACTIONS.forEach(function(a) { actionLabels[a.key] = a.label.toLowerCase(); });
                    instrEl.value = actionLabels[selectedAction] || '';
                }
            });
        });

        if (startBtn) {
        startBtn.addEventListener('click', function() {
            startBtn.disabled = true;
            startBtn.innerHTML = '<span class="material-symbols-outlined" style="animation:spin 1s linear infinite;">refresh</span> Generating\u2026';

            var regenInstr = document.getElementById('qgen-regen-instr');
            var additionalInstr = regenInstr ? regenInstr.value.trim() : '';

            Ajax.call([{
                methodname: 'local_umat_ai_regenerate_quizgen_question',
                args: {
                    jobid: _lastJobId,
                    question_index: idx,
                    question_json: JSON.stringify(q),
                    regeneration_instruction: additionalInstr
                }
            }])[0].done(function(result) {
                var resultDiv = document.getElementById('qgen-regen-result');
                if (result.status === 'completed' && result.question) {
                    var newQ = typeof result.question === 'string' ? JSON.parse(result.question) : result.question;

                    resultDiv.style.display = 'block';
                    resultDiv.innerHTML = '<div class="qgen-regen-new">' +
                        '<div class="qgen-regen-label">New Question:</div>' +
                        '<div class="qgen-regen-text">' + esc(newQ.question_text) + '</div>' +
                        '</div>' +
                        '<div class="qgen-regen-compare-actions">' +
                        '<button type="button" class="umat-btn-s" id="qgen-regen-keep-old"><span class="material-symbols-outlined">history</span> Keep Original</button>' +
                        '<button type="button" class="umat-btn-p" id="qgen-regen-use-new"><span class="material-symbols-outlined">check</span> Use New</button>' +
                        '</div>';

                    document.getElementById('qgen-regen-keep-old').addEventListener('click', function() {
                        closeModal();
                        showMsg('Kept original question.', 'var(--u-ol)');
                    });

                    document.getElementById('qgen-regen-use-new').addEventListener('click', function() {
                        _questions[idx] = newQ;
                        closeModal();
                        refreshReviewView(cid);
                        showMsg('Question ' + (idx + 1) + ' replaced with new version.', 'var(--u-sec)');
                    });
                } else {
                    resultDiv.style.display = 'block';
                    resultDiv.innerHTML = '<div class="qgen-regen-error">Failed to regenerate: ' + esc(result.failure_reason || 'Unknown error') + '</div>';
                    startBtn.disabled = false;
                    startBtn.innerHTML = '<span class="material-symbols-outlined">auto_awesome</span> Try Again';
                }
            }).fail(function(e) {
                var resultDiv = document.getElementById('qgen-regen-result');
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '<div class="qgen-regen-error">Failed: ' + esc(e.message || 'Error') + '</div>';
                startBtn.disabled = false;
                startBtn.innerHTML = '<span class="material-symbols-outlined">auto_awesome</span> Try Again';
            });
        });
        }
    }

    function refreshReviewView(cid) {
        var content = document.getElementById('qgen-review-content');
        if (!content) return;
        content.innerHTML = _reviewViewMode === 'paper' ? renderQuestionPaper() : renderMarkingScheme();

        // Re-render toolbar with updated counts.
        var toolbarEl = document.querySelector('.qgen-review-toolbar');
        if (toolbarEl) {
            var temp = document.createElement('div');
            temp.innerHTML = renderReviewToolbar();
            toolbarEl.parentNode.replaceChild(temp.firstChild, toolbarEl);
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  SAVE & APPROVE
    // ═══════════════════════════════════════════════════════════

    function saveQuestions(jobId, cid) {
        if (!_questions.length || !jobId) return;

        var btn = document.getElementById('qgen-save-btn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-outlined" style="animation:spin 1s linear infinite;">refresh</span> Saving\u2026';
        }

        Ajax.call([{
            methodname: 'local_umat_ai_save_quizgen_questions',
            args: {
                jobid: jobId,
                questions_json: JSON.stringify(_questions)
            }
        }])[0].done(function(result) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined">save</span> Save Changes';
            }
            showMsg('Changes saved!', 'var(--u-sec)');
        }).fail(function(e) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined">save</span> Save Changes';
            }
            showMsg('Save failed: ' + (e.message || 'Error'), 'var(--u-ter)');
        });
    }

    function approveQuiz(jobId, cid) {
        // Validate: at least 1 question.
        if (!_questions.length) {
            showMsg('No questions to approve.', 'var(--u-ter)');
            return;
        }

        var dest = document.querySelector('input[name="qgen-dest"]:checked');
        var categoryChoice = 'new';
        var existingJobId = 0;

        if (dest && dest.value === 'existing') {
            var sel = document.getElementById('qgen-append-job');
            var val = parseInt(sel.value);
            if (val > 0) {
                categoryChoice = 'existing';
                existingJobId = val;
            }
        }

        var btn = document.getElementById('qgen-approve-btn');
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined" style="animation:spin 1s linear infinite;">refresh</span> Creating Quiz\u2026';

        var msgEl = document.getElementById('qgen-finalize-msg');
        msgEl.style.display = 'block';
        msgEl.textContent = 'Importing questions and creating quiz activity\u2026';
        msgEl.style.color = 'var(--u-ol)';

        // First save any unsaved edits.
        Ajax.call([{
            methodname: 'local_umat_ai_save_quizgen_questions',
            args: {
                jobid: jobId,
                questions_json: JSON.stringify(_questions)
            }
        }])[0].done(function() {
            // Then finalize.
            Ajax.call([{
                methodname: 'local_umat_ai_finalize_quiz',
                args: {
                    jobid: jobId,
                    category_choice: categoryChoice,
                    existing_job_id: existingJobId
                }
            }])[0].done(function(result) {
                if (result.status === 'imported') {
                    msgEl.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">check_circle</span> Quiz created! ' +
                        result.question_count + ' questions imported.' +
                        '<br><a href="' + window.location.origin + '/mod/quiz/view.php?id=' + result.quiz_cmid + '" target="_blank" style="font-weight:700;color:var(--u-p);">Open Quiz \u2192</a>';
                    msgEl.style.color = 'var(--u-sec)';
                    btn.innerHTML = '<span class="material-symbols-outlined">check_circle</span> Quiz Created!';
                } else {
                    msgEl.textContent = 'Unexpected status: ' + result.status;
                    msgEl.style.color = 'var(--u-ter)';
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-outlined">check_circle</span> Approve & Create Quiz';
                }
            }).fail(function(e) {
                msgEl.textContent = 'Import failed: ' + (e.message || 'Error');
                msgEl.style.color = 'var(--u-ter)';
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined">check_circle</span> Approve & Create Quiz';
            });
        }).fail(function(e) {
            // If save fails, still try to finalize.
            Ajax.call([{
                methodname: 'local_umat_ai_finalize_quiz',
                args: {
                    jobid: jobId,
                    category_choice: categoryChoice,
                    existing_job_id: existingJobId
                }
            }])[0].done(function(result) {
                if (result.status === 'imported') {
                    msgEl.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">check_circle</span> Quiz created! ' +
                        result.question_count + ' questions imported.' +
                        '<br><a href="' + window.location.origin + '/mod/quiz/view.php?id=' + result.quiz_cmid + '" target="_blank" style="font-weight:700;color:var(--u-p);">Open Quiz \u2192</a>';
                    msgEl.style.color = 'var(--u-sec)';
                    btn.innerHTML = '<span class="material-symbols-outlined">check_circle</span> Quiz Created!';
                } else {
                    msgEl.textContent = 'Unexpected status: ' + result.status;
                    msgEl.style.color = 'var(--u-ter)';
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-outlined">check_circle</span> Approve & Create Quiz';
                }
            }).fail(function(e2) {
                msgEl.textContent = 'Failed: ' + (e2.message || e.message || 'Error');
                msgEl.style.color = 'var(--u-ter)';
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined">check_circle</span> Approve & Create Quiz';
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    //  WORD EXPORT
    // ═══════════════════════════════════════════════════════════

    function gatherDocSettings() {
        var getText = function(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; };
        var getNum = function(id, def) { return parseInt(document.getElementById(id).value) || def; };
        var getChecked = function(id) { var el = document.getElementById(id); return el ? el.checked : false; };

        var orient = 'portrait';
        document.querySelectorAll('input[name="qgen-doc-orient"]').forEach(function(r) {
            if (r.checked) orient = r.value;
        });

        var versions = [];
        document.querySelectorAll('.qgen-version-check:checked').forEach(function(chk) {
            versions.push(chk.value);
        });
        if (!versions.length) versions = ['A'];

        var studentFields = {};
        document.querySelectorAll('.qgen-stu-check').forEach(function(chk) {
            studentFields[chk.value] = chk.checked;
        });

        var dateStr = getText('qgen-doc-date');
        var formattedDate = '';
        if (dateStr) {
            try {
                var d = new Date(dateStr + 'T00:00:00');
                formattedDate = d.toLocaleDateString('en-GB', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            } catch (e) {
                formattedDate = dateStr;
            }
        }

        return {
            assessment_title:      getText('qgen-doc-title') || 'Assessment',
            institution_name:      getText('qgen-doc-institution') || 'University of Mines and Technology',
            course_title:          getText('qgen-doc-coursetitle'),
            course_code:           getText('qgen-doc-coursecode'),
            department:            getText('qgen-doc-dept'),
            lecturer_name:         getText('qgen-doc-lecturer'),
            examination_date:      dateStr,
            examination_date_display: formattedDate,
            duration:              getNum('qgen-doc-duration', 120),
            total_marks:           getNum('qgen-doc-totalmarks', 100),
            candidate_instructions: getText('qgen-doc-instructions'),
            orientation:           orient,
            show_page_numbers:     getChecked('qgen-doc-pagenum'),
            show_marks:            getChecked('qgen-doc-marks'),
            student_info_fields:   studentFields,
            answer_spaces:         parseInt(document.getElementById('qgen-doc-answerspaces').value) || 0,
            versions:              versions,
            marks_per_question:    parseFloat(document.getElementById('qgen-marks').value) || 1
        };
    }

    function getSelectedExportTypes() {
        var types = [];
        document.querySelectorAll('.qgen-export-type-check:checked').forEach(function(chk) {
            types.push(chk.value);
        });
        return types.length ? types : ['question_paper'];
    }

    function exportWordDocument(jobId, cid) {
        if (!_questions || !_questions.length) {
            showMsg('No questions to export.', 'var(--u-ter)');
            return;
        }

        var docSettings = gatherDocSettings();
        var exportTypes = getSelectedExportTypes();
        var versions = docSettings.versions || ['A'];

        var jobs = [];
        versions.forEach(function(ver) {
            exportTypes.forEach(function(exportType) {
                jobs.push({ version: ver, exportType: exportType });
            });
        });

        var btn = document.getElementById('qgen-export-btn');
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined" style="animation:spin 1s linear infinite;">refresh</span> Generating\u2026';

        var msgEl = document.getElementById('qgen-finalize-msg');
        msgEl.style.display = 'block';
        msgEl.textContent = 'Generating ' + jobs.length + ' document(s)\u2026';
        msgEl.style.color = 'var(--u-ol)';

        var completed = 0;
        var total = jobs.length;
        var errors = [];

        jobs.forEach(function(job) {
            console.log('[WordExport] Starting:', job.exportType, job.version);
            Ajax.call([{
                methodname: 'local_umat_ai_export_quiz_word',
                args: {
                    questions_json: JSON.stringify(_questions),
                    export_type: job.exportType,
                    version: job.version,
                    doc_settings: JSON.stringify(docSettings)
                }
            }])[0].done(function(result) {
                console.log('[WordExport] Done:', result.filename);
                completed++;
                downloadBase64Docx(result.docx_base64, result.filename);
                if (completed === total) {
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-outlined">download</span> Export Word Document';
                    var suffix = total > 1 ? ' (' + total + ' files)' : '';
                    msgEl.textContent = '\u2713 Document(s) exported!' + suffix;
                    msgEl.style.color = 'var(--u-sec)';
                }
            }).fail(function(e) {
                completed++;
                errors.push(job.exportType + ' v' + job.version);
                console.error('[WordExport] Failed:', job.exportType, job.version, e);
                var detail = '';
                if (e) {
                    if (e.message) detail = e.message;
                    else if (e.error) detail = typeof e.error === 'string' ? e.error : JSON.stringify(e.error);
                    else if (e.statusText) detail = e.statusText;
                    else if (typeof e === 'string') detail = e;
                    else try { detail = JSON.stringify(e); } catch(ex) { detail = String(e); }
                }
                detail = detail || 'Unknown error';
                if (completed === total) {
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-outlined">download</span> Export Word Document';
                    if (errors.length === total) {
                        msgEl.textContent = 'Export failed: ' + detail;
                        msgEl.style.color = 'var(--u-ter)';
                    } else {
                        msgEl.textContent = '\u2713 ' + (total - errors.length) + ' of ' + total + ' exported. ' + errors.length + ' failed: ' + detail;
                        msgEl.style.color = '#d97706';
                    }
                }
            });
        });
    }

    function downloadBase64Docx(base64Data, filename) {
        var binaryStr = atob(base64Data);
        var len = binaryStr.length;
        var bytes = new Uint8Array(len);
        for (var i = 0; i < len; i++) {
            bytes[i] = binaryStr.charCodeAt(i);
        }
        var blob = new Blob([bytes], { type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function() { URL.revokeObjectURL(url); }, 5000);
    }

    // ═══════════════════════════════════════════════════════════
    //  HISTORY
    // ═══════════════════════════════════════════════════════════

    function loadHistory() {
        var container = document.getElementById('qgen-tab-history');
        container.innerHTML = '<div class="qgen-loading"><span class="material-symbols-outlined" style="animation:spin 1s linear infinite;">refresh</span> Loading history\u2026</div>';

        Ajax.call([{
            methodname: 'local_umat_ai_get_quiz_job_history',
            args: { courseid: _currentCid }
        }])[0].done(function(r) {
            _allJobsData = r.jobs || [];
            renderHistory(container);
        }).fail(function(e) {
            container.innerHTML = '<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>' + esc(e.message || 'Failed to load history') + '</p></div>';
        });
    }

    function renderHistory(container) {
        var jobs = _allJobsData;
        if (!jobs.length) {
            container.innerHTML = '<div class="umat-empty"><span class="material-symbols-outlined">history</span><p>No quiz generation history yet.</p></div>';
            return;
        }

        var html = '<div class="qgen-history-table-wrap"><table class="qgen-history-table">' +
            '<thead><tr><th>Date</th><th>Name</th><th>Status</th><th>Config</th><th>Q\'s</th><th>Actions</th></tr></thead><tbody>';

        jobs.forEach(function(j) {
            var date = new Date(j.timecreated * 1000);
            var dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
            var statusLabel = j.status.charAt(0).toUpperCase() + j.status.slice(1);
            var config = {};
            try { config = JSON.parse(j.config_summary || '{}'); } catch(e) {}
            var qtypes = config.question_types || [];
            if (Array.isArray(qtypes)) {
                qtypes = qtypes.join('/');
            } else if (typeof qtypes === 'object') {
                qtypes = Object.keys(qtypes).join('/');
            }
            var configStr = [config.bloom_level, config.difficulty, qtypes, (config.total_questions || 0) + 'q'].join(' \u00b7 ');

            html += '<tr class="qgen-hrow-' + j.status + '">' +
                '<td class="qgen-hdate">' + esc(dateStr) + '</td>' +
                '<td class="qgen-hname">' + esc(j.category_name) + '</td>' +
                '<td><span class="qgen-status-badge ' + j.status + '">' + statusLabel + '</span></td>' +
                '<td class="qgen-hconfig">' + esc(configStr) + '</td>' +
                '<td>' + j.question_count + '</td>' +
                '<td class="qgen-hactions">' + actionButtons(j) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;

        jobs.forEach(function(j) {
            var viewBtn = document.getElementById('qgen-view-' + j.job_id);
            if (viewBtn) viewBtn.addEventListener('click', function() { viewJobQuestions(j.job_id); });
            var retryBtn = document.getElementById('qgen-retry-' + j.job_id);
            if (retryBtn) retryBtn.addEventListener('click', function() { retryJob(j); });
        });
    }

    function actionButtons(j) {
        var btns = '';
        if (j.status === 'completed' || j.status === 'imported' || j.status === 'importing') {
            btns += '<button class="qgen-act-btn" id="qgen-view-' + j.job_id + '" title="View questions"><span class="material-symbols-outlined">visibility</span></button>';
        }
        if (j.quiz_id > 0) {
            btns += '<a href="' + window.location.origin + '/mod/quiz/view.php?id=' + j.quiz_id + '" target="_blank" class="qgen-act-btn" title="Open quiz"><span class="material-symbols-outlined">open_in_new</span></a>';
        }
        if (j.status === 'failed') {
            btns += '<button class="qgen-act-btn" id="qgen-retry-' + j.job_id + '" title="Retry"><span class="material-symbols-outlined">refresh</span></button>';
        }
        return btns;
    }

    function viewJobQuestions(jobId) {
        document.querySelector('.qgen-tab[data-tab="generate"]').click();
        Ajax.call([{
            methodname: 'local_umat_ai_get_quiz_job_status',
            args: { jobid: jobId }
        }])[0].done(function(result) {
            if (result.questions && result.questions.length) {
                var qs = typeof result.questions === 'string' ? JSON.parse(result.questions) : result.questions;
                renderReview(qs, jobId, _currentCid);
                showMsg('Loaded questions from job #' + jobId, 'var(--u-sec)');
            } else {
                showMsg('Job #' + jobId + ' has no questions data.', 'var(--u-ter)');
            }
        }).fail(function(e) {
            showMsg('Failed to load questions: ' + (e.message || 'Error'), 'var(--u-ter)');
        });
    }

    function retryJob(j) {
        document.querySelector('.qgen-tab[data-tab="generate"]').click();
        showMsg('Retrying generation for ' + j.category_name + '\u2026', 'var(--u-ol)');
        Ajax.call([{
            methodname: 'local_umat_ai_get_quiz_job_status',
            args: { jobid: j.job_id }
        }])[0].done(function(result) {
            var config = {};
            try { config = JSON.parse(j.config_summary || '{}'); } catch(e) {}
            Ajax.call([{
                methodname: 'local_umat_ai_generate_quiz_draft',
                args: {
                    courseid: _currentCid,
                    source_type: 'text',
                    content: result.questions ? JSON.stringify(result.questions).substring(0, 500) : 'Retry for: ' + j.category_name,
                    material_ids: '[]',
                    bloom_level: typeof config.bloom_level === 'string' ? (config.bloom_level || 'understand') : JSON.stringify(config.bloom_level || 'understand'),
                    question_types: JSON.stringify(config.question_types || {multichoice: 5}),
                    difficulty: typeof config.difficulty === 'string' ? (config.difficulty || 'medium') : JSON.stringify(config.difficulty || 'medium'),
                    marks_per_question: 1,
                    category_name: j.category_name + ' (retry)',
                    ai_instructions: '',
                    quiz_description: '',
                    shuffle_questions: 0,
                    shuffle_answers: 1,
                    show_feedback: 1,
                    time_limit: 0,
                    max_attempts: -1
                }
            }])[0].done(function(newResult) {
                if (newResult.status === 'completed' && newResult.questions) {
                    var qs = typeof newResult.questions === 'string' ? JSON.parse(newResult.questions) : newResult.questions;
                    if (qs && qs.length) {
                        showMsg('Done! Review questions below.', 'var(--u-sec)');
                        renderReview(qs, newResult.job_id, _currentCid);
                        return;
                    }
                }
                if (newResult.status === 'failed') {
                    showMsg('Retry failed: ' + (newResult.failure_reason || 'Unknown error'), 'var(--u-ter)');
                } else {
                    showMsg('Generating\u2026', '#d97706');
                    pollStatus(newResult.job_id, _currentCid);
                }
            }).fail(function(e) {
                showMsg('Retry failed: ' + (e.message || 'Error'), 'var(--u-ter)');
            });
        }).fail(function(e) {
            showMsg('Failed to load original job: ' + (e.message || 'Error'), 'var(--u-ter)');
        });
    }

    // ═══════════════════════════════════════════════════════════
    //  UTILITIES
    // ═══════════════════════════════════════════════════════════

    function renderError(reason) {
        var body = document.getElementById('qgen-preview-body');
        if (!body) return;
        body.innerHTML = '<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>' + esc(reason || 'An error occurred') + '</p></div>';
    }

    function showMsg(text, color) {
        var el = document.getElementById('qgen-msg');
        if (!el) return;
        el.style.display = 'block';
        el.textContent = text;
        el.style.color = color || 'var(--u-ol)';
    }

    return { init: init };
});
