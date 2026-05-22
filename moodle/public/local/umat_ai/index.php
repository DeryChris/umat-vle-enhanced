<?php
/**
 * Expanded AI Workspace page.
 *
 * Full-page view: video player + synchronized transcript (left) + AI chat (right).
 * Accessible at: /local/umat_ai/index.php?courseid=X[&sessionid=Y]
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

$courseid  = required_param('courseid', PARAM_INT);
$sessionid = optional_param('sessionid', 0, PARAM_INT);

$course = get_course($courseid);
require_login($course);

$context = context_course::instance($courseid);
require_capability('local/umat_ai:chatwithai', $context);

// ---- Page setup --------------------------------------------------------- //
$PAGE->set_context($context);
$PAGE->set_url('/local/umat_ai/index.php', ['courseid' => $courseid]);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('ai_assistant', 'local_umat_ai') . ': ' . $course->fullname);
$PAGE->set_heading($course->fullname);

// ---- Load session data -------------------------------------------------- //
$session     = null;
$sessionRec  = null;
$transcripts = [];
$videoUrl    = '';

if ($sessionid) {
    $sessionRec = $DB->get_record('umat_ai_sessions', ['id' => $sessionid, 'courseid' => $courseid]);
    if ($sessionRec) {
        $videoUrl = $sessionRec->recording_url ?? '';
    }
}

// If no specific session, try to get the most recent one.
if (!$sessionRec) {
    $sessionRec = $DB->get_record_sql(
        "SELECT id, sessionid, timecreated, recording_url
           FROM {umat_ai_sessions}
          WHERE courseid = :cid AND status = 'complete'
          ORDER BY timecreated DESC",
        ['cid' => $courseid],
        IGNORE_MULTIPLE
    );
}

$sessionTitle   = $sessionRec ? 'Session ' . date('d M Y', $sessionRec->timecreated) : get_string('ai_assistant', 'local_umat_ai');
$sessionDbId    = $sessionRec ? (int) $sessionRec->id : 0;

// ---- Template context --------------------------------------------------- //
$tctx = [
    'courseid'       => $courseid,
    'coursefullname' => format_string($course->fullname, true, ['context' => $context]),
    'sessionid'      => $sessionDbId,
    'sessiontitle'   => $sessionTitle,
    'video_url'      => $videoUrl,
    'has_video'      => !empty($videoUrl),
    'transcript'     => [],
    'has_capability' => true,
    'hub_url'        => (new moodle_url('/local/umat_ai/hub.php'))->out(false),
    'wwwroot'        => $CFG->wwwroot,
];

// Load the AMD workspace module.
$PAGE->requires->js_amd_inline("
    require(['local_umat_ai/ai_workspace'], function(Workspace) {
        Workspace.init({
            courseId:      {$courseid},
            sessionId:     {$sessionDbId},
            courseName:    " . json_encode($course->fullname) . ",
            hasCapability: true,
        });
    });
");

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_umat_ai/ai_workspace', $tctx);
echo $OUTPUT->footer();
