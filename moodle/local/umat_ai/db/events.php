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
];
