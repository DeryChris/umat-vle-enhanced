<?php
/**
 * Message providers for local_umat_ai.
 * Defines approval and private Student Issues notification channels.
 *
 * @package    local_umat_ai
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    'pendingapproval' => [
        'capability' => 'local/umat_ai:approveoutput',
    ],
    'studentissues' => [],
];
