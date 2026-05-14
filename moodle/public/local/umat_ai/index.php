<?php
/**
 * Page to display the AI Chat Panel.
 *
 * Students access this from course navigation to chat with AI about course materials.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);

require_login($course, true);
$context = context_course::instance($course->id);

// Check capability - allow if has capability OR for testing, show UI anyway
$hasCapability = has_capability('local/umat_ai:chatwithai', $context);
// TEMP: Force true for testing UI
$hasCapability = true;

$PAGE->set_context($context);
$PAGE->set_title(get_string('chatpanel_title', 'local_umat_ai'));
$PAGE->set_heading($course->fullname);
$PAGE->set_url('/local/umat_ai/index.php', ['courseid' => $courseid]);

// Prepare template data
$hasCapability = true;
$coursename = format_string($course->fullname, true, ['context' => $context]);

// Check if course materials are indexed
$indexed = false;
// TODO: Check if materials exist in ChromaDB for this course

$templateData = [
    'courseid' => $courseid,
    'coursename' => $coursename,
    'has_capability' => $hasCapability,
    'indexed' => $indexed,
    'is_global' => false,
    'wwwroot' => $CFG->wwwroot,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_umat_ai/ai_chat_panel', $templateData);
echo $OUTPUT->footer();