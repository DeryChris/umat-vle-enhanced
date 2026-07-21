<?php
// ============================================================
// Event observer: fires when a course module is updated.
// Detects file replacements in resource/folder modules and
// re-indexes the new file into the AI service.
// ============================================================

namespace local_umat_ai\event;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

class material_updated {

    /**
     * Handle course_module_updated event.
     *
     * @param \core\event\course_module_updated $event
     */
    public static function handle_resource_updated(\core\event\course_module_updated $event): void {
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

        $fs        = get_file_storage();
        $cmcontext = \context_module::instance($cmid);
        $cfg       = local_umat_ai_get_service_config();

        $component = 'mod_' . $modname;
        $files = $fs->get_area_files(
            $cmcontext->id, $component, 'content', false, '', false
        );

        foreach ($files as $file) {
            if ($file->get_filesize() === 0) continue;

            $fileid   = $file->get_id();
            $filename = $file->get_filename();

            // Find existing record for this cmid + filename
            $existing = $DB->get_record('umat_ai_materials', [
                'courseid' => $courseid,
                'cmid'     => $cmid,
                'filename' => $filename,
            ]);

            // If the fileid changed, the file was replaced — re-index
            if ($existing && (int)$existing->fileid === $fileid && $existing->is_indexed) {
                continue; // Same file, already indexed — skip
            }

            // Delete old ChromaDB entry if the fileid changed
            if ($existing && (int)$existing->fileid !== $fileid && $existing->is_indexed) {
                try {
                    $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($cfg['url'])]);
                    $client->setHeader([
                        'Authorization: Bearer ' . $cfg['token'],
                        'X-Request-Id: ' . local_umat_ai_request_id(),
                    ]);
                    $params = http_build_query([
                        'course_id' => $courseid,
                        'filename'  => $filename,
                    ]);
                    $client->delete($cfg['url'] . '/api/v1/materials/' . (int)$existing->fileid . '?' . $params);
                } catch (\Throwable $e) {
                    // Best effort — will re-index below anyway
                    debugging("Old index cleanup failed for {$filename}: " . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }

            // Index the new/updated file
            $tempdir  = make_temp_directory('umat_ai_index');
            $filepath = $tempdir . '/' . $filename;
            try {
                $file->copy_content_to($filepath);

                $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($cfg['url'])]);
                $client->setHeader([
                    'Authorization: Bearer ' . $cfg['token'],
                    'X-Request-Id: ' . local_umat_ai_request_id(),
                ]);

                $postData = [
                    'course_id'   => (string)$courseid,
                    'material_id' => (string)$fileid,
                    'filename'    => $filename,
                    'file'        => new \CURLFile($filepath, $file->get_mimetype(), $filename),
                ];

                $raw      = $client->post($cfg['url'] . '/api/v1/materials/index', $postData);
                $response = @json_decode($raw, true);

                if (!empty($response['success'])) {
                    if ($existing) {
                        $existing->fileid      = $fileid;
                        $existing->is_indexed  = 1;
                        $existing->timeindexed = time();
                        $DB->update_record('umat_ai_materials', $existing);
                    } else {
                        $nameMatch = $DB->get_record('umat_ai_materials', [
                            'courseid' => $courseid,
                            'filename' => $filename,
                        ]);
                        if ($nameMatch) {
                            $nameMatch->fileid      = $fileid;
                            $nameMatch->cmid        = $cmid;
                            $nameMatch->is_indexed  = 1;
                            $nameMatch->timeindexed = time();
                            $DB->update_record('umat_ai_materials', $nameMatch);
                        } else {
                            $DB->insert_record('umat_ai_materials', (object)[
                                'courseid'    => $courseid,
                                'cmid'        => $cmid,
                                'fileid'      => $fileid,
                                'filename'    => $filename,
                                'is_indexed'  => 1,
                                'timeindexed' => time(),
                                'timecreated' => time(),
                            ]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                debugging("Re-index failed for {$filename}: " . $e->getMessage(), DEBUG_DEVELOPER);
            } finally {
                if (isset($filepath) && file_exists($filepath)) {
                    @unlink($filepath);
                }
            }
        }
    }
}
