<?php
/**
 * Lecturer AI Output Approval page.
 *
 * Lists all AI-generated outputs (summary, notes, quiz) for a course
 * that are pending lecturer approval before becoming visible to students.
 *
 * Accessible at: /local/umat_ai/approve.php?courseid=X
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

$courseid = required_param('courseid', PARAM_INT);
$course   = get_course($courseid);
require_login($course);

$context = context_course::instance($courseid);
require_capability('local/umat_ai:approveoutput', $context);

// ---- Page setup --------------------------------------------------------- //
$PAGE->set_context($context);
$PAGE->set_url('/local/umat_ai/approve.php', ['courseid' => $courseid]);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('approval_title', 'local_umat_ai'));
$PAGE->set_heading($course->fullname);

// ---- Load sessions with pending outputs --------------------------------- //
$sessionRecords = $DB->get_records_sql(
    "SELECT s.*, COUNT(o.id) AS pending_count
       FROM {umat_ai_sessions} s
       JOIN {umat_ai_outputs} o ON o.sessionrecordid = s.id
      WHERE s.courseid = :courseid AND o.is_approved = 0
   GROUP BY s.id
   ORDER BY s.timecreated DESC",
    ['courseid' => $courseid]
);

$sessionIds = [];
foreach ($sessionRecords as $sess) {
    $sessionIds[] = (int)$sess->id;
}

// Bulk-load all pending outputs for the sessions shown on this page (avoids N+1 queries).
$outputsBySession = [];
if (!empty($sessionIds)) {
    list($insql, $params) = $DB->get_in_or_equal($sessionIds, SQL_PARAMS_NAMED);
    $outputs = $DB->get_records_sql(
        "SELECT id, sessionrecordid, output_type, content, timecreated
           FROM {umat_ai_outputs}
          WHERE sessionrecordid {$insql} AND is_approved = 0
       ORDER BY output_type ASC, timecreated ASC",
        $params
    );

    foreach ($outputs as $out) {
        $sid = (int)$out->sessionrecordid;
        if (!isset($outputsBySession[$sid])) {
            $outputsBySession[$sid] = [];
        }
        $outputsBySession[$sid][] = [
            'output_id'   => (int) $out->id,
            'output_type' => $out->output_type,
            'content'     => format_text($out->content, FORMAT_PLAIN),
            'timecreated' => userdate($out->timecreated),
        ];
    }
}

$sessions = [];
foreach ($sessionRecords as $sess) {
    $sid = (int)$sess->id;
    $outputData = $outputsBySession[$sid] ?? [];

    if (!empty($outputData)) {
        $sessions[] = [
            'session_id'    => (int) $sess->id,
            'bbb_sessionid' => htmlspecialchars($sess->sessionid, ENT_QUOTES, 'UTF-8'),
            'timecreated'   => userdate($sess->timecreated),
            'courseid'      => $courseid,
            'outputs'       => $outputData,
        ];
    }
}


// ---- Template context --------------------------------------------------- //
$tctx = [
    'courseid'       => $courseid,
    'coursename'     => format_string($course->fullname, true, ['context' => $context]),
    'sessions'       => $sessions,
    'str_approve'    => get_string('str_approve', 'local_umat_ai'),
    'str_reject'     => get_string('str_reject', 'local_umat_ai'),
    'str_summary'    => get_string('str_summary', 'local_umat_ai'),
    'str_notes'      => get_string('str_notes', 'local_umat_ai'),
    'str_quiz'       => get_string('str_quiz', 'local_umat_ai'),
    'str_no_pending' => get_string('str_no_pending', 'local_umat_ai'),
    'dashboard_url'  => (new moodle_url('/local/umat_ai/lecturer_dashboard.php', ['courseid' => $courseid]))->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_umat_ai/approval', $tctx);
echo $OUTPUT->footer();
