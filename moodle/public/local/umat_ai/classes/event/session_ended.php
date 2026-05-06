<?php
// ============================================================
// Event observer: fires when a BigBlueButton meeting ends
// Creates a pending record so the scheduled task can process the recording
// ============================================================

namespace local_umat_ai\event;

defined('MOODLE_INTERNAL') || die();

class session_ended {

    public static function handle_meeting_ended(\core\event\base $event) {
        global $DB;

        $data     = $event->get_data();
        $cmid     = $data['contextinstanceid'];
        $courseid = $data['courseid'];

        // The meeting_ended event contains the BBB meeting ID
        $bbbmeetingid = $data['other']['meetingid'] ?? '';

        if (empty($bbbmeetingid)) {
            return;
        }

        // Avoid duplicate records
        $exists = $DB->record_exists('umat_ai_sessions', ['sessionid' => $bbbmeetingid]);

        if (!$exists) {
            $record = (object)[
                'sessionid'    => $bbbmeetingid,
                'courseid'     => $courseid,
                'cmid'         => $cmid,
                'status'       => 'pending_recording', // Recording URL fetching not yet implemented
                'timecreated'  => time(),
                'timemodified' => time(),
            ];
            $DB->insert_record('umat_ai_sessions', $record);
        }
    }
}