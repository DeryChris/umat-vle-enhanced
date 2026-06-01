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
    'local_umat_ai_request_analysis' => [
        'classname'   => '\local_umat_ai\external\analysis', 'methodname' => 'request_analysis',
        'description' => 'Trigger material analysis on AI service', 'type' => 'write', 'ajax' => true,
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
