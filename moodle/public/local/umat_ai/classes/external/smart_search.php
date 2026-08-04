<?php
/**
 * External API: Smart Search over a course's indexed materials (F2).
 * Proxies to the AI service POST /api/v1/search, enforces course context +
 * capability + accessible-material filtering, and re-scopes results to what
 * the caller is allowed to see.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');
require_once(__DIR__ . '/../../lib.php');

class smart_search extends \external_api {

    public static function smart_search_parameters() {
        return new \external_function_parameters([
            'courseid'     => new \external_value(PARAM_INT,  'Course ID'),
            'query'        => new \external_value(PARAM_TEXT, 'Search query', VALUE_REQUIRED, '', 2, 500),
            'material_ids' => new \external_multiple_structure(
                new \external_value(PARAM_INT, 'Material ID'),
                'Restrict search to these material IDs — empty means all accessible materials',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    public static function smart_search($courseid, $query, $material_ids = []) {
        global $DB, $USER;

        $params = self::validate_parameters(self::smart_search_parameters(), [
            'courseid'     => $courseid,
            'query'        => $query,
            'material_ids' => $material_ids,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);

        // Role + capability gate: lecturers may use Smart Search via the
        // analytics capability; students need chatwithai.
        $islecturer = local_umat_ai_is_lecturer($params['courseid']);
        if ($islecturer) {
            require_capability('local/umat_ai:viewanalytics', $context);
            $role = 'lecturer';
        } else {
            require_capability('local/umat_ai:chatwithai', $context);
            $role = 'student';
        }

        // Student-side rate limit (AI service enforces its own too).
        if ($role !== 'lecturer') {
            $ratelimit = (int) get_config('local_umat_ai', 'rate_limit') ?: 10;
            $remaining = \local_umat_ai_questions_remaining($USER->id, $ratelimit);
            if ($remaining <= 0) {
                return ['results' => [], 'error' => 'rate_limit', 'remaining' => 0];
            }
        }

        // Security boundary: only search materials the caller can access.
        $mids = array_map('intval', (array) ($params['material_ids'] ?? []));
        $hadselection = !empty($mids);
        $accessible = \local_umat_ai_filter_accessible_materials(
            $params['courseid'], $USER->id,
            $hadselection ? $mids : null
        );

        $cfg    = \local_umat_ai_get_service_config();
        $client = new \curl(['ignoresecurity' => \local_umat_ai_is_localhost($cfg['url'])]);
        $client->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['token'],
            'X-Request-Id: ' . \local_umat_ai_request_id(),
        ]);
        $client->setopt(['CURLOPT_TIMEOUT' => 30]);

        $req = [
            'course_id' => (int) $params['courseid'],
            'user_id'   => (int) $USER->id,
            'role'      => $role,
            'query'     => $params['query'],
        ];
        if (!empty($accessible)) {
            $req['material_ids'] = $accessible;
        } elseif ($hadselection) {
            $req['material_ids'] = [];
        }

        $raw = $client->post($cfg['url'] . '/api/v1/search', json_encode($req));
        $result = json_decode($raw, true);

        $rresults = $result['results'] ?? [];
        if (!$hadselection && $role !== 'lecturer' && !empty($rresults)) {
            // Re-scope to accessible materials as a second boundary (AI service
            // already applies ChromaDB visibility — this guards material-id lean).
            $accessset = array_flip($accessible);
            $rresults = array_values(array_filter($rresults, function ($r) use ($accessset) {
                $mid = (int) ($r['citation']['material_id'] ?? 0);
                return isset($accessset[$mid]);
            }));
        }

        return ['results' => $rresults, 'error' => '', 'remaining' => ($role === 'lecturer') ? -1 : (int) ($remaining ?? -1)];
    }

    public static function smart_search_returns() {
        $citationstruct = new \external_single_structure([
            'index'        => new \external_value(PARAM_INT),
            'title'        => new \external_value(PARAM_TEXT),
            'material_id'  => new \external_value(PARAM_INT),
            'chunk_index'  => new \external_value(PARAM_INT, '', VALUE_DEFAULT, 0),
            'snippet'      => new \external_value(PARAM_TEXT, '', VALUE_DEFAULT, ''),
            'location'     => new \external_value(PARAM_TEXT, '', VALUE_DEFAULT, ''),
            'source_type'  => new \external_value(PARAM_TEXT, '', VALUE_DEFAULT, ''),
            'session_id'   => new \external_value(PARAM_TEXT, '', VALUE_DEFAULT, ''),
            'score'        => new \external_value(PARAM_FLOAT, '', VALUE_DEFAULT, 0),
            'visibility'   => new \external_value(PARAM_TEXT, '', VALUE_DEFAULT, ''),
        ]);
        return new \external_single_structure([
            'results' => new \external_multiple_structure(
                new \external_single_structure([
                    'chunk'     => new \external_value(PARAM_RAW, 'Matching chunk text'),
                    'citation'  => $citationstruct,
                    'score'     => new \external_value(PARAM_FLOAT, '', VALUE_DEFAULT, 0),
                ]),
                'Ranked search results',
                VALUE_DEFAULT,
                []
            ),
            'error'     => new \external_value(PARAM_TEXT, '', VALUE_DEFAULT, ''),
            'remaining' => new \external_value(PARAM_INT, 'Questions remaining (students)', VALUE_DEFAULT, -1),
        ]);
    }
}