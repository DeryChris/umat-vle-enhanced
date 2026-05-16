<?php
/**
 * External web services (called via AJAX from AMD JS / inline scripts).
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    // ------------------------------------------------------------------ //
    // STUDENT — Submit a question to the AI assistant                     //
    // ------------------------------------------------------------------ //
    'local_umat_ai_ask_question' => [
        'classname'     => '\local_umat_ai\external\ai_query',
        'methodname'    => 'ask_question',
        'description'   => 'Submit a question to the AI for a specific course',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
        'capabilities'  => 'local/umat_ai:chatwithai',
    ],

    // ------------------------------------------------------------------ //
    // STUDENT — Get chat history for a session                            //
    // ------------------------------------------------------------------ //
    'local_umat_ai_get_chat_history' => [
        'classname'     => '\local_umat_ai\external\ai_query',
        'methodname'    => 'get_chat_history',
        'description'   => 'Retrieve chat history for a session key',
        'type'          => 'read',
        'ajax'          => true,
        'loginrequired' => true,
        'capabilities'  => 'local/umat_ai:chatwithai',
    ],

    // ------------------------------------------------------------------ //
    // STUDENT — Get AI-generated summary / notes for a session            //
    // ------------------------------------------------------------------ //
    'local_umat_ai_get_session_outputs' => [
        'classname'     => '\local_umat_ai\external\get_summary',
        'methodname'    => 'get_session_outputs',
        'description'   => 'Get AI-generated summary/notes for a session',
        'type'          => 'read',
        'ajax'          => true,
        'loginrequired' => true,
        'capabilities'  => 'local/umat_ai:viewsummary',
    ],

    // ------------------------------------------------------------------ //
    // LECTURER — Approve or reject an AI-generated output                 //
    // ------------------------------------------------------------------ //
    'local_umat_ai_approve_output' => [
        'classname'     => '\local_umat_ai\external\approve_output',
        'methodname'    => 'approve',
        'description'   => 'Lecturer approves or rejects AI-generated content',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
        'capabilities'  => 'local/umat_ai:approveoutput',
    ],

    // ------------------------------------------------------------------ //
    // LECTURER — Get course analytics data                                //
    // ------------------------------------------------------------------ //
    'local_umat_ai_get_analytics' => [
        'classname'     => '\local_umat_ai\external\get_analytics',
        'methodname'    => 'get_course_analytics',
        'description'   => 'Get analytics data for a course (lecturer only)',
        'type'          => 'read',
        'ajax'          => true,
        'loginrequired' => true,
        'capabilities'  => 'local/umat_ai:viewanalytics',
    ],

    // ------------------------------------------------------------------ //
    // LECTURER — Ask AI a lecturer-specific question about the course     //
    // ------------------------------------------------------------------ //
    'local_umat_ai_lecturer_ask' => [
        'classname'     => '\local_umat_ai\external\lecturer_ai_query',
        'methodname'    => 'ask',
        'description'   => 'Lecturer submits a query about course analytics to the AI',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
        'capabilities'  => 'local/umat_ai:viewanalytics',
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
