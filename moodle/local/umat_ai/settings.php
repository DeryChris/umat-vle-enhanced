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

    $settings->add(new admin_setting_configtext(
        'local_umat_ai/openai_api_key',
        get_string('openai_api_key', 'local_umat_ai'),
        get_string('openai_api_key_desc', 'local_umat_ai'),
        '',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configselect(
        'local_umat_ai/llm_model',
        get_string('llm_model', 'local_umat_ai'),
        get_string('llm_model_desc', 'local_umat_ai'),
        'gpt-4o-mini',
        [
            'gpt-4o-mini'   => 'GPT-4o Mini (Recommended - Cost Effective)',
            'gpt-4o'        => 'GPT-4o (More Capable)',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Fastest)',
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_umat_ai/require_approval',
        get_string('require_approval', 'local_umat_ai'),
        get_string('require_approval_desc', 'local_umat_ai'),
        1
    ));
}