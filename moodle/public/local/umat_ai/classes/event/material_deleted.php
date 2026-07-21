<?php
// ============================================================
// Event observer: fires when a course module is deleted.
// Cleans up umat_ai_materials rows and tells the AI service
// to remove embeddings from ChromaDB.
// ============================================================

namespace local_umat_ai\event;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

class material_deleted {

    /**
     * Handle course_module_deleted event.
     *
     * @param \core\event\course_module_deleted $event
     */
    public static function handle_resource_deleted(\core\event\course_module_deleted $event): void {
        global $DB;

        $data     = $event->get_data();
        $courseid = (int) $data['courseid'];
        $cmid     = (int) ($data['contextinstanceid'] ?? 0);
        $modname  = $data['other']['modulename'] ?? '';

        // Only handle resource and folder modules
        if (!in_array($modname, ['resource', 'folder'], true)) {
            return;
        }

        if (!$cmid || !$DB->get_manager()->table_exists('umat_ai_materials')) {
            return;
        }

        // Find all material records linked to this cmid
        $materials = $DB->get_records('umat_ai_materials', [
            'courseid' => $courseid,
            'cmid'     => $cmid,
        ]);

        if (empty($materials)) {
            // Fallback: try to find by fileid from the module context
            // (handles cases where cmid wasn't stored on the material row)
            try {
                $cmcontext = \context_module::instance($cmid);
                $fs = get_file_storage();
                foreach (['resource', 'folder'] as $mod) {
                    $files = $fs->get_area_files(
                        $cmcontext->id, 'mod_' . $mod, 'content', false, '', false
                    );
                    foreach ($files as $file) {
                        if ($file->get_filesize() === 0) continue;
                        $rec = $DB->get_record('umat_ai_materials', [
                            'courseid' => $courseid,
                            'fileid'   => $file->get_id(),
                        ]);
                        if ($rec) {
                            $materials[$rec->id] = $rec;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Context may already be gone — not fatal
            }
        }

        $cfg = local_umat_ai_get_service_config();
        $deleted = 0;

        foreach ($materials as $mat) {
            // Tell AI service to remove from ChromaDB + indexed_documents
            if (!empty($mat->fileid)) {
                try {
                    $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($cfg['url'])]);
                    $client->setHeader([
                        'Authorization: Bearer ' . $cfg['token'],
                        'X-Request-Id: ' . local_umat_ai_request_id(),
                    ]);

                    $params = http_build_query([
                        'course_id'   => $courseid,
                        'filename'    => $mat->filename,
                    ]);

                    $client->delete($cfg['url'] . '/api/v1/materials/' . (int)$mat->fileid . '?' . $params);
                } catch (\Throwable $e) {
                    // Best effort — DB cleanup still happens below
                    debugging("AI material delete failed for fileid {$mat->fileid}: " . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }

            // Delete the material record from DB
            $DB->delete_records('umat_ai_materials', ['id' => $mat->id]);
            $deleted++;
        }

        // Log the event
        if ($DB->get_manager()->table_exists('umat_ai_activity_log')) {
            $DB->insert_record('umat_ai_activity_log', (object) [
                'userid'      => (int) ($data['userid'] ?? 0),
                'courseid'    => $courseid,
                'cmid'        => $cmid,
                'event_type'  => 'module_deleted',
                'event_data'  => json_encode([
                    'modulename'       => $modname,
                    'materials_deleted' => $deleted,
                ]),
                'timecreated' => time(),
            ]);
        }
    }
}
