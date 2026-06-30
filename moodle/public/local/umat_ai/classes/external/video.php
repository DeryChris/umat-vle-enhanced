<?php

namespace local_umat_ai\external;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_system;
use context_course;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class video extends external_api {

    /**
     * Trigger AI video generation for a course material.
     * POST /api/v1/video/generate
     */
    public static function request_video_generation_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'fileid'   => new external_value(PARAM_INT, 'Moodle file ID'),
        ]);
    }

    public static function request_video_generation(int $courseid, int $fileid): array {
        global $DB, $CFG, $USER;

        $params = self::validate_parameters(self::request_video_generation_parameters(), [
            'courseid' => $courseid,
            'fileid'   => $fileid,
        ]);
        $courseid = $params['courseid'];
        $fileid   = $params['fileid'];

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        // Find or auto-create material record.
        $material = $DB->get_record('umat_ai_materials', ['fileid' => $fileid, 'courseid' => $courseid]);
        if (!$material) {
            $fs = get_file_storage();
            $file = $fs->get_file_by_id($fileid);
            if (!$file) {
                return ['success' => false, 'message' => 'File not found in course.'];
            }
            $now = time();
            $material = new \stdClass();
            $material->courseid   = $courseid;
            $material->fileid     = $fileid;
            $material->filename   = $file->get_filename();
            $material->is_indexed = 0;
            $material->timecreated = $now;
            $material->id = $DB->insert_record('umat_ai_materials', $material);
        }

        $material_id = $material->id;
        $filename = $material->filename;

        // Read file content directly via Moodle file API (avoids pluginfile auth issues).
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($fileid);
        if (!$file) {
            return ['success' => false, 'message' => 'File not available.'];
        }
        $filecontent = base64_encode($file->get_content());
        $filemime = $file->get_mimetype() ?: 'application/octet-stream';

        $config = get_config('local_umat_ai');
        $ai_url = rtrim($config->ai_service_url ?? 'http://localhost:8000', '/');
        $token  = $config->ai_service_token ?? '';

        $payload = json_encode([
            'material_id'  => $material_id,
            'course_id'    => $courseid,
            'file_content' => $filecontent,
            'file_mime'    => $filemime,
            'filename'     => $filename,
        ]);

        $ch = curl_init($ai_url . '/api/v1/video/generate');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200) {
            return ['success' => false, 'message' => 'AI service error (HTTP ' . $httpcode . '): ' . substr($response, 0, 200)];
        }

        $data = json_decode($response, true);
        if (!$data || empty($data['job_id'])) {
            return ['success' => false, 'message' => 'Invalid response from AI service.'];
        }

        $now = time();
        $record = new \stdClass();
        $record->materialid   = $material_id;
        $record->courseid     = $courseid;
        $record->job_id       = $data['job_id'];
        $record->status       = 'queued';
        $record->timecreated  = $now;
        $record->timemodified = $now;
        $DB->insert_record('umat_ai_videos', $record);

        return [
            'success' => true,
            'job_id'  => $data['job_id'],
            'message' => 'Video generation queued.',
        ];
    }

    public static function request_video_generation_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the request succeeded'),
            'job_id'  => new external_value(PARAM_RAW, 'Job ID for polling', VALUE_OPTIONAL, ''),
            'message' => new external_value(PARAM_RAW, 'Status message'),
        ]);
    }

    /**
     * Get video generation status for materials in a course.
     */
    public static function get_video_status_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    public static function get_video_status(int $courseid): array {
        global $DB;

        $params = self::validate_parameters(self::get_video_status_parameters(), ['courseid' => $courseid]);
        $courseid = $params['courseid'];

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        $config = get_config('local_umat_ai');
        $ai_url = rtrim($config->ai_service_url ?? 'http://localhost:8000', '/');
        $token  = $config->ai_service_token ?? '';

        $materials = $DB->get_records('umat_ai_materials', ['courseid' => $courseid]);
        $videos = $DB->get_records('umat_ai_videos', ['courseid' => $courseid], 'timecreated DESC');
        $vlookup = [];
        foreach ($videos as $v) {
            if (!isset($vlookup[$v->materialid])) {
                $vlookup[$v->materialid] = $v;
            }
        }

        $result = [];

        foreach ($materials as $m) {
            $entry = [
                'material_id' => (int)$m->id,
                'fileid'      => (int)$m->fileid,
                'filename'    => $m->filename,
                'has_video'   => false,
                'job_status'  => null,
                'video_url'   => null,
            ];

            $existing = $vlookup[$m->id] ?? null;
            if ($existing && !empty($existing->video_url)) {
                $entry['has_video']  = true;
                $entry['job_status'] = 'completed';
                $entry['video_url']  = $existing->video_url;
            } elseif ($existing && !empty($existing->job_id)) {
                $entry['job_status'] = $existing->status;
                if ($existing->status === 'queued' || $existing->status === 'processing') {
                    $poll = self::poll_ai_status($existing->job_id, $ai_url, $token);
                    if ($poll) {
                        $entry['job_status'] = $poll['status'];
                        if ($poll['status'] === 'completed' && !empty($poll['video_url'])) {
                            $entry['has_video']  = true;
                            $entry['video_url']  = $poll['video_url'];
                            $upd = new \stdClass();
                            $upd->id           = $existing->id;
                            $upd->status       = 'completed';
                            $upd->video_url    = $poll['video_url'];
                            $upd->timemodified = time();
                            $DB->update_record('umat_ai_videos', $upd);
                        } elseif ($poll['status'] === 'failed') {
                            $entry['job_status'] = 'failed';
                        }
                    }
                }
            }

            $result[] = $entry;
        }

        return ['materials' => $result];
    }

    private static function poll_ai_status(string $job_id, string $ai_url, string $token): ?array {
        $ch = curl_init($ai_url . '/api/v1/video/status/' . urlencode($job_id));
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        if (!$data || empty($data['status'])) {
            return null;
        }

        $url = null;
        if ($data['status'] === 'completed' && !empty($data['video_url'])) {
            $url = rtrim($ai_url, '/') . $data['video_url'];
        }

        return [
            'status'    => $data['status'],
            'video_url' => $url,
        ];
    }

    public static function get_video_status_returns(): external_single_structure {
        return new external_single_structure([
            'materials' => new \external_multiple_structure(
                new external_single_structure([
                    'material_id' => new external_value(PARAM_INT, 'Material ID'),
                    'fileid'      => new external_value(PARAM_INT, 'File ID'),
                    'filename'    => new external_value(PARAM_RAW, 'Original filename'),
                    'has_video'   => new external_value(PARAM_BOOL, 'Whether a video has been generated'),
                    'job_status'  => new external_value(PARAM_RAW, 'Job status if in progress', VALUE_OPTIONAL, null),
                    'video_url'   => new external_value(PARAM_RAW, 'URL to the generated video', VALUE_OPTIONAL, null),
                ])
            ),
        ]);
    }
}
