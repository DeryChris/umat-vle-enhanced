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
        'classname'   => '\local_umat_ai\task\process_recording',
        'blocking'    => 0,
        'minute'      => '*/5',    // Every 5 minutes — fetch BBB recording URL & submit to AI.
        'hour'        => '*',
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
        'disabled'    => 0,
    ],
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
        'minute'      => '*/10',   // Every 10 minutes.
        'hour'        => '*',
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
        'disabled'    => 0,
    ],
    [
        'classname'   => '\local_umat_ai\task\aggregate_student_metrics',
        'blocking'    => 0,
        'minute'      => '0',      // Every hour.
        'hour'        => '*',
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
        'disabled'    => 0,
    ],
    [
        'classname'   => '\local_umat_ai\task\compute_topic_friction',
        'blocking'    => 0,
        'minute'      => '5',      // Hourly at :05.
        'hour'        => '*',
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
        'disabled'    => 0,
    ],
    [
        'classname'   => '\local_umat_ai\task\compute_material_health',
        'blocking'    => 0,
        'minute'      => '10',     // Hourly at :10.
        'hour'        => '*',
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
        'disabled'    => 0,
    ],
    [
        'classname'   => '\local_umat_ai\task\snapshot_metric_trends',
        'blocking'    => 0,
        'minute'      => '15',     // Hourly at :15.
        'hour'        => '*',
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
        'disabled'    => 0,
    ],
];
