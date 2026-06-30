<?php
/**
 * External API: Material analysis status, trigger, and sync.
 *
 * @package    local_umat_ai
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

class analysis extends \external_api {

    // ------------------------------------------------------------------ //
    // get_analysis_status — returns analysis status for course materials   //
    // ------------------------------------------------------------------ //

    public static function get_analysis_status_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    public static function get_analysis_status($courseid) {
        global $DB;

        $params = self::validate_parameters(self::get_analysis_status_parameters(), [
            'courseid' => $courseid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        // Get all materials for this course
        $materials = $DB->get_records('umat_ai_materials', ['courseid' => $params['courseid']]);
        $materialIds = array_keys($materials);

        // Get latest analysis per material
        $analyses = [];
        if (!empty($materialIds)) {
            list($insql, $inparams) = $DB->get_in_or_equal($materialIds, SQL_PARAMS_NAMED);
            $sql = "SELECT a.*
                      FROM {umat_ai_analysis} a
                      JOIN (SELECT materialid, MAX(timecreated) AS maxts
                              FROM {umat_ai_analysis}
                             WHERE materialid $insql
                             GROUP BY materialid) latest
                        ON a.materialid = latest.materialid AND a.timecreated = latest.maxts
                     ORDER BY a.timecreated DESC";
            $analyses = $DB->get_records_sql($sql, $inparams);
        }

        $result = [];
        foreach ($materials as $mat) {
            $file = $DB->get_record('files', ['id' => $mat->fileid], 'id, filename, mimetype, filesize');
            $analysis = $analyses[$mat->id] ?? null;
            $result[] = [
                'material_id'     => (int)$mat->id,
                'fileid'          => (int)$mat->fileid,
                'filename'        => $file ? $file->filename : $mat->filename,
                'mimetype'        => $file ? $file->mimetype : '',
                'filesize'        => $file ? (int)$file->filesize : 0,
                'is_indexed'      => (bool)$mat->is_indexed,
                'is_analyzed'     => $analysis ? true : false,
                'analysis_types'  => $analysis ? [$analysis->analysis_type] : [],
                'last_analysis'   => $analysis ? [
                    'id'            => (int)$analysis->id,
                    'analysis_type' => $analysis->analysis_type,
                    'scope'         => $analysis->scope,
                    'status'        => $analysis->status,
                    'model_version' => $analysis->model_version ?? '',
                    'token_count'   => (int)($analysis->token_count ?? 0),
                    'summary'       => $analysis->summary ?? '',
                    'timecreated'   => (int)$analysis->timecreated,
                ] : null,
            ];
        }

        return ['materials' => $result];
    }

    public static function get_analysis_status_returns() {
        return new \external_single_structure([
            'materials' => new \external_multiple_structure(
                new \external_single_structure([
                    'material_id'    => new \external_value(PARAM_INT),
                    'fileid'         => new \external_value(PARAM_INT),
                    'filename'       => new \external_value(PARAM_TEXT),
                    'mimetype'       => new \external_value(PARAM_TEXT),
                    'filesize'       => new \external_value(PARAM_INT),
                    'is_indexed'     => new \external_value(PARAM_BOOL),
                    'is_analyzed'    => new \external_value(PARAM_BOOL),
                    'analysis_types' => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT)
                    ),
                    'last_analysis'  => new \external_single_structure([
                        'id'            => new \external_value(PARAM_INT),
                        'analysis_type' => new \external_value(PARAM_TEXT),
                        'scope'         => new \external_value(PARAM_TEXT),
                        'status'        => new \external_value(PARAM_TEXT),
                        'model_version' => new \external_value(PARAM_TEXT),
                        'token_count'   => new \external_value(PARAM_INT),
                        'summary'       => new \external_value(PARAM_RAW),
                        'timecreated'   => new \external_value(PARAM_INT),
                    ], 'last_analysis', VALUE_OPTIONAL),
                ])
            ),
        ]);
    }

    // ------------------------------------------------------------------ //
    // request_analysis — triggers analysis on the AI service              //
    // ------------------------------------------------------------------ //

    public static function request_analysis_parameters() {
        return new \external_function_parameters([
            'courseid'      => new \external_value(PARAM_INT, 'Course ID'),
            'material_id'   => new \external_value(PARAM_INT, 'umat_ai_materials.id'),
            'analysis_type' => new \external_value(PARAM_TEXT, 'full_analysis|summary|key_concepts|quiz|custom', VALUE_DEFAULT, 'full_analysis'),
            'scope'         => new \external_value(PARAM_TEXT, 'null=full, pages:N-M, sections:...', VALUE_DEFAULT, ''),
            'force'         => new \external_value(PARAM_BOOL, 'Skip cache and re-analyze', VALUE_DEFAULT, false),
        ]);
    }

    public static function request_analysis($courseid, $material_id, $analysis_type = 'full_analysis', $scope = '', $force = false) {
        global $DB;

        $params = self::validate_parameters(self::request_analysis_parameters(), [
            'courseid'      => $courseid,
            'material_id'   => $material_id,
            'analysis_type' => $analysis_type,
            'scope'         => $scope,
            'force'         => $force,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        // Get material record
        $material = $DB->get_record('umat_ai_materials', ['id' => $params['material_id'], 'courseid' => $params['courseid']]);
        if (!$material) {
            throw new \moodle_exception('invalidmaterial', 'local_umat_ai');
        }

        // Get file record for URL + mimetype
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($material->fileid);
        if (!$file) {
            throw new \moodle_exception('filenotfound', 'local_umat_ai');
        }

        $fileurl = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        )->out(false);

        // Call AI service
        $cfg = local_umat_ai_get_service_config();
        $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($cfg['url'])]);
        $client->setHeader(['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['token'], 'X-Request-Id: ' . local_umat_ai_request_id()]);
        $client->setopt(['CURLOPT_TIMEOUT' => 120]);

        $payload = [
            'material_id'   => (int)$params['material_id'],
            'course_id'     => (int)$params['courseid'],
            'file_url'      => $fileurl,
            'filename'      => $file->get_filename(),
            'analysis_type' => $params['analysis_type'],
            'force'         => (bool)$params['force'],
            'scope'         => $params['scope'] ?: null,
        ];

        $raw = $client->post($cfg['url'] . '/api/v1/materials/analyze', json_encode($payload));
        $result = @json_decode($raw, true);

        if (!$result || !empty($result['detail'])) {
            $msg = $result['detail'] ?? 'AI service unavailable';
            return ['success' => false, 'analysis_id' => 0, 'cached' => false, 'error' => $msg];
        }

        return [
            'success'     => true,
            'analysis_id' => (int)($result['analysis_id'] ?? 0),
            'cached'      => !empty($result['cached']),
            'error'       => '',
        ];
    }

    public static function request_analysis_returns() {
        return new \external_single_structure([
            'success'     => new \external_value(PARAM_BOOL),
            'analysis_id' => new \external_value(PARAM_INT),
            'cached'      => new \external_value(PARAM_BOOL),
            'error'       => new \external_value(PARAM_TEXT),
        ]);
    }
}
