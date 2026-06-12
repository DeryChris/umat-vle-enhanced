<?php
/**
 * Message providers for local_umat_ai.
 * Notifies lecturers when AI-generated outputs are waiting for approval.
 *
 * @package    local_umat_ai
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    'pendingapproval' => [
        'capability' => 'local/umat_ai:approveoutput',
    ],
];
