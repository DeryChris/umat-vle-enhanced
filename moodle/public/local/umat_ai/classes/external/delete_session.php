<?php

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

class delete_session extends \external_api {

    // ------------------------------------------------------------------ //
    // delete_session — Delete a single chat session by session_key       //
    // ------------------------------------------------------------------ //

    public static function delete_session_parameters() {
        return new \external_function_parameters([
            'session_key' => new \external_value(PARAM_ALPHANUMEXT, 'Session key to delete'),
        ]);
    }

    public static function delete_session(string $session_key): array {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::delete_session_parameters(),
            ['session_key' => $session_key]
        );
        $sk = trim($params['session_key']);

        if (empty($sk)) {
            throw new \moodle_exception('invalidparameter', 'local_umat_ai', '', 'session_key cannot be empty');
        }

        // Verify the session belongs to the current user.
        $exists = $DB->record_exists('umat_ai_chat_logs', [
            'session_key' => $sk,
            'userid'      => $USER->id,
        ]);
        if (!$exists) {
            return ['success' => true, 'deleted' => 0]; // Idempotent — nothing to delete.
        }

        // Delete all chat logs with this session_key for this user.
        // helpfulness ratings cascade-delete automatically.
        $deleted = $DB->delete_records('umat_ai_chat_logs', [
            'session_key' => $sk,
            'userid'      => $USER->id,
        ]);

        return ['success' => true, 'deleted' => (int)$deleted];
    }

    public static function delete_session_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Whether the operation succeeded'),
            'deleted' => new \external_value(PARAM_INT, 'Number of chat log rows deleted'),
        ]);
    }
}
