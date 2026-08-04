<?php
/**
 * The single, authoritative configuration for the student risk model.
 *
 * Every consumer of risk in this plugin — the live insights API, the hourly
 * aggregation task, the student detail view and any course-level at-risk count
 * — reads its weights, thresholds and vocabulary from this file. To retune the
 * model, edit this file only.
 *
 * Design rules encoded here:
 *
 *  1. Risk is evidence of academic difficulty, not of platform usage. AI
 *     question volume is deliberately NOT a factor; it is reported as context
 *     and interpreted against performance.
 *  2. Missing data must never raise a score. A factor with no underlying data
 *     is dropped from both the numerator and the denominator (see
 *     student_risk_calculator::compute) and lowers confidence instead.
 *  3. Percentages must be mathematically valid. Minimum denominators live here
 *     and are enforced by safe_percentage.
 *
 * @package    local_umat_ai
 * @subpackage analytics
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

return [
    // ── Factor weights ──────────────────────────────────────────────────────
    // max_points is the worst-case contribution of a factor. Because scoring is
    // renormalised over only the factors that have data, these need not sum to
    // 100 — their ratios are what matter.
    'factors' => [
        'quiz_performance' => [
            'max_points' => 30,
            'enabled'    => true,
            'label'      => 'Quiz performance',
            // No graded attempt in the window → factor omitted entirely.
        ],
        'quiz_trend' => [
            'max_points' => 10,
            'enabled'    => true,
            'label'      => 'Quiz trend',
            // Requires at least two graded attempts to be meaningful.
            'min_attempts' => 2,
        ],
        'missed_assessments' => [
            'max_points' => 25,
            'enabled'    => true,
            'label'      => 'Missed assessments',
            // No past-due assessment in the course → factor omitted.
        ],
        'inactivity' => [
            'max_points' => 20,
            'enabled'    => true,
            'label'      => 'Inactivity',
            // Days of inactivity that constitute the worst case.
            'days_for_full_points' => 14,
        ],
        'bbb_attendance' => [
            'max_points' => 15,
            'enabled'    => true,
            'label'      => 'Live class attendance',
            // Omitted unless real BigBlueButton join/leave records exist.
        ],
        'resource_engagement' => [
            'max_points' => 10,
            'enabled'    => true,
            'label'      => 'Resource engagement',
            // Omitted unless the course has published materials.
        ],
        'repeated_misconception' => [
            'max_points' => 10,
            'enabled'    => true,
            'label'      => 'Repeated academic confusion',
            // Counts repeats of the SAME academic topic, not question volume.
            'repeats_for_full_points' => 3,
        ],
    ],

    // ── Risk level thresholds ───────────────────────────────────────────────
    // Evaluated in descending order; score >= threshold wins.
    // The vocabulary is critical / high / medium / low. "moderate" is not used
    // anywhere — the CSS pill classes and the JS both expect "medium".
    'thresholds' => [
        'critical' => 70,
        'high'     => 50,
        'medium'   => 30,
        // < 30 → 'low'
    ],

    // ── Time windows (days) ─────────────────────────────────────────────────
    'time_windows' => [
        'quiz_grades'      => 14,
        'activity'         => 14,
        'academic_queries' => 14,
        'assessment_scope' => null, // null = whole course, no time filter.
    ],

    // ── Confidence ──────────────────────────────────────────────────────────
    // Confidence reports how complete the evidence is, NOT how severe the risk
    // is. It is the share of enabled factors that actually had data.
    'confidence' => [
        'min_factors_required'    => 2,   // Fewer than this → floor confidence.
        'full_confidence_factors' => 5,   // This many with data → 1.0.
        'floor'                   => 0.3,
    ],

    // ── Minimum denominators for reportable percentages ─────────────────────
    'min_denominator' => [
        'trend_previous_period' => 5,  // Prior-period count before a % change.
        'question_sample'       => 5,  // Questions before a failure rate.
        'student_sample'        => 1,  // Populations of 1 are still real.
    ],

    // ── Trend sensitivity ───────────────────────────────────────────────────
    // A change larger than this, in the metric's own unit, is directional.
    'trend' => [
        'quiz_delta'        => 5.0,   // ±5 percentage points
        'login_delta'       => 2.0,   // ±2 logins
        'inactivity_delta'  => 2.0,   // ±2 days
        'attendance_delta'  => 0.10,  // ±10 percentage points, as a fraction
        'risk_delta'        => 5.0,   // ±5 risk points
    ],

    // ── Classification rules ────────────────────────────────────────────────
    // Evaluated in order; the first rule whose conditions ALL hold wins.
    // Using an explicit "all" list (rather than the previous any-key-matches
    // behaviour) makes each classification auditable and prevents a student who
    // is present and failing from being labelled disengaged.
    'categories' => [
        [
            'id'    => 'academically_struggling',
            'label' => 'Academically struggling',
            'reason' => 'Attending and active, but assessment performance is weak.',
            'all' => [
                'quiz_avg_below'      => 50,
                'max_days_inactive'   => 7,
            ],
        ],
        [
            'id'    => 'assessment_risk',
            'label' => 'Assessment risk',
            'reason' => 'Past-due assessments have not been submitted.',
            'all' => [
                'min_missed'          => 1,
            ],
        ],
        [
            'id'    => 'attendance_risk',
            'label' => 'Attendance risk',
            'reason' => 'Missing a large share of live sessions.',
            'all' => [
                'requires_bbb_data'   => true,
                'max_attendance_rate' => 0.50,
            ],
        ],
        [
            'id'    => 'disengaged',
            'label' => 'Disengaged',
            'reason' => 'No recent activity of any kind on the course.',
            'all' => [
                'min_days_inactive'      => 10,
                'max_academic_questions' => 3,
            ],
        ],
        [
            'id'    => 'resource_engagement_risk',
            'label' => 'Resource engagement risk',
            'reason' => 'Course materials are largely unopened.',
            'all' => [
                'max_resource_access_rate' => 0.30,
                'requires_resource_data'   => true,
            ],
        ],
        [
            'id'    => 'monitoring',
            'label' => 'Monitoring',
            'reason' => 'Some risk signals present, none yet decisive.',
            'all' => [
                'min_risk_score'      => 30,
            ],
        ],
        [
            'id'    => 'low_risk',
            'label' => 'Low risk',
            'reason' => 'No risk signals above threshold.',
            'all'   => [],
        ],
    ],

    // ── Recommendation generation (consumed from Phase 2 onwards) ───────────
    'recommendations' => [
        'max_count'             => 5,
        'min_students_affected' => 2,
        'min_topic_struggle'    => 40,
        'min_quiz_failure_rate' => 0.20,
        'min_inactive_days'     => 10,
        'attendance_drop_pct'   => 20,
    ],

    // ── Academic query filtering ────────────────────────────────────────────
    // Patterns are fully anchored (^…$). The previous version anchored only the
    // start, so a genuine question beginning "How do I calculate…" was matched
    // by the "how are you" alternative and silently discarded, while
    // "conduct a quiz for me please" escaped the command filter.
    'query_filter' => [
        'min_question_length' => 8,   // Characters, after normalisation.
        'min_question_words'  => 3,   // Below this it is not a question.

        'greeting_patterns' => '/^(hi|hii+|hey+|hello+|yo|good\s*(morning|afternoon|evening|day|night)'
            . '|thanks?|thank\s*you|thx|ty|cheers|welcome|bye|goodbye|see\s*you|good\s*bye'
            . '|how\s*(are|r)\s*(you|u)|how\s*are\s*you\s*doing|whats?\s*up|sup)'
            . '[\s\W]*$/i',

        'command_patterns' => '/^(please\s+)?((can|could|would|will)\s+(you|u)\s+)?'
            . '(quiz|test|examine|assess)\s*(me|us)?'
            . '|^(please\s+)?((can|could|would|will)\s+(you|u)\s+)?'
            . '(conduct|start|begin|give|generate|create|make|set|prepare|run|do)\s+'
            . '(me\s+|us\s+)?(a|an|some|the)?\s*'
            . '(practice\s+|mock\s+|revision\s+|sample\s+)?(quiz|test|exam|mcq|questions?)'
            . '/i',

        'filler_patterns' => '/^(hmm+|hm+|huh|lol|lmao|haha+|ok|okay|k|kk|yep|yeah|yh|nah|no|yes|y|n'
            . '|eh|mhm|ugh|wow|cool|nice|great|awesome|good|bad|fine|sure|alright|right|wrong'
            . '|true|false|maybe|idk|dunno|nvm|never\s*mind|got\s*it|understood|noted|done'
            . '|test|testing|hello\s*world)'
            . '[\s\W]*$/i',

        // Source types that are never academic learning questions, regardless
        // of their text. Issue reports in particular must never reach the topic
        // analysis — a login problem is not a course topic.
        'non_academic_sources' => [
            'issue_report',
            'login_issue',
            'technical_issue',
            'quiz_generation',
            'system',
        ],

        // Stripped before classification: the client prefixes chat messages
        // that cite a material, e.g. "[Referencing: lecture3.pdf] What is …".
        'strip_prefixes' => [
            '/^\[referencing:\s*[^\]]*\]\s*/i',
            '/^\[context:\s*[^\]]*\]\s*/i',
        ],
    ],
];
