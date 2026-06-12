<?php
/**
 * Lecturer Analytics Dashboard page.
 *
 * Accessible at: /local/umat_ai/lecturer_dashboard.php?courseid=X
 * Requires: local/umat_ai:viewanalytics capability.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

$courseid = required_param('courseid', PARAM_INT);
$tab      = optional_param('tab', 'overview', PARAM_ALPHA);

$course  = get_course($courseid);
require_login($course);

$context = context_course::instance($courseid);
require_capability('local/umat_ai:viewanalytics', $context);

// ---- Page setup --------------------------------------------------------- //
$PAGE->set_context($context);
$PAGE->set_url('/local/umat_ai/lecturer_dashboard.php', ['courseid' => $courseid]);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('analytics_dashboard_title', 'local_umat_ai') . ': ' . $course->fullname);
$PAGE->set_heading($course->fullname);

// ---- Load analytics data ------------------------------------------------ //
$since = time() - (30 * 86400);

// Enrolled students.
$enrolledCount = count_enrolled_users($context, '', 0, true);

// Active students (unique AI users this month).
$activeStudents = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT userid) FROM {umat_ai_chat_logs}
     WHERE courseid = :cid AND timecreated > :since AND role = 'student'",
    ['cid' => $courseid, 'since' => $since]
);

// Total AI interactions this month.
$totalInteractions = $DB->count_records_select(
    'umat_ai_chat_logs',
    'courseid = :cid AND timecreated > :since',
    ['cid' => $courseid, 'since' => $since]
);

// Pending approvals.
$pendingApprovals = $DB->count_records_sql(
    "SELECT COUNT(o.id) FROM {umat_ai_outputs} o
     JOIN {umat_ai_sessions} s ON s.id = o.sessionrecordid
     WHERE s.courseid = :cid AND o.is_approved = 0",
    ['cid' => $courseid]
);

// Daily interaction counts (last 14 days).
$dailyCounts = [];
for ($i = 13; $i >= 0; $i--) {
    $dayStart = mktime(0, 0, 0) - ($i * 86400);
    $dayEnd   = $dayStart + 86400;
    $count    = $DB->count_records_select(
        'umat_ai_chat_logs',
        'courseid = :cid AND timecreated >= :from AND timecreated < :to',
        ['cid' => $courseid, 'from' => $dayStart, 'to' => $dayEnd]
    );
    $dailyCounts[] = ['label' => date('d M', $dayStart), 'count' => (int) $count];
}

// Max for chart scaling.
$maxCount = max(array_column($dailyCounts, 'count')) ?: 1;

// Top 10 student questions.
$topQuestions = $DB->get_records_sql(
    "SELECT question, COUNT(*) AS ask_count
       FROM {umat_ai_chat_logs}
      WHERE courseid = :cid AND timecreated > :since AND role = 'student'
   GROUP BY question ORDER BY ask_count DESC",
    ['cid' => $courseid, 'since' => $since],
    0, 10
);

// Struggle index — the course material students cite most in their questions
// (human-readable, unlike the old session-key fragment).
$struggleIndex = get_string('na', 'local_umat_ai');
$struggleCount = 0;
$sourceRows = $DB->get_records_sql(
    "SELECT id, sources FROM {umat_ai_chat_logs}
      WHERE courseid = :cid AND timecreated > :since AND role = 'student'
        AND sources IS NOT NULL AND sources != '' AND sources != '[]'",
    ['cid' => $courseid, 'since' => $since]
);
$sourceCounts = [];
foreach ($sourceRows as $row) {
    $srcs = json_decode($row->sources, true);
    if (!is_array($srcs)) continue;
    foreach (array_unique(array_filter($srcs, 'is_string')) as $src) {
        $sourceCounts[$src] = ($sourceCounts[$src] ?? 0) + 1;
    }
}
if (!empty($sourceCounts)) {
    arsort($sourceCounts);
    $topSource     = array_key_first($sourceCounts);
    $struggleIndex = pathinfo($topSource, PATHINFO_FILENAME);
    $struggleCount = (int) $sourceCounts[$topSource];
}

// Avg questions per session.
$avgQPS = 0;
$sessionsData = $DB->get_records_sql(
    "SELECT session_key, COUNT(*) AS q_count
       FROM {umat_ai_chat_logs}
      WHERE courseid = :cid AND timecreated > :since AND role = 'student'
        AND session_key IS NOT NULL AND session_key != ''
   GROUP BY session_key",
    ['cid' => $courseid, 'since' => $since]
);
if (!empty($sessionsData)) {
    $avgQPS = round(array_sum(array_column((array) $sessionsData, 'q_count')) / count($sessionsData), 1);
}

// Student performance breakdown (approximate from AI usage frequency).
// Same fix as get_analytics.php: COUNT over a grouped subquery, not GROUP BY in count_records_sql.
$highPerformers = (int) $DB->get_field_sql(
    "SELECT COUNT(*) FROM (
        SELECT userid
          FROM {umat_ai_chat_logs}
         WHERE courseid = :cid AND timecreated > :since AND role = 'student'
      GROUP BY userid
        HAVING COUNT(*) >= 10
     ) hp_subq",
    ['cid' => $courseid, 'since' => $since]
) ?: 0;

$atRiskCount = max(0, $enrolledCount - (int) $activeStudents);

// ---- Build template context -------------------------------------------- //
$tctx = [
    'courseid'            => $courseid,
    'coursefullname'      => format_string($course->fullname, true, ['context' => $context]),
    'shortname'           => $course->shortname,
    'enrolled_students'   => $enrolledCount,
    'active_students'     => $activeStudents,
    'active_pct'          => $enrolledCount > 0 ? round($activeStudents / $enrolledCount * 100) : 0,
    'total_interactions'  => $totalInteractions,
    'pending_approvals'   => $pendingApprovals,
    'struggle_index'      => $struggleIndex,
    'struggle_count'      => $struggleCount,
    'avg_qps'             => $avgQPS,
    'high_performers'     => $highPerformers,
    'at_risk'             => $atRiskCount,
    'on_track'            => max(0, $enrolledCount - $highPerformers - $atRiskCount),
    'daily_counts_json'   => json_encode($dailyCounts),
    'max_count'           => $maxCount,
    'daily_counts'        => $dailyCounts,
    'top_questions'       => array_values(array_map(function ($q) {
        return [
            'text'      => strlen($q->question) > 120 ? substr($q->question, 0, 117) . '…' : $q->question,
            'ask_count' => (int) $q->ask_count,
        ];
    }, (array) $topQuestions)),
    'approve_url'         => (new moodle_url('/local/umat_ai/approve.php', ['courseid' => $courseid]))->out(false),
    'hub_url'             => (new moodle_url('/local/umat_ai/hub.php'))->out(false),
    'wwwroot'             => $CFG->wwwroot,
    'has_pending'         => $pendingApprovals > 0,
    'has_questions'       => !empty($topQuestions),
];

// ---- Render ------------------------------------------------------------- //
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_umat_ai/lecturer_dashboard', $tctx);
echo $OUTPUT->footer();
