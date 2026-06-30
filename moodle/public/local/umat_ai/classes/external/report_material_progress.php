<?php

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class report_material_progress extends \external_api {

    public static function report_material_progress_parameters() {
        return new \external_function_parameters([
            'courseid'      => new \external_value(PARAM_INT, 'Course ID'),
            'materialid'    => new \external_value(PARAM_INT, 'Material ID'),
            'progress_pct'  => new \external_value(PARAM_FLOAT, 'Progress percentage 0-100'),
            'time_spent_sec' => new \external_value(PARAM_INT, 'Time spent in seconds'),
            'last_position' => new \external_value(PARAM_INT, 'Last scroll/viewport position', VALUE_DEFAULT, null),
        ]);
    }

    public static function report_material_progress($courseid, $materialid, $progress_pct, $time_spent_sec, $last_position = null) {
        global $DB, $USER;

        $params = self::validate_parameters(self::report_material_progress_parameters(), [
            'courseid'      => $courseid,
            'materialid'    => $materialid,
            'progress_pct'  => $progress_pct,
            'time_spent_sec' => $time_spent_sec,
            'last_position' => $last_position,
        ]);

        $context = \context_course::instance((int)$params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        $progressPct = max(0, min(100, (float)$params['progress_pct']));
        $timeSpent   = max(0, (int)$params['time_spent_sec']);

        $existing = $DB->get_record('umat_ai_material_progress', [
            'userid'     => (int)$USER->id,
            'courseid'   => (int)$params['courseid'],
            'materialid' => (int)$params['materialid'],
        ]);

        if ($existing) {
            $record = new \stdClass();
            $record->id = $existing->id;
            $record->progress_pct   = $progressPct;
            $record->time_spent_sec = $timeSpent;
            $record->timemodified   = time();
            if ($params['last_position'] !== null) {
                $record->last_position = (int)$params['last_position'];
            }
            $DB->update_record('umat_ai_material_progress', $record);
            $progressId = (int)$existing->id;
        } else {
            $record = new \stdClass();
            $record->userid          = (int)$USER->id;
            $record->courseid        = (int)$params['courseid'];
            $record->materialid      = (int)$params['materialid'];
            $record->progress_pct    = $progressPct;
            $record->time_spent_sec  = $timeSpent;
            $record->last_position   = $params['last_position'] !== null ? (int)$params['last_position'] : null;
            $record->timemodified    = time();
            $progressId = (int)$DB->insert_record('umat_ai_material_progress', $record);
        }

        return [
            'status'      => 'ok',
            'progress_id' => $progressId,
        ];
    }

    public static function report_material_progress_returns() {
        return new \external_single_structure([
            'status'      => new \external_value(PARAM_TEXT, 'Operation status'),
            'progress_id' => new \external_value(PARAM_INT, 'Progress record ID'),
        ]);
    }
}
