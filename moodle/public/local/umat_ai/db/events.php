<?php
// ============================================================
// Registers event observers (hooks into BBB and core events)
// ============================================================

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname'   => '\mod_bigbluebuttonbn\event\meeting_ended',
        'callback'    => '\local_umat_ai\event\session_ended::handle_meeting_ended',
        'priority'    => 200,
        'internal'    => false,
    ],
    [
        'eventname'   => '\core\event\course_module_created',
        'callback'    => '\local_umat_ai\event\material_uploaded::handle_resource_created',
        'priority'    => 200,
        'internal'    => false,
    ],
    [
        'eventname'   => '\core\event\course_module_deleted',
        'callback'    => '\local_umat_ai\event\material_deleted::handle_resource_deleted',
        'priority'    => 200,
        'internal'    => false,
    ],
    [
        'eventname'   => '\mod_quiz\event\attempt_submitted',
        'callback'    => '\local_umat_ai\observer::quiz_submitted',
        'priority'    => 200,
        'internal'    => false,
    ],
    [
        'eventname'   => '\core\event\course_module_viewed',
        'callback'    => '\local_umat_ai\observer::resource_viewed',
        'priority'    => 200,
        'internal'    => false,
    ],
    [
        'eventname'   => '\mod_assign\event\submission_graded',
        'callback'    => '\local_umat_ai\observer::submission_graded',
        'priority'    => 200,
        'internal'    => false,
    ],
];
