<?php
// ============================================================
// Event observer: fires when a course module is created
// Immediately indexes the uploaded file into the AI service for Q&A.
// ============================================================

namespace local_umat_ai\event;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

class material_uploaded {

    public static function handle_resource_created(\core\event\course_module_created $event): void {
        global $DB;

        $data     = $event->get_data();
        $courseid = (int) $data['courseid'];
        $cmid     = (int) ($data['contextinstanceid'] ?? 0);
        $modname  = $data['other']['modulename'] ?? '';

        // Only index resource and folder modules
        if (!in_array($modname, ['resource', 'folder'], true)) {
            return;
        }

        // Only proceed if the plugin tables exist
        if (!$DB->get_manager()->table_exists('umat_ai_materials') ||
            !$DB->get_manager()->table_exists('umat_ai_activity_log')) {
            return;
        }

        $fs       = get_file_storage();
        $cmcontext = \context_module::instance($cmid);
        $cfg       = local_umat_ai_get_service_config();
        $indexed   = 0;

        // Determine file areas based on module type
        $fileareas = $modname === 'folder' ? ['content'] : ['content'];
        $component = 'mod_' . $modname;

        foreach ($fileareas as $filearea) {
            $files = $fs->get_area_files(
                $cmcontext->id, $component, $filearea, false, '', false
            );
            foreach ($files as $file) {
                if ($file->get_filesize() === 0) continue;

                $fileid   = $file->get_id();
                $filename = $file->get_filename();

                // Check if already recorded and indexed
                $existing = $DB->get_record('umat_ai_materials', [
                    'courseid' => $courseid,
                    'fileid'   => $fileid,
                ]);
                if ($existing && $existing->is_indexed) {
                    continue;
                }

                // Save to temp and send to AI service
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

                    if ($response && !empty($response['success'])) {
                        if ($existing) {
                            $existing->is_indexed  = 1;
                            $existing->cmid        = $cmid;
                            $existing->timeindexed = time();
                            $DB->update_record('umat_ai_materials', $existing);
                        } else {
                            // Dedup by (courseid, filename) — avoid duplicate rows for same file
                            $nameMatch = $DB->get_record('umat_ai_materials', [
                                'courseid' => $courseid,
                                'filename' => $filename,
                            ]);
                            if ($nameMatch) {
                                $nameMatch->fileid      = $fileid;
                                $nameMatch->is_indexed  = 1;
                                $nameMatch->cmid        = $cmid;
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
                        $indexed++;
                    }
                } catch (\Throwable $e) {
                    debugging("Auto-index failed for {$filename}: " . $e->getMessage(), DEBUG_DEVELOPER);
                } finally {
                    if (isset($filepath) && file_exists($filepath)) {
                        unlink($filepath);
                    }
                }
            }
        }

        // Log the event
        $DB->insert_record('umat_ai_activity_log', (object) [
            'userid'      => (int) ($data['userid'] ?? 0),
            'courseid'    => $courseid,
            'cmid'        => $cmid ?: null,
            'event_type'  => 'module_created',
            'event_data'  => json_encode([
                'modulename' => $modname,
                'files_indexed' => $indexed,
            ]),
            'timecreated' => time(),
        ]);
    }
}
