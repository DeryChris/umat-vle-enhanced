<?php
/**
 * Admin settings for the UMaT AI plugin.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_umat_ai',
        get_string('pluginname', 'local_umat_ai')
    );

    $ADMIN->add('localplugins', $settings);

    /* ---- Section heading ---- */
    $settings->add(new admin_setting_heading(
        'local_umat_ai/heading',
        get_string('settings_heading', 'local_umat_ai'),
        ''
    ));

    /* ---- AI service URL ---- */
    $settings->add(new admin_setting_configtext(
        'local_umat_ai/ai_service_url',
        get_string('ai_service_url', 'local_umat_ai'),
        get_string('ai_service_url_desc', 'local_umat_ai'),
        'http://localhost:8000',
        PARAM_URL
    ));

    /* ---- API token ---- */
    $settings->add(new admin_setting_configpasswordunmask(
        'local_umat_ai/ai_service_token',
        get_string('ai_service_token', 'local_umat_ai'),
        get_string('ai_service_token_desc', 'local_umat_ai'),
        ''
    ));

    /* ---- Rate limit ---- */
    $settings->add(new admin_setting_configtext(
        'local_umat_ai/rate_limit',
        get_string('rate_limit', 'local_umat_ai'),
        get_string('rate_limit_desc', 'local_umat_ai'),
        '10',
        PARAM_INT
    ));

    /* ---- Enable Student FAB ---- */
    $settings->add(new admin_setting_configcheckbox(
        'local_umat_ai/enable_student_fab',
        get_string('enable_student_fab', 'local_umat_ai'),
        get_string('enable_student_fab_desc', 'local_umat_ai'),
        '1'
    ));

    /* ---- Enable Lecturer FAB ---- */
    $settings->add(new admin_setting_configcheckbox(
        'local_umat_ai/enable_lecturer_fab',
        get_string('enable_lecturer_fab', 'local_umat_ai'),
        get_string('enable_lecturer_fab_desc', 'local_umat_ai'),
        '1'
    ));

    /* ---- Enable Hub FAB ---- */
    $settings->add(new admin_setting_configcheckbox(
        'local_umat_ai/enable_hub_fab',
        get_string('enable_hub_fab', 'local_umat_ai'),
        get_string('enable_hub_fab_desc', 'local_umat_ai'),
        '1'
    ));
}
