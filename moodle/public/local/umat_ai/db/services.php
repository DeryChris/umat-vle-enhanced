<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_umat_ai_ask_question' => [
        'classname'  => '\local_umat_ai\external\ai_query', 'methodname' => 'ask_question',
        'description'=> 'Submit a question to the AI', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_chat_history' => [
        'classname'  => '\local_umat_ai\external\ai_query', 'methodname' => 'get_chat_history',
        'description'=> 'Retrieve chat history', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_session_transcript' => [
        'classname'  => '\local_umat_ai\external\ai_query', 'methodname' => 'get_session_transcript',
        'description'=> 'Get recording URL + transcript segments for the workspace', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_session_outputs' => [
        'classname'  => '\local_umat_ai\external\get_summary', 'methodname' => 'get_session_outputs',
        'description'=> 'Get approved AI outputs (summary, notes, quiz)', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewsummary',
    ],
    'local_umat_ai_approve_output' => [
        'classname'  => '\local_umat_ai\external\approve_output', 'methodname' => 'approve',
        'description'=> 'Approve or reject AI-generated content', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:approveoutput',
    ],
    'local_umat_ai_get_analytics' => [
        'classname'  => '\local_umat_ai\external\get_analytics', 'methodname' => 'get_course_analytics',
        'description'=> 'Get course analytics (lecturer only)', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_lecturer_ask' => [
        'classname'  => '\local_umat_ai\external\lecturer_ai_query', 'methodname' => 'ask',
        'description'=> 'Lecturer AI analytics query', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
];

$services = [
    'UMaT AI Service' => [
        'functions' => array_keys($functions), 'restrictedusers' => 0,
        'enabled' => 1, 'downloadfiles' => 0, 'uploadfiles' => 0,
    ],
];
