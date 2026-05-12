<?php
// ============================================================
// UMaT theme renderer — fixes firstview_fakeblocks() availability
// ============================================================

defined('MOODLE_INTERNAL') || die();

/**
 * UMaT core renderer.
 * Extends Boost's core_renderer to ensure firstview_fakeblocks() is available.
 */
class theme_umat_core_renderer extends \theme_boost\output\core_renderer {
}
