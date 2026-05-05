<?php
// ============================================================
// Defines capabilities for role-based access control
// ============================================================

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // View AI-generated summaries, notes, and quiz
    'local/umat_ai:viewsummary' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'student'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
        ],
    ],

    // Approve AI-generated content before it is visible to students
    'local/umat_ai:approveoutput' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
        ],
    ],

    // Ask questions via the AI chat panel
    'local/umat_ai:chatwithai' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'student'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
        ],
    ],

    // View the lecturer analytics dashboard
    'local/umat_ai:viewanalytics' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
];