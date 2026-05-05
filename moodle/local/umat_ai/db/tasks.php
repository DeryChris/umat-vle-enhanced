<?php
// ============================================================
// Registers scheduled (cron) tasks for the plugin
// ============================================================

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_umat_ai\task\process_recording',
        'blocking'  => 0,
        'minute'    => '*/5',   // every 5 minutes
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
    [
        'classname' => '\local_umat_ai\task\sync_transcripts',
        'blocking'  => 0,
        'minute'    => '*/10',  // every 10 minutes
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
    [
        'classname' => '\local_umat_ai\task\index_materials',
        'blocking'  => 0,
        'minute'    => '*/15',  // every 15 minutes
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
];