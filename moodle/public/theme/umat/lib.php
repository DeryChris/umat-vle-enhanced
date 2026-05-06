<?php
// ============================================================
// Required theme callback functions referenced in config.php
// Moodle calls these automatically — do not rename them.
// ============================================================

defined('MOODLE_INTERNAL') || die();

/**
 * Returns the main SCSS content for the UMaT theme.
 * Moodle calls this via the $THEME->scss closure in config.php.
 *
 * @param theme_config $theme The theme configuration object.
 * @return string The compiled SCSS content.
 */
function theme_umat_get_main_scss_content($theme) {
    global $CFG;

    $scss = '';

    // Load Boost's own main SCSS as our base
    $filename = !empty($theme->settings->preset) ? $theme->settings->preset : 'default';
    $fs       = get_file_storage();

    $context = context_system::instance();

    // Check if a custom preset file was uploaded via theme settings
    if ($filename !== 'default' && $filename !== 'plain') {
        $presetfile = $fs->get_file($context->id, 'theme_umat', 'preset', 0, '/', $filename . '.scss');
        if ($presetfile) {
            $scss .= $presetfile->get_content();
        } else {
            // Fallback to Boost's default preset
            $scss .= file_get_contents($CFG->dirroot . '/theme/boost/scss/preset/default.scss');
        }
    } else {
        $scss .= file_get_contents($CFG->dirroot . '/theme/boost/scss/preset/' . $filename . '.scss');
    }

    return $scss;
}

/**
 * Returns SCSS to prepend before the main SCSS.
 * Used to inject brand variable overrides (pre.scss) before Boost's variables compile.
 * Moodle calls this via $THEME->prescsscallback in config.php.
 *
 * @param theme_config $theme The theme configuration object.
 * @return string SCSS variable overrides.
 */
function theme_umat_get_pre_scss($theme) {
    global $CFG;

    $scss = '';

    // Inject any custom SCSS variables set through the theme settings UI
    // (e.g. a colour picker in admin settings — add those here if implemented)

    // Load our pre.scss which sets UMaT brand colour variables
    $prescssfile = $CFG->dirroot . '/theme/umat/scss/pre.scss';
    if (file_exists($prescssfile)) {
        $scss .= file_get_contents($prescssfile);
    }

    return $scss;
}

/**
 * Returns SCSS to append after the main SCSS.
 * Used to inject component overrides and custom styles (post.scss) after Boost compiles.
 * Moodle calls this via $THEME->extrascsscallback in config.php.
 *
 * @param theme_config $theme The theme configuration object.
 * @return string Additional SCSS overrides.
 */
function theme_umat_get_extra_scss($theme) {
    global $CFG;

    $scss = '';

    // Load our post.scss which contains UMaT-specific component styles
    $postscssfile = $CFG->dirroot . '/theme/umat/scss/post.scss';
    if (file_exists($postscssfile)) {
        $scss .= file_get_contents($postscssfile);
    }

    // Append any extra raw CSS entered in the theme admin settings
    if (!empty($theme->settings->scss)) {
        $scss .= $theme->settings->scss;
    }

    return $scss;
}

/**
 * CSS tree post-processor.
 * Called after all SCSS is compiled to CSS.
 * Referenced by $THEME->csstreepostprocessor in config.php.
 * We delegate to Boost's own post-processor.
 *
 * @param css_tree $tree The CSS tree.
 * @param theme_config $theme The theme configuration.
 */
function theme_umat_css_tree_post_processor($tree, $theme) {
    $boost = theme_config::load('boost');
    $boost->css_tree_post_processor($tree, $theme);
}