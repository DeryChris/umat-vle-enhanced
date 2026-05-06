<?php
// ============================================================
// Admin settings page: Site Admin → Plugins → Local plugins → UMaT AI
// ============================================================

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_umat_ai', get_string('pluginname', 'local_umat_ai'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_umat_ai/ai_service_url',
        get_string('ai_service_url', 'local_umat_ai'),
        get_string('ai_service_url_desc', 'local_umat_ai'),
        'http://localhost:8000',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_umat_ai/ai_service_token',
        get_string('ai_service_token', 'local_umat_ai'),
        get_string('ai_service_token_desc', 'local_umat_ai'),
        ''
    ));

    // Gemini API Key (Google AI Studio).
    $settings->add(new admin_setting_configpasswordunmask(
        'local_umat_ai/google_api_key',
        get_string('google_api_key', 'local_umat_ai'),
        get_string('google_api_key_desc', 'local_umat_ai'),
        ''
    ));

    // Gemini model selection.
    $settings->add(new admin_setting_configselect(
        'local_umat_ai/llm_model',
        get_string('llm_model', 'local_umat_ai'),
        get_string('llm_model_desc', 'local_umat_ai'),
        'gemini-1.5-flash',
        [
            'gemini-1.5-flash' => 'Gemini 1.5 Flash (Recommended)',
            'gemini-1.5-pro'   => 'Gemini 1.5 Pro (More capable)',
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_umat_ai/require_approval',
        get_string('require_approval', 'local_umat_ai'),
        get_string('require_approval_desc', 'local_umat_ai'),
        1
    ));
}