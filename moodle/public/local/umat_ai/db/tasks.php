<?php
/**
 * Scheduled tasks for local_umat_ai.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname'   => '\local_umat_ai\task\process_recordings',
        'blocking'    => 0,
        'minute'      => '*/15',   // Every 15 minutes.
        'hour'        => '*',
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
        'disabled'    => 0,
    ],
    [
        'classname'   => '\local_umat_ai\task\cleanup_old_logs',
        'blocking'    => 0,
        'minute'      => '0',
        'hour'        => '2',      // 2 AM daily.
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
        'disabled'    => 0,
    ],
    [
        'classname'   => '\local_umat_ai\task\index_course_materials',
        'blocking'    => 0,
        'minute'      => '*/30',   // Every 30 minutes.
        'hour'        => '*',
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
        'disabled'    => 0,
    ],
];
