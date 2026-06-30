<?php

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class execute_intervention extends \external_api {

    public static function execute_intervention_parameters() {
        return new \external_function_parameters([
            'courseid'  => new \external_value(PARAM_INT, 'Course ID'),
            'userid'    => new \external_value(PARAM_INT, 'Student user ID'),
            'action'    => new \external_value(PARAM_TEXT, 'Intervention type: encouragement|meeting|remedial_quiz'),
            'message'   => new \external_value(PARAM_RAW, 'Custom message body'),
        ]);
    }

    public static function execute_intervention($courseid, $userid, $action, $message) {
        global $DB, $CFG, $USER;

        $params = self::validate_parameters(self::execute_intervention_parameters(), [
            'courseid' => $courseid,
            'userid'   => $userid,
            'action'   => $action,
            'message'  => $message,
        ]);
        $cid     = (int)$params['courseid'];
        $uid     = (int)$params['userid'];
        $action  = $params['action'];
        $message = $params['message'];

        $context = \context_course::instance($cid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $allowed = ['encouragement', 'meeting', 'remedial_quiz'];
        if (!in_array($action, $allowed)) {
            throw new \moodle_exception('Invalid intervention action');
        }

        $recent = $DB->get_record_select('umat_ai_interventions',
            "courseid = ? AND userid = ? AND action = ? AND timecreated > ?",
            [$cid, $uid, $action, time() - 86400],
            'id'
        );
        if ($recent) {
            return ['status' => 'cooldown', 'message' => 'Already sent within 24h'];
        }

        require_once($CFG->dirroot . '/message/lib.php');

        $senduser = \core_user::get_user($uid);
        if (!$senduser) {
            throw new \moodle_exception('User not found');
        }

        $eventdata = new \core\message\message();
        $eventdata->component         = 'moodle';
        $eventdata->name              = 'instantmessage';
        $eventdata->courseid          = $cid;
        $eventdata->userfrom          = $USER;
        $eventdata->userto            = $senduser;
        $eventdata->subject           = get_string('intervention_subject', 'local_umat_ai', $action);
        $eventdata->fullmessage       = $message;
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml   = s($message);
        $eventdata->smallmessage      = '';
        $eventdata->notification      = 0;

        $msgid = message_send($eventdata);

        if ($msgid) {
            $DB->insert_record('umat_ai_interventions', [
                'courseid'    => $cid,
                'userid'      => $uid,
                'action'      => $action,
                'status'      => 'sent',
                'timecreated' => time(),
            ]);
            return ['status' => 'sent'];
        }

        return ['status' => 'failed', 'message' => 'message_send returned false'];
    }

    public static function execute_intervention_returns() {
        return new \external_single_structure([
            'status'  => new \external_value(PARAM_TEXT, 'Result: sent|cooldown|failed'),
            'message' => new \external_value(PARAM_RAW, 'Details or error info', VALUE_OPTIONAL),
        ]);
    }
}
