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
];
