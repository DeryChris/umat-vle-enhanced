<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_umat_ai_ask_question' => [
        'classname'   => '\local_umat_ai\external\ai_query', 'methodname' => 'ask_question',
        'description' => 'Student AI question', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_chat_history' => [
        'classname'   => '\local_umat_ai\external\ai_query', 'methodname' => 'get_chat_history',
        'description' => 'Get chat history for a session', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_session_transcript' => [
        'classname'   => '\local_umat_ai\external\ai_query', 'methodname' => 'get_session_transcript',
        'description' => 'Get recording URL + transcript', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_session_outputs' => [
        'classname'   => '\local_umat_ai\external\get_summary', 'methodname' => 'get_session_outputs',
        'description' => 'Get approved AI outputs', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewsummary',
    ],
    'local_umat_ai_approve_output' => [
        'classname'   => '\local_umat_ai\external\approve_output', 'methodname' => 'approve',
        'description' => 'Approve or reject AI content', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:approveoutput',
    ],
    'local_umat_ai_get_analytics' => [
        'classname'   => '\local_umat_ai\external\get_analytics', 'methodname' => 'get_course_analytics',
        'description' => 'Get course analytics (lecturer)', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_lecturer_ask' => [
        'classname'   => '\local_umat_ai\external\lecturer_ai_query', 'methodname' => 'ask',
        'description' => 'Lecturer AI analytics query', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_get_my_courses' => [
        'classname'   => '\local_umat_ai\external\course_data', 'methodname' => 'get_my_courses',
        'description' => 'Get enrolled/teaching courses', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => '',
    ],
    'local_umat_ai_get_course_materials' => [
        'classname'   => '\local_umat_ai\external\course_data', 'methodname' => 'get_course_materials',
        'description' => 'Get course files for library', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_course_recordings' => [
        'classname'   => '\local_umat_ai\external\course_data', 'methodname' => 'get_course_recordings',
        'description' => 'Get BBB recordings with AI metadata', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_ai_sessions' => [
        'classname'   => '\local_umat_ai\external\course_data', 'methodname' => 'get_ai_sessions',
        'description' => 'Get AI chat sessions for a user', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => '',
    ],
    'local_umat_ai_get_pending_outputs' => [
        'classname'   => '\local_umat_ai\external\course_data', 'methodname' => 'get_pending_outputs',
        'description' => 'Get unapproved AI outputs for lecturer review', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:approveoutput',
    ],
    'local_umat_ai_get_analysis_status' => [
        'classname'   => '\local_umat_ai\external\analysis', 'methodname' => 'get_analysis_status',
        'description' => 'Get analysis status for course materials', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_struggle_insights' => [
        'classname'   => '\local_umat_ai\external\get_struggle_insights', 'methodname' => 'get_struggle_insights',
        'description' => 'Get detailed struggle insights for a course', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_request_analysis' => [
        'classname'   => '\local_umat_ai\external\analysis', 'methodname' => 'request_analysis',
        'description' => 'Trigger material analysis on AI service', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_service_status' => [
        'classname'   => '\local_umat_ai\external\service_status', 'methodname' => 'ping',
        'description' => 'Check AI service availability for the connection indicator', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => '',
    ],

    /* ---- Student Notes ---- */
    'local_umat_ai_get_notes' => [
        'classname'   => '\local_umat_ai\external\notes', 'methodname' => 'get_notes',
        'description' => 'Get all notes for the current user', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_save_note' => [
        'classname'   => '\local_umat_ai\external\notes', 'methodname' => 'save_note',
        'description' => 'Create or update a note with tags', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_delete_note' => [
        'classname'   => '\local_umat_ai\external\notes', 'methodname' => 'delete_note',
        'description' => 'Delete a note and its tags', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_note_tag_sources' => [
        'classname'   => '\local_umat_ai\external\notes', 'methodname' => 'get_note_tag_sources',
        'description' => 'Get available tag sources (sessions, materials) for a course', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],

    /* ---- Issue Reports ---- */
    'local_umat_ai_submit_issue' => [
        'classname'   => '\local_umat_ai\external\issue_report', 'methodname' => 'submit_issue',
        'description' => 'Student submits an issue/complaint', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_student_issues' => [
        'classname'   => '\local_umat_ai\external\issue_report', 'methodname' => 'get_student_issues',
        'description' => 'Student views their own issue reports', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_course_issues' => [
        'classname'   => '\local_umat_ai\external\issue_report', 'methodname' => 'get_course_issues',
        'description' => 'Lecturer views all issues for a course', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_update_issue_status' => [
        'classname'   => '\local_umat_ai\external\issue_report', 'methodname' => 'update_issue_status',
        'description' => 'Lecturer updates issue status and notes', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],

    /* ---- Student Progress ---- */
    'local_umat_ai_get_my_progress' => [
        'classname'   => '\local_umat_ai\external\student_progress', 'methodname' => 'get_my_progress',
        'description' => 'Student views their personal progress/struggle dashboard', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],

    /* ---- Quiz Generator ---- */
    'local_umat_ai_generate_quiz_draft' => [
        'classname'   => '\local_umat_ai\external\quizgen', 'methodname' => 'generate_quiz_draft',
        'description' => 'Lecturer creates an AI quiz generation job and queues it', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_get_quiz_job_status' => [
        'classname'   => '\local_umat_ai\external\quizgen', 'methodname' => 'get_quiz_job_status',
        'description' => 'Poll the status of a quiz generation job', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_finalize_quiz' => [
        'classname'   => '\local_umat_ai\external\quizgen', 'methodname' => 'finalize_quiz',
        'description' => 'Import generated questions into question bank + create quiz', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_get_quiz_job_history' => [
        'classname'   => '\local_umat_ai\external\quizgen', 'methodname' => 'get_quiz_job_history',
        'description' => 'List all quiz generation jobs for a course (history tracking)', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],

    /* ---- Lecturer Insights Dashboard ---- */
    'local_umat_ai_get_dashboard_summary' => [
        'classname'   => '\local_umat_ai\external\get_dashboard_summary', 'methodname' => 'get_dashboard_summary',
        'description' => 'Lecturer dashboard summary (engagement, at-risk count)', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_query_student_insights' => [
        'classname'   => '\local_umat_ai\external\query_student_insights', 'methodname' => 'query_student_insights',
        'description' => 'NLQ-powered student insight query with risk filter', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_get_student_profile' => [
        'classname'   => '\local_umat_ai\external\get_student_profile', 'methodname' => 'get_student_profile',
        'description' => 'Deep-dive profile for a single student', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_execute_intervention' => [
        'classname'   => '\local_umat_ai\external\execute_intervention', 'methodname' => 'execute_intervention',
        'description' => 'Send intervention message to a student', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],

    /* ---- Struggle Dashboard (Phase 2) ---- */
    'local_umat_ai_get_struggle_dashboard_data' => [
        'classname'   => '\local_umat_ai\external\get_struggle_dashboard_data', 'methodname' => 'get_struggle_dashboard_data',
        'description' => 'Single aggregated payload for the Struggle Areas Dashboard', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_report_material_progress' => [
        'classname'   => '\local_umat_ai\external\report_material_progress', 'methodname' => 'report_material_progress',
        'description' => 'Report material viewing progress via JS beacon', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_rate_answer' => [
        'classname'   => '\local_umat_ai\external\rate_answer', 'methodname' => 'rate_answer',
        'description' => 'Rate the helpfulness of an AI answer', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
];

$services = [
    'UMaT AI Service' => [
        'functions'       => array_keys($functions),
        'restrictedusers' => 0,
        'enabled'         => 1,
        'downloadfiles'   => 0,
        'uploadfiles'     => 0,
    ],
];
