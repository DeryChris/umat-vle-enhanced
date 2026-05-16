<?php
/**
 * General AI Hub Page.
 *
 * Cross-course AI conversations, session logs, and learning pulse dashboard.
 * Accessible at: /local/umat_ai/hub.php
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
$PAGE->set_heading(get_string('ai_hub_title', 'local_umat_ai'));
$PAGE->set_url('/local/umat_ai/hub.php');
$PAGE->set_pagelayout('standard');

// ---- Load user's enrolled courses -------------------------------------- //
$enrolledCourses = enrol_get_users_courses($USER->id, true, 'id, fullname, shortname');

$courseOptions = [];
foreach ($enrolledCourses as $c) {
    $courseOptions[] = [
        'id'        => $c->id,
        'fullname'  => format_string($c->fullname),
        'shortname' => $c->shortname,
    ];
}

// ---- Load recent AI sessions ------------------------------------------- //
$recentLogs = $DB->get_records_sql(
    "SELECT session_key,
            MAX(courseid)    AS courseid,
            MIN(timecreated) AS started,
            MAX(timecreated) AS lastactive,
            COUNT(*)         AS msg_count,
            MIN(question)    AS first_q
       FROM {umat_ai_chat_logs}
      WHERE userid = :uid AND role = 'student' AND session_key IS NOT NULL AND session_key != ''
   GROUP BY session_key
   ORDER BY lastactive DESC",
    ['uid' => $USER->id],
    0, 12
);

$recentSessions = [];
foreach ($recentLogs as $log) {
    // Resolve course name.
    $cName = '';
    $cShort = '';
    foreach ($enrolledCourses as $c) {
        if ($c->id == $log->courseid) {
            $cName  = format_string($c->fullname);
            $cShort = $c->shortname;
            break;
        }
    }

    $elapsed = time() - $log->lastactive;
    if ($elapsed < 3600)       { $timeStr = round($elapsed / 60) . 'm ago'; }
    elseif ($elapsed < 86400)  { $timeStr = round($elapsed / 3600) . 'h ago'; }
    elseif ($elapsed < 604800) { $timeStr = round($elapsed / 86400) . ' days ago'; }
    else                       { $timeStr = date('d M', $log->lastactive); }

    $preview = strlen($log->first_q) > 100
        ? substr($log->first_q, 0, 97) . '…'
        : $log->first_q;

    $recentSessions[] = [
        'session_key' => $log->session_key,
        'courseid'    => (int) $log->courseid,
        'course_name' => $cName,
        'course_short'=> $cShort,
        'time_label'  => $timeStr,
        'msg_count'   => (int) $log->msg_count,
        'preview'     => $preview,
    ];
}

// ---- Compute weekly stats --------------------------------------------- //
$weekSince    = time() - (7 * 86400);
$weekSessions = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT session_key) FROM {umat_ai_chat_logs}
     WHERE userid = :uid AND timecreated > :since AND role = 'student'",
    ['uid' => $USER->id, 'since' => $weekSince]
);
$weekQuestions = $DB->count_records_select(
    'umat_ai_chat_logs',
    'userid = :uid AND timecreated > :since AND role = :role',
    ['uid' => $USER->id, 'since' => $weekSince, 'role' => 'student']
);

// ---- Learning pulse topics (most asked course topics) ------------------ //
$topTopics = $DB->get_records_sql(
    "SELECT courseid, COUNT(*) AS cnt
       FROM {umat_ai_chat_logs}
      WHERE userid = :uid AND role = 'student' AND timecreated > :since
   GROUP BY courseid ORDER BY cnt DESC",
    ['uid' => $USER->id, 'since' => time() - (30 * 86400)],
    0, 5
);
$pulseTopics = [];
foreach ($topTopics as $t) {
    foreach ($enrolledCourses as $c) {
        if ($c->id == $t->courseid) {
            $pulseTopics[] = ['label' => $c->shortname, 'count' => (int) $t->cnt];
            break;
        }
    }
}

// Rough "goal progress" based on questions asked vs. weekly goal of 20.
$goalProgress = min(100, round($weekQuestions / 20 * 100));

// ---- Template context -------------------------------------------------- //
$tctx = [
    'wwwroot'         => $CFG->wwwroot,
    'userid'          => $USER->id,
    'username'        => fullname($USER),
    'goalprogress'    => $goalProgress,
    'week_sessions'   => (int) $weekSessions,
    'week_questions'  => (int) $weekQuestions,
    'courses'         => $courseOptions,
    'recent_sessions' => $recentSessions,
    'pulse_topics'    => $pulseTopics,
    'has_sessions'    => !empty($recentSessions),
    'has_courses'     => !empty($courseOptions),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_umat_ai/ai_hub', $tctx);
echo $OUTPUT->footer();
