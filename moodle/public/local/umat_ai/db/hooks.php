<?php
/**
 * Hook registrations for local_umat_ai.
 *
 * Maps the core\hook\output\before_footer_html_generation hook to
 * our listener class so the FABs are injected on every applicable page.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook'        => \core\hook\output\before_footer_html_generation::class,
        'callback'    => [\local_umat_ai\hooks\before_footer::class, 'handle'],
        'priority'    => 500,
    ],
];
