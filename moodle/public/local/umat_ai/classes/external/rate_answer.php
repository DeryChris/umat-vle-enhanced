<?php

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class rate_answer extends \external_api {

    public static function rate_answer_parameters() {
        return new \external_function_parameters([
            'chatlogid' => new \external_value(PARAM_INT, 'Chat log entry ID'),
            'rating'    => new \external_value(PARAM_INT, 'Rating 1-5'),
        ]);
    }

    public static function rate_answer($chatlogid, $rating) {
        global $DB, $USER;

        $params = self::validate_parameters(self::rate_answer_parameters(), [
            'chatlogid' => $chatlogid,
            'rating'    => $rating,
        ]);

        $chatlog = $DB->get_record('umat_ai_chat_logs', ['id' => (int)$params['chatlogid']], '*', MUST_EXIST);

        if ((int)$chatlog->userid !== (int)$USER->id) {
            throw new \moodle_exception('notyourchatlog', 'local_umat_ai');
        }

        $context = \context_course::instance((int)$chatlog->courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        $rating = max(1, min(5, (int)$params['rating']));

        $existing = $DB->get_record('umat_ai_chat_log_helpfulness', [
            'chatlogid' => (int)$params['chatlogid'],
        ]);

        if ($existing) {
            $record = new \stdClass();
            $record->id     = $existing->id;
            $record->rating = $rating;
            $DB->update_record('umat_ai_chat_log_helpfulness', $record);
            $ratingId = (int)$existing->id;
        } else {
            $record = new \stdClass();
            $record->chatlogid   = (int)$params['chatlogid'];
            $record->userid      = (int)$USER->id;
            $record->rating      = $rating;
            $record->timecreated = time();
            $ratingId = (int)$DB->insert_record('umat_ai_chat_log_helpfulness', $record);
        }

        return [
            'status'    => 'ok',
            'rating_id' => $ratingId,
        ];
    }

    public static function rate_answer_returns() {
        return new \external_single_structure([
            'status'    => new \external_value(PARAM_TEXT, 'Operation status'),
            'rating_id' => new \external_value(PARAM_INT, 'Helpfulness rating record ID'),
        ]);
    }
}
