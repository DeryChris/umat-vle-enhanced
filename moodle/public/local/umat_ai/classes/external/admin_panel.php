<?php

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

class admin_panel extends \external_api {

    const MASKED_KEYS = ['ai_service_token', 'gemini_api_key', 'bearer_token'];

    public static function get_config_parameters() {
        return new \external_function_parameters([]);
    }

    public static function get_config() {
        self::validate_parameters(self::get_config_parameters(), []);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/umat_ai:adminpanel', $context);

        $config = get_config('local_umat_ai');
        $result = [];
        foreach ($config as $key => $value) {
            if (in_array($key, self::MASKED_KEYS) && !empty($value)) {
                $result[$key] = '********';
            } else {
                $result[$key] = $value;
            }
        }
        return [
            'status' => 'ok',
            'config_json' => json_encode($result),
        ];
    }

    public static function get_config_returns() {
        return new \external_single_structure([
            'status' => new \external_value(PARAM_TEXT, 'always ok'),
            'config_json' => new \external_value(PARAM_RAW, 'JSON-encoded config object'),
        ]);
    }


    public static function save_config_parameters() {
        return new \external_function_parameters([
            'settings_json' => new \external_value(PARAM_RAW, 'JSON object of settings'),
        ]);
    }

    public static function save_config($settings_json) {
        self::validate_parameters(self::save_config_parameters(), ['settings_json' => $settings_json]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/umat_ai:adminpanel', $context);

        $settings = json_decode($settings_json, true);
        if (!is_array($settings)) {
            throw new \moodle_exception('invalidjson', 'local_umat_ai');
        }

        foreach ($settings as $key => $value) {
            if (in_array($key, self::MASKED_KEYS) && $value === '********') {
                continue;
            }
            set_config($key, $value, 'local_umat_ai');
        }
        return ['status' => 'success'];
    }

    public static function save_config_returns() {
        return new \external_single_structure([
            'status' => new \external_value(PARAM_TEXT, 'success or error'),
        ]);
    }


    public static function execute_action_parameters() {
        return new \external_function_parameters([
            'action' => new \external_value(PARAM_ALPHAEXT, 'Action name'),
        ]);
    }

    public static function execute_action($action) {
        self::validate_parameters(self::execute_action_parameters(), ['action' => $action]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/umat_ai:adminpanel', $context);

        switch ($action) {
            case 'clear_ai_cache':
                $cfg = local_umat_ai_get_service_config();
                $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($cfg['url'])]);
                $client->setHeader([
                    'Authorization: Bearer ' . $cfg['token'],
                    'X-Request-Id: ' . local_umat_ai_request_id(),
                ]);
                $client->post($cfg['url'] . '/api/v1/admin/clear-cache');
                return ['status' => 'success', 'message' => 'AI semantic cache cleared.'];

            case 'trigger_index':
                \core\task\manager::queue_adhoc_task(new \local_umat_ai\task\index_course_materials());
                return ['status' => 'success', 'message' => 'Material indexing triggered.'];

            case 'purge_moodle_cache':
                \cache_helper::purge_all();
                return ['status' => 'success', 'message' => 'Moodle caches purged.'];

            case 'purge_theme_cache':
                \theme_reset_all_caches();
                return ['status' => 'success', 'message' => 'Theme caches purged.'];

            case 'trigger_cron':
                \core\task\manager::run_from_cli();
                return ['status' => 'success', 'message' => 'Cron triggered.'];

            default:
                throw new \moodle_exception('invalidaction', 'local_umat_ai', '', $action);
        }
    }

    public static function execute_action_returns() {
        return new \external_single_structure([
            'status'  => new \external_value(PARAM_TEXT, 'success or error'),
            'message' => new \external_value(PARAM_TEXT, 'Human-readable result'),
        ]);
    }


    public static function system_health_parameters() {
        return new \external_function_parameters([]);
    }

    public static function system_health() {
        self::validate_parameters(self::system_health_parameters(), []);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/umat_ai:adminpanel', $context);

        $cfg  = local_umat_ai_get_service_config();
        $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($cfg['url'])]);
        $client->setHeader([
            'Authorization: Bearer ' . $cfg['token'],
            'X-Request-Id: ' . local_umat_ai_request_id(),
        ]);
        $client->setopt(['CURLOPT_TIMEOUT' => 5, 'CURLOPT_CONNECTTIMEOUT' => 3]);

        $start = microtime(true);
        $raw   = $client->get($cfg['url'] . '/api/v1/admin/health');
        $latency = round((microtime(true) - $start) * 1000);
        $data  = json_decode($raw, true);

        $online  = !empty($data['status']) && $data['status'] === 'healthy';

        global $DB;
        $cronlast = $DB->get_field_sql('SELECT MAX(lastruntime) FROM {task_scheduled}');
        $cronfresh = $cronlast && (time() - (int)$cronlast) < 600;

        return [
            'online'          => $online,
            'latency_ms'      => $latency,
            'chroma_collections' => (int)($data['chroma_collections'] ?? 0),
            'chroma_documents'   => (int)($data['chroma_total_documents'] ?? 0),
            'python_memory_mb'   => (float)($data['python_memory_mb'] ?? 0),
            'cron_last_run'   => $cronlast ? (int)$cronlast : 0,
            'cron_fresh'      => $cronfresh,
            'plugin_version'  => get_config('local_umat_ai', 'version') ?: 'unknown',
        ];
    }

    public static function system_health_returns() {
        return new \external_single_structure([
            'online'            => new \external_value(PARAM_BOOL, 'AI service reachable'),
            'latency_ms'        => new \external_value(PARAM_INT, 'Response time in ms'),
            'chroma_collections'=> new \external_value(PARAM_INT, 'Number of ChromaDB collections'),
            'chroma_documents'  => new \external_value(PARAM_INT, 'Total documents in ChromaDB'),
            'python_memory_mb'  => new \external_value(PARAM_FLOAT, 'Python process memory'),
            'cron_last_run'     => new \external_value(PARAM_INT, 'Unix ts of last cron'),
            'cron_fresh'        => new \external_value(PARAM_BOOL, 'Cron ran within 10 min'),
            'plugin_version'    => new \external_value(PARAM_TEXT, 'Plugin version string'),
        ]);
    }
}
