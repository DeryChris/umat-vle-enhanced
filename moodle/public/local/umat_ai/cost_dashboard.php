<?php
/**
 * Transcription Cost Dashboard page.
 *
 * Aggregated view of transcription costs per course, per month,
 * and per provider. Accessible at:
 *   /local/umat_ai/cost_dashboard.php?courseid=X
 *
 * Requires: local/umat_ai:viewanalytics capability.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
$tab      = optional_param('tab', 'overview', PARAM_ALPHA);

if ($courseid > 0) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($courseid);
    require_capability('local/umat_ai:viewanalytics', $context);
    $title = get_string('cost_dashboard_title', 'local_umat_ai') . ': ' . $course->fullname;
    $heading = $course->fullname;
} else {
    require_login();
    $context = context_system::instance();
    require_capability('local/umat_ai:viewanalytics', $context);
    $title = get_string('cost_dashboard_title', 'local_umat_ai');
    $heading = get_string('cost_dashboard_title', 'local_umat_ai');
}

// ---- Page setup --------------------------------------------------------- //
$PAGE->set_context($context);
$PAGE->set_url('/local/umat_ai/cost_dashboard.php', ['courseid' => $courseid]);
$PAGE->set_pagelayout('standard');
$PAGE->set_title($title);
$PAGE->set_heading($heading);

$PAGE->requires->js_call_amd('local_umat_ai/cost_dashboard', 'init', [$courseid]);

// ---- Determine accessible courses for the dropdown --------------------- //
$accessibleCourses = [];
if ($courseid > 0) {
    $accessibleCourses[] = ['id' => $courseid, 'fullname' => format_string($course->fullname), 'shortname' => $course->shortname];
} else {
    $courses = enrol_get_users_courses($USER->id, true, 'id,fullname,shortname');
    foreach ($courses as $c) {
        $ctx = context_course::instance($c->id);
        if (has_capability('local/umat_ai:viewanalytics', $ctx)) {
            $accessibleCourses[] = [
                'id' => $c->id,
                'fullname' => format_string($c->fullname),
                'shortname' => $c->shortname,
            ];
        }
    }
}

$PAGE->requires->css('/local/umat_ai/styles.css');

// ---- Build template context -------------------------------------------- //
$tctx = [
    'courseid'          => $courseid,
    'courses'           => $accessibleCourses,
    'hascourses'        => !empty($accessibleCourses),
    'wwwroot'           => $CFG->wwwroot,
    'tab'               => $tab,
];

// ---- Render ------------------------------------------------------------- //
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_umat_ai/cost_dashboard', $tctx);
echo $OUTPUT->footer();
