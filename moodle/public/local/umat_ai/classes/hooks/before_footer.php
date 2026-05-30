<?php
/**
 * Hook listener: inject full-screen AI overlays on every Moodle page.
 *
 * STUDENT (course pages)   → FAB → compact panel → full workspace overlay
 * LECTURER (course pages)  → FAB → compact panel → full analytics overlay
 * ANY STUDENT (other pages) → Hub FAB → full hub overlay (NOT a page nav)
 *
 * All navigation stays WITHIN overlays. No overlay redirects to another page.
 *
 * @package    local_umat_ai
 */

namespace local_umat_ai\hooks;

use core\hook\output\before_footer_html_generation;

class before_footer {

    // ================================================================== //
    // HOOK ENTRY POINT                                                    //
    // ================================================================== //

    public static function handle(before_footer_html_generation $hook): void {
        global $PAGE, $COURSE, $USER, $CFG, $DB;

        if (!isloggedin() || isguestuser()) return;

        $path = $PAGE->url->get_path();
        $isCourseArea = (strpos($path, '/course/') !== false
            || strpos($path, '/mod/')    !== false
            || strpos($path, '/section/') !== false);

        $courseid = 0;
        $ctx = $PAGE->context;
        if ($ctx && $ctx->contextlevel === CONTEXT_COURSE) {
            $courseid = (int)$ctx->instanceid;
        } elseif (!empty($COURSE->id) && $COURSE->id != SITEID) {
            $courseid = (int)$COURSE->id;
        }

        $wwwroot = rtrim($CFG->wwwroot, '/');

        $hook->add_html(self::shared_styles());
        $hook->add_html(self::responsive_styles());

        if ($isCourseArea && $courseid) {
            $courseCtx  = \context_course::instance($courseid);
            $courseName = format_string($COURSE->fullname ?? '', true, ['context' => $courseCtx]);
            $isLecturer = has_capability('local/umat_ai:viewanalytics', $courseCtx);
            $isStudent  = !$isLecturer && is_enrolled($courseCtx, $USER, '', false);

            if ($isLecturer && get_config('local_umat_ai', 'enable_lecturer_fab')) {
                $userData = self::preload_user_data($USER->id, $wwwroot);
                $pending = (int)($DB->get_field_sql(
                    "SELECT COUNT(o.id) FROM {umat_ai_outputs} o
                     JOIN {umat_ai_sessions} s ON s.id = o.sessionrecordid
                     WHERE s.courseid = :cid AND o.is_approved = 0",
                    ['cid' => $courseid]
                ) ?: 0);
                $hook->add_html(self::lecturer_overlay($courseid, $courseName, $pending, $wwwroot, $USER, $userData));
            } elseif ($isStudent && get_config('local_umat_ai', 'enable_student_fab')) {
                $userData = self::preload_user_data($USER->id, $wwwroot);
                $hook->add_html(self::student_overlay($courseid, $courseName, $wwwroot, $USER, $userData));
            }
        } elseif (!$isCourseArea) {
            $isLecturerAnywhere = $DB->record_exists_sql(
                "SELECT 1 FROM {role_assignments} ra
                 JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.userid = :uid AND r.shortname IN ('editingteacher','teacher','manager')",
                ['uid' => $USER->id]
            );
            if ($isLecturerAnywhere && get_config('local_umat_ai', 'enable_lecturer_fab')) {
                $userData = self::preload_user_data($USER->id, $wwwroot);
                $totalPending = (int)($DB->get_field_sql(
                    "SELECT COUNT(o.id) FROM {umat_ai_outputs} o
                     JOIN {umat_ai_sessions} s ON s.id = o.sessionrecordid
                     WHERE o.is_approved = 0"
                ) ?: 0);
                $hook->add_html(self::lecturer_overlay(0, 'All Courses', $totalPending, $wwwroot, $USER, $userData));
            } elseif (get_config('local_umat_ai', 'enable_hub_fab')) {
                $userData = self::preload_user_data($USER->id, $wwwroot);
                $hook->add_html(self::hub_overlay($wwwroot, $USER, $userData));
            }
        }
    }

    // ================================================================== //
    // PRE-LOAD USER DATA — embedded as JSON in every overlay              //
    // ================================================================== //

    private static $preloadCache = null;

    private static function preload_user_data(int $userId, string $wwwroot): string {
        if (self::$preloadCache !== null) return self::$preloadCache;
        global $DB;

        $courses = enrol_get_users_courses($userId, true, 'id,fullname,shortname');
        $courseList = [];
        foreach ($courses as $c) {
            $courseList[] = [
                'id' => (int)$c->id,
                'fullname'  => format_string($c->fullname),
                'shortname' => $c->shortname,
                'url' => $wwwroot . '/course/view.php?id=' . $c->id,
            ];
        }

        $weekSince     = time() - 7 * DAYSECS;
        $weekSessions  = (int)($DB->get_field_sql(
            "SELECT COUNT(DISTINCT session_key) FROM {umat_ai_chat_logs}
             WHERE userid=:uid AND timecreated>:s AND role='student'",
            ['uid' => $userId, 's' => $weekSince]) ?: 0);
        $weekQuestions = (int)$DB->count_records_select(
            'umat_ai_chat_logs',
            "userid=:uid AND timecreated>:s AND role='student'",
            ['uid' => $userId, 's' => $weekSince]);

        // Last 12 sessions.
        $rawSessions = $DB->get_records_sql(
            "SELECT session_key, MAX(courseid) AS courseid, MIN(timecreated) AS started,
                    MAX(timecreated) AS lastactive, COUNT(*) AS msg_count, MIN(question) AS first_q
               FROM {umat_ai_chat_logs}
              WHERE userid=:uid AND role='student'
                AND session_key IS NOT NULL AND session_key != ''
           GROUP BY session_key ORDER BY lastactive DESC",
            ['uid' => $userId], 0, 12
        );

        $sessions = [];
        foreach ($rawSessions as $s) {
            $cName = $cShort = '';
            foreach ($courses as $c) {
                if ($c->id == $s->courseid) { $cName = format_string($c->fullname); $cShort = $c->shortname; break; }
            }
            $e = time() - $s->lastactive;
            $t = $e < 3600 ? round($e/60).'m ago'
               : ($e < 86400 ? round($e/3600).'h ago'
               : ($e < 604800 ? round($e/86400).' days ago' : date('d M', $s->lastactive)));
            $sessions[] = [
                'session_key'  => $s->session_key,
                'courseid'     => (int)$s->courseid,
                'course_name'  => $cName,
                'course_short' => $cShort,
                'time_label'   => $t,
                'msg_count'    => (int)$s->msg_count,
                'preview'      => mb_strlen($s->first_q) > 110 ? mb_substr($s->first_q,0,107).'…' : $s->first_q,
            ];
        }

        $topTopics = [];
        $topCourses = $DB->get_records_sql(
            "SELECT courseid, COUNT(*) AS cnt FROM {umat_ai_chat_logs}
             WHERE userid=:uid AND role='student' AND timecreated>:s
             GROUP BY courseid ORDER BY cnt DESC",
            ['uid' => $userId, 's' => time()-30*DAYSECS], 0, 5
        );
        foreach ($topCourses as $t) {
            foreach ($courses as $c) {
                if ($c->id == $t->courseid) { $topTopics[] = ['label'=>$c->shortname,'count'=>(int)$t->cnt]; break; }
            }
        }

        return json_encode([
            'courses'        => $courseList,
            'week_sessions'  => $weekSessions,
            'week_questions' => $weekQuestions,
            'sessions'       => $sessions,
            'pulse_topics'   => $topTopics,
            'goal_progress'  => min(100, round($weekQuestions/20*100)),
        ]);
    }


    // ================================================================== //
    // SHARED STYLES                                                        //
    // ================================================================== //

    private static function shared_styles(): string {
        return <<<'STYLES'
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
<style id="umat-ai-v3">
:root{
  --u-p:#006b2f;--u-pb:#00873d;--u-op:#fff;
  --u-sf:#f5fbf0;--u-sfl:#eff6eb;--u-sflo:#fff;
  --u-ons:#171d17;--u-onsv:#3e4a3e;--u-ol:#6e7a6d;--u-olv:#bdcaba;
  --u-sec:#3d6844;--u-secc:#beefc1;--u-ter:#a5304d;--u-warn:#f59e0b;--u-ok:#4ade80;
  --u-r6:6px;--u-r8:8px;--u-r12:12px;--u-r16:16px;--u-r20:20px;--u-rp:9999px;
  --u-shadow:0 12px 40px rgba(0,0,0,.16);--u-fshadow:0 6px 22px rgba(0,107,47,.44);
  --u-sb-col:68px;--u-sb-exp:248px;
  --u-cb:cubic-bezier(.4,0,.2,1);--u-cbi:cubic-bezier(.68,-0.15,.27,1.15);
}
/* ==== SCROLLBAR (global within overlays) ==== */
.umat-ov *::-webkit-scrollbar{width:4px;height:4px;}
.umat-ov *::-webkit-scrollbar-track{background:transparent;}
.umat-ov *::-webkit-scrollbar-thumb{background:var(--u-olv);border-radius:3px;}
.umat-ov *::-webkit-scrollbar-thumb:hover{background:var(--u-ol);}
/* ==== ENTRANCE ANIMATIONS ==== */
@keyframes ufade{0%{opacity:0;transform:translateY(16px)}100%{opacity:1;transform:translateY(0)}}
@keyframes uslide{0%{opacity:0;transform:translateX(-12px)}100%{opacity:1;transform:translateX(0)}}
@keyframes uscale{0%{transform:scale(.95);opacity:0}100%{transform:scale(1);opacity:1}}
@keyframes uskel{0%{background-position:-200px 0}100%{background-position:calc(200px + 100%) 0}}
@keyframes uspin{0%{transform:rotate(0)}100%{transform:rotate(360deg)}}
.umat-ent{animation:ufade .4s var(--u-cb) forwards;}
.umat-ent-slide{animation:uslide .35s var(--u-cb) forwards;}
.umat-ent-scale{animation:uscale .3s var(--u-cb) forwards;}
/* ==== SKELETON LOADER ==== */
.umat-sk{background:linear-gradient(90deg,var(--u-sfl) 25%,var(--u-sflo) 50%,var(--u-sfl) 75%);
  background-size:200px 100%;animation:uskel 1.4s infinite;border-radius:var(--u-r6);}
.umat-sk-t{height:14px;width:65%;margin-bottom:8px;}
.umat-sk-s{height:11px;width:45%;margin-bottom:6px;}
.umat-sk-thumb{aspect-ratio:16/9;border-radius:var(--u-r12) var(--u-r12) 0 0;}
/* ==== PRESS RIPPLE ==== */
.umat-ripple{position:relative;overflow:hidden;}
.umat-ripple::after{content:'';position:absolute;inset:0;background:rgba(255,255,255,.25);opacity:0;transition:opacity .3s;}
.umat-ripple:active::after{opacity:1;transition:0s;}
/* ==== NO-SELECT (for FAB, interactive) ==== */
.umat-nosel{-webkit-user-select:none;user-select:none;}
/* ==== FAB ==== */
.umat-fab{position:fixed!important;bottom:28px!important;right:28px!important;z-index:9990!important;
  width:56px;height:56px;border-radius:50%;border:none;cursor:pointer;
  background:linear-gradient(135deg,var(--u-p),var(--u-pb));color:var(--u-op);
  box-shadow:var(--u-fshadow);display:flex;align-items:center;justify-content:center;
  transition:transform .25s var(--u-cb),box-shadow .25s var(--u-cb);font-family:inherit;text-decoration:none;}
.umat-fab:hover{transform:scale(1.1);box-shadow:0 8px 30px rgba(0,107,47,.58);}
.umat-fab:active{transform:scale(.95);}
.umat-fab .material-symbols-outlined{font-size:26px;transition:transform .25s var(--u-cbi);}
.umat-fab:hover .material-symbols-outlined{transform:scale(1.1) rotate(-8deg);}
@keyframes umat-pulse{0%{box-shadow:var(--u-fshadow),0 0 0 0 rgba(0,107,47,.5)}70%{box-shadow:var(--u-fshadow),0 0 0 14px rgba(0,107,47,0)}100%{box-shadow:var(--u-fshadow),0 0 0 0 rgba(0,107,47,0)}}
.umat-fab-pulse{animation:umat-pulse 2.8s infinite;}
.umat-fab-badge{position:absolute;top:-3px;right:-3px;min-width:20px;height:20px;padding:0 5px;
  background:var(--u-ter);color:#fff;border-radius:var(--u-rp);font-size:11px;font-weight:700;
  display:flex;align-items:center;justify-content:center;border:2px solid #fff;font-family:Inter,sans-serif;}
.umat-fab-tip{position:absolute;right:68px;white-space:nowrap;background:#1a1c19;color:#fff;
  padding:5px 11px;border-radius:var(--u-r8);font-size:12px;font-weight:500;
  opacity:0;pointer-events:none;transition:opacity .2s;font-family:Inter,sans-serif;}
.umat-fab-tip::after{content:'';position:absolute;right:-6px;top:50%;transform:translateY(-50%);
  border:6px solid transparent;border-left-color:#1a1c19;}
.umat-fab:hover .umat-fab-tip{opacity:1;}
/* ==== COMPACT PANEL ==== */
.umat-cp-ov{position:fixed!important;inset:0!important;z-index:9995!important;
  background:rgba(0,0,0,.32);backdrop-filter:blur(6px);display:none;justify-content:flex-end;}
.umat-cp-ov.open{display:flex;animation:ufade .25s var(--u-cb) forwards;}
.umat-cp{width:440px;max-width:96vw;height:100%;background:var(--u-sf);
  box-shadow:var(--u-shadow);display:flex;flex-direction:column;
  transform:translateX(100%);transition:transform .36s var(--u-cb);overflow:hidden;
  font-family:Inter,-apple-system,BlinkMacSystemFont,sans-serif;}
.umat-cp-ov.open .umat-cp{transform:translateX(0);}
.umat-cp-lec{width:480px;}
/* Panel header */
.umat-cp-hdr{background:linear-gradient(135deg,var(--u-p),var(--u-pb));color:var(--u-op);padding:15px 17px 12px;flex-shrink:0;}
.umat-cp-hdr-row{display:flex;align-items:center;gap:11px;}
.umat-cp-av{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.2);
  display:flex;align-items:center;justify-content:center;position:relative;flex-shrink:0;}
.umat-cp-av .material-symbols-outlined{font-size:22px;}
.umat-cp-dot{position:absolute;bottom:1px;right:1px;width:10px;height:10px;border-radius:50%;
  background:var(--u-ok);border:2px solid var(--u-p);}
@keyframes dot-pulse{0%,100%{opacity:1}50%{opacity:.35}}
.umat-cp-dot{animation:dot-pulse 2s infinite;}
.umat-cp-info{flex:1;min-width:0;}
.umat-cp-info h2{margin:0;font-size:14px;font-weight:700;}
.umat-cp-info .sub{font-size:11px;opacity:.85;margin-top:1px;}
.umat-cp-info .ctx{font-size:11px;opacity:.7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.umat-cp-hbtn{background:rgba(255,255,255,.18);border:none;color:var(--u-op);
  width:30px;height:30px;border-radius:50%;cursor:pointer;display:flex;align-items:center;
  justify-content:center;transition:background .2s;flex-shrink:0;}
.umat-cp-hbtn .material-symbols-outlined{font-size:16px;}
.umat-cp-hbtn:hover{background:rgba(255,255,255,.3);}
.umat-cp-exp{border-radius:var(--u-r6);width:auto;padding:0 11px;gap:5px;font-size:12px;font-weight:700;}
/* Panel tabs */
.umat-cp-tabs{display:flex;background:var(--u-sflo);border-bottom:1px solid var(--u-olv);flex-shrink:0;}
.umat-cp-tab{flex:1;padding:10px 5px;border:none;background:none;cursor:pointer;font-size:12px;
  font-weight:500;color:var(--u-ol);border-bottom:2.5px solid transparent;transition:all .2s;font-family:inherit;}
.umat-cp-tab:hover{color:var(--u-p);background:var(--u-sfl);}
.umat-cp-tab.active{color:var(--u-p);border-bottom-color:var(--u-p);font-weight:700;}
.umat-cp-pane{display:none;flex:1;flex-direction:column;overflow:hidden;}
.umat-cp-pane.active{display:flex;}
/* ==== FULL OVERLAY SHELL ==== */
.umat-ov{position:fixed!important;inset:0!important;z-index:99998!important;
  display:none;background:var(--u-sf);font-family:Inter,-apple-system,sans-serif;}
.umat-ov.open{display:flex;}
@keyframes ov-in{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.umat-ov.open{animation:ov-in .28s cubic-bezier(.4,0,.2,1) forwards;}
/* ==== COLLAPSIBLE SIDEBAR ==== */
.umat-sb{width:var(--u-sb-col);background:var(--u-sflo);border-right:1px solid var(--u-olv);
  display:flex;flex-direction:column;overflow:hidden;
  transition:width .32s cubic-bezier(.4,0,.2,1);flex-shrink:0;}
.umat-sb:hover{width:var(--u-sb-exp);}
.umat-sb-head{display:flex;align-items:center;gap:12px;padding:14px 16px;
  border-bottom:1px solid var(--u-olv);flex-shrink:0;min-height:70px;overflow:hidden;}
.umat-sb-logo{width:36px;height:36px;border-radius:var(--u-r8);
  background:linear-gradient(135deg,var(--u-p),var(--u-pb));color:#fff;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.umat-sb-logo .material-symbols-outlined{font-size:20px;}
.umat-sb-brand{white-space:nowrap;overflow:hidden;opacity:0;transition:opacity .2s;flex:1;}
.umat-sb:hover .umat-sb-brand{opacity:1;}
.umat-sb-brand strong{display:block;font-size:13px;font-weight:700;color:var(--u-p);}
.umat-sb-brand span{font-size:11px;color:var(--u-ol);}
.umat-sb-close-btn{background:rgba(0,0,0,.06);border:none;width:28px;height:28px;border-radius:50%;
  cursor:pointer;display:flex;align-items:center;justify-content:center;
  color:var(--u-ol);transition:all .2s;flex-shrink:0;opacity:0;}
.umat-sb:hover .umat-sb-close-btn{opacity:1;}
.umat-sb-close-btn:hover{background:#fee2e2;color:var(--u-ter);}
.umat-sb-close-btn .material-symbols-outlined{font-size:16px;}
.umat-sb-nav{flex:1;overflow-y:auto;overflow-x:hidden;padding:8px 0;display:flex;flex-direction:column;}
.umat-sb-nav::-webkit-scrollbar{width:3px;}
.umat-sb-nav::-webkit-scrollbar-thumb{background:var(--u-olv);border-radius:2px;}
.umat-sb-item{display:flex;align-items:center;gap:14px;padding:11px 16px;cursor:pointer;
  white-space:nowrap;overflow:hidden;text-decoration:none;color:var(--u-onsv);
  border-left:3px solid transparent;transition:background .18s,color .18s,border-color .18s;
  border:none;background:none;font-family:inherit;width:100%;position:relative;}
.umat-sb-item:hover{background:var(--u-sfl);color:var(--u-ons);}
.umat-sb-item.active{background:rgba(0,107,47,.08);color:var(--u-p);border-left-color:var(--u-p);font-weight:600;}
.umat-sb-item.active::before{content:'';position:absolute;left:-3px;top:50%;transform:translateY(-50%);
  width:3px;height:20px;background:var(--u-p);border-radius:0 2px 2px 0;}
.umat-sb-item .material-symbols-outlined{font-size:22px;flex-shrink:0;transition:transform .2s var(--u-cbi);}
.umat-sb-item:hover .material-symbols-outlined{transform:scale(1.1);}
.umat-sb-item-lbl{font-size:13px;font-weight:500;opacity:0;transition:opacity .18s,transform .2s;overflow:hidden;transform:translateX(-4px);}
.umat-sb:hover .umat-sb-item-lbl{opacity:1;transform:translateX(0);}
.umat-sb-divider{height:1px;background:var(--u-olv);margin:6px 12px;flex-shrink:0;}
.umat-sb-new{display:flex;align-items:center;gap:12px;margin:8px;padding:10px 12px;
  background:var(--u-p);color:#fff;border-radius:var(--u-r8);cursor:pointer;border:none;
  font-family:inherit;font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;
  transition:background .2s,transform .2s;flex-shrink:0;}
.umat-sb-new:hover{background:var(--u-pb);transform:scale(1.02);}
.umat-sb-new:active{transform:scale(.97);}
.umat-sb-new .material-symbols-outlined{font-size:18px;flex-shrink:0;}
.umat-sb-new-lbl{opacity:0;transition:opacity .18s,transform .2s;overflow:hidden;transform:translateX(-4px);}
.umat-sb:hover .umat-sb-new-lbl{opacity:1;transform:translateX(0);}
.umat-sb-foot{padding:8px 0 10px;border-top:1px solid var(--u-olv);flex-shrink:0;}
/* ==== CONTENT AREA ==== */
.umat-ov-content{flex:1;display:flex;flex-direction:column;overflow:hidden;position:relative;}
.umat-tab-pane{display:none;flex:1;flex-direction:column;overflow:hidden;}
.umat-tab-pane.active{display:flex;animation:ufade .32s var(--u-cb) forwards;}
.umat-tab-pane.active > *{animation:uslide .35s var(--u-cb) forwards;}
.umat-tab-pane.active > *:nth-child(2){animation-delay:.04s;}
.umat-tab-pane.active > *:nth-child(3){animation-delay:.08s;}
/* Content header bar */
.umat-content-hdr{height:52px;background:var(--u-sflo);border-bottom:1px solid var(--u-olv);
  display:flex;align-items:center;padding:0 20px;gap:12px;flex-shrink:0;}
.umat-content-hdr h2{margin:0;font-size:15px;font-weight:700;color:var(--u-ons);flex:1;}
.umat-content-hdr .pill{padding:3px 10px;border-radius:var(--u-rp);background:var(--u-secc);
  color:var(--u-sec);font-size:11px;font-weight:700;}
.umat-content-hdr-btn{background:none;border:1.5px solid var(--u-olv);color:var(--u-onsv);
  padding:6px 13px;border-radius:var(--u-r8);font-size:12px;font-weight:600;cursor:pointer;
  display:flex;align-items:center;gap:5px;font-family:inherit;transition:all .2s;}
.umat-content-hdr-btn .material-symbols-outlined{font-size:16px;transition:transform .2s var(--u-cbi);}
.umat-content-hdr-btn:hover{border-color:var(--u-p);color:var(--u-p);}
.umat-content-hdr-btn:active{transform:scale(.95);}
.umat-content-hdr-btn:hover .material-symbols-outlined{transform:scale(1.15);}
/* ==== HOME TAB ==== */
.umat-home-wrap{padding:24px;overflow-y:auto;flex:1;background:var(--u-sf);}
.umat-home-wrap::-webkit-scrollbar{width:5px;}
.umat-home-wrap::-webkit-scrollbar-thumb{background:var(--u-olv);border-radius:3px;}
.umat-home-hero{background:linear-gradient(135deg,var(--u-p),var(--u-pb));color:#fff;
  border-radius:var(--u-r16);padding:28px 32px;margin-bottom:22px;position:relative;overflow:hidden;}
.umat-home-hero::after{content:'';position:absolute;right:-20px;top:-20px;width:140px;height:140px;
  border-radius:50%;background:rgba(255,255,255,.07);}
.umat-home-hero h1{margin:0 0 6px;font-size:22px;font-weight:800;}
.umat-home-hero p{margin:0;font-size:14px;opacity:.88;}
.umat-home-hero .hero-sub{font-size:12px;opacity:.72;margin-top:4px;}
.umat-metrics-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px;}
.umat-metric-card{background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);
  padding:16px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 8px rgba(0,0,0,.04);
  transition:transform .25s var(--u-cb),box-shadow .25s var(--u-cb);}
.umat-metric-card:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.07);}
.umat-metric-icon{width:38px;height:38px;border-radius:var(--u-r8);
  display:flex;align-items:center;justify-content:center;}
.umat-metric-icon .material-symbols-outlined{font-size:22px;}
.mi-g{background:rgba(0,107,47,.1);color:var(--u-p);}
.mi-s{background:rgba(61,104,68,.1);color:var(--u-sec);}
.mi-w{background:rgba(245,158,11,.1);color:#d97706;}
.mi-r{background:rgba(165,48,77,.1);color:var(--u-ter);}
.umat-metric-val{font-size:24px;font-weight:800;color:var(--u-ons);line-height:1;}
.umat-metric-lbl{font-size:11px;color:var(--u-ol);margin-top:2px;}
.umat-home-section{margin-bottom:22px;}
.umat-home-section h3{margin:0 0 12px;font-size:14px;font-weight:700;color:var(--u-ons);}
.umat-quick-actions-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;}
.umat-qa-btn{display:flex;align-items:center;gap:10px;padding:13px 16px;
  background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);
  cursor:pointer;font-family:inherit;transition:all .2s;text-align:left;}
.umat-qa-btn .material-symbols-outlined{font-size:22px;color:var(--u-p);flex-shrink:0;}
.umat-qa-btn-text strong{display:block;font-size:13px;font-weight:600;color:var(--u-ons);}
.umat-qa-btn-text span{font-size:11px;color:var(--u-ol);}
.umat-qa-btn:hover{border-color:var(--u-p);background:rgba(0,107,47,.04);transform:translateY(-2px);}
.umat-qa-btn:active{transform:translateY(0);}
.umat-pending-banner{background:#fef3c7;border:1px solid #fcd34d;border-radius:var(--u-r12);
  padding:14px 18px;display:flex;align-items:center;gap:12px;margin-bottom:16px;}
.umat-pending-banner .material-symbols-outlined{font-size:22px;color:#d97706;flex-shrink:0;}
.umat-pending-banner p{margin:0;font-size:13px;color:#92400e;font-weight:600;}
.umat-goal-bar-wrap{margin-top:12px;}
.umat-goal-bar-row{display:flex;justify-content:space-between;font-size:12px;color:var(--u-onsv);margin-bottom:6px;}
.umat-goal-bar-row strong{color:var(--u-p);}
.umat-goal-bar{height:8px;background:var(--u-sfl);border-radius:4px;overflow:hidden;}
.umat-goal-fill{height:100%;background:linear-gradient(90deg,var(--u-p),var(--u-pb));
  border-radius:4px;transition:width .5s ease;}
/* ==== MESSAGES / CHAT ==== */
.umat-msgs{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:12px;background:var(--u-sf);}
.umat-msgs::-webkit-scrollbar{width:4px;}
.umat-msgs::-webkit-scrollbar-thumb{background:var(--u-olv);border-radius:2px;}
.umat-msg-ai{display:flex;gap:8px;align-items:flex-start;animation:uslide .32s var(--u-cb) forwards;}
.umat-msg-ai-ic{width:30px;height:30px;border-radius:50%;background:rgba(0,107,47,.12);
  color:var(--u-p);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.umat-msg-ai-ic .material-symbols-outlined{font-size:15px;}
.umat-msg-ai-wrap{display:flex;flex-direction:column;gap:4px;}
.umat-msg-lbl{font-size:10px;font-weight:700;color:var(--u-p);letter-spacing:.04em;}
.umat-bubble-ai{background:var(--u-sflo);border-left:2.5px solid var(--u-p);padding:10px 13px;
  border-radius:0 var(--u-r12) var(--u-r12) var(--u-r12);font-size:13px;line-height:1.55;
  color:var(--u-ons);box-shadow:0 1px 6px rgba(0,0,0,.05);}
.umat-bubble-ai p{margin:0;}
.umat-msg-user{display:flex;justify-content:flex-end;animation:uslide .32s var(--u-cb) forwards;}
.umat-bubble-user{background:linear-gradient(135deg,#dcfce7,#bbf7d0);color:#052e16;
  padding:10px 13px;border-radius:var(--u-r12) 0 var(--u-r12) var(--u-r12);
  font-size:13px;line-height:1.55;max-width:86%;}
.umat-bubble-user p{margin:0;}
.umat-chips-row{display:flex;flex-wrap:wrap;gap:6px;margin-top:7px;}
.umat-chip{padding:4px 11px;border-radius:var(--u-rp);border:1.5px solid var(--u-olv);
  background:var(--u-sflo);font-size:11px;font-weight:600;color:var(--u-onsv);cursor:pointer;
  transition:all .2s;font-family:inherit;}
.umat-chip:hover{border-color:var(--u-p);color:var(--u-p);background:rgba(0,107,47,.04);}
.umat-src-chips{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px;}
.umat-src-chip{padding:2px 8px;border-radius:var(--u-rp);background:var(--u-secc);
  color:var(--u-sec);font-size:10px;font-weight:700;}
@keyframes dot-b{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-5px)}}
.umat-typing{display:flex;gap:4px;padding:8px 0;}
.umat-typing span{width:7px;height:7px;border-radius:50%;background:var(--u-p);animation:dot-b 1.2s infinite;}
.umat-typing span:nth-child(2){animation-delay:.2s;}
.umat-typing span:nth-child(3){animation-delay:.4s;}
/* ==== AI INPUT AREA ==== */
.umat-input-area{padding:11px 14px;background:var(--u-sflo);border-top:1px solid var(--u-olv);flex-shrink:0;position:relative;}
.umat-input-row{display:flex;gap:8px;align-items:flex-end;}
.umat-textarea{flex:1;padding:9px 12px;border:1.5px solid var(--u-olv);border-radius:var(--u-r8);
  font-size:13px;font-family:inherit;resize:none;outline:none;line-height:1.45;
  color:var(--u-ons);background:var(--u-sf);transition:border-color .2s;}
.umat-textarea:focus{border-color:var(--u-p);box-shadow:0 0 0 3px rgba(0,107,47,.12);}
.umat-textarea::placeholder{color:var(--u-ol);opacity:.7;transition:opacity .2s;}
.umat-textarea:focus::placeholder{opacity:.4;}
.umat-textarea:disabled{opacity:.5;cursor:not-allowed;}
.umat-send-btn{width:40px;height:40px;border-radius:var(--u-r8);background:var(--u-p);
  color:var(--u-op);border:none;cursor:pointer;display:flex;align-items:center;
  justify-content:center;flex-shrink:0;transition:background .2s;}
.umat-send-btn .material-symbols-outlined{font-size:19px;transition:transform .2s var(--u-cbi);}
.umat-send-btn:hover{background:var(--u-pb);}
.umat-send-btn:active{transform:scale(.92);}
.umat-send-btn:hover .material-symbols-outlined{transform:scale(1.1);}
.umat-input-actions{display:flex;justify-content:space-between;align-items:center;margin-top:6px;}
.umat-ia-btn{background:none;border:none;color:var(--u-ol);font-size:11px;font-weight:500;
  cursor:pointer;display:flex;align-items:center;gap:3px;font-family:inherit;
  padding:4px 6px;border-radius:var(--u-r6);transition:all .2s;}
.umat-ia-btn .material-symbols-outlined{font-size:15px;}
.umat-ia-btn:hover{background:var(--u-sfl);color:var(--u-p);}
.umat-ia-btn.recording{color:var(--u-ter);background:rgba(165,48,77,.08);}
@keyframes mic-pulse{0%,100%{opacity:1}50%{opacity:.4}}
.umat-ia-btn.recording .material-symbols-outlined{animation:mic-pulse .8s infinite;}
/* ==== ATTACHMENT DRAWER ==== */
.umat-attach-drawer{position:absolute;top:100%;left:0;right:0;
  background:var(--u-sflo,#fff);border:1px solid var(--u-olv);border-radius:var(--u-r12) var(--u-r12) 0 0;
  box-shadow:0 -8px 24px rgba(0,0,0,.1);max-height:360px;display:flex;flex-direction:column;
  transform:translateY(0);transition:transform .3s cubic-bezier(.4,0,.2,1);overflow:hidden;}
.umat-mat-bar{display:flex;flex-wrap:wrap;gap:6px;padding:0 14px 6px;flex-shrink:0;}
.umat-mat-bar:empty{display:none;}
.umat-mat-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 8px 3px 10px;
  background:var(--u-sfl,#eff6eb);border:1px solid var(--u-olv);border-radius:var(--u-rp);
  font-size:11px;font-weight:600;color:var(--u-ons);max-width:200px;}
.umat-mat-chip-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.umat-mat-chip-remove{background:none;border:none;cursor:pointer;color:var(--u-ol);padding:0;
  width:16px;height:16px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-size:14px;line-height:1;flex-shrink:0;transition:color .15s,background .15s;}
.umat-mat-chip-remove:hover{color:var(--u-ons);background:rgba(0,0,0,.06);}
.umat-attach-drawer.open{transform:translateY(-100%);}
.umat-drawer-hdr{padding:13px 16px;border-bottom:1px solid var(--u-olv);
  display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.umat-drawer-hdr h4{margin:0;font-size:14px;font-weight:700;color:var(--u-ons);}
.umat-drawer-hdr-close{background:none;border:none;cursor:pointer;color:var(--u-ol);
  width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;}
.umat-drawer-hdr-close .material-symbols-outlined{font-size:18px;}
.umat-drawer-search{padding:9px 14px;border-bottom:1px solid var(--u-olv);flex-shrink:0;}
.umat-drawer-search input{width:100%;padding:7px 11px;border:1px solid var(--u-olv);
  border-radius:var(--u-r8);font-size:13px;outline:none;font-family:inherit;
  color:var(--u-ons);background:var(--u-sf);transition:border-color .2s;}
.umat-drawer-search input:focus{border-color:var(--u-p);box-shadow:0 0 0 3px rgba(0,107,47,.09);}
.umat-drawer-list{overflow-y:auto;flex:1;padding:8px 14px;}
.umat-drawer-list::-webkit-scrollbar{width:4px;}
.umat-drawer-list::-webkit-scrollbar-thumb{background:var(--u-olv);border-radius:2px;}
.umat-drawer-item{display:flex;align-items:center;gap:10px;padding:9px 10px;
  border-radius:var(--u-r8);cursor:pointer;transition:background .15s;}
.umat-drawer-item:hover{background:var(--u-sfl);}
.umat-drawer-item input[type=checkbox]{width:16px;height:16px;accent-color:var(--u-p);flex-shrink:0;}
.umat-drawer-item-icon{width:32px;height:32px;border-radius:var(--u-r6);
  display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.di-pdf{background:rgba(239,68,68,.1);color:#dc2626;}
.di-video{background:rgba(0,107,47,.1);color:var(--u-p);}
.di-doc{background:rgba(59,130,246,.1);color:#2563eb;}
.di-img{background:rgba(245,158,11,.1);color:#d97706;}
.umat-drawer-item-info{flex:1;min-width:0;}
.umat-drawer-item-info strong{display:block;font-size:12px;font-weight:600;
  color:var(--u-ons);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.umat-drawer-item-info span{font-size:10px;color:var(--u-ol);}
.umat-drawer-foot{padding:10px 14px;border-top:1px solid var(--u-olv);
  display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.umat-drawer-confirm{padding:8px 16px;background:var(--u-p);color:#fff;border:none;
  border-radius:var(--u-r8);font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;
  transition:background .2s,transform .2s;}
.umat-drawer-confirm:hover{background:var(--u-pb);}
.umat-drawer-confirm:active{transform:scale(.96);}
.umat-card-enter{animation:ufade .4s var(--u-cb) forwards;}
/* ==== VIDEO TILE GRID (YouTube-style) ==== */
.umat-video-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));
  gap:16px;padding:20px;overflow-y:auto;flex:1;}
.umat-video-grid::-webkit-scrollbar{width:5px;}
.umat-video-grid::-webkit-scrollbar-thumb{background:var(--u-olv);border-radius:3px;}
/* ==== VIDEO TILE (16:9 overlay style) ==== */
.umat-video-tile{aspect-ratio:16/9;border-radius:var(--u-r12);overflow:hidden;cursor:pointer;
  position:relative;background:linear-gradient(135deg,#1a1c19,#2d3a2d);
  transition:transform .25s var(--u-cb),box-shadow .25s var(--u-cb);
  animation:ufade .4s var(--u-cb) forwards;}
.umat-video-tile:hover{transform:translateY(-4px);box-shadow:0 12px 28px rgba(0,0,0,.25);}
.umat-video-thumb{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;}
.umat-video-thumb .umat-vid-play-icon{font-size:44px;color:rgba(255,255,255,.5);
  transition:all .25s var(--u-cb);z-index:1;filter:drop-shadow(0 2px 8px rgba(0,0,0,.3));}
.umat-video-tile:hover .umat-vid-play-icon{color:#fff;transform:scale(1.15);}
.umat-duration-badge{position:absolute;bottom:8px;right:8px;background:rgba(0,0,0,.8);
  color:#fff;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:700;z-index:1;
  font-family:Inter,sans-serif;}
.umat-video-tile-info{position:absolute;bottom:0;left:0;right:0;
  padding:20px 10px 8px;display:flex;align-items:flex-end;gap:8px;
  background:linear-gradient(transparent,rgba(0,0,0,.82));
  pointer-events:none;}
.umat-video-tile-info h4{flex:1;margin:0;font-size:12px;font-weight:700;color:#fff;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;
  text-shadow:0 1px 4px rgba(0,0,0,.5);pointer-events:auto;}
.umat-vid-time{font-size:10px;color:rgba(255,255,255,.65);white-space:nowrap;
  text-shadow:0 1px 3px rgba(0,0,0,.4);pointer-events:auto;}
.umat-video-tile-dl{display:flex;align-items:center;justify-content:center;width:26px;height:26px;
  border-radius:50%;color:rgba(255,255,255,.7);text-decoration:none;transition:all .2s;
  flex-shrink:0;pointer-events:auto;}
.umat-video-tile-dl .material-symbols-outlined{font-size:15px;}
.umat-video-tile-dl:hover{background:rgba(255,255,255,.15);color:#fff;text-decoration:none;}
/* ==== VIDEO PLAYER PANEL ==== */
.umat-player-panel{position:absolute;inset:0;background:var(--u-sf);z-index:10;
  display:none;flex-direction:column;overflow:hidden;}
.umat-player-panel.open{display:flex;}
.umat-player-top{background:var(--u-sflo);border-bottom:1px solid var(--u-olv);
  padding:10px 16px;display:flex;align-items:center;gap:12px;flex-shrink:0;}
.umat-player-back{background:none;border:none;cursor:pointer;color:var(--u-p);
  display:flex;align-items:center;gap:5px;font-size:13px;font-weight:600;font-family:inherit;}
.umat-player-back .material-symbols-outlined{font-size:18px;}
.umat-player-title{flex:1;font-size:14px;font-weight:700;color:var(--u-ons);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.umat-player-dl-btn{display:flex;align-items:center;gap:5px;padding:7px 13px;
  background:var(--u-p);color:#fff;border:none;border-radius:var(--u-r8);
  font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;}
.umat-player-dl-btn .material-symbols-outlined{font-size:16px;}
.umat-player-dl-btn:active{transform:scale(.96);}
.umat-player-dl-btn:hover{background:var(--u-pb);}
.umat-player-body{display:flex;flex:1;overflow:hidden;}
.umat-player-left{flex:1;display:flex;flex-direction:column;overflow:hidden;border-right:1px solid var(--u-olv);}
.umat-player-video-wrap{background:#000;flex-shrink:0;position:relative;}
.umat-player-video-wrap video{width:100%;display:block;max-height:55vh;object-fit:contain;}
.umat-vc{display:flex;align-items:center;gap:10px;padding:8px 14px;
  background:linear-gradient(0,rgba(0,0,0,.85),transparent);
  position:absolute;bottom:0;left:0;right:0;}
.umat-vc-btn{background:none;border:none;color:#fff;cursor:pointer;padding:4px;}
.umat-vc-btn .material-symbols-outlined{font-size:22px;}
.umat-vc-time{color:#fff;font-size:12px;font-family:inherit;}
.umat-vc-progress{flex:1;height:4px;-webkit-appearance:none;appearance:none;
  background:rgba(255,255,255,.3);border-radius:2px;cursor:pointer;}
.umat-vc-progress::-webkit-slider-thumb{-webkit-appearance:none;width:12px;height:12px;border-radius:50%;background:#fff;}
.umat-player-transcript{flex:1;display:flex;flex-direction:column;overflow:hidden;margin:10px 12px 12px;
  background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);}
.umat-ts-hdr{padding:11px 14px;border-bottom:1px solid var(--u-olv);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.umat-ts-hdr h4{margin:0;font-size:13px;font-weight:700;display:flex;align-items:center;gap:6px;}
.umat-ts-hdr h4 .material-symbols-outlined{font-size:15px;color:var(--u-p);}
.umat-ts-srch{display:flex;align-items:center;gap:5px;padding:5px 9px;
  border:1px solid var(--u-olv);border-radius:var(--u-r8);background:var(--u-sf);}
.umat-ts-srch .material-symbols-outlined{font-size:14px;color:var(--u-ol);}
.umat-ts-srch input{border:none;background:none;outline:none;font-size:12px;width:100px;font-family:inherit;}
.umat-ts-srch input:focus{width:140px;}
.umat-ts-srch input::placeholder{color:var(--u-ol);}
.umat-ts-body{flex:1;overflow-y:auto;padding:6px;}
.umat-ts-seg{display:flex;gap:9px;padding:7px 9px;border-radius:var(--u-r8);cursor:pointer;transition:background .15s;}
.umat-ts-seg:hover{background:var(--u-sfl);}
.umat-ts-seg.active{background:rgba(0,107,47,.09);border-left:3px solid var(--u-p);padding-left:6px;}
.umat-ts-time{font-size:10px;font-weight:700;color:var(--u-p);white-space:nowrap;min-width:32px;}
.umat-ts-text{font-size:12px;color:var(--u-ons);line-height:1.5;margin:0;}
.umat-ts-seg.active .umat-ts-text{font-weight:600;}
.umat-player-right{width:380px;flex-shrink:0;display:flex;flex-direction:column;background:var(--u-sflo);}
/* ==== MATERIAL TILES (horizontal row) ==== */
.umat-lib-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:10px;padding:20px;overflow-y:auto;flex:1;}
.umat-lib-tile{background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r10);box-shadow:0 1px 4px rgba(0,0,0,.04);
  transition:transform .2s var(--u-cb),box-shadow .2s var(--u-cb);
  animation:ufade .35s var(--u-cb) forwards;
  display:flex;align-items:center;gap:10px;padding:8px 10px;cursor:default;}
.umat-lib-tile:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.08);}
.umat-lib-tile-icon{width:36px;height:36px;border-radius:var(--u-r8);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.umat-lib-tile-icon .material-symbols-outlined{font-size:22px;}
.lt-pdf{background:#fef2f2;color:#dc2626;}
.lt-video{background:#f0fdf4;color:var(--u-p);}
.lt-doc{background:#eff6ff;color:#2563eb;}
.lt-img{background:#fffbeb;color:#d97706;}
.lt-other{background:var(--u-sfl);color:var(--u-ol);}
.umat-lib-tile-info{flex:1;min-width:0;}
.umat-lib-tile-info strong{display:block;font-size:11px;font-weight:700;color:var(--u-ons);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-bottom:1px;}
.umat-lib-meta{font-size:9px;color:var(--u-onsv);display:inline;line-height:1.3;font-weight:500;}
.umat-lib-time{font-size:9px;color:var(--u-ol);display:inline;margin-left:4px;}
.umat-lib-tile-actions{display:flex;gap:4px;flex-shrink:0;}
.umat-lib-btn{padding:5px 8px;border:1px solid var(--u-olv);background:var(--u-sf);
  color:var(--u-onsv);border-radius:var(--u-r6);font-size:9px;font-weight:600;
  cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:3px;transition:all .15s;white-space:nowrap;}
.umat-lib-btn .material-symbols-outlined{font-size:13px;transition:transform .2s var(--u-cbi);}
.umat-lib-btn:hover{border-color:var(--u-p);color:var(--u-p);background:var(--u-sflo);}
.umat-lib-btn:hover .material-symbols-outlined{transform:scale(1.15);}
.umat-lib-btn:active{transform:scale(.96);}
.umat-pdf-viewer-wrap{position:absolute;inset:0;z-index:10;background:var(--u-sf);display:none;flex-direction:column;}
.umat-pdf-viewer-wrap.open{display:flex;}
.umat-pdf-viewer-bar{padding:10px 16px;background:var(--u-sflo);border-bottom:1px solid var(--u-olv);display:flex;align-items:center;gap:12px;flex-shrink:0;}
.umat-pdf-viewer-bar h4{flex:1;margin:0;font-size:14px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.umat-pdf-viewer-back{background:none;border:none;cursor:pointer;color:var(--u-p);display:flex;align-items:center;gap:5px;font-size:13px;font-weight:600;font-family:inherit;}
.umat-pdf-viewer-back .material-symbols-outlined{font-size:18px;}
.umat-pdf-iframe{flex:1;border:none;background:#fff;}
/* ==== COURSES GRID ==== */
.umat-courses-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;padding:20px;overflow-y:auto;flex:1;}
/* ==== LECTURER COURSE CARDS (compact) ==== */
.umat-lec-course{background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);overflow:hidden;cursor:pointer;transition:transform .25s var(--u-cb),box-shadow .25s var(--u-cb),border-color .25s;box-shadow:0 2px 6px rgba(0,0,0,.04);}
.umat-lec-course:hover{border-color:var(--u-p);box-shadow:0 8px 26px rgba(0,107,47,.16);transform:translateY(-3px);}
.umat-lec-course:active{transform:translateY(-1px);}
.umat-lec-c-body{padding:14px;}
.umat-lec-c-row{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
.umat-lec-c-icon{width:34px;height:34px;border-radius:var(--u-r8);background:linear-gradient(135deg,var(--u-p),var(--u-pb));color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.umat-lec-c-icon .material-symbols-outlined{font-size:18px;}
.umat-lec-c-info{flex:1;min-width:0;}
.umat-lec-c-info h4{margin:0;font-size:13px;font-weight:700;color:var(--u-ons);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.umat-lec-c-meta{font-size:10px;color:var(--u-ol);}
.umat-lec-c-stats{display:flex;gap:6px;margin-bottom:10px;}
.umat-lec-c-stat{display:flex;align-items:center;gap:3px;font-size:10px;font-weight:600;color:var(--u-onsv);padding:3px 8px;background:var(--u-sfl);border-radius:var(--u-r6);}
.umat-lec-c-stat .material-symbols-outlined{font-size:13px;color:var(--u-olv);}
.umat-lec-c-stat.stat-warn{color:#d97706;background:rgba(217,119,6,.08);}
.umat-lec-c-stat.stat-warn .material-symbols-outlined{color:#d97706;}
.umat-lec-c-actions{display:flex;gap:4px;}
.umat-lec-c-act{display:flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:var(--u-r6);font-size:0;cursor:pointer;font-family:inherit;border:1px solid var(--u-olv);background:var(--u-sf);color:var(--u-onsv);transition:all .15s;padding:0;}
.umat-lec-c-act .material-symbols-outlined{font-size:16px;transition:transform .2s var(--u-cbi);}
.umat-lec-c-act:hover{background:var(--u-sflo);border-color:var(--u-p);color:var(--u-p);}
.umat-lec-c-act:hover .material-symbols-outlined{transform:scale(1.15);}
.umat-lec-c-act:active{transform:scale(.96);}
.umat-lec-c-act.primary{background:rgba(0,107,47,.06);border-color:rgba(0,107,47,.2);color:var(--u-p);}
.umat-lec-c-act.primary:hover{background:var(--u-p);color:#fff;border-color:var(--u-p);}
.umat-lec-c-act.review{background:rgba(217,119,6,.08);border-color:rgba(217,119,6,.2);color:#d97706;}
.umat-lec-c-act.review:hover{background:#d97706;color:#fff;border-color:#d97706;}
.umat-lec-c-act[title]{position:relative;}
.umat-lec-c-act[title]:hover::after{content:attr(title);position:absolute;bottom:calc(100%+4px);left:50%;transform:translateX(-50%);background:#1a1c19;color:#fff;padding:3px 8px;border-radius:4px;font-size:10px;white-space:nowrap;z-index:5;}
.umat-course-tile{background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);
  padding:18px;cursor:pointer;transition:transform .25s var(--u-cb),box-shadow .25s var(--u-cb),border-color .25s;
  display:flex;align-items:center;gap:14px;
  box-shadow:0 2px 6px rgba(0,0,0,.04);}
.umat-course-tile:hover{border-color:var(--u-p);box-shadow:0 6px 20px rgba(0,107,47,.16);transform:translateY(-3px);}
.umat-course-tile:active{transform:translateY(-1px);}
.umat-course-tile-icon{width:44px;height:44px;border-radius:var(--u-r12);
  background:linear-gradient(135deg,var(--u-p),var(--u-pb));color:#fff;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.umat-course-tile-icon .material-symbols-outlined{font-size:24px;}
.umat-course-tile-info{flex:1;min-width:0;}
.umat-course-tile-info h4{margin:0 0 3px;font-size:14px;font-weight:700;color:var(--u-ons);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.umat-course-tile-info span{font-size:11px;color:var(--u-ol);}
.umat-course-tile-arrow .material-symbols-outlined{font-size:20px;color:var(--u-olv);}
.umat-course-tile:hover .umat-course-tile-arrow .material-symbols-outlined{color:var(--u-p);}
/* ==== SESSION TILES ==== */
.umat-sessions-list{display:flex;flex-direction:column;gap:10px;padding:20px;overflow-y:auto;flex:1;}
.umat-sessions-list::-webkit-scrollbar{width:5px;}
.umat-sessions-list::-webkit-scrollbar-thumb{background:var(--u-olv);border-radius:3px;}
.umat-session-tile{background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);
  padding:16px;cursor:pointer;transition:transform .25s var(--u-cb),box-shadow .25s var(--u-cb),border-color .25s;
  box-shadow:0 2px 6px rgba(0,0,0,.04);}
.umat-session-tile:hover{transform:translateY(-3px);border-color:var(--u-p);box-shadow:0 8px 22px rgba(0,107,47,.14);}
.umat-session-tile:active{transform:translateY(-1px);}
.umat-session-tile-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;}
.umat-session-badge{padding:3px 9px;border-radius:var(--u-rp);background:var(--u-secc);color:var(--u-sec);font-size:11px;font-weight:700;
  transition:background .2s,color .2s;}
.umat-session-tile:hover .umat-session-badge{background:var(--u-p);color:#fff;}
.umat-session-time{font-size:11px;color:var(--u-ol);}
.umat-session-tile h4{margin:0 0 5px;font-size:14px;font-weight:700;color:var(--u-ons);}
.umat-session-tile p{margin:0 0 10px;font-size:12px;color:var(--u-onsv);line-height:1.4;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.umat-session-tile-foot{display:flex;align-items:center;justify-content:space-between;}
.umat-session-meta{font-size:11px;color:var(--u-ol);display:flex;align-items:center;gap:4px;}
.umat-session-meta .material-symbols-outlined{font-size:13px;}
.umat-resume-btn{padding:5px 13px;background:var(--u-p);color:#fff;border:none;
  border-radius:var(--u-r6);font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .2s;}
.umat-resume-btn:active{transform:scale(.95);}
.umat-resume-btn:hover{background:var(--u-pb);}
/* ==== ANALYTICS WIDGETS ==== */
.umat-an-scroll{flex:1;overflow-y:auto;padding:20px;background:var(--u-sf);}
.umat-an-scroll::-webkit-scrollbar{width:5px;}
.umat-an-scroll::-webkit-scrollbar-thumb{background:var(--u-olv);border-radius:3px;}
.umat-an-kpi-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px;}
.umat-an-kpi{background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:15px;box-shadow:0 2px 6px rgba(0,0,0,.04);transition:transform .25s var(--u-cb),box-shadow .25s var(--u-cb);}
.umat-an-kpi:hover{transform:translateY(-3px);box-shadow:0 10px 24px rgba(0,0,0,.08);}
.umat-an-kpi-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
.umat-an-kpi-ico{width:34px;height:34px;border-radius:var(--u-r8);display:flex;align-items:center;justify-content:center;}
.umat-an-kpi-ico .material-symbols-outlined{font-size:18px;}
.ak-g{background:rgba(0,107,47,.1);color:var(--u-p);}
.ak-s{background:rgba(61,104,68,.1);color:var(--u-sec);}
.ak-w{background:rgba(245,158,11,.1);color:#d97706;}
.ak-r{background:rgba(165,48,77,.1);color:var(--u-ter);}
.umat-an-kpi-pill{padding:2px 8px;border-radius:var(--u-rp);font-size:10px;font-weight:700;}
.pill-g{background:#dcfce7;color:#065f46;}.pill-w{background:#fef3c7;color:#78350f;}.pill-r{background:#fee2e2;color:#991b1b;}.pill-b{background:#dbeafe;color:#1e40af;}
.umat-an-kpi-lbl{font-size:11px;color:var(--u-ol);margin-bottom:3px;}
.umat-an-kpi-val{font-size:26px;font-weight:800;color:var(--u-ons);line-height:1;}
.umat-an-kpi-sub{font-size:11px;color:var(--u-ol);margin-top:2px;}
.umat-an-2col{display:grid;grid-template-columns:1.6fr 1fr;gap:14px;margin-bottom:16px;}
.umat-an-card{background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.04);transition:box-shadow .25s var(--u-cb);}
.umat-an-card:hover{box-shadow:0 8px 24px rgba(0,0,0,.08);}
.umat-an-card-hdr{padding:13px 16px;border-bottom:1px solid var(--u-olv);display:flex;align-items:center;justify-content:space-between;}
.umat-an-card-title{margin:0;font-size:13px;font-weight:700;color:var(--u-ons);display:flex;align-items:center;gap:6px;}
.umat-an-card-title .material-symbols-outlined{font-size:16px;color:var(--u-p);}
.umat-an-card-body{padding:16px;}
.umat-chart-canvas{width:100%;height:180px;}
.umat-perf-item{margin-bottom:12px;}
.umat-perf-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;}
.umat-perf-lbl{font-size:12px;font-weight:600;color:var(--u-ons);}
.umat-perf-num{font-size:12px;color:var(--u-ol);}
.umat-perf-bar{height:8px;border-radius:4px;background:var(--u-sfl);overflow:hidden;}
.umat-perf-fill{height:100%;border-radius:4px;}
.pf-high{background:var(--u-p);}.pf-track{background:#f59e0b;}.pf-risk{background:var(--u-ter);}
.umat-hm-grid{display:grid;gap:4px;}
.umat-hm-cell{border-radius:4px;aspect-ratio:1;min-height:32px;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:600;cursor:default;transition:transform .2s var(--u-cbi),box-shadow .2s;}
.umat-hm-cell:hover{transform:scale(1.12);z-index:2;}
.umat-hm-row-lbl{font-size:10px;color:var(--u-ol);display:flex;align-items:center;justify-content:flex-end;padding-right:6px;}
.umat-hm-legend{display:flex;align-items:center;gap:7px;margin-top:10px;font-size:11px;color:var(--u-ol);}
.umat-hm-legend-sw{width:12px;height:12px;border-radius:2px;}
.umat-q-row{display:flex;align-items:center;gap:12px;padding:13px 16px;border-bottom:1px solid var(--u-sfl);}
.umat-q-row:last-child{border-bottom:none;}
.umat-q-row:hover{background:var(--u-sfl);}
.umat-q-votes{min-width:48px;text-align:center;}
.umat-q-votes .v-n{font-size:20px;font-weight:800;color:var(--u-p);line-height:1;}
.umat-q-votes .v-l{font-size:9px;font-weight:700;color:var(--u-ol);text-transform:uppercase;letter-spacing:.06em;}
.umat-q-content{flex:1;min-width:0;}
.umat-q-text{font-size:13px;color:var(--u-ons);margin-bottom:2px;line-height:1.4;}
.umat-q-related{font-size:10px;color:var(--u-ol);}
.umat-q-related span{color:var(--u-p);font-weight:600;}
.umat-q-action-btn{padding:5px 12px;border:none;background:none;color:var(--u-p);
  font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;border-radius:var(--u-r6);transition:all .2s;white-space:nowrap;}
.umat-q-action-btn:hover{background:rgba(0,107,47,.08);}
.umat-q-action-btn:active{transform:scale(.95);}
.umat-an-ai-insight{display:flex;align-items:flex-start;gap:10px;padding:13px;
  background:#fffbeb;border:1px solid #fde68a;border-radius:var(--u-r8);margin-top:12px;
  animation:uslide .35s var(--u-cb) forwards;}
.umat-an-ai-insight .material-symbols-outlined{font-size:20px;color:var(--u-warn);flex-shrink:0;}
.umat-an-insight-text strong{font-size:13px;display:block;margin-bottom:2px;}
.umat-an-insight-text span{font-size:12px;color:var(--u-onsv);}
/* ==== REVIEW OUTPUTS ==== */
.umat-rev-sess{background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);margin-bottom:16px;overflow:hidden;animation:ufade .38s var(--u-cb) forwards;}
.umat-rev-shdr{display:flex;align-items:center;gap:10px;padding:12px 16px;background:var(--u-sf);border-bottom:1px solid var(--u-olv);}
.umat-rev-shdr>div{flex:1;}
.umat-rev-shdr strong{display:block;font-size:13px;color:var(--u-ons);}
.umat-rev-shdr span{font-size:11px;color:var(--u-ol);}
.umat-rev-badge{padding:3px 10px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:11px;font-weight:700;white-space:nowrap;}
.umat-rev-out{border-bottom:1px solid var(--u-olv);padding:14px 16px;transition:all .4s;}
.umat-rev-out:last-child{border-bottom:none;}
.umat-rev-ohdr{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.umat-rev-type{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:var(--u-r6);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;}
.umat-rev-type .material-symbols-outlined{font-size:14px;}
.type-summary{background:rgba(59,130,246,.1);color:#2563eb;}
.type-notes{background:rgba(16,185,129,.1);color:#059669;}
.type-quiz{background:rgba(245,158,11,.1);color:#d97706;}
.umat-rev-date{font-size:11px;color:var(--u-ol);margin-left:auto;}
.umat-rev-cont{font-size:13px;color:var(--u-onsv);line-height:1.5;white-space:pre-wrap;margin-bottom:10px;max-height:200px;overflow-y:auto;background:var(--u-sf);border-radius:var(--u-r6);padding:10px;border:1px solid var(--u-olv);}
.umat-rev-acts{display:flex;gap:8px;}
.umat-rev-btn{display:flex;align-items:center;gap:5px;padding:7px 14px;border-radius:var(--u-r8);font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;border:1.5px solid transparent;transition:all .2s;}
.umat-rev-btn .material-symbols-outlined{font-size:16px;}
.umat-rev-btn.rev-ap{background:rgba(16,185,129,.1);color:#059669;border-color:rgba(16,185,129,.25);}
.umat-rev-btn.rev-ap:hover{background:#059669;color:#fff;}
.umat-rev-btn.rev-rj{background:rgba(239,68,68,.08);color:#dc2626;border-color:rgba(239,68,68,.18);}
.umat-rev-btn.rev-rj:hover{background:#dc2626;color:#fff;}
.umat-rev-btn:active{transform:scale(.95);}
.umat-rev-btn .material-symbols-outlined{transition:transform .2s var(--u-cbi);}
.umat-rev-btn:hover .material-symbols-outlined{transform:scale(1.15);}
.umat-rev-btn:disabled{opacity:.5;cursor:default;pointer-events:none;}
.umat-rev-done{display:flex;align-items:center;gap:4px;color:var(--u-ok);font-size:12px;font-weight:700;}
.umat-rev-done .material-symbols-outlined{font-size:16px;}
.umat-badge-num{font-weight:400;font-size:13px;color:var(--u-ol);margin-left:5px;}
/* ==== EMPTY STATE ==== */
.umat-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;
  padding:48px 24px;gap:12px;color:var(--u-ol);text-align:center;flex:1;
  animation:ufade .4s var(--u-cb) forwards;}
.umat-empty .material-symbols-outlined{font-size:48px;color:var(--u-olv);transition:transform .3s var(--u-cbi);}
.umat-empty:hover .material-symbols-outlined{transform:scale(1.08) rotate(-2deg);}
.umat-empty p{font-size:13px;margin:0;}
/* ==== MISC ==== */
.umat-btn-p{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;
  background:var(--u-p);color:#fff;border:none;border-radius:var(--u-r8);
  font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:background .2s,transform .2s;text-decoration:none;}
.umat-btn-p .material-symbols-outlined{font-size:16px;transition:transform .2s var(--u-cbi);}
.umat-btn-p:hover{background:var(--u-pb);}
.umat-btn-p:hover .material-symbols-outlined{transform:scale(1.1);}
.umat-btn-p:active{transform:scale(.96);}
.umat-btn-o{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;
  background:none;border:1.5px solid var(--u-olv);color:var(--u-onsv);border-radius:var(--u-r8);
  font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .2s;text-decoration:none;}
.umat-btn-o:hover{border-color:var(--u-p);color:var(--u-p);}
.umat-btn-o:active{transform:scale(.96);}
@media(max-width:680px){.umat-an-kpi-row{grid-template-columns:repeat(2,1fr);}
  .umat-an-2col{grid-template-columns:1fr;}.umat-cp,.umat-cp-lec{width:100vw;max-width:100vw;}}

/* ═══════════════════════════════════════════════════════════
   YOUTUBE-STYLE TILE GRID  — UMaT AI v1.4
   Applies to: student Lectures, My Courses, Library
               lecturer My Courses, Library
   ═══════════════════════════════════════════════════════════ */

/* Grid container */
.umat-ov .yt-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 20px 18px;
  padding: 20px;
  overflow-y: auto;
  flex: 1;
  align-content: start;
}
.umat-ov .yt-grid::-webkit-scrollbar { width: 5px; }
.umat-ov .yt-grid::-webkit-scrollbar-thumb { background: var(--u-olv); border-radius: 3px; }

/* Tile card */
.umat-ov .yt-tile {
  display: flex;
  flex-direction: column;
  cursor: pointer;
  background: transparent;
  border: none;
  padding: 0;
  font-family: Inter, -apple-system, sans-serif;
  text-align: left;
  width: 100%;
  transition: transform .18s ease;
}
.umat-ov .yt-tile:hover { transform: translateY(-3px); }

/* ─── Thumbnail ────────────────────────────── */
.umat-ov .yt-thumb {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  border-radius: 12px;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: box-shadow .2s ease;
}
.umat-ov .yt-tile:hover .yt-thumb {
  box-shadow: 0 8px 28px rgba(0,0,0,.28);
}
/* Type-based backgrounds */
.umat-ov .yt-bg-video  { background: linear-gradient(150deg, #071a12 0%, #103826 100%); }
.umat-ov .yt-bg-pdf    { background: linear-gradient(150deg, #3b0000 0%, #7f1d1d 100%); }
.umat-ov .yt-bg-word   { background: linear-gradient(150deg, #001040 0%, #1e3a8a 100%); }
.umat-ov .yt-bg-pptx   { background: linear-gradient(150deg, #3b1000 0%, #92400e 100%); }
.umat-ov .yt-bg-excel  { background: linear-gradient(150deg, #001a0a 0%, #14532d 100%); }
.umat-ov .yt-bg-image  { background: linear-gradient(150deg, #1a0030 0%, #4c1d95 100%); }
.umat-ov .yt-bg-audio  { background: linear-gradient(150deg, #0a0028 0%, #1e1b4b 100%); }
.umat-ov .yt-bg-course { background: linear-gradient(150deg, #004520 0%, #006b2f 100%); }
.umat-ov .yt-bg-other  { background: linear-gradient(150deg, #1a1c1a 0%, #374151 100%); }

/* Large centre icon */
.umat-ov .yt-thumb-icon {
  font-size: 72px;
  color: rgba(255,255,255,.32);
  transition: color .18s, transform .18s;
  pointer-events: none;
  user-select: none;
}
.umat-ov .yt-tile:hover .yt-thumb-icon {
  color: rgba(255,255,255,.6);
  transform: scale(1.07);
}
/* Play-overlay on hover */
.umat-ov .yt-play-ov {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0,0,0,.18);
  opacity: 0;
  transition: opacity .18s;
}
.umat-ov .yt-tile:hover .yt-play-ov { opacity: 1; }
.umat-ov .yt-play-ov .material-symbols-outlined {
  font-size: 56px;
  color: rgba(255,255,255,.92);
  filter: drop-shadow(0 2px 8px rgba(0,0,0,.55));
}
/* Duration / page-count badge */
.umat-ov .yt-badge {
  position: absolute;
  bottom: 8px;
  right: 8px;
  background: rgba(0,0,0,.82);
  color: #fff;
  padding: 3px 8px;
  border-radius: 5px;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: .02em;
  pointer-events: none;
  line-height: 1.4;
}
/* Course shortcode overlay */
.umat-ov .yt-course-ov {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 14px;
  text-align: center;
}
.umat-ov .yt-course-code {
  font-size: 28px;
  font-weight: 900;
  color: rgba(255,255,255,.96);
  letter-spacing: .07em;
  text-shadow: 0 2px 10px rgba(0,0,0,.4);
  margin-bottom: 5px;
}
.umat-ov .yt-course-name {
  font-size: 12px;
  color: rgba(255,255,255,.78);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* ─── Meta row ─────────────────────────────── */
.umat-ov .yt-meta {
  display: flex;
  gap: 10px;
  padding: 10px 2px 2px;
  align-items: flex-start;
}
/* Avatar */
.umat-ov .yt-av {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-top: 2px;
  color: #fff;
}
.umat-ov .yt-av .material-symbols-outlined { font-size: 18px; }
.umat-ov .yt-av-video  { background: linear-gradient(135deg,#006b2f,#00873d); }
.umat-ov .yt-av-pdf    { background: #dc2626; }
.umat-ov .yt-av-word   { background: #2563eb; }
.umat-ov .yt-av-pptx   { background: #c2410c; }
.umat-ov .yt-av-excel  { background: #15803d; }
.umat-ov .yt-av-image  { background: #7c3aed; }
.umat-ov .yt-av-audio  { background: #0284c7; }
.umat-ov .yt-av-course { background: linear-gradient(135deg,#006b2f,#00873d); }
.umat-ov .yt-av-other  { background: #6b7280; }
/* Text */
.umat-ov .yt-text { flex: 1; min-width: 0; }
.umat-ov .yt-title {
  margin: 0 0 3px;
  font-size: 14px;
  font-weight: 700;
  color: var(--u-ons, #171d17);
  line-height: 1.38;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.umat-ov .yt-channel {
  margin: 0;
  font-size: 12px;
  color: var(--u-ol, #6e7a6d);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.umat-ov .yt-stats {
  margin: 2px 0 0;
  font-size: 12px;
  color: var(--u-ol, #6e7a6d);
}

/* ─── Hover action bar ─────────────────────── */
.umat-ov .yt-actions {
  display: flex;
  gap: 7px;
  padding: 6px 2px 2px;
  opacity: 0;
  transform: translateY(-5px);
  transition: opacity .18s, transform .18s;
  pointer-events: none;
}
.umat-ov .yt-tile:hover .yt-actions {
  opacity: 1;
  transform: translateY(0);
  pointer-events: auto;
}
.umat-ov .yt-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 5px 13px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  border: 1.5px solid var(--u-olv, #bdcaba);
  background: var(--u-sflo, #fff);
  color: var(--u-onsv, #3e4a3e);
  transition: all .15s;
  text-decoration: none;
  font-family: inherit;
}
.umat-ov .yt-btn .material-symbols-outlined { font-size: 14px; }
.umat-ov .yt-btn:hover {
  border-color: var(--u-p, #006b2f);
  color: var(--u-p, #006b2f);
  background: rgba(0,107,47,.05);
}

/* ─── Responsive breakpoints ───────────────── */
@media (max-width: 1280px) { .umat-ov .yt-grid { grid-template-columns: repeat(auto-fill, minmax(265px,1fr)); } }
@media (max-width: 900px)  { .umat-ov .yt-grid { grid-template-columns: repeat(auto-fill, minmax(220px,1fr)); gap:14px; padding:14px; } }
@media (max-width: 600px)  { .umat-ov .yt-grid { grid-template-columns: repeat(2,1fr); padding:10px; gap:10px; } }
@media (max-width: 380px)  { .umat-ov .yt-grid { grid-template-columns: 1fr; } }

/* Safety: also scope yt tiles by direct grid IDs in case .umat-ov ancestor check fails */
#ws-video-grid.yt-grid, #ws-courses-grid.yt-grid, #ws-lib-grid.yt-grid,
#lec-courses-grid.yt-grid, #lec-lib-grid.yt-grid,
#hub-lec-grid.yt-grid, #hub-courses-grid.yt-grid, #hub-lib-grid.yt-grid,
#stu-lec-grid.yt-grid, #stu-courses-grid.yt-grid, #stu-lib-grid.yt-grid {
  display: grid !important;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 20px 18px;
  padding: 20px;
  overflow-y: auto;
  flex: 1;
  align-content: start;
}


</style>
STYLES;
    }


    // ================================================================== //
    // RESPONSIVE ADDITIONS — appended to styles                           //
    // ================================================================== //

    private static function responsive_styles(): string {
        return <<<'RS'
<style id="umat-responsive">
/* ---- Tablet (640-1023px) ---- */
@media(max-width:1023px){
  .umat-sb{pointer-events:auto;}
  .umat-an-kpi-row{grid-template-columns:repeat(2,1fr);}
  .umat-an-2col{grid-template-columns:1fr;}
  .umat-player-right{display:none;}
  .umat-an-scroll{padding:14px;}
  .umat-home-wrap{padding:16px;}
  .umat-metrics-row{grid-template-columns:repeat(2,1fr);}
  .umat-quick-actions-grid{grid-template-columns:1fr 1fr;}
}
/* ---- Mobile (< 640px) ---- */
@media(max-width:639px){
  /* FAB position */
  .umat-fab{bottom:80px!important;right:16px!important;}
  /* Compact panel full-screen on mobile */
  .umat-cp{width:100vw;max-width:100vw;height:100%;}
  /* Hide sidebar, show bottom tab bar */
  .umat-sb{display:none!important;}
  .umat-mob-tabbar{display:flex!important;}
  /* Content fills height minus bottom tab bar */
  .umat-ov{flex-direction:column;}
  .umat-ov-body{flex-direction:column-reverse;flex:1;overflow:hidden;}
  .umat-ov-content{flex:1;overflow:hidden;}
  /* Grids */
  .umat-an-kpi-row{grid-template-columns:1fr 1fr;}
  .umat-an-2col{grid-template-columns:1fr;}
  .umat-video-grid{grid-template-columns:1fr;padding:12px;}
  .umat-lib-grid{grid-template-columns:repeat(auto-fill,minmax(220px,1fr));padding:12px;}
  .umat-courses-grid{grid-template-columns:1fr;padding:12px;}
  .umat-metrics-row{grid-template-columns:1fr 1fr;}
  .umat-quick-actions-grid{grid-template-columns:1fr 1fr;}
  .umat-home-hero{padding:20px 18px;}
  .umat-home-hero h1{font-size:18px;}
  .umat-sessions-list{padding:12px;}
  /* Player adjustments */
  .umat-player-body{flex-direction:column;}
  .umat-player-right{display:none;}
  .umat-player-left{border-right:none;}
  /* Attachment drawer full-width */
  .umat-attach-drawer{border-radius:var(--u-r16) var(--u-r16) 0 0;}
  /* Home hero on mobile */
  .umat-home-wrap{padding:12px;}
  .umat-home-section h3{font-size:13px;}
}
/* ---- Very small (< 380px) ---- */
@media(max-width:380px){
  .umat-an-kpi-row{grid-template-columns:1fr;}
  .umat-metrics-row{grid-template-columns:1fr;}
  .umat-quick-actions-grid{grid-template-columns:1fr;}
  .umat-video-grid,.umat-lib-grid,.umat-courses-grid{grid-template-columns:1fr;padding:8px;}
  .umat-lib-tile{flex-direction:column;align-items:stretch;padding:8px;}
  .umat-lib-tile-icon{width:100%;height:40px;}
  .umat-lib-tile-actions{justify-content:stretch;}
  .umat-lib-btn{flex:1;justify-content:center;}
}
/* ---- Mobile tab bar base styles ---- */
.umat-mob-tabbar{display:none;position:sticky;bottom:0;left:0;right:0;height:60px;
  background:var(--u-sflo);border-top:1px solid var(--u-olv);z-index:100;flex-shrink:0;}
.umat-mob-tab{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:2px;cursor:pointer;border:none;background:none;color:var(--u-ol);font-size:10px;
  font-family:Inter,sans-serif;padding:6px 2px;transition:all .18s;
  border-top:2.5px solid transparent;}
.umat-mob-tab .material-symbols-outlined{font-size:22px;}
.umat-mob-tab.active{color:var(--u-p);border-top-color:var(--u-p);}
/* ---- Hover only on devices that support hover ---- */
@media(hover:hover){
  .umat-sb:hover{width:var(--u-sb-exp);}
  .umat-sb:hover .umat-sb-brand,.umat-sb:hover .umat-sb-item-lbl,
  .umat-sb:hover .umat-sb-new-lbl,.umat-sb:hover .umat-sb-close-btn{opacity:1;}
}
/* ---- Touch devices: keep sidebar collapsed ---- */
@media(hover:none){
  .umat-sb{width:var(--u-sb-col);}
  .umat-sb-brand,.umat-sb-item-lbl,.umat-sb-new-lbl{opacity:0!important;transform:none!important;}
  .umat-sb-close-btn{opacity:1!important;}
}
</style>
RS;
    }

    // ================================================================== //
    // STUDENT OVERLAY                                                      //
    // ================================================================== //

    private static function student_overlay(int $courseid, string $courseName, string $wwwroot, object $user, string $userData): string {
        $safeName  = htmlspecialchars($courseName, ENT_QUOTES, 'UTF-8');
        $jsCid     = (int)$courseid;
        $jsName    = json_encode($courseName);
        $userName  = fullname($user);
        $safeUser  = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        $initials  = strtoupper(mb_substr($user->firstname, 0, 1) . mb_substr($user->lastname, 0, 1));
        $approveUrl = $wwwroot . '/local/umat_ai/approve.php?courseid=' . $courseid;

        $tabs = [
            ['id' => 'home',      'icon' => 'home',          'label' => 'Home',      'active' => false],
            ['id' => 'ai-tutor',  'icon' => 'smart_toy',     'label' => 'AI Tutor',  'active' => true],
            ['id' => 'lectures',  'icon' => 'play_circle',   'label' => 'Lectures',  'active' => false],
            ['id' => 'courses',   'icon' => 'menu_book',     'label' => 'My Courses','active' => false],
            ['id' => 'library',   'icon' => 'local_library', 'label' => 'Library',   'active' => false],
            ['id' => 'sessions',  'icon' => 'chat_bubble',   'label' => 'Sessions',  'active' => false],
        ];
        $sidebar = self::sidebar_html($tabs, 'New Session', 'stu-ws-close');
        $sharedJs = self::shared_js('umat-student-ov', 'stu-ws-close');

        return <<<HTML

<!-- STUDENT FAB -->
<button class="umat-fab umat-fab-pulse" id="umat-stu-fab" type="button" aria-label="Open AI Assistant">
  <span class="material-symbols-outlined">smart_toy</span>
  <span class="umat-fab-tip">UMaT AI Assistant</span>
</button>

<!-- COMPACT PANEL -->
<div class="umat-cp-ov" id="stu-cp-ov">
  <div class="umat-cp" id="stu-cp">
    <div class="umat-cp-hdr">
      <div class="umat-cp-hdr-row">
        <div class="umat-cp-av"><span class="material-symbols-outlined">smart_toy</span><span class="umat-cp-dot"></span></div>
        <div class="umat-cp-info">
          <h2>AI Tutor</h2>
          <div class="sub">● Online &amp; Ready</div>
          <div class="ctx" title="{$safeName}">{$safeName}</div>
        </div>
        <button class="umat-cp-hbtn umat-cp-exp" id="stu-expand-btn" type="button">
          <span class="material-symbols-outlined">open_in_full</span><span>Expand</span>
        </button>
        <button class="umat-cp-hbtn" id="stu-cp-close" type="button"><span class="material-symbols-outlined">close</span></button>
      </div>
    </div>
    <div class="umat-cp-tabs">
      <button class="umat-cp-tab active" data-cp-tab="cp-chat" type="button">Chat</button>
      <button class="umat-cp-tab" data-cp-tab="cp-notes" type="button">Notes</button>
      <button class="umat-cp-tab" data-cp-tab="cp-resources" type="button">Resources</button>
    </div>
    <div class="umat-cp-pane active" id="cp-chat">
      <div class="umat-msgs" id="cp-msgs">
        <div class="umat-msg-ai">
          <div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div>
          <div class="umat-msg-ai-wrap">
            <div class="umat-msg-lbl">AI TUTOR</div>
            <div class="umat-bubble-ai"><p>Hello <strong>{$safeUser}</strong>! I'm your AI tutor for <strong>{$safeName}</strong>. Expand for the full workspace, or ask me anything here. ✨</p></div>
            <div class="umat-chips-row">
              <button class="umat-chip" data-q="Summarize today's lecture key points." type="button">Summarize lecture</button>
              <button class="umat-chip" data-q="What are the current assignment requirements?" type="button">Assignment help</button>
              <button class="umat-chip" data-q="What are my upcoming deadlines?" type="button">Deadlines</button>
            </div>
          </div>
        </div>
      </div>
      <div class="umat-input-area">
        <div class="umat-input-row">
          <textarea id="cp-input" class="umat-textarea" placeholder="Ask anything…" rows="2" maxlength="900"></textarea>
          <button class="umat-send-btn" id="cp-send" type="button"><span class="material-symbols-outlined">send</span></button>
        </div>
        <div class="umat-input-actions">
          <span class="umat-rate-txt" id="cp-rate" style="font-size:10px;color:var(--u-ol);">10 questions remaining</span>
          <button class="umat-ia-btn" id="cp-mic" type="button"><span class="material-symbols-outlined">mic</span>Voice</button>
        </div>
      </div>
    </div>
    <div class="umat-cp-pane" id="cp-notes">
      <div class="umat-empty"><span class="material-symbols-outlined">description</span><p>AI-generated notes appear here once your lecturer approves them.</p></div>
    </div>
    <div class="umat-cp-pane" id="cp-resources">
      <div class="umat-empty"><span class="material-symbols-outlined">folder_open</span><p>Indexed course materials will appear here.</p></div>
    </div>
  </div>
</div>

<!-- STUDENT FULL WORKSPACE OVERLAY -->
<div class="umat-ov" id="umat-student-ov" role="dialog" aria-modal="true">
  {$sidebar}

  <!-- MOBILE TAB BAR -->
  <div class="umat-mob-tabbar" id="stu-mob-tabs">
    <button class="umat-mob-tab active" data-sb-tab="home" type="button"><span class="material-symbols-outlined">home</span>Home</button>
    <button class="umat-mob-tab" data-sb-tab="ai-tutor" type="button"><span class="material-symbols-outlined">smart_toy</span>AI Tutor</button>
    <button class="umat-mob-tab" data-sb-tab="lectures" type="button"><span class="material-symbols-outlined">play_circle</span>Lectures</button>
    <button class="umat-mob-tab" data-sb-tab="courses" type="button"><span class="material-symbols-outlined">menu_book</span>Courses</button>
    <button class="umat-mob-tab" data-sb-tab="library" type="button"><span class="material-symbols-outlined">local_library</span>Library</button>
    <button class="umat-mob-tab" data-sb-tab="sessions" type="button"><span class="material-symbols-outlined">chat_bubble</span>Sessions</button>
  </div>

  <div class="umat-ov-content">

    <!-- HOME TAB -->
    <div class="umat-tab-pane active" data-tab="home">
      <div class="umat-content-hdr">
        <h2>Welcome back, {$safeUser}!</h2>
        <span class="pill" id="ws-goal-pill">Goal: 0%</span>
      </div>
      <div class="umat-home-wrap">
        <div class="umat-home-hero">
          <h1>Good to see you, {$safeUser}! 👋</h1>
          <p>Continue your AI-assisted learning journey for <strong>{$safeName}</strong>.</p>
          <div class="hero-sub">Your AI tutor is online and ready to help you master any concept.</div>
        </div>
        <div class="umat-metrics-row">
          <div class="umat-metric-card">
            <div class="umat-metric-icon mi-g"><span class="material-symbols-outlined">forum</span></div>
            <div><div class="umat-metric-val" id="ws-m-sessions">—</div><div class="umat-metric-lbl">Sessions this week</div></div>
          </div>
          <div class="umat-metric-card">
            <div class="umat-metric-icon mi-s"><span class="material-symbols-outlined">help</span></div>
            <div><div class="umat-metric-val" id="ws-m-questions">—</div><div class="umat-metric-lbl">Questions asked</div></div>
          </div>
          <div class="umat-metric-card">
            <div class="umat-metric-icon mi-w"><span class="material-symbols-outlined">bolt</span></div>
            <div><div class="umat-metric-val" id="ws-m-goal">—</div><div class="umat-metric-lbl">Weekly goal</div></div>
          </div>
        </div>
        <div class="umat-home-section">
          <div class="umat-goal-bar-wrap">
            <div class="umat-goal-bar-row"><span>Weekly Study Goal</span><strong id="ws-goal-pct">0%</strong></div>
            <div class="umat-goal-bar"><div class="umat-goal-fill" id="ws-goal-bar" style="width:0%"></div></div>
          </div>
        </div>
        <div class="umat-home-section">
          <h3>Quick Actions</h3>
          <div class="umat-quick-actions-grid">
            <button class="umat-qa-btn" data-sb-tab="ai-tutor" type="button">
              <span class="material-symbols-outlined">smart_toy</span>
              <div class="umat-qa-btn-text"><strong>Ask AI Tutor</strong><span>Start a new question</span></div>
            </button>
            <button class="umat-qa-btn" data-sb-tab="lectures" type="button">
              <span class="material-symbols-outlined">play_circle</span>
              <div class="umat-qa-btn-text"><strong>Watch Lectures</strong><span>Browse recordings</span></div>
            </button>
            <button class="umat-qa-btn" data-sb-tab="library" type="button">
              <span class="material-symbols-outlined">local_library</span>
              <div class="umat-qa-btn-text"><strong>Course Library</strong><span>Notes, PDFs &amp; more</span></div>
            </button>
            <button class="umat-qa-btn" data-sb-tab="sessions" type="button">
              <span class="material-symbols-outlined">chat_bubble</span>
              <div class="umat-qa-btn-text"><strong>Past Sessions</strong><span>Resume previous chats</span></div>
            </button>
          </div>
        </div>
        <div class="umat-home-section" id="ws-recent-session-wrap" style="display:none;">
          <h3>Continue where you left off</h3>
          <div id="ws-recent-session"></div>
        </div>
      </div>
    </div>

    <!-- AI TUTOR TAB -->
    <div class="umat-tab-pane" data-tab="ai-tutor">
      <div class="umat-content-hdr">
        <h2>AI Tutor</h2>
        <span class="pill" id="ws-rate-pill">10 Q remaining</span>
      </div>
      <div style="display:flex;flex:1;overflow:hidden;">
        <!-- Left: full-width chat -->
        <div style="flex:1;display:flex;flex-direction:column;overflow:hidden;">
          <div style="display:flex;flex-wrap:wrap;gap:6px;padding:10px 14px;border-bottom:1px solid var(--u-olv);flex-shrink:0;" id="ws-chips">
            <button class="umat-chip" data-q="Explain the key concept discussed in the most recent lecture." type="button">Explain key concept</button>
            <button class="umat-chip" data-q="Can you compare this topic with what was covered earlier in the course?" type="button">Compare topics</button>
            <button class="umat-chip" data-q="Create a practice quiz on this week's material." type="button">Practice quiz</button>
            <button class="umat-chip" data-q="What are the most common exam questions for this topic?" type="button">Exam prep</button>
          </div>
          <div class="umat-msgs" id="ws-msgs">
            <div class="umat-msg-ai">
              <div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div>
              <div class="umat-msg-ai-wrap">
                <div class="umat-msg-lbl">AI TUTOR</div>
                <div class="umat-bubble-ai"><p>Welcome to your AI Tutor for <strong>{$safeName}</strong>! I can reference your selected course materials for precise answers. Use the attachment button to select specific materials, or ask me anything!</p></div>
              </div>
            </div>
          </div>
          <div class="umat-input-area" style="position:relative;">
            <div class="umat-attach-drawer" id="ws-attach-drawer">
              <div class="umat-drawer-hdr">
                <h4><span class="material-symbols-outlined" style="font-size:17px;vertical-align:middle;color:var(--u-p);margin-right:5px;">attach_file</span>Select Reference Materials</h4>
                <button class="umat-drawer-hdr-close" id="ws-drawer-close" type="button"><span class="material-symbols-outlined">close</span></button>
              </div>
              <div class="umat-drawer-search"><input type="text" id="ws-drawer-search" placeholder="Search materials…"></div>
              <div class="umat-drawer-list" id="ws-drawer-list"><div style="text-align:center;padding:20px;color:var(--u-ol);font-size:13px;">Click the attachment button to load materials.</div></div>
              <div class="umat-drawer-foot">
                <span id="ws-drawer-count" style="font-size:12px;color:var(--u-ol);">0 selected</span>
                <button class="umat-drawer-confirm" id="ws-drawer-confirm" type="button">Reference Selected</button>
              </div>
            </div>
            <div class="umat-input-row">
              <textarea id="ws-input" class="umat-textarea" placeholder="Ask AI about this course…" rows="2" maxlength="900"></textarea>
              <button class="umat-send-btn" id="ws-send" type="button"><span class="material-symbols-outlined">send</span></button>
            </div>
            <div class="umat-mat-bar" id="ws-mat-bar"></div>
            <div class="umat-input-actions">
              <button class="umat-ia-btn" id="ws-attach-btn" type="button"><span class="material-symbols-outlined">attach_file</span>Reference Material</button>
              <button class="umat-ia-btn" id="ws-mic-btn" type="button"><span class="material-symbols-outlined">mic</span>Voice</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- LECTURES TAB -->
    <div class="umat-tab-pane" data-tab="lectures" style="position:relative;overflow:hidden;">
      <div class="umat-content-hdr">
        <h2>Lecture Recordings</h2>
        <button class="umat-content-hdr-btn" id="ws-lec-refresh" type="button">
          <span class="material-symbols-outlined">refresh</span>Refresh
        </button>
      </div>
      <div class="umat-video-grid" id="ws-video-grid">
        <div class="umat-empty"><span class="material-symbols-outlined">play_circle</span><p>Loading lecture recordings…</p></div>
      </div>
      <!-- Video player (slides over the grid) -->
      <div class="umat-player-panel" id="ws-player-panel">
        <div class="umat-player-top">
          <button class="umat-player-back" id="ws-player-back" type="button">
            <span class="material-symbols-outlined">arrow_back</span>Back
          </button>
          <div class="umat-player-title" id="ws-player-title">Lecture Recording</div>
          <a class="umat-player-dl-btn" id="ws-player-dl" href="#" download target="_blank">
            <span class="material-symbols-outlined">download</span>Download
          </a>
        </div>
        <div class="umat-player-body">
          <div class="umat-player-left">
            <div class="umat-player-video-wrap" id="ws-player-vwrap">
              <video id="ws-player-video" preload="metadata">Your browser does not support video.</video>
              <div class="umat-vc">
                <button class="umat-vc-btn" id="ws-vc-pp"><span class="material-symbols-outlined">play_arrow</span></button>
                <button class="umat-vc-btn" id="ws-vc-r30"><span class="material-symbols-outlined">replay_30</span></button>
                <button class="umat-vc-btn" id="ws-vc-f30"><span class="material-symbols-outlined">forward_30</span></button>
                <span class="umat-vc-time"><span id="ws-vc-cur">0:00</span> / <span id="ws-vc-dur">0:00</span></span>
                <input type="range" id="ws-vc-prog" class="umat-vc-progress" min="0" max="100" value="0">
              </div>
            </div>
            <div class="umat-player-transcript">
              <div class="umat-ts-hdr">
                <h4><span class="material-symbols-outlined">subtitles</span>Synchronized Transcript</h4>
                <div class="umat-ts-srch"><span class="material-symbols-outlined">search</span><input type="text" id="ws-ts-srch" placeholder="Search…"></div>
              </div>
              <div class="umat-ts-body" id="ws-ts-body">
                <div class="umat-empty" style="padding:24px;"><span class="material-symbols-outlined" style="font-size:32px;">article</span><p>Transcript loads when a lecture is playing.</p></div>
              </div>
            </div>
          </div>
          <div class="umat-player-right">
            <div style="padding:14px 16px;border-bottom:1px solid var(--u-olv);background:linear-gradient(135deg,var(--u-p),var(--u-pb));color:#fff;flex-shrink:0;">
              <h3 style="margin:0;font-size:14px;font-weight:700;">Ask About This Lecture</h3>
            </div>
            <div class="umat-msgs" id="ws-player-msgs">
              <div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>Play a lecture, then ask me anything about the content!</p></div></div></div>
            </div>
            <div class="umat-input-area">
              <div class="umat-input-row">
                <textarea id="ws-player-input" class="umat-textarea" placeholder="Ask about this lecture…" rows="2" maxlength="700"></textarea>
                <button class="umat-send-btn" id="ws-player-send" type="button"><span class="material-symbols-outlined">send</span></button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- MY COURSES TAB -->
    <div class="umat-tab-pane" data-tab="courses">
      <div class="umat-content-hdr">
        <h2>My Courses</h2>
        <span class="pill" id="ws-courses-count">—</span>
      </div>
      <div class="umat-courses-grid" id="ws-courses-grid">
        <div class="umat-empty"><span class="material-symbols-outlined">menu_book</span><p>Loading your courses…</p></div>
      </div>
    </div>

    <!-- LIBRARY TAB -->
    <div class="umat-tab-pane" data-tab="library" style="position:relative;overflow:hidden;">
      <div class="umat-content-hdr">
        <h2>Course Library</h2>
        <button class="umat-content-hdr-btn" id="ws-lib-refresh" type="button">
          <span class="material-symbols-outlined">refresh</span>Refresh
        </button>
      </div>
      <div class="umat-lib-grid" id="ws-lib-grid">
        <div class="umat-empty"><span class="material-symbols-outlined">local_library</span><p>Loading course materials…</p></div>
      </div>
      <!-- PDF Viewer overlay within Library tab -->
      <div class="umat-pdf-viewer-wrap" id="ws-pdf-viewer">
        <div class="umat-pdf-viewer-bar">
          <button class="umat-pdf-viewer-back" id="ws-pdf-back" type="button"><span class="material-symbols-outlined">arrow_back</span>Back to Library</button>
          <h4 id="ws-pdf-title">Document Viewer</h4>
          <a class="umat-btn-p" id="ws-pdf-dl" href="#" download target="_blank"><span class="material-symbols-outlined">download</span>Download</a>
        </div>
        <iframe id="ws-pdf-iframe" class="umat-pdf-iframe" src="about:blank"></iframe>
      </div>
    </div>

    <!-- SESSIONS TAB -->
    <div class="umat-tab-pane" data-tab="sessions">
      <div class="umat-content-hdr">
        <h2>AI Chat Sessions</h2>
        <button class="umat-sb-new" style="position:relative;margin:0;" id="ws-new-session-btn2" type="button">
          <span class="material-symbols-outlined">add</span>
          <span class="umat-sb-new-lbl">New Session</span>
        </button>
      </div>
      <div class="umat-sessions-list" id="ws-sessions-list">
        <div class="umat-empty"><span class="material-symbols-outlined">chat_bubble</span><p>Loading your AI chat sessions…</p></div>
      </div>
    </div>

  </div><!-- /ov-content -->
</div><!-- /student workspace overlay -->

{$sharedJs}

<script>
(function(){
'use strict';
var courseId   = {$jsCid};
var courseName = {$jsName};
var userData   = {$userData};
var sessionKey = 'stu_'+Math.random().toString(36).substr(2,18);
var qLeft      = 10;
var selectedMats = [];
var lecturesLoaded = false;
var libraryLoaded  = false;
var coursesLoaded  = false;
var sessionsLoaded = false;
var ov = document.getElementById('umat-student-ov');

/* ---- FAB & compact panel ---- */
var fab     = document.getElementById('umat-stu-fab');
var cpOv    = document.getElementById('stu-cp-ov');
var cpClose = document.getElementById('stu-cp-close');
var expBtn  = document.getElementById('stu-expand-btn');

fab.addEventListener('click', function(){ cpOv.classList.add('open'); updateRate(); });
cpClose.addEventListener('click', function(){ cpOv.classList.remove('open'); });
cpOv.addEventListener('click', function(e){ if(e.target===cpOv) cpOv.classList.remove('open'); });
expBtn.addEventListener('click', function(){ cpOv.classList.remove('open'); openOverlay(); });

document.getElementById('sb-new-btn').addEventListener('click', newSession);
var nb2=document.getElementById('ws-new-session-btn2'); if(nb2)nb2.addEventListener('click',newSession);

function newSession(){
  sessionKey='stu_'+Math.random().toString(36).substr(2,18);
  var m=document.getElementById('ws-msgs');
  if(m){ while(m.children.length>1)m.removeChild(m.lastChild); }
  var cm=document.getElementById('cp-msgs');
  if(cm){ while(cm.children.length>1)cm.removeChild(cm.lastChild); }
  switchToTab('ai-tutor');
}

function openOverlay(){ ov.classList.add('open'); populateHomeTab(); }
function closeOverlay(){ ov.classList.remove('open'); cpOv.classList.add('open'); }
if(ov)ov.addEventListener('click',function(e){if(e.target===ov)closeOverlay();});

/* Wire up the workspace close button */
var wsClose=document.getElementById('stu-ws-close');
if(wsClose)wsClose.addEventListener('click',closeOverlay);

/* ---- compact panel tabs ---- */
document.querySelectorAll('#stu-cp [data-cp-tab]').forEach(function(btn){
  btn.addEventListener('click',function(){
    document.querySelectorAll('#stu-cp [data-cp-tab]').forEach(function(b){b.classList.toggle('active',b===btn);});
    document.querySelectorAll('#stu-cp .umat-cp-pane').forEach(function(p){p.classList.toggle('active',p.id===btn.dataset.cpTab);});
  });
});

/* ---- workspace tab switching ---- */
function switchToTab(name){
  ov.querySelectorAll('[data-sb-tab]').forEach(function(b){b.classList.toggle('active',b.dataset.sbTab===name);});
  ov.querySelectorAll('.umat-tab-pane').forEach(function(p){p.classList.toggle('active',p.dataset.tab===name);});
  if(name==='lectures'   && !lecturesLoaded){ loadLectures(); lecturesLoaded=true; }
  if(name==='library'    && !libraryLoaded){  loadLibrary();  libraryLoaded=true;  }
  if(name==='courses'    && !coursesLoaded){  renderCourses(userData.courses||[]); coursesLoaded=true; }
  if(name==='sessions'   && !sessionsLoaded){ loadSessions();  sessionsLoaded=true; }
}
/* Select course: set context and switch to AI Tutor */
function selectCourse(cid,cname){
  courseId=cid;
  switchToTab('ai-tutor');
}
ov.querySelectorAll('[data-sb-tab]').forEach(function(btn){
  btn.addEventListener('click',function(){ switchToTab(btn.dataset.sbTab); });
});
/* Quick action buttons on Home tab */
ov.querySelectorAll('[data-sb-tab]').forEach(function(btn){
  btn.addEventListener('click',function(){ switchToTab(btn.dataset.sbTab); });
});

/* ---- rate counter ---- */
function updateRate(){
  var el=document.getElementById('cp-rate'),el2=document.getElementById('ws-rate-pill');
  var t=qLeft+' question'+(qLeft!==1?'s':'')+' remaining';
  if(el)el.textContent=t;
  if(el2)el2.textContent=t;
}

/* ---- HOME TAB data ---- */
function populateHomeTab(){
  var d=userData||{};
  var set=function(id,v){var el=document.getElementById(id);if(el)el.textContent=v;};
  set('ws-m-sessions',  d.week_sessions||0);
  set('ws-m-questions', d.week_questions||0);
  var gp=d.goal_progress||0;
  set('ws-m-goal', gp+'%');
  set('ws-goal-pct', gp+'%');
  set('ws-goal-pill','Goal: '+gp+'%');
  var bar=document.getElementById('ws-goal-bar');
  if(bar) setTimeout(function(){bar.style.width=gp+'%';},200);

  /* Recent session card */
  var sessions=d.sessions||[];
  if(sessions.length>0){
    var s=sessions[0];
    var wrap=document.getElementById('ws-recent-session-wrap');
    var cont=document.getElementById('ws-recent-session');
    if(wrap)wrap.style.display='';
    if(cont){
      cont.innerHTML='<div class="umat-session-tile" style="max-width:480px;">'
        +'<div class="umat-session-tile-hdr"><span class="umat-session-badge">'+_umatEsc(s.course_short||'')+'</span><span class="umat-session-time">'+_umatEsc(s.time_label)+'</span></div>'
        +'<h4>'+_umatEsc(s.course_name)+' AI Session</h4>'
        +'<p>'+_umatEsc(s.preview)+'</p>'
        +'<div class="umat-session-tile-foot"><span class="umat-session-meta"><span class="material-symbols-outlined">chat</span>'+s.msg_count+' messages</span>'
        +'<button class="umat-resume-btn" data-sk="'+_umatEsc(s.session_key)+'" type="button">Resume →</button></div></div>';
      cont.querySelector('.umat-resume-btn').addEventListener('click',function(){
        sessionKey=this.dataset.sk;
        switchToTab('ai-tutor');
      });
    }
  }
}

/* ---- AI TUTOR chat ---- */
function sendQuestion(q, msgsId){
  q=(q||'').trim();if(!q)return;
  if(qLeft<=0){_umatAppendAi(msgsId,'Rate limit reached. Please wait a moment.',[]); return;}
  qLeft--;updateRate();
  _umatAppendUser(msgsId,q);
  var tid='typ_'+Date.now();_umatShowTyping(msgsId,tid);

  /* Append material context if any are selected */
  var contextQ=selectedMats.length>0?'[Referencing: '+selectedMats.map(function(m){return m.name;}).join(', ')+'] '+q:q;

  require(['core/ajax'],function(Ajax){
    Ajax.call([{methodname:'local_umat_ai_ask_question',args:{courseid:courseId,question:contextQ,session_key:sessionKey}}])[0]
      .done(function(r){_umatHideTyping(tid);_umatAppendAi(msgsId,r.success?r.answer:'Sorry, an error occurred.',r.sources||[]);})
      .fail(function(){_umatHideTyping(tid);_umatAppendAi(msgsId,'Connection error. Please try again.',[]);});
  });
}

/* workspace AI tutor */
var wsInput=document.getElementById('ws-input'),wsSend=document.getElementById('ws-send');
if(wsSend)wsSend.addEventListener('click',function(){sendQuestion(wsInput.value,'ws-msgs');wsInput.value='';});
if(wsInput)wsInput.addEventListener('keypress',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();wsSend.click();}});
/* suggestion chips */
ov.addEventListener('click',function(e){
  var chip=e.target.closest('[data-q]');
  if(chip){sendQuestion(chip.dataset.q,'ws-msgs');}
});

/* compact panel send */
var cpInput=document.getElementById('cp-input'),cpSend=document.getElementById('cp-send');
if(cpSend)cpSend.addEventListener('click',function(){sendQuestion(cpInput.value,'cp-msgs');cpInput.value='';});
if(cpInput)cpInput.addEventListener('keypress',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();cpSend.click();}});

/* voice */
var wsMic=document.getElementById('ws-mic-btn');
if(wsMic&&wsInput)_umatInitVoice(wsInput,wsMic);
var cpMic=document.getElementById('cp-mic');
if(cpMic&&cpInput)_umatInitVoice(cpInput,cpMic);

/* attachment drawer */
_umatInitAttachDrawer({
  getCourseId:function(){return courseId;},
  drawerId:'ws-attach-drawer',
  attachBtnId:'ws-attach-btn',
  closeBtnId:'ws-drawer-close',
  searchId:'ws-drawer-search',
  listId:'ws-drawer-list',
  confirmId:'ws-drawer-confirm',
  countId:'ws-drawer-count',
  onConfirm:function(mats){selectedMats=mats;_umatRenderMatsBar('ws-mat-bar','ws-attach-btn',selectedMats,function(id){selectedMats=selectedMats.filter(function(s){return s.id!=id;});return selectedMats;});}
});

/* lecture player send */
var plInput=document.getElementById('ws-player-input'),plSend=document.getElementById('ws-player-send');
if(plSend)plSend.addEventListener('click',function(){sendQuestion(plInput.value,'ws-player-msgs');plInput.value='';});
if(plInput)plInput.addEventListener('keypress',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();plSend.click();}});

/* ---- LECTURES: load & display ---- */
function loadLectures(){
  require(['core/ajax'],function(Ajax){
    Ajax.call([{methodname:'local_umat_ai_get_course_recordings',args:{courseid:courseId}}])[0]
      .done(function(r){renderVideoTiles(r.recordings||r||[]);}).fail(function(){
        document.getElementById('ws-video-grid').innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Failed to load recordings. Make sure the AI service is running.</p></div>';
      });
  });
}
document.getElementById('ws-lec-refresh').addEventListener('click',function(){lecturesLoaded=false;loadLectures();lecturesLoaded=true;});


function openVideoPlayer(rec){
  var panel=document.getElementById('ws-player-panel');
  var video=document.getElementById('ws-player-video');
  var titleEl=document.getElementById('ws-player-title');
  var dlBtn=document.getElementById('ws-player-dl');
  var tsBody=document.getElementById('ws-ts-body');

  if(titleEl)titleEl.textContent=rec.title||'Lecture Recording';
  if(dlBtn){dlBtn.href=rec.url||'#';dlBtn.download=rec.title||'lecture';}

  if(video&&rec.url){
    video.src=rec.url;
    _umatInitPlayer({
      videoId:'ws-player-video',playBtnId:'ws-vc-pp',progId:'ws-vc-prog',
      curId:'ws-vc-cur',durId:'ws-vc-dur',r30Id:'ws-vc-r30',f30Id:'ws-vc-f30',
      tsBodyId:'ws-ts-body',tsSearchId:'ws-ts-srch'
    });
  }

  /* Render transcript */
  if(tsBody&&rec.transcript&&rec.transcript.length){
    tsBody.innerHTML=rec.transcript.map(function(seg){
      return'<div class="umat-ts-seg" data-start="'+seg.start+'" data-end="'+seg.end+'">'
        +'<span class="umat-ts-time">'+_umatEsc(seg.timestamp||'0:00')+'</span>'
        +'<p class="umat-ts-text">'+_umatEsc(seg.text)+'</p></div>';
    }).join('');
  } else if(tsBody){
    tsBody.innerHTML='<div class="umat-empty" style="padding:24px;"><span class="material-symbols-outlined" style="font-size:32px;">article</span><p>No transcript available for this recording yet.</p></div>';
  }

  if(panel)panel.classList.add('open');
}

document.getElementById('ws-player-back').addEventListener('click',function(){
  var panel=document.getElementById('ws-player-panel');
  var video=document.getElementById('ws-player-video');
  if(panel)panel.classList.remove('open');
  if(video){video.pause();video.src='';}
});

/* ---- MY COURSES: render from preloaded data ---- */

/* ---- LIBRARY ---- */
function loadLibrary(){
  var grid=document.getElementById('ws-lib-grid');
  grid.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading materials…</p></div>';
  require(['core/ajax'],function(Ajax){
    Ajax.call([{methodname:'local_umat_ai_get_course_materials',args:{courseid:courseId}}])[0]
      .done(function(r){renderLibrary(r.materials||[]);})
      .fail(function(){grid.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Failed to load materials.</p></div>';});
  });
}
document.getElementById('ws-lib-refresh').addEventListener('click',function(){libraryLoaded=false;loadLibrary();libraryLoaded=true;});


function openPdfViewer(url,name){
  var wrap=document.getElementById('ws-pdf-viewer');
  var iframe=document.getElementById('ws-pdf-iframe');
  var title=document.getElementById('ws-pdf-title');
  var dl=document.getElementById('ws-pdf-dl');
  if(title)title.textContent=name||'Document';
  if(dl){dl.href=url;dl.download=name||'document';}
  if(iframe)iframe.src=url;
  if(wrap)wrap.classList.add('open');
}
document.getElementById('ws-pdf-back').addEventListener('click',function(){
  var wrap=document.getElementById('ws-pdf-viewer');
  var iframe=document.getElementById('ws-pdf-iframe');
  if(wrap)wrap.classList.remove('open');
  if(iframe)iframe.src='about:blank';
});

/* ---- SESSIONS ---- */
function loadSessions(){
  var list=document.getElementById('ws-sessions-list');
  var sessions=(userData&&userData.sessions)||[];
  if(!sessions.length){
    list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">chat_bubble</span><p>No AI chat sessions yet. Start one in the AI Tutor tab!</p></div>';
    return;
  }
  list.innerHTML=sessions.map(function(s){
    return'<div class="umat-session-tile" data-sk="'+_umatEsc(s.session_key)+'" data-cid="'+s.courseid+'">'
      +'<div class="umat-session-tile-hdr"><span class="umat-session-badge">'+_umatEsc(s.course_short||'')+'</span><span class="umat-session-time">'+_umatEsc(s.time_label)+'</span></div>'
      +'<h4>'+_umatEsc(s.course_name||'General Session')+'</h4>'
      +'<p>'+_umatEsc(s.preview)+'</p>'
      +'<div class="umat-session-tile-foot"><span class="umat-session-meta"><span class="material-symbols-outlined">chat</span>'+s.msg_count+' messages</span>'
      +'<button class="umat-resume-btn" type="button">Resume →</button></div></div>';
  }).join('');
  list.querySelectorAll('.umat-session-tile').forEach(function(tile){
    tile.querySelector('.umat-resume-btn').addEventListener('click',function(){
      sessionKey=tile.dataset.sk;
      courseId=parseInt(tile.dataset.cid)||courseId;
      switchToTab('ai-tutor');
    });
  });
}

/* ---- Init on page load ---- */
populateHomeTab();
/* Expose player & course functions globally so shared yt-grid renderers can call them */
window.openVideoPlayer=openVideoPlayer;
window.openPdfViewer=openPdfViewer;
window.selectCourse=selectCourse;

/* ESC: close nested-first, root-last */
_umatInitEsc([
  {id:'ws-attach-drawer',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');}},
  {id:'ws-player-panel',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');var v=document.getElementById('ws-player-video');if(v){v.pause();v.src='';}}},
  {id:'ws-pdf-viewer',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');var f=document.getElementById('ws-pdf-iframe');if(f)f.src='about:blank';}},
  {id:'umat-student-ov',isOpen:function(e){return e.classList.contains('open');},close:closeOverlay},
  {id:'stu-cp-ov',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');}}
]);

})();
</script>
HTML;
    }


    // ================================================================== //
    // LECTURER OVERLAY                                                     //
    // ================================================================== //

    private static function lecturer_overlay(int $courseid, string $courseName, int $pending, string $wwwroot, object $user, string $userData): string {
        $safe        = htmlspecialchars($courseName, ENT_QUOTES, 'UTF-8');
        $jsCid       = (int)$courseid;
        $jsName      = json_encode($courseName);
        $jsUD        = $userData;
        $jsPending   = (int)$pending;
        $uid         = (int)$user->id;
        $uName       = json_encode(fullname($user));
        $uInit       = htmlspecialchars(strtoupper(mb_substr($user->firstname,0,1).mb_substr($user->lastname,0,1)), ENT_QUOTES);
        $logUrl      = $wwwroot . '/login/logout.php';
        $badgeHtml   = $pending > 0
            ? '<span class="umat-fab-badge">' . ($pending > 9 ? '9+' : $pending) . '</span>'
            : '';
        $pendingBannerHtml = $pending > 0
            ? '<div class="umat-pending-banner" id="lec-pending-banner"><span class="material-symbols-outlined">pending_actions</span><p>' . (int)$pending . ' AI output' . ($pending > 1 ? 's' : '') . ' awaiting your review. <button class="umat-chip" data-lp="lec-review" type="button" style="font-size:11px;padding:2px 9px;">Review now →</button></p></div>'
            : '';

        $sharedJs = self::shared_js('lec-ov', 'lec-ov-close');

        return <<<HTML
<!-- ============================================================
     LECTURER FAB + COMPACT PANEL + ANALYTICS OVERLAY
     ============================================================ -->

<!-- FAB -->
<button class="umat-fab umat-fab-pulse" id="lec-fab" type="button" aria-label="Open Analytics" style="position:relative;">
  <span class="material-symbols-outlined">leaderboard</span>
  <span class="umat-fab-tip">Lecturer Analytics</span>
  {$badgeHtml}
</button>

<!-- COMPACT INSIGHTS PANEL -->
<div class="umat-cp-ov" id="lec-cp-ov" role="dialog" aria-modal="true">
  <div class="umat-cp umat-cp-lec" id="lec-cp">
    <div class="umat-cp-hdr">
      <div class="umat-cp-hdr-row">
        <div class="umat-cp-av"><span class="material-symbols-outlined">analytics</span></div>
        <div class="umat-cp-info"><h2>Lecturer Analytics</h2><div class="ctx" title="{$safe}">{$safe}</div></div>
        <button class="umat-cp-hbtn umat-cp-exp" id="lec-expand" type="button">
          <span class="material-symbols-outlined">open_in_full</span><span>Dashboard</span>
        </button>
        <button class="umat-cp-hbtn" id="lec-cp-close" type="button" aria-label="Close">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
    </div>
    <div class="umat-cp-tabs">
      <button class="umat-cp-tab active" data-lcp-tab="lcp-insights" type="button">Insights</button>
      <button class="umat-cp-tab" data-lcp-tab="lcp-questions" type="button">Questions</button>
      <button class="umat-cp-tab" data-lcp-tab="lcp-ai" type="button">Ask AI</button>
    </div>
    <div class="umat-cp-pane active" id="lcp-insights" style="overflow-y:auto;">
      <div style="padding:14px;display:grid;grid-template-columns:1fr 1fr;gap:9px;" id="lcp-kpi-grid">
        <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:12px;">
          <div style="width:26px;height:26px;border-radius:var(--u-r6);background:rgba(0,107,47,.1);color:var(--u-p);display:flex;align-items:center;justify-content:center;margin-bottom:6px;"><span class="material-symbols-outlined" style="font-size:15px;">group</span></div>
          <div style="font-size:10px;color:var(--u-ol);">Active Students</div>
          <div style="font-size:18px;font-weight:800;" id="lcp-k-active">—</div>
          <span style="font-size:9px;background:#dcfce7;color:#065f46;padding:2px 6px;border-radius:999px;font-weight:700;" id="lcp-k-active-b">Loading</span>
        </div>
        <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:12px;">
          <div style="width:26px;height:26px;border-radius:var(--u-r6);background:rgba(245,158,11,.1);color:#d97706;display:flex;align-items:center;justify-content:center;margin-bottom:6px;"><span class="material-symbols-outlined" style="font-size:15px;">forum</span></div>
          <div style="font-size:10px;color:var(--u-ol);">AI Interactions</div>
          <div style="font-size:18px;font-weight:800;" id="lcp-k-int">—</div>
          <span style="font-size:9px;background:var(--u-secc);color:var(--u-sec);padding:2px 6px;border-radius:999px;font-weight:700;">30 days</span>
        </div>
        <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:12px;">
          <div style="width:26px;height:26px;border-radius:var(--u-r6);background:rgba(165,48,77,.1);color:var(--u-ter);display:flex;align-items:center;justify-content:center;margin-bottom:6px;"><span class="material-symbols-outlined" style="font-size:15px;">psychology_alt</span></div>
          <div style="font-size:10px;color:var(--u-ol);">Struggle Index</div>
          <div style="font-size:14px;font-weight:800;" id="lcp-k-str">—</div>
          <span style="font-size:9px;background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:999px;font-weight:700;">High</span>
        </div>
        <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:12px;">
          <div style="width:26px;height:26px;border-radius:var(--u-r6);background:rgba(61,104,68,.1);color:var(--u-sec);display:flex;align-items:center;justify-content:center;margin-bottom:6px;"><span class="material-symbols-outlined" style="font-size:15px;">pending_actions</span></div>
          <div style="font-size:10px;color:var(--u-ol);">Pending Review</div>
          <div style="font-size:18px;font-weight:800;">{$pending}</div>
          <button type="button" data-lp="lec-review" style="font-size:9px;background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:999px;font-weight:700;border:none;cursor:pointer;">Review →</button>
        </div>
      </div>
      <div style="padding:0 14px 6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--u-ol);">AI Insights</div>
      <div style="padding:0 14px 14px;display:flex;flex-direction:column;gap:8px;">
        <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-left:3px solid var(--u-ter);border-radius:var(--u-r12);padding:12px;">
          <div style="font-size:12px;font-weight:700;display:flex;align-items:center;gap:6px;margin-bottom:4px;"><span class="material-symbols-outlined" style="font-size:16px;color:var(--u-ter);">warning</span><span id="lcp-gap-title">Analysing learning gaps…</span></div>
          <div style="font-size:11px;color:var(--u-onsv);margin-bottom:8px;" id="lcp-gap-desc">Scanning question patterns…</div>
          <button class="umat-chip" id="lcp-open-dash" type="button">Open Full Dashboard</button>
        </div>
      </div>
      <div style="padding:10px 14px;border-top:1px solid var(--u-olv);display:flex;flex-direction:column;gap:7px;">
        <button class="umat-btn-p" id="lcp-dash-btn" type="button" style="justify-content:center;"><span class="material-symbols-outlined">dashboard</span>Open Analytics Dashboard</button>
        <button class="umat-btn-o" style="justify-content:center;width:100%;" data-lp="lec-review" type="button"><span class="material-symbols-outlined">fact_check</span>Review Outputs ({$pending})</button>
      </div>
    </div>
    <div class="umat-cp-pane" id="lcp-questions" style="overflow-y:auto;">
      <div style="padding:12px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--u-ol);">Top Student Questions</div>
      <div id="lcp-q-list" style="padding:0 14px 14px;display:flex;flex-direction:column;gap:6px;">
        <div style="text-align:center;padding:20px;color:var(--u-ol);font-size:13px;">Loading…</div>
      </div>
    </div>
    <div class="umat-cp-pane" id="lcp-ai" style="flex-direction:column;">
      <div class="umat-msgs" id="lcp-msgs">
        <div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div>
          <div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div>
            <div class="umat-bubble-ai"><p>Ask me about your course analytics — e.g. <em>"Which topics are students struggling with?"</em></p></div>
            <div class="umat-chips-row">
              <button class="umat-chip" data-lp="Which topics are students struggling with the most?" type="button">Struggle areas</button>
              <button class="umat-chip" data-lp="Summarise student AI questions from this week." type="button">Weekly summary</button>
              <button class="umat-chip" data-lp="Which students appear at risk based on AI usage?" type="button">At-risk students</button>
            </div>
          </div>
        </div>
      </div>
      <div class="umat-input-area">
        <div class="umat-input-row">
          <textarea id="lcp-input" class="umat-textarea" placeholder="Ask about your course…" rows="2" maxlength="700"></textarea>
          <button class="umat-send-btn" id="lcp-send" type="button"><span class="material-symbols-outlined">send</span></button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- FULL ANALYTICS OVERLAY -->
<div class="umat-ov" id="lec-ov" role="dialog" aria-modal="true" aria-label="Lecturer Analytics Dashboard">
  <div class="umat-ov-body" style="flex:1;overflow:hidden;display:flex;">

    <!-- SIDEBAR -->
    <div class="umat-sb" id="lec-sb">
      <div class="umat-sb-head">
        <div class="umat-sb-logo"><span class="material-symbols-outlined">school</span></div>
        <div class="umat-sb-brand"><strong>UMaT Moodle</strong><span>AI Enhanced Learning</span></div>
        <button class="umat-sb-close-btn" id="lec-ov-close" type="button" title="Close Dashboard">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
      <nav class="umat-sb-nav">
        <button class="umat-sb-item active" data-lp="lec-home" type="button"><span class="material-symbols-outlined">home</span><span class="umat-sb-item-lbl">Home</span></button>
        <button class="umat-sb-item" data-lp="lec-analytics" type="button"><span class="material-symbols-outlined">bar_chart</span><span class="umat-sb-item-lbl">Analytics</span></button>
        <button class="umat-sb-item" data-lp="lec-courses" type="button"><span class="material-symbols-outlined">menu_book</span><span class="umat-sb-item-lbl">My Courses</span></button>
        <button class="umat-sb-item" data-lp="lec-library" type="button"><span class="material-symbols-outlined">local_library</span><span class="umat-sb-item-lbl">Library</span></button>
        <button class="umat-sb-item" data-lp="lec-sessions" type="button"><span class="material-symbols-outlined">history</span><span class="umat-sb-item-lbl">Sessions</span></button>
        <button class="umat-sb-item" data-lp="lec-review" type="button"><span class="material-symbols-outlined">fact_check</span><span class="umat-sb-item-lbl">Review Outputs</span></button>
      </nav>
      <div class="umat-sb-divider"></div>
      <div class="umat-sb-foot">
        <button class="umat-sb-item" type="button" onclick="window.location.href='{$logUrl}'">
          <span class="material-symbols-outlined">logout</span><span class="umat-sb-item-lbl">Sign Out</span>
        </button>
      </div>
    </div>

    <!-- MOBILE TAB BAR -->
    <div class="umat-mob-tabbar" id="lec-mob-tabs">
      <button class="umat-mob-tab active" data-lp="lec-home" type="button"><span class="material-symbols-outlined">home</span>Home</button>
      <button class="umat-mob-tab" data-lp="lec-analytics" type="button"><span class="material-symbols-outlined">bar_chart</span>Analytics</button>
      <button class="umat-mob-tab" data-lp="lec-courses" type="button"><span class="material-symbols-outlined">menu_book</span>Courses</button>
      <button class="umat-mob-tab" data-lp="lec-library" type="button"><span class="material-symbols-outlined">local_library</span>Library</button>
      <button class="umat-mob-tab" data-lp="lec-sessions" type="button"><span class="material-symbols-outlined">history</span>Sessions</button>
      <button class="umat-mob-tab" data-lp="lec-review" type="button"><span class="material-symbols-outlined">fact_check</span>Review</button>
    </div>

    <!-- CONTENT -->
    <div class="umat-ov-content">

      <!-- HOME -->
      <div class="umat-tab-pane active" id="lec-home">
        <div class="umat-home-wrap">
          <div class="umat-home-hero">
            <h1>Welcome, {$uInit}! 📊</h1>
            <p>Lecturer Analytics Hub — <strong>{$safe}</strong></p>
            <div class="hero-sub" id="lec-home-date"></div>
          </div>
          {$pendingBannerHtml}
          <div class="umat-metrics-row">
            <div class="umat-metric-card"><div class="umat-metric-icon mi-g"><span class="material-symbols-outlined">group</span></div><div><div class="umat-metric-val" id="lec-met-active">—</div><div class="umat-metric-lbl">Active students</div></div></div>
            <div class="umat-metric-card"><div class="umat-metric-icon mi-w"><span class="material-symbols-outlined">forum</span></div><div><div class="umat-metric-val" id="lec-met-int">—</div><div class="umat-metric-lbl">AI interactions</div></div></div>
            <div class="umat-metric-card"><div class="umat-metric-icon mi-r"><span class="material-symbols-outlined">pending_actions</span></div><div><div class="umat-metric-val">{$pending}</div><div class="umat-metric-lbl">Pending review</div></div></div>
          </div>
          <div class="umat-home-section" style="margin-top:20px;">
            <h3>Quick Actions</h3>
            <div class="umat-quick-actions-grid">
              <button class="umat-qa-btn" data-lp="lec-analytics" type="button"><span class="material-symbols-outlined">bar_chart</span><div class="umat-qa-btn-text"><strong>View Analytics</strong><span>Course performance data</span></div></button>
              <button class="umat-qa-btn" data-lp="lec-courses" type="button"><span class="material-symbols-outlined">menu_book</span><div class="umat-qa-btn-text"><strong>My Courses</strong><span>Switch course analytics</span></div></button>
              <button class="umat-qa-btn" data-lp="lec-library" type="button"><span class="material-symbols-outlined">local_library</span><div class="umat-qa-btn-text"><strong>Library</strong><span>Materials &amp; recordings</span></div></button>
              <button class="umat-qa-btn" data-lp="lec-review" type="button"><span class="material-symbols-outlined">fact_check</span><div class="umat-qa-btn-text"><strong>Review AI Outputs</strong><span>{$pending} pending</span></div></button>
            </div>
          </div>
        </div>
      </div>

      <!-- ANALYTICS -->
      <div class="umat-tab-pane" id="lec-analytics" style="overflow:hidden;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">bar_chart</span> Analytics — <span id="lec-an-course-label">{$safe}</span></h2>
          <button class="umat-content-hdr-btn" id="lec-an-export" type="button" onclick="window.print()"><span class="material-symbols-outlined">download</span>Export</button>
        </div>
        <div class="umat-an-scroll" id="lec-an-body">
          <div class="umat-an-kpi-row">
            <div class="umat-an-kpi"><div class="umat-an-kpi-head"><div class="umat-an-kpi-ico ak-g"><span class="material-symbols-outlined">group</span></div><span class="umat-an-kpi-pill pill-g" id="an-pill-active">active</span></div><div class="umat-an-kpi-lbl">Active Students</div><div class="umat-an-kpi-val" id="an-v-active">—</div><div class="umat-an-kpi-sub" id="an-s-active">of — enrolled</div></div>
            <div class="umat-an-kpi"><div class="umat-an-kpi-head"><div class="umat-an-kpi-ico ak-s"><span class="material-symbols-outlined">timer</span></div><span class="umat-an-kpi-pill pill-b">avg Q/session</span></div><div class="umat-an-kpi-lbl">Avg Session Depth</div><div class="umat-an-kpi-val" id="an-v-time">—</div><div class="umat-an-kpi-sub">questions per session</div></div>
            <div class="umat-an-kpi"><div class="umat-an-kpi-head"><div class="umat-an-kpi-ico ak-r"><span class="material-symbols-outlined">psychology_alt</span></div><span class="umat-an-kpi-pill pill-r">High</span></div><div class="umat-an-kpi-lbl">Struggle Index</div><div class="umat-an-kpi-val" style="font-size:18px;" id="an-v-str">—</div><div class="umat-an-kpi-sub">Most-questioned session</div></div>
            <div class="umat-an-kpi"><div class="umat-an-kpi-head"><div class="umat-an-kpi-ico ak-w"><span class="material-symbols-outlined">forum</span></div><span class="umat-an-kpi-pill pill-b" id="an-pill-int">new</span></div><div class="umat-an-kpi-lbl">AI Interactions</div><div class="umat-an-kpi-val" id="an-v-int">—</div><div class="umat-an-kpi-sub">last 30 days</div></div>
          </div>
          <div class="umat-an-2col">
            <div class="umat-an-card">
              <div class="umat-an-card-hdr"><h3 class="umat-an-card-title"><span class="material-symbols-outlined">bar_chart</span>Student Engagement Trends</h3>
                <div style="display:flex;align-items:center;gap:10px;font-size:11px;color:var(--u-ol);">
                  <span style="display:flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:2px;background:var(--u-p);display:inline-block;"></span>Lectures</span>
                  <span style="display:flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:2px;background:var(--u-secc);display:inline-block;"></span>Quizzes</span>
                </div>
              </div>
              <div class="umat-an-card-body">
                <canvas id="an-chart" class="umat-chart-canvas"></canvas>
                <div id="an-chart-labels" style="display:flex;justify-content:space-around;margin-top:5px;font-size:10px;color:var(--u-ol);overflow:hidden;"></div>
              </div>
            </div>
            <div class="umat-an-card">
              <div class="umat-an-card-hdr"><h3 class="umat-an-card-title"><span class="material-symbols-outlined">stacked_bar_chart</span>Student Performance</h3></div>
              <div class="umat-an-card-body">
                <div class="umat-perf-item"><div class="umat-perf-row"><span class="umat-perf-lbl">🟢 High Engagement</span><span class="umat-perf-num" id="an-p-high">—</span></div><div class="umat-perf-bar"><div class="umat-perf-fill pf-high" id="an-pb-high" style="width:0%"></div></div></div>
                <div class="umat-perf-item"><div class="umat-perf-row"><span class="umat-perf-lbl">🟡 On Track</span><span class="umat-perf-num" id="an-p-track">—</span></div><div class="umat-perf-bar"><div class="umat-perf-fill pf-track" id="an-pb-track" style="width:0%"></div></div></div>
                <div class="umat-perf-item"><div class="umat-perf-row"><span class="umat-perf-lbl">🔴 At Risk</span><span class="umat-perf-num" id="an-p-risk">—</span></div><div class="umat-perf-bar"><div class="umat-perf-fill pf-risk" id="an-pb-risk" style="width:0%"></div></div></div>
                <div style="font-size:11px;color:var(--u-ol);margin-top:10px;font-style:italic;">Estimated from AI interaction frequency over 30 days.</div>
              </div>
            </div>
          </div>
          <div class="umat-an-card" style="margin-bottom:18px;">
            <div class="umat-an-card-hdr"><h3 class="umat-an-card-title"><span class="material-symbols-outlined">grid_view</span>Lecture Rewatch Heatmap</h3>
              <div class="umat-hm-legend">
                <span>Less</span>
                <span class="umat-hm-legend-sw" style="background:#dbeafe;"></span>
                <span class="umat-hm-legend-sw" style="background:#93c5fd;"></span>
                <span class="umat-hm-legend-sw" style="background:#4ade80;"></span>
                <span class="umat-hm-legend-sw" style="background:var(--u-p);"></span>
                <span>Struggle Zone</span>
              </div>
            </div>
            <div class="umat-an-card-body">
              <div class="umat-hm-grid" id="an-hm-grid" style="grid-template-columns:40px repeat(10,1fr);"></div>
              <div class="umat-an-ai-insight" id="an-insight" style="display:none;">
                <span class="material-symbols-outlined">lightbulb</span>
                <div class="umat-an-insight-text"><strong id="an-insight-title">AI Insight</strong><span id="an-insight-desc"></span></div>
              </div>
            </div>
          </div>
          <div class="umat-an-card">
            <div class="umat-an-card-hdr"><h3 class="umat-an-card-title"><span class="material-symbols-outlined">help</span>Common Student Questions</h3><span style="padding:3px 9px;border-radius:999px;background:var(--u-secc);color:var(--u-sec);font-size:10px;font-weight:700;" id="an-q-badge">0+ chats</span></div>
            <div class="umat-q-list" id="an-q-list"><div style="text-align:center;padding:24px;color:var(--u-ol);font-size:13px;">Loading questions…</div></div>
          </div>
        </div>
      </div>

      <!-- MY COURSES (LECTURER) -->
      <div class="umat-tab-pane" id="lec-courses">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">menu_book</span> My Courses</h2>
          <input type="text" id="lec-courses-search" placeholder="Filter courses…" style="padding:6px 12px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);width:min(160px,40vw);">
        </div>
        <div class="umat-courses-grid" id="lec-courses-grid">
          <div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading your courses…</p></div>
        </div>
      </div>

      <!-- LIBRARY (LECTURER) -->
      <div class="umat-tab-pane" id="lec-library" style="position:relative;overflow:hidden;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">local_library</span> Library</h2>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <select id="lec-lib-course-sel" style="padding:5px 10px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);max-width:min(160px,40vw);">
              <option value="0">My Courses</option>
            </select>
            <input type="text" id="lec-lib-search" placeholder="Search materials…" style="padding:6px 12px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);width:min(140px,35vw);">
          </div>
        </div>
        <div class="umat-lib-grid" id="lec-lib-grid">
          <div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading materials…</p></div>
        </div>
        <div class="umat-pdf-viewer-wrap" id="lec-pdf-viewer">
          <div class="umat-pdf-viewer-bar">
            <button class="umat-pdf-viewer-back" id="lec-pdf-back" type="button"><span class="material-symbols-outlined">arrow_back</span>Library</button>
            <h4 id="lec-pdf-title">Document</h4>
            <a class="umat-player-dl-btn" id="lec-pdf-dl" href="#" target="_blank" download><span class="material-symbols-outlined">download</span>Download</a>
          </div>
          <iframe id="lec-pdf-iframe" class="umat-pdf-iframe" src="" title="Document Viewer"></iframe>
        </div>
        <!-- Video player panel -->
        <div class="umat-player-panel" id="lec-player-panel">
          <div class="umat-player-top">
            <button class="umat-player-back" id="lec-player-back" type="button">
              <span class="material-symbols-outlined">arrow_back</span>Back
            </button>
            <div class="umat-player-title" id="lec-player-title">Video</div>
            <a class="umat-player-dl-btn" id="lec-player-dl" href="#" download target="_blank">
              <span class="material-symbols-outlined">download</span>Download
            </a>
          </div>
          <div class="umat-player-body" style="max-height:70vh;">
            <div class="umat-player-video-wrap">
              <video id="lec-player-video" preload="metadata">Your browser does not support video.</video>
              <div class="umat-vc">
                <button class="umat-vc-btn" id="lec-vc-pp"><span class="material-symbols-outlined">play_arrow</span></button>
                <button class="umat-vc-btn" id="lec-vc-r30"><span class="material-symbols-outlined">replay_30</span></button>
                <button class="umat-vc-btn" id="lec-vc-f30"><span class="material-symbols-outlined">forward_30</span></button>
                <span class="umat-vc-time"><span id="lec-vc-cur">0:00</span> / <span id="lec-vc-dur">0:00</span></span>
                <input type="range" id="lec-vc-prog" class="umat-vc-progress" min="0" max="100" value="0">
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- SESSIONS (LECTURER) -->
      <div class="umat-tab-pane" id="lec-sessions">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">history</span> AI Chat Sessions</h2>
        </div>
        <div class="umat-sessions-list" id="lec-sess-list">
          <div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading sessions…</p></div>
        </div>
      </div>

      <!-- REVIEW OUTPUTS (LECTURER) -->
      <div class="umat-tab-pane" id="lec-review" style="position:relative;overflow:hidden;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">fact_check</span> Review AI Outputs <span class="umat-badge-num" id="lec-review-badge"></span></h2>
          <button class="umat-content-hdr-btn" id="lec-review-refresh" type="button"><span class="material-symbols-outlined">refresh</span>Refresh</button>
        </div>
        <div id="lec-review-body" style="flex:1;overflow-y:auto;padding:16px 20px;">
          <div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading pending outputs…</p></div>
        </div>
      </div>

    </div><!-- /content -->

    <!-- AI FAB + Mini Panel (inside overlay, only visible when dashboard is open) -->
<button class="umat-fab umat-fab-pulse" id="lec-ai-fab" type="button" style="position:fixed;bottom:100px!important;right:28px!important;z-index:100001!important;" aria-label="Ask AI Assistant">
  <span class="material-symbols-outlined">smart_toy</span>
  <span class="umat-fab-tip">Ask AI Assistant</span>
</button>
    <div id="lec-ai-mini" style="position:fixed;bottom:170px;right:28px;z-index:100002;width:min(340px,92vw);background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r16);box-shadow:var(--u-shadow);display:none;flex-direction:column;overflow:hidden;max-height:440px;">
      <div style="background:linear-gradient(135deg,var(--u-p),var(--u-pb));padding:11px 14px;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <span style="font-size:13px;font-weight:700;">Ask AI About Analytics</span>
        <button id="lec-ai-mini-close" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:26px;height:26px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;" type="button"><span class="material-symbols-outlined" style="font-size:15px;">close</span></button>
      </div>
      <div class="umat-msgs" id="lec-mini-msgs" style="max-height:260px;">
        <div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>Ask me about your course analytics, student patterns, or teaching recommendations.</p></div></div></div>
      </div>
      <div class="umat-input-area" style="padding:8px 12px;border-top:1px solid var(--u-olv);">
        <div class="umat-input-row">
          <input type="text" id="lec-mini-input" placeholder="Ask about analytics…" style="flex:1;padding:8px 11px;border:1.5px solid var(--u-olv);border-radius:var(--u-r8);font-size:13px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sf);">
          <button class="umat-send-btn" id="lec-mini-send" type="button" style="width:36px;height:36px;"><span class="material-symbols-outlined">send</span></button>
        </div>
      </div>
    </div>
  </div><!-- /ov-body -->
</div>

{$sharedJs}

<script>
/* ============================================================
   LECTURER OVERLAY — self-contained IIFE
   ============================================================ */
(function(){
'use strict';
var CID   = {$jsCid};
var CN    = {$jsName};
var UID   = {$uid};
var UD    = {$jsUD};
var anLoaded = {};
var lecLoaded= {};


/* ─── LECTURER COURSE TILES ────────────────── */
function renderLecCourses(courses,g){
  if(!g){g=document.getElementById('lec-courses-grid');}
  if(!g)return;
  courses=courses||[];
  if(!courses.length){
    g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">school</span><p>No courses assigned.</p></div>';
    return;
  }
  g.className='yt-grid';
  g.innerHTML=courses.map(function(c){
    var pending=c.pending_count||0;
    var enrolled=c.enrolled_count||0;
    var sessions=c.session_count||0;
    var badge=pending>0?'<span class="yt-badge" style="background:var(--u-ter);">'+pending+' pending</span>':'';
    return'<div class="yt-tile" data-cid="'+c.id+'" data-cname="'+esc(c.fullname||'')+'">'+
      '<div class="yt-thumb yt-bg-course">'+
        '<div class="yt-course-ov">'+
          '<div class="yt-course-code">'+esc(c.shortname||'')+'</div>'+
          '<div class="yt-course-name">'+esc(c.fullname||'')+'</div>'+
        '</div>'+
        badge+
      '</div>'+
      '<div class="yt-meta">'+
        '<div class="yt-av yt-av-course"><span class="material-symbols-outlined">bar_chart</span></div>'+
        '<div class="yt-text">'+
          '<h4 class="yt-title">'+esc(c.fullname||'')+'</h4>'+
          '<p class="yt-channel">'+esc(c.shortname||'')+(enrolled?' · '+enrolled+' students':'')+'</p>'+
          '<p class="yt-stats">'+sessions+' sessions'+(pending>0?' · '+pending+' outputs pending':'')+'</p>'+
        '</div>'+
      '</div>'+
      '<div class="yt-actions">'+
        '<button class="yt-btn" data-act="analytics" onclick="event.stopPropagation()"><span class="material-symbols-outlined">bar_chart</span>Analytics</button>'+
        '<button class="yt-btn" data-act="library" onclick="event.stopPropagation()"><span class="material-symbols-outlined">local_library</span>Library</button>'+
        (pending>0?'<button class="yt-btn" data-act="review" onclick="event.stopPropagation()" style="border-color:var(--u-ter);color:var(--u-ter);"><span class="material-symbols-outlined">fact_check</span>Review</button>':'')+
      '</div>'+
    '</div>';
  }).join('');

  /* Tile body click → analytics */
  g.querySelectorAll('.yt-tile').forEach(function(tile){
    tile.addEventListener('click',function(e){
      if(e.target.closest('[data-act]'))return;
      CID=parseInt(tile.dataset.cid)||CID;CN=tile.dataset.cname||CN;
      var lbl=document.getElementById('lec-an-course-label');if(lbl)lbl.textContent=CN;
      var ctx=document.getElementById('lec-ctx-label');if(ctx)ctx.textContent=CN;
      anLoaded[CID]=false;
      switchPane('lec-analytics');loadAnalytics(CID);
    });
    /* Action buttons */
    tile.querySelectorAll('[data-act]').forEach(function(btn){
      btn.addEventListener('click',function(e){
        e.stopPropagation();
        CID=parseInt(tile.dataset.cid)||CID;CN=tile.dataset.cname||CN;
        var lbl=document.getElementById('lec-an-course-label');if(lbl)lbl.textContent=CN;
        var ctx=document.getElementById('lec-ctx-label');if(ctx)ctx.textContent=CN;
        var act=btn.dataset.act;
        if(act==='analytics'){anLoaded[CID]=false;switchPane('lec-analytics');loadAnalytics(CID);}
        else if(act==='library'){lecLoaded['lec-library']=false;switchPane('lec-library');loadLibrary();}
        else if(act==='review'){lecLoaded['lec-review']=false;switchPane('lec-review');if(typeof loadReviewPane==='function')loadReviewPane();}
      });
    });
  });
  var srch=document.getElementById('lec-courses-search')||document.getElementById('lec-courses-srch');
  if(srch)srch.addEventListener('input',function(){
    var q=this.value.toLowerCase();
    g.querySelectorAll('.yt-tile').forEach(function(t){t.style.display=(!q||t.textContent.toLowerCase().includes(q))?'':'none';});
  });
}

/* FAB / panel / overlay */
var fab=document.getElementById('lec-fab');
var cpOv=document.getElementById('lec-cp-ov');
var lecOv=document.getElementById('lec-ov');
var cpClose=document.getElementById('lec-cp-close');
var ovClose=document.getElementById('lec-ov-close');
var expand=document.getElementById('lec-expand');
var panelDataLoaded=false;

function openPanel(){cpOv.classList.add('open');fab.setAttribute('aria-expanded','true');if(!panelDataLoaded){loadPanelData();panelDataLoaded=true;}}
function closePanel(){cpOv.classList.remove('open');fab.setAttribute('aria-expanded','false');}
function openDash(){closePanel();lecOv.classList.add('open');if(!anLoaded[CID]){loadAnalytics(CID);}}
function closeDash(){lecOv.classList.remove('open');openPanel();}

if(fab)fab.addEventListener('click',openPanel);
if(cpClose)cpClose.addEventListener('click',closePanel);
if(cpOv)cpOv.addEventListener('click',function(e){if(e.target===cpOv)closePanel();});
if(expand)expand.addEventListener('click',openDash);
if(ovClose)ovClose.addEventListener('click',closeDash);
if(lecOv)lecOv.addEventListener('click',function(e){if(e.target===lecOv)closeDash();});
var dashBtn=document.getElementById('lcp-dash-btn');if(dashBtn)dashBtn.addEventListener('click',openDash);
var openDashBtn=document.getElementById('lcp-open-dash');if(openDashBtn)openDashBtn.addEventListener('click',openDash);

/* Compact panel tabs */
document.querySelectorAll('[data-lcp-tab]').forEach(function(b){
  b.addEventListener('click',function(){
    var t=b.dataset.lcpTab;
    document.querySelectorAll('[data-lcp-tab]').forEach(function(x){x.classList.remove('active');});
    document.querySelectorAll('#lec-cp .umat-cp-pane').forEach(function(x){x.classList.remove('active');});
    b.classList.add('active');var p=document.getElementById(t);if(p)p.classList.add('active');
  });
});
var lcpMsgs=document.getElementById('lcp-msgs');
if(lcpMsgs)lcpMsgs.addEventListener('click',function(e){
  var chip=e.target.closest('.umat-chip[data-lp]');
  if(chip){switchToAI(chip.dataset.lp);}
});
function switchToAI(q){
  document.querySelectorAll('[data-lcp-tab]').forEach(function(x){x.classList.remove('active');});
  document.querySelectorAll('#lec-cp .umat-cp-pane').forEach(function(x){x.classList.remove('active');});
  var tb=document.querySelector('[data-lcp-tab="lcp-ai"]');var pn=document.getElementById('lcp-ai');
  if(tb)tb.classList.add('active');if(pn)pn.classList.add('active');
  if(q){document.getElementById('lcp-input').value=q;document.getElementById('lcp-send').click();}
}

/* Sidebar & mobile tab pane switching */
function switchPane(name){
  document.querySelectorAll('#lec-ov .umat-tab-pane').forEach(function(p){p.classList.remove('active');});
  document.querySelectorAll('#lec-sb [data-lp], #lec-mob-tabs [data-lp]').forEach(function(b){b.classList.toggle('active',b.dataset.lp===name);});
  var pane=document.getElementById(name);if(pane)pane.classList.add('active');
  if(!lecLoaded[name]){lecLoaded[name]=true;loadPaneData(name);}
}
/* Handle data-lp clicks from compact panel → open full overlay */
document.querySelectorAll('#lec-cp [data-lp]').forEach(function(b){
  b.addEventListener('click',function(){closePanel();openDash();switchPane(b.dataset.lp);});
});
document.querySelectorAll('#lec-sb [data-lp], #lec-mob-tabs [data-lp]').forEach(function(b){
  b.addEventListener('click',function(){switchPane(b.dataset.lp);});
});
document.addEventListener('click',function(e){
  var btn=e.target.closest('[data-lp]');
  if(btn && btn.closest('#lec-home')){switchPane(btn.dataset.lp);}
});

/* Home init */
function initHome(){
  if(!CID)return;
  var d=new Date(),dEl=document.getElementById('lec-home-date');
  if(dEl)dEl.textContent=d.toLocaleDateString('en-GB',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
  /* Use panel data if already loaded */
  if(panelDataLoaded)return;
  ajax('local_umat_ai_get_analytics',{courseid:CID,days:30},function(data){
    var ms=document.getElementById('lec-met-active');var mi=document.getElementById('lec-met-int');
    if(ms)ms.textContent=data.active_students+'/'+data.enrolled_students;
    if(mi)mi.textContent=data.total_interactions.toLocaleString();
  },function(){});
}

function loadPaneData(name){
  if(name==='lec-analytics')loadAnalytics(CID);
  if(name==='lec-courses')loadLecturerCourses();
  if(name==='lec-library'){populateLibCourseSel();loadLibrary();}
  if(name==='lec-sessions')loadSessions();
  if(name==='lec-review')loadReviewPane();
  if(name==='lec-home')initHome();
}

/* Refresh review pane */
var reviewRefresh=document.getElementById('lec-review-refresh');
if(reviewRefresh)reviewRefresh.addEventListener('click',loadReviewPane);

/* Load panel (compact) data */
function loadPanelData(){
  if(!CID){return;}
  ajax('local_umat_ai_get_analytics',{courseid:CID,days:30},function(d){
    var s=function(id,v){var e=document.getElementById(id);if(e)e.textContent=v;};
    s('lcp-k-active',d.active_students+'/'+d.enrolled_students);
    s('lcp-k-active-b',Math.round(d.active_students/Math.max(d.enrolled_students,1)*100)+'% active');
    s('lcp-k-int',d.total_interactions.toLocaleString());
    s('lcp-k-str',d.struggle_index);
    if(d.struggle_index!=='N/A'){
      s('lcp-gap-title','Learning Gap: '+d.struggle_index);
      s('lcp-gap-desc','Students ask the most questions in '+d.struggle_index+'. Consider a targeted review session.');
    }
    var ms=document.getElementById('lec-met-active');var mi=document.getElementById('lec-met-int');
    if(ms)ms.textContent=d.active_students+'/'+d.enrolled_students;
    if(mi)mi.textContent=d.total_interactions.toLocaleString();
    /* Top questions */
    var ql=document.getElementById('lcp-q-list');
    if(ql&&d.top_questions&&d.top_questions.length){
      ql.innerHTML=d.top_questions.slice(0,5).map(function(q){
        return '<div style="padding:8px;background:var(--u-sf);border:1px solid var(--u-olv);border-radius:var(--u-r8);">'+
          '<div style="font-size:12px;color:var(--u-ons);margin-bottom:3px;">'+esc(q.text)+'</div>'+
          '<div style="font-size:10px;color:var(--u-ol);"><b style="color:var(--u-p);">'+q.ask_count+'</b> students asked</div></div>';
      }).join('');
    }
  },function(){});
}

/* Analytics load & render */
function loadAnalytics(cid){
  anLoaded[cid]=true;
  var label=document.getElementById('lec-an-course-label');
  if(!cid){if(label)label.textContent='Go to a course page to view analytics';return;}
  document.getElementById('lec-an-course-label').textContent=cid===CID?CN:'Loading…';
  ajax('local_umat_ai_get_analytics',{courseid:cid,days:30},function(d){
    /* KPI cards */
    var s=function(id,v){var e=document.getElementById(id);if(e)e.textContent=v;};
    s('an-v-active',d.active_students+' / '+d.enrolled_students);
    s('an-s-active','of '+d.enrolled_students+' enrolled');
    s('an-pill-active',Math.round(d.active_students/Math.max(d.enrolled_students,1)*100)+'% active');
    s('an-v-time',d.avg_questions_per_session+' Q');
    s('an-v-str',d.struggle_index);
    s('an-v-int',d.total_interactions.toLocaleString());
    s('an-pill-int','+'+d.total_interactions);
    /* Chart */
    drawChart(d.daily_counts,d.max_daily||1);
    /* Performance */
    var tot=Math.max(d.enrolled_students,1);
    var h=d.high_performers||0,risk=Math.max(0,d.enrolled_students-d.active_students),track=Math.max(0,d.active_students-h);
    s('an-p-high',h+' students');s('an-p-track',track+' students');s('an-p-risk',risk+' students');
    setTimeout(function(){
      var pb=function(id,n,tot){var e=document.getElementById(id);if(e)e.style.width=Math.min(100,Math.round(n/tot*100))+'%';};
      pb('an-pb-high',h,tot);pb('an-pb-track',track,tot);pb('an-pb-risk',risk,tot);
    },300);
    /* Heatmap */
    buildHeatmap(d.daily_counts,d.max_daily||1,d.struggle_index);
    /* Questions */
    var badge=document.getElementById('an-q-badge');if(badge)badge.textContent='Aggregation of '+d.total_interactions+'+ chats';
    var qList=document.getElementById('an-q-list');
    if(qList){
      if(!d.top_questions||!d.top_questions.length){qList.innerHTML='<div style="text-align:center;padding:32px;color:var(--u-ol);font-size:13px;">No questions logged yet.</div>';return;}
      var acts=['Prepare Response','Generate AI Summary','Add to FAQ','Create Quiz','Schedule Review'];
      qList.innerHTML=d.top_questions.map(function(q,i){
        return '<div class="umat-q-row">'+
          '<div class="umat-q-votes"><div class="v-n">'+q.ask_count+'</div><div class="v-l">votes</div></div>'+
          '<div class="umat-q-content"><div class="umat-q-text">&ldquo;'+esc(q.text)+'&rdquo;</div><div class="umat-q-related">Related to: <span>Course Materials</span></div></div>'+
          '<div class="umat-q-action"><button class="umat-q-action-btn" type="button">'+esc(acts[i%acts.length])+'</button></div></div>';
      }).join('');
    }
  },function(){var s=document.getElementById('an-v-active');if(s)s.textContent='Error';});
}

/* Bar chart */
function drawChart(daily,maxV){
  var canvas=document.getElementById('an-chart');if(!canvas||!daily||!daily.length)return;
  var ctx=canvas.getContext('2d');
  var W=canvas.offsetWidth||600,H=180;canvas.width=W;canvas.height=H;
  var n=daily.length,pad={l:28,r:8,t:16,b:24};
  var cW=W-pad.l-pad.r,cH=H-pad.t-pad.b;
  var bW=Math.max(6,(cW/n)*0.5),bW2=bW*0.55;
  var labDiv=document.getElementById('an-chart-labels');if(labDiv)labDiv.innerHTML='';
  ctx.clearRect(0,0,W,H);
  [.25,.5,.75,1].forEach(function(f){
    var y=pad.t+cH*(1-f);ctx.strokeStyle='#e5e7eb';ctx.lineWidth=1;
    ctx.beginPath();ctx.moveTo(pad.l,y);ctx.lineTo(pad.l+cW,y);ctx.stroke();
    ctx.fillStyle='#9ca3af';ctx.font='10px Inter,sans-serif';ctx.textAlign='right';
    ctx.fillText(Math.round(maxV*f),pad.l-3,y+3);
  });
  ctx.strokeStyle='#d1d5db';ctx.lineWidth=1;
  ctx.beginPath();ctx.moveTo(pad.l,pad.t+cH);ctx.lineTo(pad.l+cW,pad.t+cH);ctx.stroke();
  daily.forEach(function(d,i){
    var x=pad.l+(i/n)*cW+((cW/n)-bW-bW2-2)/2;
    var bH=Math.max(2,(d.count/maxV)*cH),y=pad.t+cH-bH;
    var g=ctx.createLinearGradient(0,y,0,pad.t+cH);g.addColorStop(0,'#00873d');g.addColorStop(1,'#006b2f');
    ctx.fillStyle=g;ctx.beginPath();
    if(ctx.roundRect){ctx.roundRect(x,y,bW,bH,[3,3,0,0]);}else{ctx.rect(x,y,bW,bH);}ctx.fill();
    var qH=Math.max(2,bH*0.38),qY=pad.t+cH-qH;
    ctx.fillStyle='rgba(190,239,193,.85)';ctx.beginPath();
    if(ctx.roundRect){ctx.roundRect(x+bW+2,qY,bW2,qH,[2,2,0,0]);}else{ctx.rect(x+bW+2,qY,bW2,qH);}ctx.fill();
    ctx.fillStyle='#6b7280';ctx.font='10px Inter,sans-serif';ctx.textAlign='center';
    ctx.fillText(d.label||'',x+bW/2,pad.t+cH+16);
  });
}

/* Heatmap */
function buildHeatmap(daily,maxV,struggleIdx){
  var grid=document.getElementById('an-hm-grid');if(!grid)return;
  var days=['Mon','Tue','Wed','Thu','Fri'];
  var n=Math.min(10,daily.length);
  if(!n){grid.innerHTML='<div style="grid-column:1/-1;text-align:center;padding:20px;color:var(--u-ol);font-size:13px;">No heatmap data yet.</div>';return;}
  grid.style.gridTemplateColumns='40px repeat('+n+',1fr)';
  var html='<div></div>';
  for(var c=0;c<n;c++)html+='<div style="font-size:9px;color:var(--u-ol);text-align:center;padding-bottom:4px;">L'+(c+1)+'</div>';
  days.forEach(function(day,row){
    html+='<div class="umat-hm-row-lbl">'+day+'</div>';
    for(var col=0;col<n;col++){
      var base=daily[col]?daily[col].count:0;
      var va=[1,.8,1.2,.6,.9][row]*[1,.7,1.1,.85,.95,.6,1.3,.8,.75,1][col%10];
      var val=Math.round(base*va*.5);var pct=val/(maxV||1);
      var bg=pct<.15?'#dbeafe':pct<.4?'#93c5fd':pct<.7?'#4ade80':'var(--u-p)';
      var color=pct>=.7?'#fff':'rgba(0,0,0,.5)';
      html+='<div class="umat-hm-cell" style="background:'+bg+';color:'+color+';" title="'+day+' · L'+(col+1)+': '+val+'">'+(val>0?val:'')+'</div>';
    }
  });
  grid.innerHTML=html;
  if(struggleIdx&&struggleIdx!=='N/A'){
    var ins=document.getElementById('an-insight');var t=document.getElementById('an-insight-title');var desc=document.getElementById('an-insight-desc');
    if(ins&&t&&desc){ins.style.display='flex';t.textContent='AI Insight: Complex Concept Detected';desc.textContent='Students are spending significantly more time on '+struggleIdx+'. Consider scheduling a recap session.';}
  }
}

/* Lecturer courses (from preloaded UD.courses, fallback AJAX) */
function loadLecturerCourses(){
  var g=document.getElementById('lec-courses-grid');
  if(UD&&UD.courses&&UD.courses.length){renderLecCourses(UD.courses,g);return;}
  ajax('local_umat_ai_get_my_courses',{role:'lecturer'},function(r){renderLecCourses(r.courses||[],g);},function(){g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load courses.</p></div>';});
}

/* Library — with course selector dropdown */
function populateLibCourseSel(){
  var sel=document.getElementById('lec-lib-course-sel');
  if(!sel||!UD||!UD.courses)return;
  sel.innerHTML='<option value="0">All My Courses</option>'+
    UD.courses.map(function(c){return '<option value="'+c.id+'">'+esc(c.shortname)+'</option>';}).join('');
  sel.addEventListener('change',function(){
    var cid=parseInt(this.value)||0;
    loadLibrary(cid);
  });
}
function loadLibrary(cid){
  var g=document.getElementById('lec-lib-grid');
  var sel=document.getElementById('lec-lib-course-sel');
  if(cid===undefined&&CID&&sel)sel.value=CID;
  var courseId=cid||(sel?parseInt(sel.value)||0:CID||0);
  if(!courseId){
    g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">school</span><p>Select a course from the dropdown to browse its materials.</p></div>';
    return;
  }
  g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading materials…</p></div>';
  ajax('local_umat_ai_get_course_materials',{courseid:courseId},function(r){renderLibTiles(r.materials||[],g);},function(){g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">error_outline</span><p>Could not load materials.</p></div>';});
}
function openLecPdf(url,name){
  var v=document.getElementById('lec-pdf-viewer');var ti=document.getElementById('lec-pdf-title');var dl=document.getElementById('lec-pdf-dl');var fr=document.getElementById('lec-pdf-iframe');
  if(!v)return;if(ti)ti.textContent=name;if(dl){dl.href=url;dl.setAttribute('download',name);}if(fr)fr.src=url;v.classList.add('open');
}
var lecPdfBack=document.getElementById('lec-pdf-back');
if(lecPdfBack)lecPdfBack.addEventListener('click',function(){
  var v=document.getElementById('lec-pdf-viewer');if(v)v.classList.remove('open');
  var fr=document.getElementById('lec-pdf-iframe');if(fr)fr.src='';
});
function openLecPlayer(url,name,segments){
  var panel=document.getElementById('lec-player-panel');
  var video=document.getElementById('lec-player-video');
  var titleEl=document.getElementById('lec-player-title');
  var dlBtn=document.getElementById('lec-player-dl');
  if(titleEl)titleEl.textContent=name||'Video';
  if(dlBtn){dlBtn.href=url||'#';dlBtn.setAttribute('download',(name||'video').replace(/[^a-z0-9]/gi,'_')+'.mp4');}
  if(video&&url){
    video.src=url;
    _umatInitPlayer({
      videoId:'lec-player-video',playBtnId:'lec-vc-pp',progId:'lec-vc-prog',
      curId:'lec-vc-cur',durId:'lec-vc-dur',r30Id:'lec-vc-r30',f30Id:'lec-vc-f30',
      tsBodyId:'',tsSearchId:''
    });
  }
  if(panel)panel.classList.add('open');
}
var lecPlayerBack=document.getElementById('lec-player-back');
if(lecPlayerBack)lecPlayerBack.addEventListener('click',function(){
  var panel=document.getElementById('lec-player-panel');
  var video=document.getElementById('lec-player-video');
  if(panel)panel.classList.remove('open');
  if(video){video.pause();video.src='';}
});

/* Sessions */
function loadSessions(){
  var list=document.getElementById('lec-sess-list');
  if(!CID){list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">school</span><p>Select a course to view its sessions.</p></div>';return;}
  ajax('local_umat_ai_get_ai_sessions',{courseid:CID,limit:20},function(r){
    if(!r.sessions||!r.sessions.length){list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">history</span><p>No AI chat sessions yet.</p></div>';return;}
    list.innerHTML=r.sessions.map(function(s){
      return '<div class="umat-session-tile">'+
        '<div class="umat-session-tile-hdr"><span class="umat-session-badge">'+esc(s.course_short||'GEN')+'</span><span class="umat-session-time">'+esc(s.time_label)+'</span></div>'+
        '<h4>'+esc(s.course_name)+' AI Session</h4><p>'+esc(s.preview)+'</p>'+
        '<div class="umat-session-tile-foot"><div class="umat-session-meta"><span class="material-symbols-outlined">chat</span>'+s.msg_count+' messages</div></div></div>';
    }).join('');
  },function(){list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load sessions.</p></div>';});
}

/* ---- Review Outputs pane ---- */
function fmtDate(ts){var d=new Date(ts*1000);return d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});}
function outTypeIcon(t){if(t==='summary')return 'summarize';if(t==='notes')return 'notes';if(t==='quiz')return 'quiz';return 'description';}
function outTypeLbl(t){if(t==='summary')return 'Summary';if(t==='notes')return 'Notes';if(t==='quiz')return 'Quiz';return t;}

function loadReviewPane(){
  var body=document.getElementById('lec-review-body');
  if(!body)return;
  body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading pending outputs…</p></div>';
  ajax('local_umat_ai_get_pending_outputs',{courseid:CID},function(r){
    renderReviewOutputs(r);
  },function(){
    body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load pending outputs.</p></div>';
  });
}

function renderReviewOutputs(data){
  var body=document.getElementById('lec-review-body');
  var badge=document.getElementById('lec-review-badge');
  if(!body)return;
  var total=data.total_pending||0;
  if(badge)badge.textContent=total?'('+total+')':'';
  if(!data.sessions||!data.sessions.length){
    body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">fact_check</span><p>No AI outputs awaiting review.</p></div>';
    return;
  }
  body.innerHTML=data.sessions.map(function(s){
    return '<div class="umat-rev-sess" data-sid="'+s.session_id+'" data-cid="'+s.courseid+'">'+
      '<div class="umat-rev-shdr">'+
        '<span class="material-symbols-outlined" style="font-size:18px;color:var(--u-p);">mic</span>'+
        '<div><strong>'+esc(s.course_name)+'</strong><span>'+fmtDate(s.timecreated)+'</span></div>'+
        '<span class="umat-rev-badge">'+s.pending_count+' pending</span>'+
      '</div>'+
      s.outputs.map(function(o){
        return '<div class="umat-rev-out" data-oid="'+o.id+'">'+
          '<div class="umat-rev-ohdr">'+
            '<span class="umat-rev-type type-'+o.type+'"><span class="material-symbols-outlined">'+outTypeIcon(o.type)+'</span>'+outTypeLbl(o.type)+'</span>'+
            '<span class="umat-rev-date">'+fmtDate(o.timecreated)+'</span>'+
          '</div>'+
          '<div class="umat-rev-cont">'+esc(o.content)+'</div>'+
          '<div class="umat-rev-acts">'+
            '<button class="umat-rev-btn rev-ap" type="button"><span class="material-symbols-outlined">check_circle</span>Approve</button>'+
            '<button class="umat-rev-btn rev-rj" type="button"><span class="material-symbols-outlined">cancel</span>Reject</button>'+
          '</div>'+
        '</div>';
      }).join('')+
    '</div>';
  }).join('');

  body.querySelectorAll('.umat-rev-btn').forEach(function(btn){
    btn.addEventListener('click',function(){
      var outEl=btn.closest('.umat-rev-out');
      var sessEl=btn.closest('.umat-rev-sess');
      if(!outEl||!sessEl)return;
      var oid=parseInt(outEl.dataset.oid);
      var cid=parseInt(sessEl.dataset.cid);
      var action=btn.classList.contains('rev-ap')?'approve':'reject';
      if(!oid||!cid)return;
      btn.disabled=true;var orig=btn.innerHTML;
      btn.innerHTML='<span class="material-symbols-outlined" style="font-size:14px;">hourglass_top</span>';
      ajax('local_umat_ai_approve_output',{outputid:oid,courseid:cid,action:action,comment:''},function(r){
        if(r.success){
          outEl.style.opacity='.35';outEl.style.pointerEvents='none';
          outEl.querySelector('.umat-rev-acts').innerHTML='<span class="umat-rev-done"><span class="material-symbols-outlined">check</span>'+action.charAt(0).toUpperCase()+action.slice(1)+'d</span>';
          updateReviewCounts();
        }else{
          btn.disabled=false;btn.innerHTML=orig+' (Failed)';
        }
      },function(){
        btn.disabled=false;btn.innerHTML=orig+' (Error)';
      });
    });
  });
}

function updateReviewCounts(){
  var badge=document.getElementById('lec-review-badge');
  var remaining=document.querySelectorAll('.umat-rev-out[style*="opacity"]').length;
  var total=document.querySelectorAll('.umat-rev-out').length;
  var pending=total-remaining;
  if(badge)badge.textContent=pending?'('+pending+')':'';
  if(pending===0){
    var body=document.getElementById('lec-review-body');
    if(body)setTimeout(function(){
      if(body.querySelectorAll('.umat-rev-out:not([style*="opacity"])').length===0)
        body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">fact_check</span><p>All outputs reviewed! 🎉</p></div>';
    },600);
  }
}

/* Compact panel lecturer AI send */
function appendLecMsg(text,isUser){
  var c=document.getElementById('lcp-msgs');if(!c)return;
  var d=document.createElement('div');
  if(isUser){d.innerHTML='<div class="umat-msg-user"><div class="umat-bubble-user"><p>'+esc(text)+'</p></div></div>';}
  else{d.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>'+esc(text)+'</p></div></div></div>';}
  c.appendChild(d);c.scrollTop=c.scrollHeight;
}
function sendLecQ(q){
  q=(q||'').trim();if(!q)return;
  if(!CID){appendLecMsg('Please open a course page first to ask about its analytics.',false);return;}
  appendLecMsg(q,true);var inp=document.getElementById('lcp-input');if(inp)inp.value='';
  var tid='lt_'+Date.now();
  var c=document.getElementById('lcp-msgs');if(c){var t=document.createElement('div');t.id=tid;t.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><div class="umat-typing"><span></span><span></span><span></span></div></div></div></div>';c.appendChild(t);c.scrollTop=c.scrollHeight;}
  ajax('local_umat_ai_lecturer_ask',{courseid:CID,query:q},
    function(r){var e=document.getElementById(tid);if(e)e.parentNode.removeChild(e);appendLecMsg(r.response||'No response.',false);},
    function(){var e=document.getElementById(tid);if(e)e.parentNode.removeChild(e);appendLecMsg('Connection error.',false);}
  );
}
var lcpIn=document.getElementById('lcp-input');var lcpSend=document.getElementById('lcp-send');
if(lcpSend)lcpSend.addEventListener('click',function(){sendLecQ(lcpIn.value);});
if(lcpIn)lcpIn.addEventListener('keypress',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();lcpSend.click();}});

/* Mini AI panel (always accessible, outside overlay) */
var aiFab=document.getElementById('lec-ai-fab');var aiMini=document.getElementById('lec-ai-mini');
if(aiFab&&aiMini)aiFab.addEventListener('click',function(){aiMini.style.display=aiMini.style.display==='flex'?'none':'flex';});
var aiclose=document.getElementById('lec-ai-mini-close');
if(aiclose&&aiMini)aiclose.addEventListener('click',function(){aiMini.style.display='none';});
if(aiMini&&aiFab)document.addEventListener('click',function(e){if(aiMini.style.display==='flex'&&!aiMini.contains(e.target)&&!aiFab.contains(e.target))aiMini.style.display='none';});
function appendMiniMsg(text,isUser){
  var c=document.getElementById('lec-mini-msgs');if(!c)return;
  var d=document.createElement('div');
  if(isUser)d.innerHTML='<div class="umat-msg-user"><div class="umat-bubble-user" style="max-width:90%;"><p>'+esc(text)+'</p></div></div>';
  else d.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>'+esc(text)+'</p></div></div></div>';
  c.appendChild(d);c.scrollTop=c.scrollHeight;
}
var miniIn=document.getElementById('lec-mini-input');var miniSend=document.getElementById('lec-mini-send');
if(miniSend)miniSend.addEventListener('click',function(){
  var q=(miniIn.value||'').trim();if(!q)return;appendMiniMsg(q,true);miniIn.value='';
  ajax('local_umat_ai_lecturer_ask',{courseid:CID,query:q},function(r){appendMiniMsg(r.response||'No response.',false);},function(){appendMiniMsg('Error.',false);});
});
if(miniIn)miniIn.addEventListener('keypress',function(e){if(e.key==='Enter'){e.preventDefault();if(miniSend)miniSend.click();}});

/* Init home on overlay open */
initHome();
document.getElementById('lec-home-date').textContent=(function(){var d=new Date();return d.toLocaleDateString('en-GB',{weekday:'long',day:'numeric',month:'long',year:'numeric'});})();
/* Populate library course selector */
populateLibCourseSel();
/* Auto-load analytics when overlay opens */
if(expand)expand.addEventListener('click',function(){setTimeout(function(){if(!lecLoaded['lec-analytics']){lecLoaded['lec-analytics']=true;loadAnalytics(CID);}},100);});
/* Expose player/viewer functions globally so shared yt-grid renderers can call them */
window.openLecPlayer=openLecPlayer;
window.openLecPdf=openLecPdf;

/* ESC: close nested-first, root-last */
_umatInitEsc([
  {id:'lec-ai-mini',isOpen:function(e){return e.style.display==='flex';},close:function(e){e.style.display='none';}},
  {id:'lec-pdf-viewer',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');var f=document.getElementById('lec-pdf-iframe');if(f)f.src='';}},
  {id:'lec-player-panel',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');var v=document.getElementById('lec-player-video');if(v){v.pause();v.src='';}}},
  {id:'lec-ov',isOpen:function(e){return e.classList.contains('open');},close:closeDash},
  {id:'lec-cp-ov',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');}}
]);

})();
</script>
HTML;
    }


    // ================================================================== //
    // HUB OVERLAY — injected on all non-course student pages              //
    // ================================================================== //
    // HUB OVERLAY — injected on all non-course student pages              //
    // ================================================================== //

    private static function hub_overlay(string $wwwroot, object $user, string $userData): string {
        $uid     = (int)$user->id;
        $uName   = json_encode(fullname($user));
        $uInit   = htmlspecialchars(strtoupper(mb_substr($user->firstname,0,1).mb_substr($user->lastname,0,1)), ENT_QUOTES);
        $jsUD    = $userData; // raw JSON string from preload_user_data()
        $logUrl  = $wwwroot . '/login/logout.php';

        return <<<HTML
<!-- ============================================================
     HUB FAB + OVERLAY (non-course pages — students only)
     ============================================================ -->

<button class="umat-fab umat-fab-pulse" id="hub-fab" type="button" aria-label="Open AI Hub">
  <span class="material-symbols-outlined">forum</span>
  <span class="umat-fab-tip">AI Learning Hub</span>
</button>

<div class="umat-ov" id="hub-ov" role="dialog" aria-modal="true" aria-label="AI Learning Hub">
  <div class="umat-ov-body" style="flex:1;overflow:hidden;display:flex;">

    <!-- SIDEBAR -->
    <div class="umat-sb" id="hub-sb">
      <div class="umat-sb-head">
        <div class="umat-sb-logo"><span class="material-symbols-outlined">school</span></div>
        <div class="umat-sb-brand"><strong>UMaT Moodle</strong><span>AI Enhanced Learning</span></div>
        <button class="umat-sb-close-btn" id="hub-ov-close" type="button" title="Close Hub">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
      <nav class="umat-sb-nav">
        <button class="umat-sb-item active" data-hp="hub-home" type="button"><span class="material-symbols-outlined">home</span><span class="umat-sb-item-lbl">Home</span></button>
        <button class="umat-sb-item" data-hp="hub-tutor" type="button"><span class="material-symbols-outlined">smart_toy</span><span class="umat-sb-item-lbl">AI Tutor</span></button>
        <button class="umat-sb-item" data-hp="hub-lectures" type="button"><span class="material-symbols-outlined">video_library</span><span class="umat-sb-item-lbl">Lecture Recordings</span></button>
        <button class="umat-sb-item" data-hp="hub-courses" type="button"><span class="material-symbols-outlined">menu_book</span><span class="umat-sb-item-lbl">My Courses</span></button>
        <button class="umat-sb-item" data-hp="hub-library" type="button"><span class="material-symbols-outlined">local_library</span><span class="umat-sb-item-lbl">Library</span></button>
        <button class="umat-sb-item" data-hp="hub-sessions" type="button"><span class="material-symbols-outlined">history</span><span class="umat-sb-item-lbl">Sessions</span></button>
      </nav>
      <div class="umat-sb-divider"></div>
      <button class="umat-sb-new" id="hub-new-sess" type="button">
        <span class="material-symbols-outlined">add</span>
        <span class="umat-sb-new-lbl">New Session</span>
      </button>
      <div class="umat-sb-foot">
        <button class="umat-sb-item" type="button" onclick="window.location.href='{$logUrl}'">
          <span class="material-symbols-outlined">logout</span><span class="umat-sb-item-lbl">Sign Out</span>
        </button>
      </div>
    </div>

    <!-- MOBILE TAB BAR -->
    <div class="umat-mob-tabbar" id="hub-mob-tabs">
      <button class="umat-mob-tab active" data-hp="hub-home" type="button"><span class="material-symbols-outlined">home</span>Home</button>
      <button class="umat-mob-tab" data-hp="hub-tutor" type="button"><span class="material-symbols-outlined">smart_toy</span>AI Tutor</button>
      <button class="umat-mob-tab" data-hp="hub-lectures" type="button"><span class="material-symbols-outlined">video_library</span>Lectures</button>
      <button class="umat-mob-tab" data-hp="hub-courses" type="button"><span class="material-symbols-outlined">menu_book</span>Courses</button>
      <button class="umat-mob-tab" data-hp="hub-library" type="button"><span class="material-symbols-outlined">local_library</span>Library</button>
      <button class="umat-mob-tab" data-hp="hub-sessions" type="button"><span class="material-symbols-outlined">history</span>Sessions</button>
    </div>

    <!-- CONTENT -->
    <div class="umat-ov-content">

      <!-- HOME -->
      <div class="umat-tab-pane active" id="hub-home">
        <div class="umat-home-wrap">
          <div class="umat-home-hero">
            <h1>Welcome back, {$uInit}! 👋</h1>
            <p>Your cross-course AI learning companion — ask anything, anytime.</p>
            <div class="hero-sub" id="hub-home-date"></div>
          </div>
          <div class="umat-metrics-row">
            <div class="umat-metric-card"><div class="umat-metric-icon mi-g"><span class="material-symbols-outlined">forum</span></div><div><div class="umat-metric-val" id="hub-met-sess">—</div><div class="umat-metric-lbl">Sessions this week</div></div></div>
            <div class="umat-metric-card"><div class="umat-metric-icon mi-s"><span class="material-symbols-outlined">help</span></div><div><div class="umat-metric-val" id="hub-met-q">—</div><div class="umat-metric-lbl">Questions asked</div></div></div>
            <div class="umat-metric-card"><div class="umat-metric-icon mi-w"><span class="material-symbols-outlined">bolt</span></div><div><div class="umat-metric-val" id="hub-met-goal">—%</div><div class="umat-metric-lbl">Weekly goal</div></div></div>
          </div>
          <div class="umat-goal-bar-wrap">
            <div class="umat-goal-bar-row"><span>Weekly Study Goal</span><strong id="hub-goal-pct">0%</strong></div>
            <div class="umat-goal-bar"><div class="umat-goal-fill" id="hub-goal-fill" style="width:0%"></div></div>
          </div>
          <div class="umat-home-section" id="hub-pulse-section" style="margin-top:20px;">
            <h3>Learning Pulse — Most Active Topics</h3>
            <div id="hub-pulse-tags" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
          </div>
          <div class="umat-home-section" style="margin-top:20px;">
            <h3>Quick Actions</h3>
            <div class="umat-quick-actions-grid">
              <button class="umat-qa-btn" data-hp="hub-tutor" type="button"><span class="material-symbols-outlined">smart_toy</span><div class="umat-qa-btn-text"><strong>Ask AI Tutor</strong><span>Get instant help across all courses</span></div></button>
              <button class="umat-qa-btn" data-hp="hub-lectures" type="button"><span class="material-symbols-outlined">video_library</span><div class="umat-qa-btn-text"><strong>Watch Lectures</strong><span>Recordings with AI search</span></div></button>
              <button class="umat-qa-btn" data-hp="hub-courses" type="button"><span class="material-symbols-outlined">menu_book</span><div class="umat-qa-btn-text"><strong>My Courses</strong><span>Jump into a specific course</span></div></button>
              <button class="umat-qa-btn" data-hp="hub-sessions" type="button"><span class="material-symbols-outlined">history</span><div class="umat-qa-btn-text"><strong>Past Sessions</strong><span>Resume previous conversations</span></div></button>
            </div>
          </div>
          <div class="umat-home-section" id="hub-recent-section" style="margin-top:20px;display:none;">
            <h3>Recent Session Logs</h3>
            <div id="hub-recent-tiles" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;"></div>
          </div>
        </div>
      </div>

      <!-- AI TUTOR -->
      <div class="umat-tab-pane" id="hub-tutor" style="position:relative;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">smart_toy</span> General AI Tutor</h2>
          <select id="hub-course-sel" style="padding:6px 11px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);max-width:min(200px,45vw);">
            <option value="0">All Courses</option>
          </select>
        </div>
        <div class="umat-msgs" id="hub-msgs">
          <div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div>
            <div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div>
              <div class="umat-bubble-ai"><p>Hello! I'm your cross-course AI tutor. Ask me anything about your engineering studies or campus inquiries. Select a course above to get course-specific answers! 🎓</p></div>
              <div class="umat-chips-row">
                <button class="umat-chip" data-q="What are the main differences between open-pit and underground mining?" type="button">Mining methods</button>
                <button class="umat-chip" data-q="Explain the Mohr-Coulomb failure criterion." type="button">Rock mechanics</button>
                <button class="umat-chip" data-q="How does electrical impedance affect circuit design?" type="button">Circuit theory</button>
              </div>
            </div>
          </div>
        </div>
        <div class="umat-input-area" style="position:relative;">
          <div class="umat-attach-drawer" id="hub-attach-drawer">
            <div class="umat-drawer-hdr">
              <h4><span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;color:var(--u-p);">attach_file</span> Reference Materials</h4>
              <button class="umat-drawer-hdr-close" id="hub-drawer-close" type="button"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div class="umat-drawer-search"><input type="text" id="hub-drawer-search" placeholder="Search materials…"></div>
            <div class="umat-drawer-list" id="hub-drawer-list"><div style="text-align:center;padding:20px;color:var(--u-ol);font-size:13px;">Select a course first to load materials.</div></div>
            <div class="umat-drawer-foot">
              <span id="hub-drawer-count" style="font-size:12px;color:var(--u-ol);">0 selected</span>
              <button class="umat-drawer-confirm" id="hub-drawer-confirm" type="button">Reference Selected</button>
            </div>
          </div>
          <div class="umat-input-row">
            <textarea id="hub-input" class="umat-textarea" placeholder="Ask anything about your courses…" rows="2" maxlength="900"></textarea>
            <button class="umat-send-btn" id="hub-send" type="button"><span class="material-symbols-outlined">send</span></button>
          </div>
          <div class="umat-mat-bar" id="hub-mat-bar"></div>
          <div class="umat-input-actions">
            <button class="umat-ia-btn" id="hub-attach-btn" type="button"><span class="material-symbols-outlined">attach_file</span>Reference Material</button>
            <button class="umat-ia-btn" id="hub-mic-btn" type="button"><span class="material-symbols-outlined">mic</span>Voice</button>
            <span class="umat-ia-btn" id="hub-rate" style="cursor:default;">10 Q/min</span>
          </div>
        </div>
      </div>

      <!-- LECTURES -->
      <div class="umat-tab-pane" id="hub-lectures" style="position:relative;overflow:hidden;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">video_library</span> Lecture Recordings</h2>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <select id="hub-lec-course-sel" style="padding:5px 10px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);max-width:min(170px,40vw);">
              <option value="0">All Courses</option>
            </select>
            <input type="text" id="hub-lec-search" placeholder="Search…" style="padding:6px 12px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);width:min(140px,35vw);">
          </div>
        </div>
        <div class="umat-video-grid" id="hub-lec-grid">
          <div class="umat-empty"><span class="material-symbols-outlined">video_library</span><p>Select a course and load recordings.</p></div>
        </div>
        <!-- Player -->
        <div class="umat-player-panel" id="hub-player">
          <div class="umat-player-top">
            <button class="umat-player-back" id="hub-player-back" type="button"><span class="material-symbols-outlined">arrow_back</span>All Lectures</button>
            <div class="umat-player-title" id="hub-player-title">Lecture Recording</div>
            <a class="umat-player-dl-btn" id="hub-player-dl" href="#" download><span class="material-symbols-outlined">download</span>Download</a>
          </div>
          <div class="umat-player-body">
            <div class="umat-player-left">
              <div class="umat-player-video-wrap" id="hub-vwrap"></div>
              <div class="umat-player-transcript">
                <div class="umat-ts-hdr"><h4><span class="material-symbols-outlined">subtitles</span>Synchronized Transcript</h4>
                  <div class="umat-ts-srch"><span class="material-symbols-outlined">search</span><input type="text" id="hub-ts-srch" placeholder="Search…"></div></div>
                <div class="umat-ts-body" id="hub-ts-body"></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- MY COURSES -->
      <div class="umat-tab-pane" id="hub-courses">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">menu_book</span> My Courses</h2>
          <input type="text" id="hub-courses-search" placeholder="Filter courses…" style="padding:6px 12px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);width:min(160px,40vw);">
        </div>
        <div class="umat-courses-grid" id="hub-courses-grid">
          <div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading enrolled courses…</p></div>
        </div>
      </div>

      <!-- LIBRARY -->
      <div class="umat-tab-pane" id="hub-library" style="position:relative;overflow:hidden;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">local_library</span> Library</h2>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <select id="hub-lib-course-sel" style="padding:5px 10px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);max-width:min(160px,40vw);">
              <option value="0">All Courses</option>
            </select>
            <input type="text" id="hub-lib-search" placeholder="Search materials…" style="padding:6px 12px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);width:min(140px,35vw);">
          </div>
        </div>
        <div class="umat-lib-grid" id="hub-lib-grid">
          <div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">folder_open</span><p>Select a course to browse its library.</p></div>
        </div>
        <div class="umat-pdf-viewer-wrap" id="hub-pdf-viewer">
          <div class="umat-pdf-viewer-bar">
            <button class="umat-pdf-viewer-back" id="hub-pdf-back" type="button"><span class="material-symbols-outlined">arrow_back</span>Library</button>
            <h4 id="hub-pdf-title">Document</h4>
            <a class="umat-player-dl-btn" id="hub-pdf-dl" href="#" download><span class="material-symbols-outlined">download</span>Download</a>
          </div>
          <iframe id="hub-pdf-iframe" class="umat-pdf-iframe" src="" title="Document Viewer"></iframe>
        </div>
      </div>

      <!-- SESSIONS -->
      <div class="umat-tab-pane" id="hub-sessions">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">history</span> AI Chat Sessions</h2>
          <button class="umat-content-hdr-btn" id="hub-new-sess2" type="button"><span class="material-symbols-outlined">add</span>New Session</button>
        </div>
        <div class="umat-sessions-list" id="hub-sess-list">
          <div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading your sessions…</p></div>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /ov-body -->
</div><!-- /hub-ov -->

<script>
/* ============================================================
   HUB OVERLAY IIFE
   ============================================================ */
(function(){
'use strict';

var UD      = {$jsUD} || {};
var UID     = {$uid};
var sessKey = 'hub_'+Math.random().toString(36).substr(2,18);
var qLeft   = 10;
var selMat  = [];
var matLoaded = false;
var loaded  = {};
var activeCID = 0;

function esc(s){var d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML;}
function fmtT(s){var m=Math.floor(s/60),sc=Math.floor(s%60);return m+':'+(sc<10?'0':'')+sc;}
function fmtSz(b){if(!b)return '—';if(b<1048576)return (b/1024).toFixed(0)+'KB';return (b/1048576).toFixed(1)+'MB';}
function timeAgo(ts){if(!ts)return '';var d=new Date(ts*1000),n=new Date(),s=Math.floor((n-d)/1000);if(s<60)return 'just now';var m=Math.floor(s/60);if(m<60)return m+'m ago';var h=Math.floor(m/60);if(h<24)return h+'h ago';var D=Math.floor(h/24);if(D<30)return D+'d ago';var M=Math.floor(D/30);if(M<12)return M+'mo ago';var Y=Math.floor(M/12);return Y+'y ago';}
function libTileClass(m){if(!m)return 'lt-other';if(m.includes('pdf'))return 'lt-pdf';if(m.includes('video'))return 'lt-video';if(m.includes('image'))return 'lt-img';if(m.includes('word')||m.includes('document'))return 'lt-doc';return 'lt-other';}
function fileTypeIcon(m){if(!m)return 'description';if(m.includes('pdf'))return 'picture_as_pdf';if(m.includes('video'))return 'videocam';if(m.includes('image'))return 'image';return 'description';}
function ajax(method,args,done,fail){require(['core/ajax'],function(A){A.call([{methodname:method,args:args}])[0].done(done).fail(fail||function(){});});}

/* FAB / overlay toggle */
var fab=document.getElementById('hub-fab');
var ov=document.getElementById('hub-ov');
var ovClose=document.getElementById('hub-ov-close');
var newBtn=document.getElementById('hub-new-sess');
var newBtn2=document.getElementById('hub-new-sess2');

fab.addEventListener('click',function(){ov.classList.add('open');initHome();});
ovClose.addEventListener('click',function(){ov.classList.remove('open');});
ov.addEventListener('click',function(e){if(e.target===ov)ov.classList.remove('open');});

/* Pane switching */
function switchPane(name){
  document.querySelectorAll('#hub-ov .umat-tab-pane').forEach(function(p){p.classList.remove('active');});
  document.querySelectorAll('#hub-sb [data-hp], #hub-mob-tabs [data-hp]').forEach(function(b){b.classList.toggle('active',b.dataset.hp===name);});
  var pane=document.getElementById(name);if(pane)pane.classList.add('active');
  if(!loaded[name]){loaded[name]=true;loadPane(name);}
}
document.querySelectorAll('#hub-sb [data-hp], #hub-mob-tabs [data-hp]').forEach(function(b){
  b.addEventListener('click',function(){switchPane(b.dataset.hp);});
});
document.addEventListener('click',function(e){
  var btn=e.target.closest('[data-hp]');
  if(btn&&btn.closest('#hub-home')){switchPane(btn.dataset.hp);}
});

function loadPane(name){
  if(name==='hub-courses')loadCourses();
  if(name==='hub-sessions')loadSessions();
  if(name==='hub-lectures')populateLecCourseSel();
  if(name==='hub-library')populateLibCourseSel();
}

/* Home */
function initHome(){
  var dEl=document.getElementById('hub-home-date');
  if(dEl)dEl.textContent=(new Date()).toLocaleDateString('en-GB',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
  if(UD.week_sessions!==undefined){
    var ms=document.getElementById('hub-met-sess');var mq=document.getElementById('hub-met-q');
    var mg=document.getElementById('hub-met-goal');var gp=document.getElementById('hub-goal-pct');
    var gf=document.getElementById('hub-goal-fill');
    if(ms)ms.textContent=UD.week_sessions;if(mq)mq.textContent=UD.week_questions;
    var gv=UD.goal_progress||0;
    if(mg)mg.textContent=gv+'%';if(gp)gp.textContent=gv+'%';
    if(gf)setTimeout(function(){gf.style.width=gv+'%';},300);
  }
  /* Pulse topics */
  if(UD.pulse_topics&&UD.pulse_topics.length){
    var tags=document.getElementById('hub-pulse-tags');
    if(tags)tags.innerHTML=UD.pulse_topics.map(function(t){
      return '<span style="padding:5px 13px;border-radius:999px;background:var(--u-secc);color:var(--u-sec);font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px;"><span class="material-symbols-outlined" style="font-size:13px;">school</span>'+esc(t.label)+'</span>';
    }).join('');
  }
  /* Recent sessions */
  if(UD.sessions&&UD.sessions.length){
    var rs=document.getElementById('hub-recent-section');var rt=document.getElementById('hub-recent-tiles');
    if(rs&&rt){rs.style.display='block';
      rt.innerHTML=UD.sessions.slice(0,6).map(function(s){
        return '<div class="umat-session-tile" data-sk="'+esc(s.session_key)+'" data-cid="'+s.courseid+'" data-cn="'+esc(s.course_name)+'">'+
          '<div class="umat-session-tile-hdr"><span class="umat-session-badge">'+esc(s.course_short||'GEN')+'</span><span class="umat-session-time">'+esc(s.time_label)+'</span></div>'+
          '<h4>'+esc(s.course_name)+' Session</h4><p>'+esc(s.preview)+'</p>'+
          '<div class="umat-session-tile-foot"><div class="umat-session-meta"><span class="material-symbols-outlined">chat</span>'+s.msg_count+' messages</div>'+
          '<button class="umat-resume-btn" type="button">Resume →</button></div></div>';
      }).join('');
      rt.querySelectorAll('.umat-session-tile').forEach(function(t){
        t.addEventListener('click',function(){resumeSession(t.dataset.sk,parseInt(t.dataset.cid)||0,t.dataset.cn||'');});
      });
    }
  }
  /* Populate course selects */
  if(UD.courses&&UD.courses.length){
    var sels=['hub-course-sel','hub-lec-course-sel','hub-lib-course-sel'];
    sels.forEach(function(sid){
      var sel=document.getElementById(sid);if(!sel)return;
      sel.innerHTML='<option value="0">All Courses</option>'+
        UD.courses.map(function(c){return '<option value="'+c.id+'">'+esc(c.shortname)+' — '+esc(c.fullname.substring(0,40))+'</option>';}).join('');
    });
  }
}

/* Courses */
function loadCourses(){
  var g=document.getElementById('hub-courses-grid');
  if(UD.courses&&UD.courses.length){renderCourseTiles(UD.courses,g);return;}
  ajax('local_umat_ai_get_my_courses',{},function(r){renderCourseTiles(r.courses||[],g);},function(){g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load courses.</p></div>';});
}
function renderCourseTiles(courses,g){
  if(!courses.length){g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">school</span><p>No enrolled courses found.</p></div>';return;}
  g.innerHTML=courses.map(function(c){
    return '<div class="umat-course-tile" data-cid="'+c.id+'" data-cname="'+esc(c.fullname)+'">'+
      '<div class="umat-course-tile-icon"><span class="material-symbols-outlined">menu_book</span></div>'+
      '<div class="umat-course-tile-info"><h4>'+esc(c.fullname)+'</h4><span>'+esc(c.shortname)+'</span></div>'+
      '<div class="umat-course-tile-arrow"><span class="material-symbols-outlined">arrow_forward_ios</span></div></div>';
  }).join('');
  g.querySelectorAll('.umat-course-tile').forEach(function(t){
    t.addEventListener('click',function(){
      activeCID=parseInt(t.dataset.cid)||0;
      var cs=document.getElementById('hub-course-sel');if(cs)cs.value=activeCID;
      switchPane('hub-tutor');
    });
  });
  var srch=document.getElementById('hub-courses-search');
  if(srch)srch.addEventListener('input',function(){var q=this.value.toLowerCase();g.querySelectorAll('.umat-course-tile').forEach(function(t){t.style.display=(!q||t.textContent.toLowerCase().includes(q))?'':'none';});});
}

/* Sessions */
function loadSessions(){
  var list=document.getElementById('hub-sess-list');
  if(UD.sessions&&UD.sessions.length){renderSessionTiles(UD.sessions,list);return;}
  ajax('local_umat_ai_get_ai_sessions',{courseid:0,limit:20},function(r){renderSessionTiles(r.sessions||[],list);},function(){list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load sessions.</p></div>';});
}
function renderSessionTiles(sessions,container){
  if(!sessions.length){container.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">history</span><p>No past sessions yet. Start a conversation in AI Tutor!</p></div>';return;}
  container.innerHTML=sessions.map(function(s){
    return '<div class="umat-session-tile" data-sk="'+esc(s.session_key)+'" data-cid="'+s.courseid+'" data-cn="'+esc(s.course_name)+'">'+
      '<div class="umat-session-tile-hdr"><span class="umat-session-badge">'+esc(s.course_short||'GEN')+'</span><span class="umat-session-time">'+esc(s.time_label)+'</span></div>'+
      '<h4>'+esc(s.course_name||'General')+'</h4><p>'+esc(s.preview)+'</p>'+
      '<div class="umat-session-tile-foot"><div class="umat-session-meta"><span class="material-symbols-outlined">chat</span>'+s.msg_count+' messages</div>'+
      '<button class="umat-resume-btn" type="button">Resume →</button></div></div>';
  }).join('');
  container.querySelectorAll('.umat-session-tile').forEach(function(t){
    t.addEventListener('click',function(){resumeSession(t.dataset.sk,parseInt(t.dataset.cid)||0,t.dataset.cn||'');});
  });
}
function resumeSession(sk,cid,cname){
  sessKey=sk;activeCID=cid||0;
  var cs=document.getElementById('hub-course-sel');if(cs&&cid)cs.value=cid;
  switchPane('hub-tutor');
  ajax('local_umat_ai_get_chat_history',{courseid:cid||1,session_key:sk,limit:50},
    function(r){
      var msgs=document.getElementById('hub-msgs');if(!msgs)return;
      msgs.innerHTML='';
      addWelcome(cname||'your course');
      (r.messages||[]).forEach(function(m){appendMsg(m.question,true,msgs);if(m.answer)appendMsg(m.answer,false,msgs,m.sources||[]);});
    },function(){}
  );
}
function addWelcome(cname){
  var c=document.getElementById('hub-msgs');if(!c)return;
  var d=document.createElement('div');d.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>Session resumed for <strong>'+esc(cname)+'</strong>. Continue your conversation below.</p></div></div></div>';
  c.appendChild(d);c.scrollTop=c.scrollHeight;
}

/* Lectures */
function populateLecCourseSel(){
  var sel=document.getElementById('hub-lec-course-sel');
  if(sel&&UD.courses){
    sel.innerHTML='<option value="0">All Courses</option>'+
      UD.courses.map(function(c){return '<option value="'+c.id+'">'+esc(c.shortname)+'</option>';}).join('');
    sel.addEventListener('change',function(){if(this.value!=='0')loadLectures(parseInt(this.value));});
  }
}
function loadLectures(cid){
  var g=document.getElementById('hub-lec-grid');
  g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading recordings…</p></div>';
  ajax('local_umat_ai_get_course_recordings',{courseid:cid||0},function(r){
    var recs=r.recordings||[];
    if(!recs.length){g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">video_library</span><p>No recordings available for this course yet.</p></div>';return;}
    g.innerHTML=recs.map(function(rec){
      return '<div class="umat-video-tile" data-url="'+esc(rec.url)+'" data-title="'+esc(rec.title)+'" data-segments="'+esc(JSON.stringify(rec.segments||[]))+'" data-duration="'+esc(rec.duration||'')+'">'+
        '<div class="umat-video-thumb"><span class="material-symbols-outlined umat-vid-play-icon">play_circle</span>'+
        (rec.duration?'<span class="umat-duration-badge">'+esc(rec.duration)+'</span>':'')+
        '</div><div class="umat-video-tile-info"><h4 title="'+esc(rec.title)+'">'+esc(rec.title)+'</h4>'+
        '<span class="umat-vid-time">'+esc(rec.time_ago||'')+'</span>'+
        '<a class="umat-video-tile-dl" href="'+esc(rec.url)+'" download title="Download" onclick="event.stopPropagation();"><span class="material-symbols-outlined">download</span></a>'+
        '</div></div>';
    }).join('');
    g.querySelectorAll('.umat-video-tile').forEach(function(t){
      t.addEventListener('click',function(){
        var segs=[];try{segs=JSON.parse(t.dataset.segments||'[]');}catch(e){}
        openHubPlayer(t.dataset.url,t.dataset.title,segs);
      });
    });
  },function(){g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load recordings.</p></div>';});
  var srch=document.getElementById('hub-lec-search');
  if(srch)srch.addEventListener('input',function(){var q=this.value.toLowerCase();g.querySelectorAll('.umat-video-tile').forEach(function(t){t.style.display=(!q||t.textContent.toLowerCase().includes(q))?'':'none';});});
}
function openHubPlayer(url,title,segments){
  var panel=document.getElementById('hub-player');var vwrap=document.getElementById('hub-vwrap');
  var titleEl=document.getElementById('hub-player-title');var dlBtn=document.getElementById('hub-player-dl');
  var tsBody=document.getElementById('hub-ts-body');
  if(!panel)return;
  panel.classList.add('open');
  if(titleEl)titleEl.textContent=title;
  if(dlBtn){dlBtn.href=url;dlBtn.setAttribute('download',title.replace(/[^a-z0-9]/gi,'_')+'.mp4');}
  vwrap.innerHTML='';
  var vid=document.createElement('video');vid.preload='metadata';vid.style.cssText='width:100%;display:block;max-height:55vh;object-fit:contain;';
  var src=document.createElement('source');src.src=url;src.type='video/mp4';vid.appendChild(src);
  var vc=document.createElement('div');vc.className='umat-vc';
  vc.innerHTML='<button class="umat-vc-btn" id="h-vc-pp"><span class="material-symbols-outlined">play_arrow</span></button>'+
    '<button class="umat-vc-btn" id="h-vc-r30"><span class="material-symbols-outlined">replay_30</span></button>'+
    '<button class="umat-vc-btn" id="h-vc-f30"><span class="material-symbols-outlined">forward_30</span></button>'+
    '<span class="umat-vc-time"><span id="h-vc-cur">0:00</span> / <span id="h-vc-dur">0:00</span></span>'+
    '<input type="range" id="h-vc-prog" class="umat-vc-progress" min="0" max="100" value="0">';
  vwrap.appendChild(vid);vwrap.appendChild(vc);
  vid.addEventListener('loadedmetadata',function(){var d=document.getElementById('h-vc-dur');var p=document.getElementById('h-vc-prog');if(d)d.textContent=fmtT(vid.duration);if(p)p.max=Math.floor(vid.duration);});
  vid.addEventListener('timeupdate',function(){var c=document.getElementById('h-vc-cur');var p=document.getElementById('h-vc-prog');if(c)c.textContent=fmtT(vid.currentTime);if(p)p.value=Math.floor(vid.currentTime);
    tsBody.querySelectorAll('.umat-ts-seg').forEach(function(s){var a=parseFloat(s.dataset.start||0),b=parseFloat(s.dataset.end||0);s.classList.toggle('active',vid.currentTime>=a&&vid.currentTime<=b);if(vid.currentTime>=a&&vid.currentTime<=b)s.scrollIntoView({behavior:'smooth',block:'nearest'});});
  });
  var ppBtn=document.getElementById('h-vc-pp');if(ppBtn)ppBtn.addEventListener('click',function(){if(vid.paused){vid.play();ppBtn.querySelector('.material-symbols-outlined').textContent='pause';}else{vid.pause();ppBtn.querySelector('.material-symbols-outlined').textContent='play_arrow';}});
  var r30=document.getElementById('h-vc-r30');if(r30)r30.addEventListener('click',function(){vid.currentTime=Math.max(0,vid.currentTime-30);});
  var f30=document.getElementById('h-vc-f30');if(f30)f30.addEventListener('click',function(){vid.currentTime=Math.min(vid.duration||0,vid.currentTime+30);});
  var prog=document.getElementById('h-vc-prog');if(prog)prog.addEventListener('input',function(){vid.currentTime=parseInt(this.value);});
  if(segments&&segments.length){
    tsBody.innerHTML=segments.map(function(s){return '<div class="umat-ts-seg" data-start="'+s.start+'" data-end="'+s.end+'"><span class="umat-ts-time">'+(s.timestamp||fmtT(s.start||0))+'</span><p class="umat-ts-text">'+esc(s.text)+'</p></div>';}).join('');
    tsBody.querySelectorAll('.umat-ts-seg').forEach(function(seg){seg.addEventListener('click',function(){if(vid){vid.currentTime=parseFloat(seg.dataset.start||0);vid.play();}});});
  } else tsBody.innerHTML='<div class="umat-empty" style="padding:24px;"><span class="material-symbols-outlined" style="font-size:36px;">article</span><p>Transcript not available.</p></div>';
  var tsSrch=document.getElementById('hub-ts-srch');if(tsSrch)tsSrch.addEventListener('input',function(){var q=this.value.toLowerCase();tsBody.querySelectorAll('.umat-ts-seg').forEach(function(s){s.style.display=(!q||s.querySelector('.umat-ts-text').textContent.toLowerCase().includes(q))?'':'none';});});
}
document.getElementById('hub-player-back').addEventListener('click',function(){var panel=document.getElementById('hub-player');if(panel)panel.classList.remove('open');var vid=panel&&panel.querySelector('video');if(vid)vid.pause();});

/* Library */
function populateLibCourseSel(){
  var sel=document.getElementById('hub-lib-course-sel');
  if(sel&&UD.courses){
    sel.innerHTML='<option value="0">All Courses</option>'+
      UD.courses.map(function(c){return '<option value="'+c.id+'">'+esc(c.shortname)+'</option>';}).join('');
    sel.addEventListener('change',function(){if(this.value!=='0')loadLibrary(parseInt(this.value));});
  }
}
function loadLibrary(cid){
  var g=document.getElementById('hub-lib-grid');
  g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading materials…</p></div>';
  ajax('local_umat_ai_get_course_materials',{courseid:cid||0},function(r){
    var mats=r.materials||[];
    if(!mats.length){g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">folder_open</span><p>No materials found for this course.</p></div>';return;}
    g.innerHTML=mats.map(function(m){
      var tc=libTileClass(m.mimetype),ic=fileTypeIcon(m.mimetype),ext=(m.mimetype||'').split('/').pop().toUpperCase();
      return '<div class="umat-lib-tile" data-url="'+esc(m.url)+'" data-name="'+esc(m.filename)+'" data-mime="'+esc(m.mimetype)+'">'+
        '<div class="umat-lib-tile-icon '+tc+'"><span class="material-symbols-outlined">'+ic+'</span></div>'+
        '<div class="umat-lib-tile-info"><strong title="'+esc(m.filename)+'">'+esc(m.filename)+'</strong>'+
        '<span class="umat-lib-meta">'+ext+' · '+fmtSz(m.filesize||0)+'</span>'+
        '<span class="umat-lib-time">'+esc(m.time_ago||'')+'</span>'+
        '</div>'+
        '<div class="umat-lib-tile-actions"><button class="umat-lib-btn" data-action="view" type="button"><span class="material-symbols-outlined">visibility</span>View</button>'+
        '<a class="umat-lib-btn" href="'+esc(m.url)+'" download="'+esc(m.filename)+'"><span class="material-symbols-outlined">download</span>Download</a></div></div>';
    }).join('');
    g.querySelectorAll('[data-action="view"]').forEach(function(btn){
      btn.addEventListener('click',function(){var t=btn.closest('.umat-lib-tile');openHubPdf(t.dataset.url,t.dataset.name);});
    });
    var srch=document.getElementById('hub-lib-search');if(srch)srch.addEventListener('input',function(){var q=this.value.toLowerCase();g.querySelectorAll('.umat-lib-tile').forEach(function(t){t.style.display=(!q||t.textContent.toLowerCase().includes(q))?'':'none';});});
  },function(){g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">error_outline</span><p>Could not load materials.</p></div>';});
}
function openHubPdf(url,name){
  var v=document.getElementById('hub-pdf-viewer');var ti=document.getElementById('hub-pdf-title');var dl=document.getElementById('hub-pdf-dl');var fr=document.getElementById('hub-pdf-iframe');
  if(!v)return;if(ti)ti.textContent=name;if(dl){dl.href=url;dl.setAttribute('download',name);}if(fr)fr.src=url;v.classList.add('open');
}
document.getElementById('hub-pdf-back').addEventListener('click',function(){var v=document.getElementById('hub-pdf-viewer');if(v)v.classList.remove('open');var fr=document.getElementById('hub-pdf-iframe');if(fr)fr.src='';});

/* Chat */
function updateRate(){var e=document.getElementById('hub-rate');if(e){e.textContent=qLeft+' Q/min';e.style.color=qLeft<=2?'var(--u-ter)':'';}}
function appendMsg(text,isUser,container,sources){
  var d=document.createElement('div');
  if(isUser)d.innerHTML='<div class="umat-msg-user"><div class="umat-bubble-user"><p>'+esc(text)+'</p></div></div>';
  else{var srcs='';if(sources&&sources.length)srcs='<div class="umat-src-chips">'+sources.map(function(s){return '<span class="umat-src-chip">'+esc(s)+'</span>';}).join('')+'</div>';
    d.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>'+esc(text)+'</p>'+srcs+'</div></div></div>';}
  container.appendChild(d);container.scrollTop=container.scrollHeight;
}
function sendQ(q){
  q=(q||'').trim();if(!q)return;
  if(qLeft<=0){appendMsg('Rate limit reached.',false,document.getElementById('hub-msgs'),[]);return;}
  qLeft--;updateRate();
  var ctx=selMat.length>0?'[Referencing: '+selMat.map(function(m){return m.name;}).join(', ')+'] '+q:q;
  var cid=parseInt(document.getElementById('hub-course-sel').value)||activeCID||1;
  var msgs=document.getElementById('hub-msgs');
  appendMsg(q,true,msgs);document.getElementById('hub-input').value='';
  var tid='h_'+Date.now();
  var t=document.createElement('div');t.id=tid;t.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><div class="umat-typing"><span></span><span></span><span></span></div></div></div></div>';
  msgs.appendChild(t);msgs.scrollTop=msgs.scrollHeight;
  ajax('local_umat_ai_ask_question',{courseid:cid,question:ctx,session_key:sessKey},
    function(r){var e=document.getElementById(tid);if(e)e.parentNode.removeChild(e);appendMsg(r.success?r.answer:'Error. Please try again.',false,msgs,r.sources||[]);},
    function(){var e=document.getElementById(tid);if(e)e.parentNode.removeChild(e);appendMsg('Connection error.',false,msgs,[]);}
  );
}
var hubIn=document.getElementById('hub-input');var hubSend=document.getElementById('hub-send');
hubSend.addEventListener('click',function(){sendQ(hubIn.value);});
hubIn.addEventListener('keypress',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();hubSend.click();}});
document.getElementById('hub-msgs').addEventListener('click',function(e){var chip=e.target.closest('.umat-chip[data-q]');if(chip){hubIn.value=chip.dataset.q;hubSend.click();}});

/* Attachment */
document.getElementById('hub-attach-btn').addEventListener('click',function(){
  var d=document.getElementById('hub-attach-drawer');d.classList.toggle('open');
  if(d.classList.contains('open')&&!matLoaded){matLoaded=true;
    var cid=parseInt(document.getElementById('hub-course-sel').value)||0;
    if(!cid){document.getElementById('hub-drawer-list').innerHTML='<div style="padding:16px;text-align:center;color:var(--u-ol);font-size:13px;">Please select a course first.</div>';return;}
    ajax('local_umat_ai_get_course_materials',{courseid:cid},function(r){
      var list=document.getElementById('hub-drawer-list');var mats=r.materials||[];
      if(!mats.length){list.innerHTML='<div style="padding:16px;text-align:center;color:var(--u-ol);font-size:13px;">No materials found.</div>';return;}
      list.innerHTML=mats.map(function(m){return '<label class="umat-drawer-item"><input type="checkbox" value="'+m.id+'" data-name="'+esc(m.filename)+'" data-url="'+esc(m.url)+'"><div class="umat-drawer-item-icon di-doc"><span class="material-symbols-outlined" style="font-size:16px;">description</span></div><div class="umat-drawer-item-info"><strong>'+esc(m.filename)+'</strong><span>'+((m.filesize||0)/1024).toFixed(0)+'KB</span></div></label>';}).join('');
      list.querySelectorAll('input[type=checkbox]').forEach(function(cb){cb.addEventListener('change',function(){selMat=[];list.querySelectorAll('input:checked').forEach(function(c){selMat.push({id:c.value,name:c.dataset.name});});var cnt=document.getElementById('hub-drawer-count');if(cnt)cnt.textContent=selMat.length+' selected';});});
    },function(){});
  }
});
document.getElementById('hub-drawer-close').addEventListener('click',function(){document.getElementById('hub-attach-drawer').classList.remove('open');});
document.getElementById('hub-drawer-confirm').addEventListener('click',function(){
  document.getElementById('hub-attach-drawer').classList.remove('open');
  _umatRenderMatsBar('hub-mat-bar','hub-attach-btn',selMat,function(id){selMat=selMat.filter(function(s){return s.id!=id;});return selMat;});
});

/* Voice */
(function(){
  var SR=window.SpeechRecognition||window.webkitSpeechRecognition;
  var micBtn=document.getElementById('hub-mic-btn');if(!SR||!micBtn){if(micBtn)micBtn.style.opacity='.4';return;}
  var rec=new SR();rec.continuous=false;rec.interimResults=true;rec.lang='en-US';
  var active=false;
  micBtn.addEventListener('click',function(){if(active){rec.stop();}else{rec.start();active=true;micBtn.classList.add('recording');}});
  rec.onresult=function(e){hubIn.value=Array.from(e.results).map(function(r){return r[0].transcript;}).join('');};
  rec.onend=function(){active=false;micBtn.classList.remove('recording');};
  rec.onerror=function(){active=false;micBtn.classList.remove('recording');};
})();

/* New session */
function newSession(){sessKey='hub_'+Math.random().toString(36).substr(2,18);selMat=[];var msgs=document.getElementById('hub-msgs');if(msgs){msgs.innerHTML='';addWelcome('your courses');}qLeft=10;updateRate();}
if(newBtn)newBtn.addEventListener('click',newSession);
if(newBtn2)newBtn2.addEventListener('click',function(){newSession();switchPane('hub-tutor');});

/* ESC: close nested-first, root-last */
function _umatInitEsc(layers){
  document.addEventListener('keydown',function(e){
    if(e.key!=='Escape')return;
    for(var i=0;i<layers.length;i++){
      var el=document.getElementById(layers[i].id);
      if(el&&layers[i].isOpen(el)){layers[i].close(el);e.preventDefault();return;}
    }
  });
}
_umatInitEsc([
  {id:'hub-attach-drawer',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');}},
  {id:'hub-ov',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');}}
]);

})();
</script>
HTML;
    }

    // ================================================================== //
    // SIDEBAR HTML — shared reusable sidebar for student workspace        //
    // ================================================================== //

    private static function sidebar_html(array $tabs, string $newBtnLabel, string $closeId): string {
        global $CFG;
        $wwwroot = rtrim($CFG->wwwroot, '/');
        $logUrl  = $wwwroot . '/login/logout.php';
        $tabHtml = '';
        foreach ($tabs as $t) {
            $active = !empty($t['active']) ? ' active' : '';
            $tabHtml .= '<button class="umat-sb-item' . $active . '" data-sb-tab="'
                . htmlspecialchars($t['id'], ENT_QUOTES) . '" type="button">'
                . '<span class="material-symbols-outlined">' . htmlspecialchars($t['icon'], ENT_QUOTES) . '</span>'
                . '<span class="umat-sb-item-lbl">' . htmlspecialchars($t['label'], ENT_QUOTES) . '</span></button>';
        }
        $safeLabel = htmlspecialchars($newBtnLabel, ENT_QUOTES);
        return <<<HTML
<div class="umat-sb">
    <div class="umat-sb-head">
        <div class="umat-sb-logo"><span class="material-symbols-outlined">school</span></div>
        <div class="umat-sb-brand"><strong>UMaT Moodle</strong><span>AI Enhanced Learning</span></div>
        <button class="umat-sb-close-btn" id="{$closeId}" type="button" title="Close">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>
    <nav class="umat-sb-nav">{$tabHtml}</nav>
    <div class="umat-sb-divider"></div>
    <button class="umat-sb-new" id="sb-new-btn" type="button">
        <span class="material-symbols-outlined">add</span>
        <span class="umat-sb-new-lbl">{$safeLabel}</span>
    </button>
    <div class="umat-sb-foot">
        <button class="umat-sb-item" type="button" onclick="window.location.href='{$logUrl}'">
            <span class="material-symbols-outlined">logout</span><span class="umat-sb-item-lbl">Sign Out</span>
        </button>
    </div>
</div>
HTML;
    }

    // ================================================================== //
    // SHARED JS — utility functions injected once for all overlays        //
    // ================================================================== //

    private static function shared_js(string $overlayId, string $closeId): string {
        return <<<JS
<script>
/* ---- Shared AI overlay utility functions ---- */
function _umatEsc(s){var d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML;}
function _umatFmtT(s){var m=Math.floor(s/60),sc=Math.floor(s%60);return m+':'+(sc<10?'0':'')+sc;}
function _umatFmtSz(b){if(!b)return '\u2014';if(b<1048576)return (b/1024).toFixed(0)+'KB';return (b/1048576).toFixed(1)+'MB';}
function _umatTimeAgo(ts){if(!ts)return '';var d=new Date(ts*1000),n=new Date(),s=Math.floor((n-d)/1000);if(s<60)return 'just now';var m=Math.floor(s/60);if(m<60)return m+'m ago';var h=Math.floor(m/60);if(h<24)return h+'h ago';var D=Math.floor(h/24);if(D<30)return D+'d ago';var M=Math.floor(D/30);if(M<12)return M+'mo ago';var Y=Math.floor(M/12);return Y+'y ago';}
function _umatLibTileClass(m){if(!m)return 'lt-other';if(m.includes('pdf'))return 'lt-pdf';if(m.includes('video'))return 'lt-video';if(m.includes('image'))return 'lt-img';if(m.includes('word')||m.includes('document'))return 'lt-doc';return 'lt-other';}
function _umatFileTypeIcon(m){if(!m)return 'description';if(m.includes('pdf'))return 'picture_as_pdf';if(m.includes('video'))return 'videocam';if(m.includes('image'))return 'image';return 'description';}
function _umatAppendUser(cid,q){var c=document.getElementById(cid);if(!c)return;
  var d=document.createElement('div');d.innerHTML='<div class="umat-msg-user"><div class="umat-bubble-user"><p>'+_umatEsc(q)+'</p></div></div>';
  c.appendChild(d);c.scrollTop=c.scrollHeight;}
function _umatAppendAi(cid,t,s){var c=document.getElementById(cid);if(!c)return;
  var src='';if(s&&s.length)src='<div class="umat-src-chips">'+s.map(function(x){return '<span class="umat-src-chip">'+_umatEsc(x)+'</span>';}).join('')+'</div>';
  var d=document.createElement('div');d.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>'+_umatEsc(t)+'</p>'+src+'</div></div></div>';
  c.appendChild(d);c.scrollTop=c.scrollHeight;}
function _umatShowTyping(cid,tid){var c=document.getElementById(cid);if(!c)return;
  var d=document.createElement('div');d.id=tid;d.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><div class="umat-typing"><span></span><span></span><span></span></div></div></div></div>';
  c.appendChild(d);c.scrollTop=c.scrollHeight;}
function _umatHideTyping(tid){var e=document.getElementById(tid);if(e)e.parentNode.removeChild(e);}
function _umatInitVoice(inp,btn){
  var SR=window.SpeechRecognition||window.webkitSpeechRecognition;
  if(!SR||!btn){if(btn)btn.style.opacity='.4';return;}
  var rec=new SR();rec.continuous=false;rec.interimResults=true;rec.lang='en-US';
  var a=false;btn.addEventListener('click',function(){if(a){rec.stop();}else{rec.start();a=true;btn.classList.add('recording');}});
  rec.onresult=function(e){inp.value=Array.from(e.results).map(function(r){return r[0].transcript;}).join('');};
  rec.onend=function(){a=false;btn.classList.remove('recording');};
  rec.onerror=function(){a=false;btn.classList.remove('recording');};
}
function _umatRenderMatsBar(barId,btnId,mats,onRemove){
  var bar=document.getElementById(barId),btn=document.getElementById(btnId);
  if(!bar)return;
  bar.innerHTML=mats.length?mats.map(function(m){
    return '<span class="umat-mat-chip"><span class="umat-mat-chip-name">'+_umatEsc(m.name)+'</span><button class="umat-mat-chip-remove" data-id="'+m.id+'" type="button">&times;</button></span>';
  }).join(''):'';
  bar.querySelectorAll('.umat-mat-chip-remove').forEach(function(x){
    x.addEventListener('click',function(){
      var remaining=onRemove?onRemove(this.dataset.id):[];
      _umatRenderMatsBar(barId,btnId,remaining,onRemove);
      if(btn){
        btn.style.color=remaining.length?'var(--u-p)':'';
        btn.innerHTML=remaining.length?'<span class="material-symbols-outlined">attach_file</span>'+remaining.length+' ref':'<span class="material-symbols-outlined">attach_file</span>Ref Material';
      }
    });
  });
  if(!btn)return;
  btn.style.color=mats.length?'var(--u-p)':'';
  btn.innerHTML=mats.length?'<span class="material-symbols-outlined">attach_file</span>'+mats.length+' ref':'<span class="material-symbols-outlined">attach_file</span>Ref Material';
}
function _umatInitAttachDrawer(cfg){
  var d=document.getElementById(cfg.drawerId),ab=document.getElementById(cfg.attachBtnId);
  if(!ab||!d)return;var m=[];
  function closeDrawer(){d.classList.remove('open');}
  function loadMats(){
    var cid=typeof cfg.getCourseId==='function'?cfg.getCourseId():cfg.courseid;
    if(!cid)return;
    var l=document.getElementById(cfg.listId);if(!l)return;
    require(['core/ajax'],function(A){A.call([{methodname:'local_umat_ai_get_course_materials',args:{courseid:cid}}])[0]
      .done(function(r){var ms=r.materials||[];
        if(!ms.length){l.innerHTML='<div style="text-align:center;padding:20px;color:var(--u-ol);font-size:13px;">No materials for this course.</div>';return;}
        l.innerHTML=ms.map(function(x){return '<label class="umat-drawer-item"><input type="checkbox" value="'+x.id+'" data-name="'+_umatEsc(x.filename)+'"><div class="umat-drawer-item-icon di-doc"><span class="material-symbols-outlined" style="font-size:16px;">description</span></div><div class="umat-drawer-item-info"><strong>'+_umatEsc(x.filename)+'</strong><span>'+((x.filesize||0)/1024).toFixed(0)+'KB</span></div></label>';}).join('');
        l.querySelectorAll('input[type=checkbox]').forEach(function(cb){cb.addEventListener('change',function(){m=[];l.querySelectorAll('input:checked').forEach(function(c){m.push({id:c.value,name:c.dataset.name});});var cnt=document.getElementById(cfg.countId);if(cnt)cnt.textContent=m.length+' selected';});});
      }).fail(function(){l.innerHTML='<div style="text-align:center;padding:20px;color:var(--u-ol);font-size:13px;">Failed to load materials.</div>';});
    });
  }
  ab.addEventListener('click',function(){
    d.classList.toggle('open');
    if(d.classList.contains('open')){d.dataset.loaded='1';loadMats();}
  });
  var cb=document.getElementById(cfg.closeBtnId);if(cb)cb.addEventListener('click',closeDrawer);
  var cf=document.getElementById(cfg.confirmId);if(cf)cf.addEventListener('click',function(){closeDrawer();if(cfg.onConfirm)cfg.onConfirm(m);});
  document.addEventListener('click',function(e){if(d.classList.contains('open')&&!d.contains(e.target)&&!ab.contains(e.target))closeDrawer();});
}
function _umatInitPlayer(cfg){
  var v=document.getElementById(cfg.videoId);if(!v)return;
  var pp=document.getElementById(cfg.playBtnId),pr=document.getElementById(cfg.progId),
      cu=document.getElementById(cfg.curId),du=document.getElementById(cfg.durId),
      r3=document.getElementById(cfg.r30Id),f3=document.getElementById(cfg.f30Id),
      tb=document.getElementById(cfg.tsBodyId),ts=document.getElementById(cfg.tsSearchId||'');
  v.addEventListener('loadedmetadata',function(){if(du)du.textContent=_umatFmtT(v.duration);if(pr)pr.max=Math.floor(v.duration);});
  v.addEventListener('timeupdate',function(){
    if(cu)cu.textContent=_umatFmtT(v.currentTime);if(pr)pr.value=Math.floor(v.currentTime);
    if(tb)tb.querySelectorAll('.umat-ts-seg').forEach(function(s){var a=parseFloat(s.dataset.start||0),b=parseFloat(s.dataset.end||0);s.classList.toggle('active',v.currentTime>=a&&v.currentTime<=b);if(v.currentTime>=a&&v.currentTime<=b)s.scrollIntoView({behavior:'smooth',block:'nearest'});});
  });
  if(pp)pp.addEventListener('click',function(){if(v.paused){v.play();pp.querySelector('.material-symbols-outlined').textContent='pause';}else{v.pause();pp.querySelector('.material-symbols-outlined').textContent='play_arrow';}});
  if(r3)r3.addEventListener('click',function(){v.currentTime=Math.max(0,v.currentTime-30);});
  if(f3)f3.addEventListener('click',function(){v.currentTime=Math.min(v.duration||0,v.currentTime+30);});
  if(pr)pr.addEventListener('input',function(){v.currentTime=parseInt(this.value);});
  if(ts)ts.addEventListener('input',function(){var q=this.value.toLowerCase();tb.querySelectorAll('.umat-ts-seg').forEach(function(s){s.style.display=(!q||s.querySelector('.umat-ts-text').textContent.toLowerCase().includes(q))?'':'none';});});
}
/* ═══════════════════════════════════════
   YOUTUBE-STYLE TILE RENDER HELPERS
   ═══════════════════════════════════════ */
function _ytThumbBg(mime){
  if(!mime)return'yt-bg-other';
  mime=mime.toLowerCase();
  if(mime.includes('video'))return'yt-bg-video';
  if(mime.includes('pdf'))return'yt-bg-pdf';
  if(mime.includes('word')||mime.includes('document'))return'yt-bg-word';
  if(mime.includes('presentation')||mime.includes('powerpoint'))return'yt-bg-pptx';
  if(mime.includes('sheet')||mime.includes('excel'))return'yt-bg-excel';
  if(mime.includes('image'))return'yt-bg-image';
  if(mime.includes('audio'))return'yt-bg-audio';
  return'yt-bg-other';
}
function _ytAvCls(mime){
  if(!mime)return'yt-av-other';
  mime=mime.toLowerCase();
  if(mime.includes('video'))return'yt-av-video';
  if(mime.includes('pdf'))return'yt-av-pdf';
  if(mime.includes('word')||mime.includes('document'))return'yt-av-word';
  if(mime.includes('presentation')||mime.includes('powerpoint'))return'yt-av-pptx';
  if(mime.includes('sheet')||mime.includes('excel'))return'yt-av-excel';
  if(mime.includes('image'))return'yt-av-image';
  if(mime.includes('audio'))return'yt-av-audio';
  return'yt-av-other';
}
function _ytIcon(mime){
  if(!mime)return'description';
  mime=mime.toLowerCase();
  if(mime.includes('video'))return'videocam';
  if(mime.includes('pdf'))return'picture_as_pdf';
  if(mime.includes('word')||mime.includes('document'))return'description';
  if(mime.includes('presentation')||mime.includes('powerpoint'))return'co_present';
  if(mime.includes('sheet')||mime.includes('excel'))return'table_chart';
  if(mime.includes('image'))return'image';
  if(mime.includes('audio'))return'music_note';
  return'description';
}
function _ytExtLabel(mime){
  if(!mime)return'FILE';
  var m=mime.toLowerCase();
  if(m.includes('pdf'))return'PDF';
  if(m.includes('wordprocessingml')||m.includes('msword'))return'DOCX';
  if(m.includes('presentationml')||m.includes('powerpoint'))return'PPTX';
  if(m.includes('spreadsheetml')||m.includes('excel'))return'XLSX';
  if(m.includes('video/mp4'))return'MP4';
  if(m.includes('video'))return'VIDEO';
  if(m.includes('image/png'))return'PNG';
  if(m.includes('image/jpeg'))return'JPG';
  if(m.includes('image'))return'IMG';
  if(m.includes('audio'))return'AUDIO';
  var parts=mime.split('/');return(parts[1]||parts[0]||'FILE').toUpperCase().replace('VND.','').split('.').pop();
}

/* ─── VIDEO TILES (student Lectures tab) ─── */
function renderVideoTiles(recs){
  var grid=document.getElementById('stu-lec-grid')||document.getElementById('ws-video-grid');
  if(!grid)return;
  if(recs && !Array.isArray(recs)){
    recs = recs.recordings || recs.data || recs.tiles || [];
  }
  recs=recs||[];
  if(!recs.length){
    grid.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">video_library</span><p>No lecture recordings yet. They appear once a BBB session is processed by your lecturer.</p></div>';
    return;
  }
  grid.className='yt-grid';
  grid.innerHTML=recs.map(function(r,i){
    var badge=r.duration?'<span class="yt-badge">'+esc(r.duration)+'</span>':'';
    var segsData=JSON.stringify(r.segments||[]).replace(/'/g,'&#39;');
    return'<div class="yt-tile" data-idx="'+i+'" data-url="'+esc(r.url||'')+'" data-title="'+esc(r.title||'Lecture Recording')+'" data-segs=\''+segsData+'\'>'+
      '<div class="yt-thumb yt-bg-video">'+
        '<span class="yt-thumb-icon material-symbols-outlined">play_circle</span>'+
        '<div class="yt-play-ov"><span class="material-symbols-outlined">play_arrow</span></div>'+
        badge+
      '</div>'+
      '<div class="yt-meta">'+
        '<div class="yt-av yt-av-video"><span class="material-symbols-outlined">smart_toy</span></div>'+
        '<div class="yt-text">'+
          '<h4 class="yt-title" title="'+esc(r.title||'Lecture Recording')+'">'+esc(r.title||'Lecture Recording')+'</h4>'+
          '<p class="yt-channel">'+esc(r.description||'UMaT Lecture')+'</p>'+
          '<p class="yt-stats">'+esc(r.time_ago||r.date||'')+'</p>'+
        '</div>'+
      '</div>'+
      '<div class="yt-actions">'+
        '<button class="yt-btn" data-play="1" onclick="event.stopPropagation()"><span class="material-symbols-outlined">play_arrow</span>Play</button>'+
        '<a class="yt-btn" href="'+esc(r.url||'#')+'" download onclick="event.stopPropagation()"><span class="material-symbols-outlined">download</span>Download</a>'+
      '</div>'+
    '</div>';
  }).join('');

  grid.querySelectorAll('.yt-tile').forEach(function(tile){
    tile.addEventListener('click',function(e){
      if(e.target.closest('a.yt-btn'))return;
      var segs=[];try{segs=JSON.parse(tile.dataset.segs||'[]');}catch(ex){}
      var rec={url:tile.dataset.url,title:tile.dataset.title,segments:segs};
      if(typeof openVideoPlayer==='function')openVideoPlayer(rec);
      else if(typeof openPlayer==='function')openPlayer('stu',rec.url,rec.title,rec.segments||[]);
      else if(rec.url)window.open(rec.url,'_blank');
    });
    var playBtn=tile.querySelector('[data-play]');
    if(playBtn)playBtn.addEventListener('click',function(e){
      e.stopPropagation();tile.click();
    });
  });
}

/* ─── COURSE TILES (student My Courses) ──── */
function renderCourses(courses,gridOverride){
  var grid=gridOverride||document.getElementById('stu-courses-grid')||document.getElementById('ws-courses-grid');
  if(!grid)return;
  if(courses && !Array.isArray(courses)) courses=courses.courses||[];
  courses=courses||[];
  if(!courses.length){
    grid.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">menu_book</span><p>No enrolled courses found.</p></div>';
    return;
  }
  grid.className='yt-grid';
  grid.innerHTML=courses.map(function(c){
    return'<div class="yt-tile" data-cid="'+c.id+'" data-cname="'+esc(c.fullname||'')+'">'+
      '<div class="yt-thumb yt-bg-course">'+
        '<div class="yt-course-ov">'+
          '<div class="yt-course-code">'+esc(c.shortname||'')+'</div>'+
          '<div class="yt-course-name">'+esc(c.fullname||'')+'</div>'+
        '</div>'+
      '</div>'+
      '<div class="yt-meta">'+
        '<div class="yt-av yt-av-course"><span class="material-symbols-outlined">menu_book</span></div>'+
        '<div class="yt-text">'+
          '<h4 class="yt-title">'+esc(c.fullname||'')+'</h4>'+
          '<p class="yt-channel">'+esc(c.shortname||'')+'</p>'+
          '<p class="yt-stats">Click to chat about this course</p>'+
        '</div>'+
      '</div>'+
      '<div class="yt-actions">'+
        '<button class="yt-btn"><span class="material-symbols-outlined">smart_toy</span>AI Tutor</button>'+
      '</div>'+
    '</div>';
  }).join('');

  grid.querySelectorAll('.yt-tile').forEach(function(tile){
    tile.addEventListener('click',function(){
      if(typeof selectCourse==='function')selectCourse(parseInt(tile.dataset.cid),tile.dataset.cname);
    });
  });
  var srch=document.getElementById('stu-courses-srch')||document.getElementById('stu-courses-search');
  if(srch)srch.addEventListener('input',function(){
    var q=this.value.toLowerCase();
    grid.querySelectorAll('.yt-tile').forEach(function(t){t.style.display=(!q||t.textContent.toLowerCase().includes(q))?'':'none';});
  });
}

/* ─── LIBRARY TILES (student Library) ─────── */
function renderLibrary(mats){
  var grid=document.getElementById('stu-lib-grid')||document.getElementById('ws-lib-grid');
  if(!grid)return;
  if(mats && !Array.isArray(mats)) mats=mats.materials||[];
  _renderYtMaterials(mats||[],grid,'stu');
}

/* ─── LECTURER LIBRARY TILES ───────────────── */
function renderLibTiles(materials,g){
  if(!g){g=document.getElementById('lec-lib-grid');}
  _renderYtMaterials(materials,g,'lec');
}

/* ─── SHARED MATERIAL TILE RENDERER ──────────── */
function _renderYtMaterials(mats,g,pfx){
  if(!mats||!mats.length){
    g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">folder_open</span><p>No materials found for this course.</p></div>';
    return;
  }
  g.className='yt-grid';
  g.innerHTML=mats.map(function(m){
    var mime=m.mimetype||'';
    var bg=_ytThumbBg(mime),av=_ytAvCls(mime),ic=_ytIcon(mime),ext=_ytExtLabel(mime);
    var isVideo=mime.toLowerCase().includes('video');
    var playIcon=isVideo?'play_arrow':'open_in_new';
    var badge='';
    if(m.duration)badge='<span class="yt-badge">'+esc(m.duration)+'</span>';
    else if(m.page_count&&m.page_count>0)badge='<span class="yt-badge">'+m.page_count+' pp</span>';
    else badge='<span class="yt-badge">'+ext+'</span>';
    var sz=typeof fmtSz==='function'?fmtSz(m.filesize||0):(Math.round((m.filesize||0)/1024))+'KB';
    return'<div class="yt-tile" data-url="'+esc(m.url||'')+'" data-name="'+esc(m.filename||'')+'" data-mime="'+esc(mime)+'">'+
      '<div class="yt-thumb '+bg+'">'+
        '<span class="yt-thumb-icon material-symbols-outlined">'+ic+'</span>'+
        '<div class="yt-play-ov"><span class="material-symbols-outlined">'+playIcon+'</span></div>'+
        badge+
      '</div>'+
      '<div class="yt-meta">'+
        '<div class="yt-av '+av+'"><span class="material-symbols-outlined">'+ic+'</span></div>'+
        '<div class="yt-text">'+
          '<h4 class="yt-title" title="'+esc(m.filename||'')+'">'+esc(m.filename||'')+'</h4>'+
          '<p class="yt-channel">'+ext+' · '+sz+'</p>'+
          '<p class="yt-stats">'+esc(m.time_ago||'')+'</p>'+
        '</div>'+
      '</div>'+
      '<div class="yt-actions">'+
        '<button class="yt-btn yt-view-btn"><span class="material-symbols-outlined">visibility</span>View</button>'+
        '<a class="yt-btn" href="'+esc(m.url||'#')+'" download="'+esc(m.filename||'')+'" onclick="event.stopPropagation()"><span class="material-symbols-outlined">download</span>Download</a>'+
      '</div>'+
    '</div>';
  }).join('');

  g.querySelectorAll('.yt-tile').forEach(function(tile){
    tile.addEventListener('click',function(e){
      if(e.target.closest('a.yt-btn'))return;
      var mime=tile.dataset.mime||'',url=tile.dataset.url,name=tile.dataset.name;
      if(mime.toLowerCase().includes('video')){
        if(pfx==='lec'&&typeof openLecPlayer==='function')openLecPlayer(url,name,[]);
        else if(typeof openPlayer==='function')openPlayer(pfx,url,name,[]);
        else if(typeof openVideoPlayer==='function')openVideoPlayer({url:url,title:name,segments:[]});
        else window.open(url,'_blank');
      }else{
        if(pfx==='lec'&&typeof openLecPdf==='function')openLecPdf(url,name);
        else if(typeof openPdf==='function')openPdf(pfx,url,name);
        else if(typeof openPdfViewer==='function')openPdfViewer(url,name);
        else window.open(url,'_blank');
      }
    });
    var vb=tile.querySelector('.yt-view-btn');
    if(vb)vb.addEventListener('click',function(e){e.stopPropagation();tile.click();});
  });
}

function _umatInitEsc(layers){
  document.addEventListener('keydown',function(e){
    if(e.key!=='Escape')return;
    for(var i=0;i<layers.length;i++){
      var el=document.getElementById(layers[i].id);
      if(el&&layers[i].isOpen(el)){layers[i].close(el);e.preventDefault();return;}
    }
  });
}
function esc(s){var d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML;}
function fmtSz(b){if(b<1024)return b+'B';if(b<1048576)return (b/1024).toFixed(1)+'KB';return (b/1048576).toFixed(1)+'MB';}
function timeAgo(ts){if(!ts)return '';var d=new Date(ts*1000),n=new Date(),s=Math.floor((n-d)/1000);if(s<60)return 'just now';var m=Math.floor(s/60);if(m<60)return m+'m ago';var h=Math.floor(m/60);if(h<24)return h+'h ago';var D=Math.floor(h/24);if(D<30)return D+'d ago';var M=Math.floor(D/30);if(M<12)return M+'mo ago';var Y=Math.floor(M/12);return Y+'y ago';}
function libTileClass(m){if(!m)return 'lt-other';if(m.includes('pdf'))return 'lt-pdf';if(m.includes('video'))return 'lt-video';if(m.includes('image'))return 'lt-img';if(m.includes('word')||m.includes('document'))return 'lt-doc';return 'lt-other';}
function fileTypeIcon(m){if(!m)return 'description';if(m.includes('pdf'))return 'picture_as_pdf';if(m.includes('video'))return 'videocam';if(m.includes('image'))return 'image';return 'description';}
function ajax(method,args,done,fail){require(['core/ajax'],function(A){A.call([{methodname:method,args:args}])[0].done(done).fail(fail||function(){});});}
/* Close button handler */
(function(){
  var cb=document.getElementById('{$closeId}'),ov=document.getElementById('{$overlayId}');
  if(cb&&ov)cb.addEventListener('click',function(){ov.classList.remove('open');});
})();
</script>
JS;
    }

    // ================================================================== //
    // END OF CLASS                                                        //
    // ================================================================== //
}
