<?php
/**
 * External API: persisted Studio panel outputs.
 *
 * AI-generated cards (quiz / guide / summary / FAQ / flashcard deck) live in
 * umat_ai_studio_outputs so they survive a page refresh and re-login until
 * the student explicitly removes them. Notes use their own table.
 *
 * @package    local_umat_ai
 */
namespace local_umat_ai\external;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

class studio_outputs extends \external_api {

    /** Output types that may be persisted. */
    const ALLOWED_TYPES = ['quiz', 'guide', 'summary', 'faq', 'flashcards'];

    /**
     * Types that are deduplicated by title on save (re-generating the same
     * titled quiz/deck replaces the previous one). Content types (guide,
     * summary, faq) keep every generated version.
     */
    const DEDUP_BY_TITLE = ['quiz', 'flashcards'];

    // ------------------------------------------------------------------ //
    // save_output                                                         //
    // ------------------------------------------------------------------ //
    public static function save_output_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'type'     => new \external_value(PARAM_ALPHAEXT, 'quiz|guide|summary|faq|flashcards'),
            'title'    => new \external_value(PARAM_TEXT, 'Card title', VALUE_DEFAULT, ''),
            'payload'  => new \external_value(PARAM_RAW, 'Card payload (JSON)', VALUE_DEFAULT, ''),
        ]);
    }

    public static function save_output($courseid, $type, $title, $payload = '') {
        global $DB, $USER;

        $params = self::validate_parameters(self::save_output_parameters(), [
            'courseid' => $courseid,
            'type'     => $type,
            'title'    => $title,
            'payload'  => $payload,
        ]);

        $type = $params['type'];
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \moodle_exception('invalidparameter', 'error');
        }

        $userid = (int)$USER->id;
        $cid    = (int)$params['courseid'];
        $title  = trim($params['title']) ?: ucfirst($type);
        $now    = time();

        $record = [
            'userid'       => $userid,
            'courseid'     => $cid,
            'output_type'  => $type,
            'title'        => $title,
            'payload'      => (string)$params['payload'],
            'timemodified' => $now,
        ];

        // Re-generating the same titled quiz/deck replaces the old row so the
        // Studio list never duplicates; content types always get a new row.
        $existing = null;
        if (in_array($type, self::DEDUP_BY_TITLE, true)) {
            $existing = $DB->get_record('umat_ai_studio_outputs', [
                'userid' => $userid, 'courseid' => $cid, 'output_type' => $type, 'title' => $title,
            ]);
        }

        if ($existing) {
            $record['id'] = (int)$existing->id;
            $DB->update_record('umat_ai_studio_outputs', $record);
            $outputid = (int)$existing->id;
        } else {
            $record['timecreated'] = $now;
            $outputid = (int)$DB->insert_record('umat_ai_studio_outputs', $record);
        }

        return ['outputid' => $outputid, 'saved' => true];
    }

    public static function save_output_returns() {
        return new \external_single_structure([
            'outputid' => new \external_value(PARAM_INT),
            'saved'    => new \external_value(PARAM_BOOL),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_outputs                                                         //
    // ------------------------------------------------------------------ //
    public static function get_outputs_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID (0 = all)'),
        ]);
    }

    public static function get_outputs($courseid = 0) {
        global $DB, $USER;

        $params = self::validate_parameters(self::get_outputs_parameters(), ['courseid' => $courseid]);
        $userid = (int)$USER->id;

        $sql  = 'SELECT id, courseid, output_type, title, payload, timecreated, timemodified
                 FROM {umat_ai_studio_outputs} WHERE userid = ?';
        $args = [$userid];
        if (!empty($params['courseid'])) {
            $sql .= ' AND courseid = ?';
            $args[] = (int)$params['courseid'];
        }
        $sql .= ' ORDER BY timecreated DESC';

        $rows = $DB->get_records_sql($sql, $args);
        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'id'           => (int)$r->id,
                'courseid'     => (int)$r->courseid,
                'type'         => $r->output_type,
                'title'        => $r->title,
                'payload'      => (string)($r->payload ?? ''),
                'timecreated'  => (int)$r->timecreated,
                'timemodified' => (int)$r->timemodified,
            ];
        }
        return ['outputs' => $result];
    }

    public static function get_outputs_returns() {
        return new \external_single_structure([
            'outputs' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'           => new \external_value(PARAM_INT),
                    'courseid'     => new \external_value(PARAM_INT),
                    'type'         => new \external_value(PARAM_ALPHAEXT),
                    'title'        => new \external_value(PARAM_TEXT),
                    'payload'      => new \external_value(PARAM_RAW),
                    'timecreated'  => new \external_value(PARAM_INT),
                    'timemodified' => new \external_value(PARAM_INT),
                ])
            ),
        ]);
    }

    // ------------------------------------------------------------------ //
    // delete_output                                                       //
    // ------------------------------------------------------------------ //
    public static function delete_output_parameters() {
        return new \external_function_parameters([
            'outputid' => new \external_value(PARAM_INT, 'Studio output ID to delete'),
        ]);
    }

    public static function delete_output($outputid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::delete_output_parameters(), ['outputid' => $outputid]);
        $userid = (int)$USER->id;

        $existing = $DB->get_record('umat_ai_studio_outputs', ['id' => (int)$params['outputid'], 'userid' => $userid]);
        if (!$existing) {
            throw new \moodle_exception('invalidoutput', 'local_umat_ai');
        }
        $DB->delete_records('umat_ai_studio_outputs', ['id' => (int)$params['outputid']]);

        return ['deleted' => true];
    }

    public static function delete_output_returns() {
        return new \external_single_structure([
            'deleted' => new \external_value(PARAM_BOOL),
        ]);
    }
}
