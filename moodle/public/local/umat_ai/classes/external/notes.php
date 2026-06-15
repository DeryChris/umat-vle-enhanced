<?php
/**
 * External API: student notes CRUD + tag sources.
 *
 * @package    local_umat_ai
 */
namespace local_umat_ai\external;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

class notes extends \external_api {

    // ------------------------------------------------------------------ //
    // get_notes                                                           //
    // ------------------------------------------------------------------ //
    public static function get_notes_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Filter by course (0 = all)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_notes($courseid = 0) {
        global $DB, $USER;

        $params = self::validate_parameters(self::get_notes_parameters(), ['courseid' => $courseid]);
        $userid = (int)$USER->id;

        $sql  = 'SELECT n.* FROM {umat_ai_notes} n WHERE n.userid = ?';
        $args = [$userid];
        if (!empty($params['courseid'])) {
            $sql   .= ' AND n.courseid = ?';
            $args[] = (int)$params['courseid'];
        }
        $sql .= ' ORDER BY n.pinned DESC, n.timemodified DESC';

        $notes = $DB->get_records_sql($sql, $args);

        $result = [];
        foreach ($notes as $n) {
            $tags = $DB->get_records('umat_ai_note_tags', ['noteid' => $n->id], 'id ASC');
            $taglist = [];
            foreach ($tags as $t) {
                $taglist[] = [
                    'id'        => (int)$t->id,
                    'tag_type'  => $t->tag_type,
                    'tag_id'    => $t->tag_id ? (int)$t->tag_id : 0,
                    'tag_label' => $t->tag_label,
                    'tag_value' => $t->tag_value ?? '',
                ];
            }
            $result[] = [
                'id'           => (int)$n->id,
                'courseid'     => (int)$n->courseid,
                'title'        => $n->title,
                'content'      => $n->content ?? '',
                'pinned'       => (bool)$n->pinned,
                'timecreated'  => (int)$n->timecreated,
                'timemodified' => (int)$n->timemodified,
                'tags'         => $taglist,
            ];
        }
        return ['notes' => $result];
    }

    public static function get_notes_returns() {
        return new \external_single_structure([
            'notes' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'           => new \external_value(PARAM_INT),
                    'courseid'     => new \external_value(PARAM_INT),
                    'title'        => new \external_value(PARAM_TEXT),
                    'content'      => new \external_value(PARAM_RAW),
                    'pinned'       => new \external_value(PARAM_BOOL),
                    'timecreated'  => new \external_value(PARAM_INT),
                    'timemodified' => new \external_value(PARAM_INT),
                    'tags'         => new \external_multiple_structure(
                        new \external_single_structure([
                            'id'        => new \external_value(PARAM_INT),
                            'tag_type'  => new \external_value(PARAM_ALPHA),
                            'tag_id'    => new \external_value(PARAM_INT),
                            'tag_label' => new \external_value(PARAM_TEXT),
                            'tag_value' => new \external_value(PARAM_RAW),
                        ])
                    ),
                ])
            ),
        ]);
    }

    // ------------------------------------------------------------------ //
    // save_note                                                           //
    // ------------------------------------------------------------------ //
    public static function save_note_parameters() {
        return new \external_function_parameters([
            'noteid'    => new \external_value(PARAM_INT, '0 = new note', VALUE_DEFAULT, 0),
            'courseid'  => new \external_value(PARAM_INT, 'Course ID', VALUE_DEFAULT, 0),
            'title'     => new \external_value(PARAM_TEXT, 'Note title', VALUE_DEFAULT, ''),
            'content'   => new \external_value(PARAM_RAW, 'Note content (HTML)', VALUE_DEFAULT, ''),
            'pinned'    => new \external_value(PARAM_BOOL, 'Pin to top', VALUE_DEFAULT, false),
            'tags'      => new \external_multiple_structure(
                new \external_single_structure([
                    'tag_type'  => new \external_value(PARAM_ALPHA, 'course|material|session|custom'),
                    'tag_id'    => new \external_value(PARAM_INT, 'FK to tagged entity (0 for custom)', VALUE_DEFAULT, 0),
                    'tag_label' => new \external_value(PARAM_TEXT, 'Display label'),
                    'tag_value' => new \external_value(PARAM_RAW, 'Extra info (e.g. session_key)', VALUE_DEFAULT, ''),
                ]),
                'Tags to attach',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    public static function save_note($noteid = 0, $courseid = 0, $title = '', $content = '', $pinned = false, $tags = []) {
        global $DB, $USER;

        $params = self::validate_parameters(self::save_note_parameters(), [
            'noteid'   => $noteid,
            'courseid' => $courseid,
            'title'    => $title,
            'content'  => $content,
            'pinned'   => $pinned,
            'tags'     => $tags,
        ]);
        $userid = (int)$USER->id;
        $now    = time();

        $record = [
            'userid'       => $userid,
            'courseid'     => !empty($params['courseid']) ? (int)$params['courseid'] : null,
            'title'        => trim($params['title']) ?: 'Untitled Note',
            'content'      => $params['content'] ?? '',
            'pinned'       => !empty($params['pinned']) ? 1 : 0,
            'timemodified' => $now,
        ];

        if (!empty($params['noteid'])) {
            $existing = $DB->get_record('umat_ai_notes', ['id' => (int)$params['noteid'], 'userid' => $userid]);
            if (!$existing) {
                throw new \moodle_exception('invalidnote', 'local_umat_ai', '', null, 'Note not found or access denied');
            }
            $record['id'] = (int)$params['noteid'];
            $record['timecreated'] = $existing->timecreated;
            $DB->update_record('umat_ai_notes', $record);
            $noteid = (int)$params['noteid'];
        } else {
            $record['timecreated'] = $now;
            $noteid = $DB->insert_record('umat_ai_notes', $record);
        }

        // Replace tags: delete existing, insert new
        $DB->delete_records('umat_ai_note_tags', ['noteid' => $noteid]);
        if (!empty($params['tags'])) {
            foreach ($params['tags'] as $t) {
                $DB->insert_record('umat_ai_note_tags', [
                    'noteid'     => $noteid,
                    'tag_type'   => $t['tag_type'],
                    'tag_id'     => !empty($t['tag_id']) ? (int)$t['tag_id'] : null,
                    'tag_label'  => $t['tag_label'],
                    'tag_value'  => $t['tag_value'] ?? '',
                    'timecreated' => $now,
                ]);
            }
        }

        return ['noteid' => $noteid, 'saved' => true];
    }

    public static function save_note_returns() {
        return new \external_single_structure([
            'noteid' => new \external_value(PARAM_INT),
            'saved'  => new \external_value(PARAM_BOOL),
        ]);
    }

    // ------------------------------------------------------------------ //
    // delete_note                                                         //
    // ------------------------------------------------------------------ //
    public static function delete_note_parameters() {
        return new \external_function_parameters([
            'noteid' => new \external_value(PARAM_INT, 'Note ID to delete'),
        ]);
    }

    public static function delete_note($noteid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::delete_note_parameters(), ['noteid' => $noteid]);
        $userid = (int)$USER->id;

        $existing = $DB->get_record('umat_ai_notes', ['id' => (int)$params['noteid'], 'userid' => $userid]);
        if (!$existing) {
            throw new \moodle_exception('invalidnote', 'local_umat_ai');
        }
        $DB->delete_records('umat_ai_note_tags', ['noteid' => (int)$params['noteid']]);
        $DB->delete_records('umat_ai_notes', ['id' => (int)$params['noteid']]);

        return ['deleted' => true];
    }

    public static function delete_note_returns() {
        return new \external_single_structure([
            'deleted' => new \external_value(PARAM_BOOL),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_note_tag_sources                                                //
    // ------------------------------------------------------------------ //
    public static function get_note_tag_sources_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    public static function get_note_tag_sources($courseid) {
        global $DB, $USER;

        $params  = self::validate_parameters(self::get_note_tag_sources_parameters(), ['courseid' => $courseid]);
        $cid     = (int)$params['courseid'];
        $userid  = (int)$USER->id;

        $context = \context_course::instance($cid);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        // Materials tagged to this course
        $materials = $DB->get_records('umat_ai_materials', ['courseid' => $cid], 'filename ASC', 'id, filename');
        $matlist = [];
        foreach ($materials as $m) {
            $matlist[] = ['id' => (int)$m->id, 'label' => $m->filename, 'type' => 'material'];
        }

        // Recent chat sessions for this user+course (grouped by session_key)
        $sql = 'SELECT session_key, MIN(timecreated) AS first_ts, COUNT(*) AS msg_count
                FROM {umat_ai_chat_logs}
                WHERE userid = ? AND courseid = ? AND session_key IS NOT NULL AND session_key != ?
                GROUP BY session_key
                ORDER BY first_ts DESC
                LIMIT 50';
        $rows = $DB->get_records_sql($sql, [$userid, $cid, '']);
        $sesslist = [];
        foreach ($rows as $r) {
            $date = userdate($r->first_ts, '%d %b %Y', 0);
            $sesslist[] = [
                'id'    => 0,
                'label' => "Session — {$date} ({$r->msg_count} msgs)",
                'type'  => 'session',
                'value' => $r->session_key,
            ];
        }

        return [
            'materials' => $matlist,
            'sessions'  => $sesslist,
        ];
    }

    public static function get_note_tag_sources_returns() {
        return new \external_single_structure([
            'materials' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'    => new \external_value(PARAM_INT),
                    'label' => new \external_value(PARAM_TEXT),
                    'type'  => new \external_value(PARAM_ALPHA),
                ])
            ),
            'sessions' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'    => new \external_value(PARAM_INT),
                    'label' => new \external_value(PARAM_TEXT),
                    'type'  => new \external_value(PARAM_ALPHA),
                    'value' => new \external_value(PARAM_RAW),
                ])
            ),
        ]);
    }
}
