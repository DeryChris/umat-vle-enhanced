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

require_once(__DIR__ . '/../../lib.php');

class index_course_materials extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('pluginname', 'local_umat_ai') . ': Index Course Materials';
    }

    public function execute(): void {
        global $DB;

        mtrace("  [umat_ai] Starting course material indexing...");

        // All real courses — not just ones with existing umat_ai_materials
        // records, otherwise a course is never indexed until something
        // else creates its first record (chicken-and-egg).
        $courseids = $DB->get_fieldset_sql(
            "SELECT id FROM {course} WHERE id <> :siteid AND visible = 1",
            ['siteid' => SITEID]
        );

        if (empty($courseids)) {
            mtrace("  [umat_ai] No courses found. Skipping.");
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

        // Existing records keyed by fileid. Files marked is_indexed=0 are
        // re-sent (failed first attempts, or a deliberate re-index after an
        // LLM provider switch: UPDATE {umat_ai_materials} SET is_indexed=0).
        // fileid=0 rows come from manual uploads in materials.php and are
        // not discoverable via the File API, so they are ignored here.
        $existingRecords = [];
        foreach ($DB->get_records('umat_ai_materials', ['courseid' => $courseid]) as $rec) {
            if ($rec->fileid > 0) $existingRecords[$rec->fileid] = $rec;
        }

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

        // Collect all module contexts for this course with CM ID mapping
        $modinfo = get_fast_modinfo($course);
        $contextMap = [$courseCtx->id => 0]; // course context has no cmid
        $contexts = [$courseCtx];
        foreach ($modinfo->get_cms() as $cm) {
            $cmCtx = \context_module::instance($cm->id);
            $contexts[] = $cmCtx;
            $contextMap[$cmCtx->id] = $cm->id;
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

                    // Skip only files that are recorded AND successfully indexed.
                    $existing = $existingRecords[$file->get_id()] ?? null;
                    if ($existing && $existing->is_indexed) {
                        $result['skipped']++;
                        continue;
                    }

                    // Index (or re-index) this file
                    try {
                        $this->send_to_ai_service($file, $courseid);
                        $result['indexed']++;

                        if ($existing) {
                            $existing->is_indexed  = 1;
                            $existing->cmid        = $contextMap[$ctx->id] ?? $existing->cmid;
                            $existing->timeindexed = time();
                            $DB->update_record('umat_ai_materials', $existing);
                        } else {
                            $DB->insert_record('umat_ai_materials', (object)[
                                'courseid'    => $courseid,
                                'cmid'        => $contextMap[$ctx->id] ?? 0,
                                'fileid'      => $file->get_id(),
                                'filename'    => $file->get_filename(),
                                'is_indexed'  => 1,
                                'timeindexed' => time(),
                                'timecreated' => time(),
                            ]);
                        }

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
            $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($cfg['url'])]);
            $client->setHeader(['Authorization: Bearer ' . $cfg['token'], 'X-Request-Id: ' . local_umat_ai_request_id()]);
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
