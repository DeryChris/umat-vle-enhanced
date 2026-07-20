<?php
/**
 * Cache definitions for local_umat_ai.
 *
 * @package    local_umat_ai
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Course-level struggle insights (lecturer dashboard).
    // Key: "struggle_{courseid}_{days}"
    'struggle_insights' => [
        'mode'                   => \cache_store::MODE_APPLICATION,
        'simplekeys'             => true,
        'ttl'                    => 300,                    // 5 minutes.
        'staticacceleration'     => true,
        'staticaccelerationsize' => 32,
    ],
];
