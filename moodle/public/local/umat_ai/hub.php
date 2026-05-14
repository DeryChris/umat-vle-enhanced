<?php
/**
 * General AI Hub Page.
 *
 * Cross-course AI conversations, session logs, and learning pulse dashboard.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

require_login();

$PAGE->set_context(\context_user::instance($USER->id));
$PAGE->set_title(get_string('ai_hub_title', 'local_umat_ai'));
$PAGE->set_heading(get_string('ai_hub_heading', 'local_umat_ai'));
$PAGE->set_url('/local/umat_ai/hub.php');

// Render hub content
$hubData = [
    'wwwroot' => $CFG->wwwroot,
    'userid' => $USER->id,
    'username' => fullname($USER),
    'goalprogress' => 78,
];

// Render global FAB (shows on non-course pages)
$fabData = [
    'courseid' => 0,
    'coursename' => '',
    'has_capability' => true,
    'is_global' => true,
    'wwwroot' => $CFG->wwwroot,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_umat_ai/ai_hub', $hubData);

// Inject global FAB overlay (user can expand to full hub from here)
echo $OUTPUT->render_from_template('local_umat_ai/ai_chat_panel', $fabData);

echo $OUTPUT->footer();