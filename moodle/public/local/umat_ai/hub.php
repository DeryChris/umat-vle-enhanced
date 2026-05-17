<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

require_login();
$PAGE->set_context(\context_user::instance($USER->id));
$PAGE->set_url('/local/umat_ai/hub.php');
$PAGE->set_title(get_string('ai_hub_title', 'local_umat_ai'));
$PAGE->set_pagelayout('embedded');

$courses = enrol_get_users_courses($USER->id, true, 'id,fullname,shortname');
$courseOptions = [];
foreach ($courses as $c) {
    $courseOptions[] = ['id' => $c->id, 'fullname' => format_string($c->fullname), 'shortname' => $c->shortname];
}

$recentRaw = $DB->get_records_sql(
    "SELECT session_key, MAX(courseid) AS courseid, MIN(timecreated) AS started,
            MAX(timecreated) AS lastactive, COUNT(*) AS msg_count, MIN(question) AS first_q
       FROM {umat_ai_chat_logs}
      WHERE userid = :uid AND role = 'student' AND session_key IS NOT NULL AND session_key != ''
   GROUP BY session_key ORDER BY lastactive DESC",
    ['uid' => $USER->id], 0, 12
);

$recentSessions = [];
foreach ($recentRaw as $log) {
    $cName = $cShort = '';
    foreach ($courses as $c) {
        if ($c->id == $log->courseid) { $cName = format_string($c->fullname); $cShort = $c->shortname; break; }
    }
    $elapsed = time() - $log->lastactive;
    $timeStr = $elapsed < 3600 ? round($elapsed/60).'m ago'
             : ($elapsed < 86400 ? round($elapsed/3600).'h ago'
             : ($elapsed < 604800 ? round($elapsed/86400).' days ago' : date('d M', $log->lastactive)));
    $recentSessions[] = [
        'session_key'  => $log->session_key, 'courseid' => (int)$log->courseid,
        'course_name'  => $cName, 'course_short' => $cShort,
        'time_label'   => $timeStr, 'msg_count' => (int)$log->msg_count,
        'preview'      => mb_strlen($log->first_q) > 100 ? mb_substr($log->first_q,0,97).'…' : $log->first_q,
    ];
}

$weekSince     = time() - 7 * DAYSECS;
$weekSessions  = (int)($DB->get_field_sql("SELECT COUNT(DISTINCT session_key) FROM {umat_ai_chat_logs} WHERE userid=:uid AND timecreated>:s AND role='student'", ['uid'=>$USER->id,'s'=>$weekSince]) ?: 0);
$weekQuestions = (int)$DB->count_records_select('umat_ai_chat_logs','userid=:uid AND timecreated>:s AND role=:r',['uid'=>$USER->id,'s'=>$weekSince,'r'=>'student']);

$pulseTopics = [];
$topCourses  = $DB->get_records_sql("SELECT courseid, COUNT(*) AS cnt FROM {umat_ai_chat_logs} WHERE userid=:uid AND role='student' AND timecreated>:s GROUP BY courseid ORDER BY cnt DESC", ['uid'=>$USER->id,'s'=>time()-30*DAYSECS], 0, 5);
foreach ($topCourses as $t) {
    foreach ($courses as $c) { if ($c->id==$t->courseid){ $pulseTopics[]=['label'=>$c->shortname,'count'=>(int)$t->cnt]; break; } }
}

$tctx = [
    'wwwroot'        => $CFG->wwwroot,
    'userid'         => $USER->id,
    'username'       => fullname($USER),
    'user_initials'  => strtoupper(mb_substr($USER->firstname,0,1).mb_substr($USER->lastname,0,1)),
    'goalprogress'   => min(100, round($weekQuestions/20*100)),
    'week_sessions'  => $weekSessions,
    'week_questions' => $weekQuestions,
    'courses'        => $courseOptions,
    'recent_sessions'=> $recentSessions,
    'pulse_topics'   => $pulseTopics,
    'has_sessions'   => !empty($recentSessions),
    'has_courses'    => !empty($courseOptions),
    'first_courseid' => !empty($courseOptions) ? (int)$courseOptions[0]['id'] : 0,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_umat_ai/ai_hub', $tctx);
echo $OUTPUT->footer();
