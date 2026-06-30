<?php
/**
 * External API: Group Study CRUD + shared AI chat.
 *
 * @package    local_umat_ai
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

class group_study extends \external_api {

    // ------------------------------------------------------------------ //
    // get_study_groups
    // ------------------------------------------------------------------ //
    public static function get_study_groups_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    public static function get_study_groups($courseid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::get_study_groups_parameters(), ['courseid' => $courseid]);
        $courseid = (int)$params['courseid'];
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        $groups = $DB->get_records('umat_ai_group_study',
            ['courseid' => $courseid],
            'timecreated DESC'
        );

        $result = [];
        foreach ($groups as $g) {
            $membercount = $DB->count_records('umat_ai_group_members', ['groupid' => $g->id]);
            $ismember = $DB->record_exists('umat_ai_group_members', ['groupid' => $g->id, 'userid' => $USER->id]);
            $result[] = [
                'id'            => (int)$g->id,
                'courseid'      => (int)$g->courseid,
                'name'          => $g->name,
                'description'   => $g->description ?? '',
                'max_members'   => (int)$g->max_members,
                'status'        => $g->status,
                'created_by'    => (int)$g->created_by,
                'member_count'  => $membercount,
                'is_member'     => $ismember,
                'timecreated'   => (int)$g->timecreated,
            ];
        }

        return ['groups' => $result];
    }

    public static function get_study_groups_returns() {
        return new \external_single_structure([
            'groups' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'           => new \external_value(PARAM_INT),
                    'courseid'     => new \external_value(PARAM_INT),
                    'name'         => new \external_value(PARAM_TEXT),
                    'description'  => new \external_value(PARAM_RAW),
                    'max_members'  => new \external_value(PARAM_INT),
                    'status'       => new \external_value(PARAM_ALPHA),
                    'created_by'   => new \external_value(PARAM_INT),
                    'member_count' => new \external_value(PARAM_INT),
                    'is_member'    => new \external_value(PARAM_BOOL),
                    'timecreated'  => new \external_value(PARAM_INT),
                ])
            ),
        ]);
    }

    // ------------------------------------------------------------------ //
    // create_study_group
    // ------------------------------------------------------------------ //
    public static function create_study_group_parameters() {
        return new \external_function_parameters([
            'courseid'    => new \external_value(PARAM_INT, 'Course ID'),
            'name'        => new \external_value(PARAM_TEXT, 'Group name'),
            'description' => new \external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'max_members' => new \external_value(PARAM_INT, 'Max members', VALUE_DEFAULT, 5),
        ]);
    }

    public static function create_study_group($courseid, $name, $description = '', $max_members = 5) {
        global $DB, $USER;

        $params = self::validate_parameters(self::create_study_group_parameters(), [
            'courseid' => $courseid, 'name' => $name,
            'description' => $description, 'max_members' => $max_members,
        ]);

        $courseid = (int)$params['courseid'];
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:creategroup', $context);

        $now = time();
        $id = $DB->insert_record('umat_ai_group_study', [
            'courseid'    => $courseid,
            'name'        => trim($params['name']),
            'description' => $params['description'] ?? '',
            'max_members' => min(20, max(2, (int)$params['max_members'])),
            'status'      => 'open',
            'created_by'  => (int)$USER->id,
            'timecreated' => $now,
            'timemodified'=> $now,
        ]);

        // Auto-add creator as owner
        $DB->insert_record('umat_ai_group_members', [
            'groupid' => $id, 'userid' => $USER->id,
            'role' => 'owner', 'timecreated' => $now,
        ]);

        return ['groupid' => $id, 'saved' => true];
    }

    public static function create_study_group_returns() {
        return new \external_single_structure([
            'groupid' => new \external_value(PARAM_INT),
            'saved'   => new \external_value(PARAM_BOOL),
        ]);
    }

    // ------------------------------------------------------------------ //
    // join_study_group
    // ------------------------------------------------------------------ //
    public static function join_study_group_parameters() {
        return new \external_function_parameters([
            'groupid' => new \external_value(PARAM_INT, 'Group study ID'),
        ]);
    }

    public static function join_study_group($groupid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::join_study_group_parameters(), ['groupid' => $groupid]);
        $groupid = (int)$params['groupid'];

        $group = $DB->get_record('umat_ai_group_study', ['id' => $groupid]);
        if (!$group) {
            throw new \moodle_exception('group_invalid', 'local_umat_ai');
        }

        $context = \context_course::instance($group->courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        if ($DB->record_exists('umat_ai_group_members', ['groupid' => $groupid, 'userid' => $USER->id])) {
            throw new \moodle_exception('group_already_member', 'local_umat_ai');
        }

        $count = $DB->count_records('umat_ai_group_members', ['groupid' => $groupid]);
        if ($count >= $group->max_members) {
            throw new \moodle_exception('group_full', 'local_umat_ai');
        }

        $DB->insert_record('umat_ai_group_members', [
            'groupid' => $groupid, 'userid' => $USER->id,
            'role' => 'member', 'timecreated' => time(),
        ]);

        return ['joined' => true];
    }

    public static function join_study_group_returns() {
        return new \external_single_structure([
            'joined' => new \external_value(PARAM_BOOL),
        ]);
    }

    // ------------------------------------------------------------------ //
    // leave_study_group
    // ------------------------------------------------------------------ //
    public static function leave_study_group_parameters() {
        return new \external_function_parameters([
            'groupid' => new \external_value(PARAM_INT, 'Group study ID'),
        ]);
    }

    public static function leave_study_group($groupid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::leave_study_group_parameters(), ['groupid' => $groupid]);
        $groupid = (int)$params['groupid'];

        $group = $DB->get_record('umat_ai_group_study', ['id' => $groupid]);
        if (!$group) {
            throw new \moodle_exception('group_invalid', 'local_umat_ai');
        }

        $context = \context_course::instance($group->courseid);
        self::validate_context($context);

        $DB->delete_records('umat_ai_group_members', ['groupid' => $groupid, 'userid' => $USER->id]);

        // If last member, delete the group
        $count = $DB->count_records('umat_ai_group_members', ['groupid' => $groupid]);
        if ($count == 0) {
            $DB->delete_records('umat_ai_group_messages', ['groupid' => $groupid]);
            $DB->delete_records('umat_ai_group_study', ['id' => $groupid]);
        }

        return ['left' => true];
    }

    public static function leave_study_group_returns() {
        return new \external_single_structure([
            'left' => new \external_value(PARAM_BOOL),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_group_members
    // ------------------------------------------------------------------ //
    public static function get_group_members_parameters() {
        return new \external_function_parameters([
            'groupid' => new \external_value(PARAM_INT, 'Group study ID'),
        ]);
    }

    public static function get_group_members($groupid) {
        global $DB;

        $params = self::validate_parameters(self::get_group_members_parameters(), ['groupid' => $groupid]);
        $groupid = (int)$params['groupid'];

        $group = $DB->get_record('umat_ai_group_study', ['id' => $groupid]);
        if (!$group) {
            throw new \moodle_exception('group_invalid', 'local_umat_ai');
        }

        $context = \context_course::instance($group->courseid);
        self::validate_context($context);

        $records = $DB->get_records('umat_ai_group_members', ['groupid' => $groupid]);
        $members = [];
        foreach ($records as $r) {
            $user = $DB->get_record('user', ['id' => $r->userid], 'id, firstname, lastname');
            $members[] = [
                'userid' => (int)$r->userid,
                'fullname' => $user ? fullname($user) : 'Unknown',
                'role' => $r->role,
            ];
        }

        return ['members' => $members];
    }

    public static function get_group_members_returns() {
        return new \external_single_structure([
            'members' => new \external_multiple_structure(
                new \external_single_structure([
                    'userid'   => new \external_value(PARAM_INT),
                    'fullname' => new \external_value(PARAM_TEXT),
                    'role'     => new \external_value(PARAM_ALPHA),
                ])
            ),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_group_messages
    // ------------------------------------------------------------------ //
    public static function get_group_messages_parameters() {
        return new \external_function_parameters([
            'groupid'  => new \external_value(PARAM_INT, 'Group study ID'),
            'limit'    => new \external_value(PARAM_INT, 'Max messages', VALUE_DEFAULT, 50),
            'offset'   => new \external_value(PARAM_INT, 'Offset for pagination', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_group_messages($groupid, $limit = 50, $offset = 0) {
        global $DB;

        $params = self::validate_parameters(self::get_group_messages_parameters(), [
            'groupid' => $groupid, 'limit' => $limit, 'offset' => $offset,
        ]);
        $groupid = (int)$params['groupid'];

        $group = $DB->get_record('umat_ai_group_study', ['id' => $groupid]);
        if (!$group) {
            throw new \moodle_exception('group_invalid', 'local_umat_ai');
        }

        $context = \context_course::instance($group->courseid);
        self::validate_context($context);

        $records = $DB->get_records('umat_ai_group_messages', ['groupid' => $groupid],
            'timecreated ASC', '*', $offset, $limit);

        $messages = [];
        foreach ($records as $r) {
            $user = $DB->get_record('user', ['id' => $r->userid], 'id, firstname, lastname');
            $messages[] = [
                'id'          => (int)$r->id,
                'userid'      => (int)$r->userid,
                'fullname'    => $user ? fullname($user) : 'Unknown',
                'message'     => $r->message ?? '',
                'question'    => $r->question ?? '',
                'answer'      => $r->answer ?? '',
                'sources'     => $r->sources ?? '',
                'timecreated' => (int)$r->timecreated,
            ];
        }

        return ['messages' => $messages];
    }

    public static function get_group_messages_returns() {
        return new \external_single_structure([
            'messages' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'          => new \external_value(PARAM_INT),
                    'userid'      => new \external_value(PARAM_INT),
                    'fullname'    => new \external_value(PARAM_TEXT),
                    'message'     => new \external_value(PARAM_RAW),
                    'question'    => new \external_value(PARAM_RAW),
                    'answer'      => new \external_value(PARAM_RAW),
                    'sources'     => new \external_value(PARAM_RAW),
                    'timecreated' => new \external_value(PARAM_INT),
                ])
            ),
        ]);
    }

    // ------------------------------------------------------------------ //
    // send_group_message
    // ------------------------------------------------------------------ //
    public static function send_group_message_parameters() {
        return new \external_function_parameters([
            'groupid'  => new \external_value(PARAM_INT, 'Group study ID'),
            'question' => new \external_value(PARAM_RAW, 'AI question (null for chat messages)', VALUE_DEFAULT, ''),
            'answer'   => new \external_value(PARAM_RAW, 'AI answer text', VALUE_DEFAULT, ''),
            'sources'  => new \external_value(PARAM_RAW, 'Source citations', VALUE_DEFAULT, ''),
            'message'  => new \external_value(PARAM_RAW, 'Free-form chat text', VALUE_DEFAULT, ''),
        ]);
    }

    public static function send_group_message($groupid, $question = '', $answer = '', $sources = '', $message = '') {
        global $DB, $USER;

        $params = self::validate_parameters(self::send_group_message_parameters(), [
            'groupid' => $groupid, 'question' => $question,
            'answer' => $answer, 'sources' => $sources,
            'message' => $message,
        ]);
        $groupid = (int)$params['groupid'];

        $group = $DB->get_record('umat_ai_group_study', ['id' => $groupid]);
        if (!$group) {
            throw new \moodle_exception('group_invalid', 'local_umat_ai');
        }

        $context = \context_course::instance($group->courseid);
        self::validate_context($context);

        $ismember = $DB->record_exists('umat_ai_group_members', ['groupid' => $groupid, 'userid' => $USER->id]);
        if (!$ismember) {
            throw new \moodle_exception('group_not_member', 'local_umat_ai');
        }

        $q = trim($params['question']);
        $m = trim($params['message']);
        if (empty($q) && empty($m)) {
            throw new \moodle_exception('group_invalid', 'local_umat_ai');
        }

        $DB->insert_record('umat_ai_group_messages', [
            'groupid' => $groupid, 'userid' => $USER->id,
            'message' => $m ?: null,
            'question' => $q ?: null,
            'answer' => $params['answer'] ?: null,
            'sources' => $params['sources'] ?: null,
            'timecreated' => time(),
        ]);

        return ['sent' => true];
    }

    public static function send_group_message_returns() {
        return new \external_single_structure([
            'sent' => new \external_value(PARAM_BOOL),
        ]);
    }

    // ------------------------------------------------------------------ //
    // delete_study_group (owner only)
    // ------------------------------------------------------------------ //
    public static function delete_study_group_parameters() {
        return new \external_function_parameters([
            'groupid' => new \external_value(PARAM_INT, 'Group study ID'),
        ]);
    }

    public static function delete_study_group($groupid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::delete_study_group_parameters(), ['groupid' => $groupid]);
        $groupid = (int)$params['groupid'];

        $group = $DB->get_record('umat_ai_group_study', ['id' => $groupid]);
        if (!$group) {
            throw new \moodle_exception('group_invalid', 'local_umat_ai');
        }

        if ((int)$group->created_by !== (int)$USER->id) {
            throw new \moodle_exception('nopermissions', 'local_umat_ai');
        }

        $context = \context_course::instance($group->courseid);
        self::validate_context($context);

        $DB->delete_records('umat_ai_group_messages', ['groupid' => $groupid]);
        $DB->delete_records('umat_ai_group_members', ['groupid' => $groupid]);
        $DB->delete_records('umat_ai_group_study', ['id' => $groupid]);

        return ['deleted' => true];
    }

    public static function delete_study_group_returns() {
        return new \external_single_structure([
            'deleted' => new \external_value(PARAM_BOOL),
        ]);
    }
}
