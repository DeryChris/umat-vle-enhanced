<?php
// ============================================================
// Event observer: fires when a course module is created
// Material indexing is handled by the scheduled task; this logs the event.
// ============================================================

namespace local_umat_ai\event;

defined('MOODLE_INTERNAL') || die();

class material_uploaded {

    public static function handle_resource_created(\core\event\course_module_created $event): void {
        global $DB;

        $data     = $event->get_data();
        $courseid = (int) $data['courseid'];
        $cmid     = (int) ($data['contextinstanceid'] ?? 0);
        $modname  = $data['other']['modulename'] ?? '';

        if (!$DB->get_manager()->table_exists('umat_ai_activity_log')) {
            return;
        }

        $DB->insert_record('umat_ai_activity_log', (object) [
            'userid'      => (int) ($data['userid'] ?? 0),
            'courseid'    => $courseid,
            'cmid'        => $cmid ?: null,
            'event_type'  => 'module_created',
            'event_data'  => json_encode(['modulename' => $modname]),
            'timecreated' => time(),
        ]);
    }
}
