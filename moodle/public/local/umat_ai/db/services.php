<?php
// ============================================================
// Defines Moodle external web services (called via AJAX from AMD JS)
// ============================================================

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_umat_ai_ask_question' => [
        'classname'     => '\local_umat_ai\external\ai_query',
        'methodname'    => 'ask_question',
        'description'   => 'Submit a question to the AI for a specific course',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
        'capabilities'  => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_summary' => [
        'classname'     => '\local_umat_ai\external\get_summary',
        'methodname'    => 'get_summary',
        'description'   => 'Get AI-generated summary for a session',
        'type'          => 'read',
        'ajax'          => true,
        'loginrequired' => true,
        'capabilities'  => 'local/umat_ai:viewsummary',
    ],
    'local_umat_ai_approve_output' => [
        'classname'     => '\local_umat_ai\external\approve_output',
        'methodname'    => 'approve',
        'description'   => 'Lecturer approves AI-generated content for publishing',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
        'capabilities'  => 'local/umat_ai:approveoutput',
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