<?php
// ============================================================
// Inherits from Boost; overrides SCSS and specific layouts
// NOTE: theme_umat_get_main_scss_content(), theme_umat_get_pre_scss(),
// and theme_umat_get_extra_scss() must be defined in theme/umat/lib.php
// (that file is not generated here — add it as a separate file)
// ============================================================

defined('MOODLE_INTERNAL') || die();

$THEME->name        = 'umat';
$THEME->parents     = ['boost'];
$THEME->sheets      = [];
$THEME->editor_sheets = [];
$THEME->usefallback = true;

$THEME->scss = function($theme) {
    return theme_umat_get_main_scss_content($theme);
};

$THEME->prescsscallback   = 'theme_umat_get_pre_scss';
$THEME->extrascsscallback = 'theme_umat_get_extra_scss';

$THEME->layouts = [
    'base'     => ['file' => 'drawers.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre'],
    'standard' => ['file' => 'drawers.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre'],
    'course'   => ['file' => 'drawers.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre'],
    'admin'    => ['file' => 'drawers.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre'],
    'login'    => ['file' => 'login.php',    'regions' => []],
];

$THEME->enable_dock        = false;
$THEME->requiredblocks     = '';
$THEME->iconsystem         = \core\output\icon_system::FONTAWESOME;
$THEME->haseditswitch      = true;