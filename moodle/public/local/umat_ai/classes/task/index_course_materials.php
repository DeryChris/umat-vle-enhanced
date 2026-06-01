<?php
/**
 * Scheduled task: Auto-discover course files and index them into ChromaDB for Q&A.
 * Does NOT trigger detailed analysis (that remains manual).
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\task;

defined('MOODLE_INTERNAL') || die();

class index_course_materials extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('pluginname', 'local_umat_ai') . ': Index Course Materials';
    }

    public function execute(): void {
        global $DB;

        mtrace("  [umat_ai] Starting course material indexing...");

        // Get all courses that have at least one umat_ai_materials record
        // (i.e. courses where the plugin is active)
        $courseids = $DB->get_fieldset_sql(
            "SELECT DISTINCT courseid FROM {umat_ai_materials}"
        );

        if (empty($courseids)) {
            mtrace("  [umat_ai] No courses found with existing materials. Skipping.");
            return;
        }

        $indexed = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($courseids as $courseid) {
            try {
                $result = $this->index_course($courseid);
                $indexed += $result['indexed'];
                $skipped += $result['skipped'];
                $failed  += $result['failed'];
            } catch (\Exception $e) {
                mtrace("  [umat_ai] Error indexing course {$courseid}: " . $e->getMessage());
                $failed++;
            }
        }

        mtrace("  [umat_ai] Indexing complete: {$indexed} indexed, {$skipped} skipped, {$failed} failed.");
    }

    /**
     * Index all unindexed files in a course.
     */
    private function index_course(int $courseid): array {
        global $DB;

        $result = ['indexed' => 0, 'skipped' => 0, 'failed' => 0];

        // Get existing indexed file IDs to skip duplicates
        $existing = $DB->get_fieldset_sql(
            "SELECT fileid FROM {umat_ai_materials} WHERE courseid = :cid",
            ['cid' => $courseid]
        );
        $existingFileIds = array_flip($existing);

        // Discover files via Moodle File API (mirrors get_course_materials logic)
        $course = get_course($courseid);
        $courseCtx = \context_course::instance($courseid);
        $fs = get_file_storage();

        $fileSources = [
            ['component' => 'mod_resource', 'filearea' => 'content'],
            ['component' => 'mod_folder',   'filearea' => 'content'],
            ['component' => 'course',       'filearea' => 'legacy'],
            ['component' => 'local_umat_ai', 'filearea' => 'materials'],
        ];

        // Collect all module contexts for this course
        $modinfo = get_fast_modinfo($course);
        $contexts = [$courseCtx];
        foreach ($modinfo->get_cms() as $cm) {
            $contexts[] = \context_module::instance($cm->id);
        }

        $seen = [];

        foreach ($contexts as $ctx) {
            foreach ($fileSources as $src) {
                $files = $fs->get_area_files(
                    $ctx->id, $src['component'], $src['filearea'],
                    false, 'timemodified DESC', false
                );
                foreach ($files as $file) {
                    $hash = $file->get_pathnamehash();
                    if (isset($seen[$hash])) continue;
                    $seen[$hash] = true;

                    if ($file->get_filesize() === 0) continue;

                    // Skip already-indexed files
                    if (isset($existingFileIds[$file->get_id()])) {
                        $result['skipped']++;
                        continue;
                    }

                    // Index this file
                    try {
                        $this->send_to_ai_service($file, $courseid);
                        $result['indexed']++;

                        // Record in umat_ai_materials
                        $record = (object)[
                            'courseid'   => $courseid,
                            'cmid'       => 0,
                            'fileid'     => $file->get_id(),
                            'filename'   => $file->get_filename(),
                            'is_indexed' => 1,
                            'timeindexed' => time(),
                            'timecreated' => time(),
                        ];
                        $DB->insert_record('umat_ai_materials', $record);

                        mtrace("  [umat_ai]   Indexed: {$file->get_filename()} (course {$courseid})");

                    } catch (\Exception $e) {
                        mtrace("  [umat_ai]   Failed: {$file->get_filename()} - " . $e->getMessage());
                        $result['failed']++;
                    }

                    // Limit per run to avoid timeout
                    if ($result['indexed'] >= 20) {
                        mtrace("  [umat_ai] Reached batch limit (20). Remaining files will be indexed next run.");
                        return $result;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Upload a file to the AI service for ChromaDB indexing.
     */
    private function send_to_ai_service(\stored_file $file, int $courseid): void {
        $cfg = local_umat_ai_get_service_config();
        $url  = $cfg['url'] . '/api/v1/materials/index';
        $materialId = $file->get_id();

        // Save file to temp directory for upload
        $tempdir  = make_temp_directory('umat_ai_index');
        $filepath = $tempdir . '/' . $file->get_filename();
        $file->copy_content_to($filepath);

        try {
            $client = new \curl(['ignoresecurity' => true]);
            $client->setHeader(['Authorization: Bearer ' . $cfg['token']]);
            $client->setopt(['CURLOPT_TIMEOUT' => 120]);

            $postData = [
                'course_id'   => (string)$courseid,
                'material_id' => (string)$materialId,
                'filename'    => $file->get_filename(),
                'file'        => new \CURLFile($filepath, $file->get_mimetype(), $file->get_filename()),
            ];

            $raw = $client->post($url, $postData);
            $response = @json_decode($raw, true);

            if (!$response || empty($response['success'])) {
                $detail = $response['detail'] ?? $response['message'] ?? 'Unknown error';
                throw new \moodle_exception('indexfailed', 'local_umat_ai', '', $detail);
            }
        } finally {
            if (file_exists($filepath)) unlink($filepath);
        }
    }
}
