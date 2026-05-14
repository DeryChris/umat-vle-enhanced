<?php
/**
 * Hook definitions for local_umat_ai plugin.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$hooks = [
    [
        'callback' => '\local_umat_ai\hooks\before_footer::handle',
        'hook' => '\core\hook\output\before_footer_html_generation',
    ],
];