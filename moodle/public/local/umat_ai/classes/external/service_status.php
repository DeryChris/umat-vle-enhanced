<?php
/**
 * External API: AI service availability check.
 * Pings the AI service health endpoint server-side so the browser never
 * needs to know the service URL. Drives the online/offline indicator in
 * the chat UI.
 *
 * @package    local_umat_ai
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/../../lib.php');

class service_status extends \external_api {

    public static function ping_parameters() {
        return new \external_function_parameters([]);
    }

    public static function ping() {
        self::validate_context(\context_system::instance());

        $cfg    = \local_umat_ai_get_service_config();
        $client = new \curl(['ignoresecurity' => \local_umat_ai_is_localhost($cfg['url'])]);
        $client->setHeader(['X-Request-Id: ' . \local_umat_ai_request_id()]);
        // Short timeouts — this is called from the UI, so fail fast.
        $client->setopt(['CURLOPT_TIMEOUT' => 5, 'CURLOPT_CONNECTTIMEOUT' => 3]);

        $raw  = $client->get($cfg['url'] . '/api/v1/health');
        $data = json_decode($raw, true);

        return ['online' => !empty($data['status']) && $data['status'] === 'healthy'];
    }

    public static function ping_returns() {
        return new \external_single_structure([
            'online' => new \external_value(PARAM_BOOL, 'True when the AI service health check succeeds'),
        ]);
    }
}
