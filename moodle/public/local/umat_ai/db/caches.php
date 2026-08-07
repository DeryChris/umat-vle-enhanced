<?php
/**
 * Cache definitions for local_umat_ai.
 *
 * @package    local_umat_ai
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'struggle_insights' => [
        'mode'                   => \cache_store::MODE_APPLICATION,
        'simplekeys'             => true,
        'ttl'                    => 120,
    ],
    // Consolidated lecturer dashboard payload (analytics_data external).
    // Course-level and identical for every lecturer, so a short shared TTL
    // makes course switching effectively instant. Cheap to rebuild, so the
    // TTL stays small to avoid staleness.
    'analytics_data' => [
        'mode'                   => \cache_store::MODE_APPLICATION,
        'simplekeys'             => true,
        'simpledata'             => true,
        'ttl'                    => 60,
    ],
];
