<?php
// ============================================================
// Lecturer approval page: /local/umat_ai/approve.php?courseid=XX
// Lists AI-generated outputs pending review for the current course.
// Only accessible by users with approveoutput capability.
// ============================================================

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/../../lib/weblib.php');
require_once(__DIR__ . '/../../lib/outputrenderers.php');

$courseid = required_param('courseid', PARAM_INT);

// Load course and check access.
$course = get_course($courseid);
require_login($courseid);
$context = context_course::instance($courseid);
require_capability('local/umat_ai:approveoutput', $context);

// Page setup.
$PAGE->set_context($context);
$PAGE->set_url('/local/umat_ai/approve.php', ['courseid' => $courseid]);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('pluginname', 'local_umat_ai') . ': AI Output Review');

// Load pending sessions from the database.
$sessions = $DB->get_records_sql(
    "SELECT DISTINCT s.id    AS session_id,
                    s.sessionid,
                    s.timecreated,
                    o.id          AS output_id,
                    o.output_type,
                    o.content
               FROM {umat_ai_sessions} s
               JOIN {umat_ai_outputs} o ON o.sessionrecordid = s.id
              WHERE s.courseid = :courseid
                AND (:require_approval = 0 OR o.is_approved = 0)
           ORDER BY s.timecreated DESC",
    [
        'courseid'        => $courseid,
        'require_approval' => (int) get_config('local_umat_ai', 'require_approval'),
    ]
);

// Group outputs by session.
$grouped = [];
foreach ($sessions as $row) {
    $sid = $row->session_id;
    if (!isset($grouped[$sid])) {
        $grouped[$sid] = [
            'session_id'    => (int) $sid,
            'bbb_sessionid' => $row->sessionid,
            'timecreated'   => (int) $row->timecreated,
            'outputs'       => [],
        ];
    }
    $grouped[$sid]['outputs'][] = [
        'output_id'   => (int) $row->output_id,
        'output_type' => $row->output_type,
        'content'     => $row->content,
    ];
}
$grouped = array_values($grouped);

// Build template context.
$templatecontext = [
    'courseid'    => $courseid,
    'coursename'  => $course->fullname,
    'sessions'     => $grouped,
    'str_approve'  => get_string('approve_btn', 'local_umat_ai'),
    'str_reject'   => get_string('reject_btn',   'local_umat_ai'),
    'str_summary' => get_string('session_summary', 'local_umat_ai'),
    'str_notes'    => get_string('session_notes',   'local_umat_ai'),
    'str_quiz'     => get_string('session_quiz',     'local_umat_ai'),
    'str_pending'  => get_string('pending_approval', 'local_umat_ai'),
    'str_no_pending' => 'No outputs pending review',
];

// Render using the approval template.
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_umat_ai/approval', $templatecontext);
echo $OUTPUT->footer();