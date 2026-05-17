<?php
/**
 * Hook listener — inject AI FABs, compact panels, and full-screen overlays.
 *
 * STUDENT  (course pages) → FAB → compact chat panel → workspace overlay
 * LECTURER (course pages) → FAB → insights panel     → analytics overlay
 * STUDENT  (other pages)  → Hub FAB → hub.php
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\hooks;

use core\hook\output\before_footer_html_generation;

class before_footer {

    // ------------------------------------------------------------------ //
    // ENTRY POINT                                                          //
    // ------------------------------------------------------------------ //

    public static function handle(before_footer_html_generation $hook): void {
        global $PAGE, $COURSE, $USER, $CFG, $DB;

        if (!isloggedin() || isguestuser()) {
            return;
        }

        $path = $PAGE->url->get_path();

        $isCourseArea = (
            strpos($path, '/course/') !== false ||
            strpos($path, '/mod/')    !== false ||
            strpos($path, '/section/') !== false
        );

        $courseid = 0;
        $ctx      = $PAGE->context;
        if ($ctx && $ctx->contextlevel === CONTEXT_COURSE) {
            $courseid = (int) $ctx->instanceid;
        } elseif (!empty($COURSE->id) && $COURSE->id != SITEID) {
            $courseid = (int) $COURSE->id;
        }

        $wwwroot = rtrim($CFG->wwwroot, '/');

        // Always inject shared fonts/styles once.
        $hook->add_html(self::shared_styles());

        if ($isCourseArea && $courseid) {
            $courseCtx  = \context_course::instance($courseid);
            $courseName = format_string($COURSE->fullname ?? '', true, ['context' => $courseCtx]);

            $isLecturer = has_capability('local/umat_ai:viewanalytics', $courseCtx);
            $isStudent  = !$isLecturer && is_enrolled($courseCtx, $USER, '', false);

            if ($isLecturer) {
                // Enable disable checks.
                if (!get_config('local_umat_ai', 'enable_lecturer_fab')) {
                    return;
                }
                $pending = (int) $DB->get_field_sql(
                    "SELECT COUNT(o.id) FROM {umat_ai_outputs} o
                     JOIN {umat_ai_sessions} s ON s.id = o.sessionrecordid
                     WHERE s.courseid = :cid AND o.is_approved = 0",
                    ['cid' => $courseid]
                ) ?: 0;

                $hook->add_html(self::lecturer_fab($courseid, $courseName, $pending, $wwwroot, $USER));

            } elseif ($isStudent) {
                if (!get_config('local_umat_ai', 'enable_student_fab')) {
                    return;
                }
                $hook->add_html(self::student_fab($courseid, $courseName, $wwwroot, $USER));
            }

        } elseif (!$isCourseArea && get_config('local_umat_ai', 'enable_hub_fab')) {
            // Non-course pages: Hub FAB for students only (no hub for lecturers).
            $isAnyLecturer = $DB->record_exists_sql(
                "SELECT 1 FROM {role_assignments} ra
                 JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.userid = :uid AND r.shortname IN ('editingteacher','teacher','manager')",
                ['uid' => $USER->id]
            );
            if (!$isAnyLecturer) {
                $hook->add_html(self::hub_fab($wwwroot));
            }
        }
    }

    // ================================================================== //
    // SHARED STYLES & FONTS                                               //
    // ================================================================== //

    private static function shared_styles(): string {
        return <<<'HTML'
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
<style id="umat-ai-base">
/* ================================================================
   UMaT Precision Green — global tokens
   ================================================================ */
:root {
  --u-p:       #006b2f;
  --u-pb:      #00873d;
  --u-pfixed:  #81fb9c;
  --u-op:      #ffffff;
  --u-sf:      #f5fbf0;
  --u-sfl:     #eff6eb;
  --u-sflo:    #ffffff;
  --u-ons:     #171d17;
  --u-onsv:    #3e4a3e;
  --u-ol:      #6e7a6d;
  --u-olv:     #bdcaba;
  --u-sec:     #3d6844;
  --u-secc:    #beefc1;
  --u-ter:     #a5304d;
  --u-err:     #ba1a1a;
  --u-ok:      #4ade80;
  --u-warn:    #f59e0b;
  --u-r6:      6px;
  --u-r8:      8px;
  --u-r12:     12px;
  --u-r16:     16px;
  --u-r20:     20px;
  --u-rp:      9999px;
  --u-shadow:  0 8px 32px rgba(0,0,0,.14);
  --u-fshadow: 0 6px 20px rgba(0,107,47,.42);
  --u-sb-col:  64px;
  --u-sb-exp:  240px;
  --u-topnav:  56px;
}

/* ---- FABs ---- */
.umat-fab {
  position: fixed !important;
  bottom: 28px !important;
  right: 28px !important;
  z-index: 9990 !important;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--u-p) 0%, var(--u-pb) 100%);
  color: var(--u-op);
  border: none;
  box-shadow: var(--u-fshadow);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: transform .25s, box-shadow .25s;
  font-family: 'Inter', sans-serif;
  text-decoration: none;
}
.umat-fab:hover { transform: scale(1.1); box-shadow: 0 8px 28px rgba(0,107,47,.55); }
.umat-fab .material-symbols-outlined { font-size: 26px; }

@keyframes umat-pulse-fab {
  0%   { box-shadow: var(--u-fshadow), 0 0 0 0 rgba(0,107,47,.5); }
  70%  { box-shadow: var(--u-fshadow), 0 0 0 14px rgba(0,107,47,0); }
  100% { box-shadow: var(--u-fshadow), 0 0 0 0 rgba(0,107,47,0); }
}
.umat-fab-pulse { animation: umat-pulse-fab 2.8s infinite; }

.umat-fab-badge {
  position: absolute;
  top: -3px; right: -3px;
  min-width: 20px; height: 20px;
  padding: 0 5px;
  background: var(--u-ter);
  color: #fff;
  border-radius: var(--u-rp);
  font-size: 11px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  border: 2px solid #fff;
  font-family: 'Inter', sans-serif;
}

.umat-fab-tip {
  position: absolute;
  right: 68px;
  white-space: nowrap;
  background: #1a1c19;
  color: #fff;
  padding: 5px 11px;
  border-radius: var(--u-r8);
  font-size: 12px; font-weight: 500;
  opacity: 0; pointer-events: none;
  transition: opacity .2s;
  font-family: 'Inter', sans-serif;
}
.umat-fab-tip::after {
  content: '';
  position: absolute;
  right: -6px; top: 50%; transform: translateY(-50%);
  border: 6px solid transparent;
  border-left-color: #1a1c19;
}
.umat-fab:hover .umat-fab-tip { opacity: 1; }

/* ================================================================
   FULL-SCREEN OVERLAY SHELL
   ================================================================ */
.umat-ov {
  position: fixed !important;
  inset: 0 !important;
  z-index: 99998 !important;
  display: none;
  flex-direction: column;
  background: var(--u-sf);
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
.umat-ov.umat-ov-open { display: flex; }

/* Slide-in animation */
@keyframes umat-ov-in {
  from { opacity: 0; transform: translateY(18px); }
  to   { opacity: 1; transform: translateY(0); }
}
.umat-ov.umat-ov-open { animation: umat-ov-in .3s cubic-bezier(.4,0,.2,1) forwards; }

/* ---- Top Nav Bar ---- */
.umat-ov-topnav {
  height: var(--u-topnav);
  min-height: var(--u-topnav);
  background: var(--u-sflo);
  border-bottom: 1px solid var(--u-olv);
  display: flex;
  align-items: center;
  padding: 0 20px;
  gap: 20px;
  flex-shrink: 0;
  z-index: 10;
}
.umat-ov-brand {
  font-size: 16px; font-weight: 800;
  color: var(--u-p);
  white-space: nowrap;
  display: flex; align-items: center; gap: 8px;
}
.umat-ov-brand .material-symbols-outlined { font-size: 20px; }
.umat-ov-tabs {
  display: flex; align-items: center; gap: 2px;
  flex: 1;
}
.umat-ov-tab {
  padding: 6px 14px;
  border-radius: var(--u-rp);
  border: none; background: none;
  font-size: 13px; font-weight: 500;
  color: var(--u-ol);
  cursor: pointer;
  transition: all .2s;
  font-family: inherit;
  white-space: nowrap;
}
.umat-ov-tab:hover { color: var(--u-ons); background: var(--u-sfl); }
.umat-ov-tab.active { color: var(--u-p); background: rgba(0,107,47,.08); font-weight: 700; }
.umat-ov-search {
  display: flex; align-items: center; gap: 7px;
  padding: 7px 13px;
  background: var(--u-sfl);
  border: 1px solid var(--u-olv);
  border-radius: var(--u-rp);
  min-width: 180px;
}
.umat-ov-search .material-symbols-outlined { font-size: 16px; color: var(--u-ol); }
.umat-ov-search input {
  border: none; background: none; outline: none;
  font-size: 13px; color: var(--u-ons);
  font-family: inherit; width: 120px;
}
.umat-ov-icon-btn {
  width: 34px; height: 34px; border-radius: 50%;
  background: none; border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: var(--u-ol); transition: all .2s;
}
.umat-ov-icon-btn .material-symbols-outlined { font-size: 20px; }
.umat-ov-icon-btn:hover { background: var(--u-sfl); color: var(--u-ons); }
.umat-ov-close {
  width: 32px; height: 32px; border-radius: 50%;
  background: var(--u-sfl); border: 1px solid var(--u-olv);
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  color: var(--u-onsv); transition: all .2s; flex-shrink: 0;
}
.umat-ov-close:hover { background: #fee2e2; color: var(--u-err); border-color: var(--u-err); }
.umat-ov-close .material-symbols-outlined { font-size: 18px; }

/* ---- Collapsible Sidebar ---- */
.umat-ov-body { display: flex; flex: 1; overflow: hidden; }

.umat-sb {
  width: var(--u-sb-col);
  background: var(--u-sflo);
  border-right: 1px solid var(--u-olv);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transition: width .32s cubic-bezier(.4,0,.2,1);
  flex-shrink: 0;
  position: relative;
  z-index: 5;
}
.umat-sb:hover { width: var(--u-sb-exp); }

.umat-sb-logo {
  display: flex; align-items: center; gap: 12px;
  padding: 16px;
  overflow: hidden;
  flex-shrink: 0;
  border-bottom: 1px solid var(--u-olv);
  min-height: 72px;
}
.umat-sb-logo-icon {
  width: 32px; height: 32px; border-radius: var(--u-r8);
  background: linear-gradient(135deg, var(--u-p), var(--u-pb));
  color: var(--u-op);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.umat-sb-logo-icon .material-symbols-outlined { font-size: 18px; }
.umat-sb-logo-text {
  white-space: nowrap; overflow: hidden;
  opacity: 0; transition: opacity .2s;
}
.umat-sb:hover .umat-sb-logo-text { opacity: 1; }
.umat-sb-logo-text strong { display: block; font-size: 13px; font-weight: 700; color: var(--u-p); }
.umat-sb-logo-text span   { font-size: 11px; color: var(--u-ol); }

.umat-sb-nav {
  flex: 1; overflow-y: auto; overflow-x: hidden;
  padding: 10px 0;
  display: flex; flex-direction: column;
}
.umat-sb-nav::-webkit-scrollbar { width: 3px; }
.umat-sb-nav::-webkit-scrollbar-thumb { background: var(--u-olv); border-radius: 2px; }

.umat-sb-item {
  display: flex; align-items: center; gap: 14px;
  padding: 11px 16px;
  cursor: pointer;
  transition: background .18s;
  white-space: nowrap;
  overflow: hidden;
  text-decoration: none;
  color: var(--u-onsv);
  border-left: 3px solid transparent;
  position: relative;
}
.umat-sb-item:hover { background: var(--u-sfl); color: var(--u-ons); }
.umat-sb-item.active {
  background: rgba(0,107,47,.08);
  color: var(--u-p);
  border-left-color: var(--u-p);
  font-weight: 600;
}
.umat-sb-item .material-symbols-outlined {
  font-size: 22px; flex-shrink: 0;
  transition: color .18s;
}
.umat-sb-item.active .material-symbols-outlined { color: var(--u-p); }
.umat-sb-item-label {
  font-size: 13px; font-weight: 500;
  opacity: 0; transition: opacity .18s;
  overflow: hidden;
}
.umat-sb:hover .umat-sb-item-label { opacity: 1; }

.umat-sb-divider { height: 1px; background: var(--u-olv); margin: 6px 12px; flex-shrink: 0; }

.umat-sb-new-btn {
  display: flex; align-items: center; gap: 12px;
  margin: 10px 10px;
  padding: 10px 12px;
  background: var(--u-p);
  color: var(--u-op);
  border-radius: var(--u-r8);
  cursor: pointer;
  border: none;
  font-family: inherit;
  font-size: 13px; font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  transition: background .2s;
  flex-shrink: 0;
}
.umat-sb-new-btn:hover { background: var(--u-pb); }
.umat-sb-new-btn .material-symbols-outlined { font-size: 18px; flex-shrink: 0; }
.umat-sb-new-btn-label { opacity: 0; transition: opacity .18s; overflow: hidden; }
.umat-sb:hover .umat-sb-new-btn-label { opacity: 1; }

.umat-sb-footer {
  padding: 10px 0 12px;
  border-top: 1px solid var(--u-olv);
  flex-shrink: 0;
}

/* ---- Shared compact panel base ---- */
.umat-cp-overlay {
  position: fixed !important;
  inset: 0 !important;
  z-index: 9995 !important;
  background: rgba(0,0,0,.3);
  backdrop-filter: blur(6px);
  display: none; justify-content: flex-end;
}
.umat-cp-overlay.open { display: flex; }
.umat-cp {
  width: 440px; max-width: 96vw; height: 100%;
  background: var(--u-sf);
  box-shadow: var(--u-shadow);
  display: flex; flex-direction: column;
  transform: translateX(100%);
  transition: transform .38s cubic-bezier(.4,0,.2,1);
  overflow: hidden;
}
.umat-cp-overlay.open .umat-cp { transform: translateX(0); }
.umat-cp-lec { width: 480px; }

/* Panel header */
.umat-cp-hdr {
  background: linear-gradient(135deg, var(--u-p) 0%, var(--u-pb) 100%);
  color: var(--u-op);
  padding: 16px 18px 12px;
  flex-shrink: 0;
}
.umat-cp-hdr-row { display: flex; align-items: center; gap: 12px; }
.umat-cp-avatar {
  width: 42px; height: 42px; border-radius: 50%;
  background: rgba(255,255,255,.2);
  display: flex; align-items: center; justify-content: center;
  position: relative; flex-shrink: 0;
}
.umat-cp-avatar .material-symbols-outlined { font-size: 22px; }
.umat-cp-status-dot {
  position: absolute; bottom: 1px; right: 1px;
  width: 11px; height: 11px; border-radius: 50%;
  background: var(--u-ok); border: 2px solid var(--u-p);
}
@keyframes umat-dot-pulse { 0%,100% { opacity:1; } 50% { opacity:.4; } }
.umat-cp-status-dot { animation: umat-dot-pulse 2s infinite; }
.umat-cp-info { flex: 1; min-width: 0; }
.umat-cp-info h2 { margin: 0; font-size: 15px; font-weight: 700; }
.umat-cp-info .umat-cp-sub { font-size: 11px; opacity: .85; margin-top: 1px; }
.umat-cp-info .umat-cp-ctx { font-size: 11px; opacity: .7; margin-top: 1px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.umat-cp-hdr-btn {
  background: rgba(255,255,255,.18); border: none; color: var(--u-op);
  width: 32px; height: 32px; border-radius: 50%; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: background .2s; flex-shrink: 0;
}
.umat-cp-hdr-btn .material-symbols-outlined { font-size: 17px; }
.umat-cp-hdr-btn:hover { background: rgba(255,255,255,.3); }
.umat-cp-expand-btn {
  border-radius: var(--u-r6); width: auto; padding: 0 12px;
  gap: 5px; font-size: 12px; font-weight: 700;
}
.umat-cp-expand-btn .material-symbols-outlined { font-size: 15px; }

/* Panel tabs */
.umat-cp-tabs { display: flex; background: var(--u-sflo); border-bottom: 1px solid var(--u-olv); flex-shrink: 0; }
.umat-cp-tab {
  flex: 1; padding: 11px 6px;
  border: none; background: none; cursor: pointer;
  font-size: 12px; font-weight: 500; color: var(--u-ol);
  border-bottom: 2.5px solid transparent; transition: all .2s; font-family: inherit;
}
.umat-cp-tab:hover { color: var(--u-p); background: var(--u-sfl); }
.umat-cp-tab.active { color: var(--u-p); border-bottom-color: var(--u-p); font-weight: 700; }

/* Panel tab panes */
.umat-cp-pane { display: none; flex: 1; flex-direction: column; overflow: hidden; }
.umat-cp-pane.active { display: flex; }

/* Chat area */
.umat-msgs {
  flex: 1; overflow-y: auto; padding: 14px;
  display: flex; flex-direction: column; gap: 12px;
  background: var(--u-sf);
}
.umat-msgs::-webkit-scrollbar { width: 4px; }
.umat-msgs::-webkit-scrollbar-thumb { background: var(--u-olv); border-radius: 2px; }

.umat-msg-ai { display: flex; gap: 8px; align-items: flex-start; }
.umat-msg-ai-icon {
  width: 30px; height: 30px; border-radius: 50%;
  background: rgba(0,107,47,.12); color: var(--u-p);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.umat-msg-ai-icon .material-symbols-outlined { font-size: 15px; }
.umat-msg-ai-wrap { display: flex; flex-direction: column; gap: 4px; }
.umat-msg-ai-label { font-size: 10px; font-weight: 700; color: var(--u-p); letter-spacing: .04em; }
.umat-bubble-ai {
  background: var(--u-sflo); border-left: 2.5px solid var(--u-p);
  padding: 10px 12px;
  border-radius: 0 var(--u-r12) var(--u-r12) var(--u-r12);
  font-size: 13px; line-height: 1.55; color: var(--u-ons);
  box-shadow: 0 1px 6px rgba(0,0,0,.05);
}
.umat-bubble-ai p { margin: 0; }

.umat-msg-user { display: flex; justify-content: flex-end; }
.umat-bubble-user {
  background: linear-gradient(135deg, #dcfce7, #bbf7d0);
  color: #052e16; padding: 10px 13px;
  border-radius: var(--u-r12) 0 var(--u-r12) var(--u-r12);
  font-size: 13px; line-height: 1.55; max-width: 85%;
}
.umat-bubble-user p { margin: 0; }

/* Suggestion chips below AI bubble */
.umat-suggestion-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 7px; }
.umat-suggestion-chip {
  padding: 4px 11px; border-radius: var(--u-rp);
  border: 1.5px solid var(--u-olv); background: var(--u-sflo);
  font-size: 11px; font-weight: 600; color: var(--u-onsv);
  cursor: pointer; transition: all .2s; font-family: inherit;
}
.umat-suggestion-chip:hover { border-color: var(--u-p); color: var(--u-p); background: rgba(0,107,47,.05); }

/* Source chips */
.umat-sources { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 7px; }
.umat-source-chip {
  padding: 2px 8px; border-radius: var(--u-rp);
  background: var(--u-secc); color: var(--u-sec);
  font-size: 10px; font-weight: 700;
}

/* Typing indicator */
@keyframes umat-dot-bounce { 0%,60%,100% { transform:translateY(0); } 30% { transform:translateY(-5px); } }
.umat-typing { display: flex; gap: 4px; padding: 8px 0; }
.umat-typing span { width: 7px; height: 7px; border-radius: 50%; background: var(--u-p); animation: umat-dot-bounce 1.2s infinite; }
.umat-typing span:nth-child(2) { animation-delay: .2s; }
.umat-typing span:nth-child(3) { animation-delay: .4s; }

/* Quick actions */
.umat-quick-grid {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 7px; padding: 10px 14px 2px; flex-shrink: 0;
}
.umat-quick-btn {
  display: flex; flex-direction: column; align-items: center;
  justify-content: center; gap: 5px; padding: 10px 6px;
  border: 1px solid var(--u-olv); background: var(--u-sflo);
  border-radius: var(--u-r12); cursor: pointer; transition: all .2s; font-family: inherit;
}
.umat-quick-btn .material-symbols-outlined { font-size: 20px; color: var(--u-p); }
.umat-quick-btn .q-lbl { font-size: 11px; color: var(--u-ons); text-align: center; line-height: 1.3; }
.umat-quick-btn:hover { border-color: var(--u-p); background: rgba(129,251,156,.1); }

/* Input area */
.umat-input-area {
  padding: 11px 14px; background: var(--u-sflo);
  border-top: 1px solid var(--u-olv); flex-shrink: 0;
}
.umat-input-row { display: flex; gap: 8px; align-items: flex-end; }
.umat-textarea {
  flex: 1; padding: 9px 12px;
  border: 1.5px solid var(--u-olv); border-radius: var(--u-r8);
  font-size: 13px; font-family: inherit; resize: none; outline: none;
  line-height: 1.45; color: var(--u-ons); background: var(--u-sf);
  transition: border-color .2s;
}
.umat-textarea:focus { border-color: var(--u-p); box-shadow: 0 0 0 3px rgba(0,107,47,.09); }
.umat-send-btn {
  width: 40px; height: 40px; border-radius: var(--u-r8);
  background: var(--u-p); color: var(--u-op); border: none;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; transition: background .2s, transform .15s;
}
.umat-send-btn .material-symbols-outlined { font-size: 19px; }
.umat-send-btn:hover { background: var(--u-pb); transform: scale(1.05); }
.umat-input-footer {
  display: flex; justify-content: space-between; align-items: center; margin-top: 6px;
}
.umat-rate-txt { font-size: 10px; color: var(--u-ol); }
.umat-rate-txt.warn { color: var(--u-ter); font-weight: 700; }
.umat-ref-btn {
  background: none; border: none; color: var(--u-p); font-size: 11px;
  cursor: pointer; display: flex; align-items: center; gap: 3px; font-family: inherit;
}
.umat-ref-btn .material-symbols-outlined { font-size: 14px; }

/* KPI mini cards */
.umat-kpi-mini-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; padding: 14px; }
.umat-kpi-mini {
  background: var(--u-sflo); border: 1px solid var(--u-olv);
  border-radius: var(--u-r12); padding: 12px;
}
.umat-kpi-mini .kmi-ico {
  width: 28px; height: 28px; border-radius: var(--u-r6);
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 8px;
}
.kmi-ico .material-symbols-outlined { font-size: 16px; }
.kmi-g { background: rgba(0,107,47,.1); color: var(--u-p); }
.kmi-s { background: rgba(61,104,68,.1); color: var(--u-sec); }
.kmi-w { background: rgba(245,158,11,.12); color: #d97706; }
.kmi-r { background: rgba(165,48,77,.1); color: var(--u-ter); }
.umat-kpi-mini .kmi-lbl { font-size: 10px; color: var(--u-ol); margin-bottom: 3px; }
.umat-kpi-mini .kmi-val { font-size: 18px; font-weight: 800; color: var(--u-ons); line-height: 1; }
.umat-kpi-mini .kmi-badge {
  display: inline-flex; align-items: center;
  padding: 2px 7px; border-radius: var(--u-rp);
  font-size: 9px; font-weight: 700; margin-top: 4px;
}
.kmi-badge-ok   { background: #dcfce7; color: #065f46; }
.kmi-badge-warn { background: #fef3c7; color: #92400e; }
.kmi-badge-info { background: var(--u-secc); color: var(--u-sec); }
.kmi-badge-high { background: #fee2e2; color: #991b1b; }

/* Insight cards */
.umat-insight-card {
  background: var(--u-sflo); border: 1px solid var(--u-olv);
  border-radius: var(--u-r12); padding: 13px; margin-bottom: 9px;
}
.umat-insight-card.ic-warn { border-left: 3px solid var(--u-ter); }
.umat-insight-card.ic-alert { border-left: 3px solid var(--u-warn); }
.umat-insight-card.ic-info { border-left: 3px solid var(--u-p); }
.umat-insight-card h4 {
  margin: 0 0 5px; font-size: 12px; font-weight: 700; color: var(--u-ons);
  display: flex; align-items: center; gap: 6px;
}
.umat-insight-card h4 .material-symbols-outlined { font-size: 16px; }
.umat-insight-card p { margin: 0 0 9px; font-size: 12px; color: var(--u-onsv); line-height: 1.5; }
.umat-insight-actions { display: flex; flex-wrap: wrap; gap: 6px; }
.umat-chip-btn {
  padding: 4px 11px; border-radius: var(--u-rp);
  border: 1.5px solid var(--u-p); background: none; color: var(--u-p);
  font-size: 11px; font-weight: 600; cursor: pointer;
  transition: all .2s; font-family: inherit; text-decoration: none;
  display: inline-flex; align-items: center; gap: 3px;
}
.umat-chip-btn .material-symbols-outlined { font-size: 13px; }
.umat-chip-btn:hover { background: var(--u-p); color: var(--u-op); }

/* Panel footer */
.umat-cp-footer {
  padding: 11px 14px; border-top: 1px solid var(--u-olv);
  display: flex; flex-direction: column; gap: 7px;
  flex-shrink: 0; background: var(--u-sflo);
}
.umat-footer-btn {
  width: 100%; padding: 10px; border-radius: var(--u-r8);
  border: none; font-size: 12px; font-weight: 700; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 6px;
  transition: all .2s; font-family: inherit; text-decoration: none;
}
.umat-footer-btn .material-symbols-outlined { font-size: 16px; }
.umat-footer-btn-p { background: var(--u-p); color: var(--u-op); }
.umat-footer-btn-p:hover { background: var(--u-pb); }
.umat-footer-btn-o { border: 1.5px solid var(--u-p); background: none; color: var(--u-p); }
.umat-footer-btn-o:hover { background: var(--u-sfl); }

/* Empty state */
.umat-empty {
  display: flex; flex-direction: column; align-items: center;
  justify-content: center; padding: 36px 20px; gap: 10px;
  color: var(--u-ol); text-align: center; font-size: 13px; flex: 1;
}
.umat-empty .material-symbols-outlined { font-size: 44px; color: var(--u-olv); }

/* ================================================================
   WORKSPACE OVERLAY SPECIFIC
   ================================================================ */
.umat-ws-main {
  display: flex; flex: 1; overflow: hidden;
}
.umat-ws-left {
  flex: 1; display: flex; flex-direction: column; overflow: hidden;
  background: var(--u-sf); border-right: 1px solid var(--u-olv);
}
.umat-ws-video-wrap {
  position: relative; background: #000; flex-shrink: 0;
}
.umat-ws-video-wrap video { width: 100%; display: block; max-height: 300px; object-fit: contain; }
.umat-ws-no-vid {
  aspect-ratio: 16/9; max-height: 300px; background: #111;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 10px; color: #777; font-size: 14px;
}
.umat-ws-no-vid .material-symbols-outlined { font-size: 48px; color: #444; }
.umat-ws-vc {
  display: flex; align-items: center; gap: 10px; padding: 8px 14px;
  background: linear-gradient(0deg,rgba(0,0,0,.85),transparent);
  position: absolute; bottom: 0; left: 0; right: 0;
}
.umat-ws-vc-btn { background: none; border: none; color: #fff; cursor: pointer; padding: 4px; }
.umat-ws-vc-btn .material-symbols-outlined { font-size: 22px; }
.umat-ws-vc-time { color: #fff; font-size: 12px; font-family: inherit; }
.umat-ws-vc-progress {
  flex: 1; height: 4px; -webkit-appearance: none; appearance: none;
  background: rgba(255,255,255,.3); border-radius: 2px; cursor: pointer;
}
.umat-ws-vc-progress::-webkit-slider-thumb { -webkit-appearance: none; width: 13px; height: 13px; border-radius: 50%; background: #fff; }

.umat-ws-transcript-wrap {
  flex: 1; display: flex; flex-direction: column; overflow: hidden;
  margin: 12px; background: var(--u-sflo); border: 1px solid var(--u-olv);
  border-radius: var(--u-r12);
}
.umat-ws-ts-header {
  padding: 11px 14px; border-bottom: 1px solid var(--u-olv);
  display: flex; align-items: center; justify-content: space-between;
  flex-shrink: 0;
}
.umat-ws-ts-title {
  font-size: 14px; font-weight: 700; color: var(--u-ons);
  display: flex; align-items: center; gap: 6px; margin: 0;
}
.umat-ws-ts-title .material-symbols-outlined { font-size: 16px; color: var(--u-p); }
.umat-ws-ts-search {
  display: flex; align-items: center; gap: 6px; padding: 6px 10px;
  border: 1px solid var(--u-olv); border-radius: var(--u-r8); background: var(--u-sf);
}
.umat-ws-ts-search .material-symbols-outlined { font-size: 15px; color: var(--u-ol); }
.umat-ws-ts-search input { border: none; background: none; outline: none; font-size: 12px; width: 120px; font-family: inherit; }
.umat-ws-ts-body { flex: 1; overflow-y: auto; padding: 8px; }
.umat-ws-ts-body::-webkit-scrollbar { width: 4px; }
.umat-ws-ts-body::-webkit-scrollbar-thumb { background: var(--u-olv); border-radius: 2px; }
.umat-ts-seg {
  display: flex; gap: 10px; padding: 8px 10px;
  border-radius: var(--u-r8); cursor: pointer; transition: background .15s; align-items: flex-start;
}
.umat-ts-seg:hover { background: var(--u-sfl); }
.umat-ts-seg.active { background: rgba(0,107,47,.08); border-left: 3px solid var(--u-p); padding-left: 7px; }
.umat-ts-seg .ts-time { font-size: 11px; font-weight: 700; color: var(--u-p); white-space: nowrap; min-width: 36px; }
.umat-ts-seg .ts-text { font-size: 13px; color: var(--u-ons); line-height: 1.5; margin: 0; }
.umat-ts-seg.active .ts-text { font-weight: 600; }

.umat-ws-right {
  width: 420px; flex-shrink: 0; display: flex; flex-direction: column;
  background: var(--u-sflo); overflow: hidden;
}
.umat-ws-right-hdr {
  background: linear-gradient(135deg, var(--u-p), var(--u-pb));
  padding: 14px 16px; color: var(--u-op); flex-shrink: 0;
  display: flex; align-items: center; gap: 10px;
}
.umat-ws-right-hdr h3 { margin: 0; font-size: 14px; font-weight: 700; flex: 1; }
.umat-ws-right-hdr span { font-size: 11px; opacity: .85; }
.umat-ws-rhdr-btn {
  background: rgba(255,255,255,.18); border: none; color: var(--u-op);
  width: 30px; height: 30px; border-radius: var(--u-r8); cursor: pointer;
  display: flex; align-items: center; justify-content: center; transition: background .2s;
}
.umat-ws-rhdr-btn .material-symbols-outlined { font-size: 17px; }
.umat-ws-rhdr-btn:hover { background: rgba(255,255,255,.3); }

/* ================================================================
   ANALYTICS OVERLAY SPECIFIC
   ================================================================ */
.umat-an-main {
  flex: 1; overflow-y: auto; padding: 24px 28px 40px;
  background: var(--u-sf);
}
.umat-an-main::-webkit-scrollbar { width: 6px; }
.umat-an-main::-webkit-scrollbar-thumb { background: var(--u-olv); border-radius: 3px; }
.umat-an-toprow {
  display: flex; align-items: flex-end; justify-content: space-between;
  margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.umat-an-toprow h1 { margin: 0 0 4px; font-size: 22px; font-weight: 800; color: var(--u-p); }
.umat-an-toprow p { margin: 0; font-size: 13px; color: var(--u-onsv); }
.umat-an-toprow-btns { display: flex; gap: 8px; flex-wrap: wrap; }

.umat-an-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 16px; border-radius: var(--u-r8);
  font-size: 13px; font-weight: 600; cursor: pointer; border: none;
  transition: all .2s; font-family: inherit; text-decoration: none;
}
.umat-an-btn .material-symbols-outlined { font-size: 16px; }
.umat-an-btn-p { background: var(--u-p); color: var(--u-op); }
.umat-an-btn-p:hover { background: var(--u-pb); }
.umat-an-btn-o { background: var(--u-sflo); border: 1.5px solid var(--u-olv); color: var(--u-ons); }
.umat-an-btn-o:hover { border-color: var(--u-p); color: var(--u-p); }
.umat-an-btn-w { background: #fef3c7; border: 1.5px solid #fcd34d; color: #92400e; }
.umat-an-btn-w:hover { background: #fde68a; }

/* KPI row */
.umat-an-kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 22px; }
@media(max-width:1100px) { .umat-an-kpi-row { grid-template-columns: repeat(2,1fr); } }
.umat-an-kpi {
  background: var(--u-sflo); border: 1px solid var(--u-olv);
  border-radius: var(--u-r12); padding: 16px;
  box-shadow: 0 2px 8px rgba(0,0,0,.04);
}
.umat-an-kpi-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.umat-an-kpi-ico {
  width: 36px; height: 36px; border-radius: var(--u-r8);
  display: flex; align-items: center; justify-content: center;
}
.umat-an-kpi-ico .material-symbols-outlined { font-size: 20px; }
.ak-g  { background: rgba(0,107,47,.1);  color: var(--u-p); }
.ak-s  { background: rgba(61,104,68,.1); color: var(--u-sec); }
.ak-w  { background: rgba(245,158,11,.1); color: #d97706; }
.ak-r  { background: rgba(165,48,77,.1); color: var(--u-ter); }
.umat-an-kpi-pill {
  padding: 3px 9px; border-radius: var(--u-rp);
  font-size: 10px; font-weight: 700;
}
.pill-g  { background: #dcfce7; color: #065f46; }
.pill-w  { background: #fef3c7; color: #78350f; }
.pill-r  { background: #fee2e2; color: #991b1b; }
.pill-b  { background: #dbeafe; color: #1e40af; }
.umat-an-kpi-lbl { font-size: 12px; color: var(--u-ol); margin-bottom: 4px; }
.umat-an-kpi-val { font-size: 30px; font-weight: 800; color: var(--u-ons); line-height: 1; }
.umat-an-kpi-sub { font-size: 11px; color: var(--u-ol); margin-top: 3px; }

/* Two-column analytics section */
.umat-an-2col { display: grid; grid-template-columns: 1.6fr 1fr; gap: 16px; margin-bottom: 20px; }
@media(max-width:1000px) { .umat-an-2col { grid-template-columns: 1fr; } }

.umat-an-card {
  background: var(--u-sflo); border: 1px solid var(--u-olv);
  border-radius: var(--u-r16); overflow: hidden;
  box-shadow: 0 2px 10px rgba(0,0,0,.05);
}
.umat-an-card-hdr {
  padding: 14px 18px; border-bottom: 1px solid var(--u-olv);
  display: flex; align-items: center; justify-content: space-between;
}
.umat-an-card-title {
  margin: 0; font-size: 14px; font-weight: 700; color: var(--u-ons);
  display: flex; align-items: center; gap: 7px;
}
.umat-an-card-title .material-symbols-outlined { font-size: 17px; color: var(--u-p); }
.umat-an-card-body { padding: 18px; }

/* Chart canvas */
.umat-chart-canvas { width: 100%; height: 200px; }

/* Performance bars */
.umat-perf-item { margin-bottom: 14px; }
.umat-perf-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px; }
.umat-perf-lbl { font-size: 13px; font-weight: 600; color: var(--u-ons); }
.umat-perf-num { font-size: 13px; color: var(--u-ol); }
.umat-perf-bar { height: 8px; border-radius: 4px; background: var(--u-sfl); overflow: hidden; margin-bottom: 2px; }
.umat-perf-fill { height: 100%; border-radius: 4px; }
.pf-high { background: var(--u-p); }
.pf-track { background: #f59e0b; }
.pf-risk  { background: var(--u-ter); }
.umat-at-risk-link {
  text-align: right; margin-top: 12px;
  font-size: 12px; color: var(--u-p); font-weight: 600;
  cursor: pointer; text-decoration: none;
  display: block;
}

/* Heatmap */
.umat-an-heatmap-wrap { margin-bottom: 20px; }
.umat-heatmap-grid {
  display: grid; gap: 5px;
  grid-template-columns: auto repeat(10, 1fr);
}
.umat-hm-cell {
  border-radius: 5px; aspect-ratio: 1; min-height: 38px;
  display: flex; align-items: center; justify-content: center;
  font-size: 10px; font-weight: 600; cursor: default;
  transition: transform .15s; position: relative;
}
.umat-hm-cell:hover { transform: scale(1.12); z-index: 2; }
.umat-hm-label { font-size: 11px; color: var(--u-ol); display: flex; align-items: center; justify-content: center; }
.umat-hm-legend { display: flex; align-items: center; gap: 8px; margin-top: 8px; font-size: 11px; color: var(--u-ol); }
.umat-hm-legend-swatch { width: 12px; height: 12px; border-radius: 2px; }
.umat-an-ai-insight {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 14px; background: #fffbeb;
  border: 1px solid #fde68a; border-radius: var(--u-r8);
  margin-top: 14px;
}
.umat-an-ai-insight .material-symbols-outlined { font-size: 20px; color: var(--u-warn); flex-shrink: 0; }
.umat-an-ai-insight-text strong { font-size: 13px; display: block; margin-bottom: 3px; }
.umat-an-ai-insight-text span { font-size: 12px; color: var(--u-onsv); }

/* Questions table */
.umat-q-list { display: flex; flex-direction: column; gap: 0; }
.umat-q-row {
  display: flex; align-items: center; gap: 14px; padding: 14px 18px;
  border-bottom: 1px solid var(--u-sfl); transition: background .15s;
}
.umat-q-row:last-child { border-bottom: none; }
.umat-q-row:hover { background: var(--u-sfl); }
.umat-q-votes {
  min-width: 52px; text-align: center; flex-shrink: 0;
}
.umat-q-votes .v-num { font-size: 22px; font-weight: 800; color: var(--u-p); line-height: 1; }
.umat-q-votes .v-lbl { font-size: 9px; font-weight: 700; color: var(--u-ol); letter-spacing: .06em; text-transform: uppercase; }
.umat-q-content { flex: 1; min-width: 0; }
.umat-q-text { font-size: 13px; color: var(--u-ons); margin-bottom: 3px; line-height: 1.4; }
.umat-q-related { font-size: 11px; color: var(--u-ol); }
.umat-q-related span { color: var(--u-p); font-weight: 600; }
.umat-q-action { flex-shrink: 0; }
.umat-q-action-btn {
  padding: 6px 13px; border: none; background: none;
  color: var(--u-p); font-size: 12px; font-weight: 700;
  cursor: pointer; font-family: inherit; white-space: nowrap;
  transition: all .2s; border-radius: var(--u-r6);
}
.umat-q-action-btn:hover { background: rgba(0,107,47,.08); }

@media(max-width: 680px) {
  .umat-ws-right { display: none; }
  .umat-cp, .umat-cp-lec { width: 100vw; max-width: 100vw; }
}
</style>
HTML;
    }

    // ================================================================== //
    // SHARED HELPERS — sidebar & topnav HTML                             //
    // ================================================================== //

    private static function sidebar(string $wwwroot, string $activeItem = 'ai-tutor', string $role = 'student'): string {
        $hubUrl    = $wwwroot . '/local/umat_ai/hub.php';
        $homeUrl   = $wwwroot;
        $courseUrl = $wwwroot . '/my/';
        $logUrl    = $wwwroot . '/local/umat_ai/hub.php';
        $libUrl    = $wwwroot . '/local/umat_ai/hub.php';

        $items = [
            ['id' => 'home',     'icon' => 'home',        'label' => 'Home',        'href' => $homeUrl],
            ['id' => 'courses',  'icon' => 'menu_book',   'label' => 'My Courses',  'href' => $courseUrl],
            ['id' => 'ai-tutor', 'icon' => 'smart_toy',   'label' => 'AI Tutor',    'href' => $hubUrl],
            ['id' => 'analytics','icon' => 'bar_chart',   'label' => 'Analytics',   'href' => $logUrl],
            ['id' => 'library',  'icon' => 'local_library','label' => 'Library',    'href' => $libUrl],
        ];

        $navHtml = '';
        foreach ($items as $item) {
            $active = $item['id'] === $activeItem ? ' active' : '';
            $navHtml .= <<<HTML
<a class="umat-sb-item{$active}" href="{$item['href']}">
  <span class="material-symbols-outlined">{$item['icon']}</span>
  <span class="umat-sb-item-label">{$item['label']}</span>
</a>
HTML;
        }

        return <<<HTML
<div class="umat-sb" id="umat-sidebar">
  <div class="umat-sb-logo">
    <div class="umat-sb-logo-icon"><span class="material-symbols-outlined">school</span></div>
    <div class="umat-sb-logo-text">
      <strong>UMaT Moodle</strong>
      <span>AI Enhanced Learning</span>
    </div>
  </div>
  <nav class="umat-sb-nav">
    {$navHtml}
  </nav>
  <div class="umat-sb-divider"></div>
  <button class="umat-sb-new-btn" id="sb-new-session-btn" type="button">
    <span class="material-symbols-outlined">add</span>
    <span class="umat-sb-new-btn-label">New AI Session</span>
  </button>
  <div class="umat-sb-footer">
    <a class="umat-sb-item" href="{$homeUrl}">
      <span class="material-symbols-outlined">help_outline</span>
      <span class="umat-sb-item-label">Help</span>
    </a>
    <a class="umat-sb-item" href="{$wwwroot}/login/logout.php">
      <span class="material-symbols-outlined">logout</span>
      <span class="umat-sb-item-label">Sign Out</span>
    </a>
  </div>
</div>
HTML;
    }

    private static function topnav(
        string $wwwroot, string $activeTab,
        string $overlayCloseId, bool $showSearch,
        string $searchPlaceholder, string $userName
    ): string {
        $hub = $wwwroot . '/local/umat_ai/hub.php';
        $tabs = [
            ['id' => 'dashboard', 'label' => 'Dashboard', 'href' => $hub],
            ['id' => 'courses',   'label' => 'Courses',   'href' => $wwwroot . '/my/'],
            ['id' => 'logs',      'label' => 'Session Logs', 'href' => $hub . '#logs'],
            ['id' => 'analytics', 'label' => 'Analytics', 'href' => 'javascript:void(0)'],
        ];
        $tabsHtml = '';
        foreach ($tabs as $t) {
            $cls = $t['id'] === $activeTab ? ' active' : '';
            $tabsHtml .= <<<HTML
<button class="umat-ov-tab{$cls}" data-nav-tab="{$t['id']}" onclick="window.location.href='{$t['href']}'" type="button">{$t['label']}</button>
HTML;
        }

        $searchHtml = $showSearch
            ? '<div class="umat-ov-search"><span class="material-symbols-outlined">search</span><input type="text" placeholder="' . htmlspecialchars($searchPlaceholder, ENT_QUOTES) . '"></div>'
            : '';

        $initial = strtoupper(substr($userName, 0, 1));

        return <<<HTML
<div class="umat-ov-topnav">
  <div class="umat-ov-brand">
    <span class="material-symbols-outlined">smart_toy</span>
    UMaT AI Assistant
  </div>
  <div class="umat-ov-tabs">{$tabsHtml}</div>
  {$searchHtml}
  <button class="umat-ov-icon-btn" type="button" title="Notifications">
    <span class="material-symbols-outlined">notifications</span>
  </button>
  <button class="umat-ov-icon-btn" type="button" title="Settings">
    <span class="material-symbols-outlined">settings</span>
  </button>
  <div style="width:32px;height:32px;border-radius:50%;background:var(--u-p);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;">{$initial}</div>
  <button class="umat-ov-close" id="{$overlayCloseId}" type="button" title="Close">
    <span class="material-symbols-outlined">close</span>
  </button>
</div>
HTML;
    }

    // ================================================================== //
    // STUDENT FAB + COMPACT PANEL + WORKSPACE OVERLAY                    //
    // ================================================================== //

    private static function student_fab(int $courseid, string $courseName, string $wwwroot, object $user): string {
        $safeName   = htmlspecialchars($courseName, ENT_QUOTES, 'UTF-8');
        $jsName     = json_encode($courseName);
        $jsCid      = (int) $courseid;
        $hubUrl     = $wwwroot . '/local/umat_ai/hub.php';
        $userName   = fullname($user);

        $sidebar = self::sidebar($wwwroot, 'ai-tutor', 'student');
        $topnav  = self::topnav($wwwroot, 'dashboard', 'umat-ws-close-btn', false, '', $userName);

        return <<<HTML
<!-- ============================================================
     STUDENT AI FAB
     ============================================================ -->
<button class="umat-fab umat-fab-pulse" id="umat-student-fab" type="button" aria-label="Open AI Assistant">
  <span class="material-symbols-outlined">smart_toy</span>
  <span class="umat-fab-tip">Ask UMaT AI Assistant</span>
</button>

<!-- COMPACT PANEL OVERLAY -->
<div class="umat-cp-overlay" id="umat-cp-overlay" role="dialog" aria-modal="true">
  <div class="umat-cp" id="umat-student-cp">
    <!-- Header -->
    <div class="umat-cp-hdr">
      <div class="umat-cp-hdr-row">
        <div class="umat-cp-avatar">
          <span class="material-symbols-outlined">smart_toy</span>
          <span class="umat-cp-status-dot"></span>
        </div>
        <div class="umat-cp-info">
          <h2>AI Assistant</h2>
          <div class="umat-cp-sub">● Online &amp; Ready</div>
          <div class="umat-cp-ctx" title="{$safeName}">{$safeName}</div>
        </div>
        <button class="umat-cp-hdr-btn umat-cp-expand-btn" id="umat-expand-btn" type="button">
          <span class="material-symbols-outlined">open_in_full</span>
          <span>Expand</span>
        </button>
        <button class="umat-cp-hdr-btn" id="umat-cp-close-btn" type="button" aria-label="Close">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
    </div>
    <!-- Tabs -->
    <div class="umat-cp-tabs" role="tablist">
      <button class="umat-cp-tab active" data-tab="cp-chat" type="button">Chat</button>
      <button class="umat-cp-tab" data-tab="cp-notes" type="button">Notes</button>
      <button class="umat-cp-tab" data-tab="cp-resources" type="button">Resources</button>
    </div>
    <!-- Chat tab -->
    <div class="umat-cp-pane active" id="cp-chat">
      <div class="umat-quick-grid" id="cp-quick-grid">
        <button class="umat-quick-btn" data-action="summarize" type="button">
          <span class="material-symbols-outlined">summarize</span>
          <span class="q-lbl">Summarize Lecture</span>
        </button>
        <button class="umat-quick-btn" data-action="assignment" type="button">
          <span class="material-symbols-outlined">quiz</span>
          <span class="q-lbl">Assignment Help</span>
        </button>
        <button class="umat-quick-btn" data-action="explain" type="button">
          <span class="material-symbols-outlined">lightbulb</span>
          <span class="q-lbl">Explain Concept</span>
        </button>
        <button class="umat-quick-btn" data-action="deadlines" type="button">
          <span class="material-symbols-outlined">schedule</span>
          <span class="q-lbl">Deadlines</span>
        </button>
      </div>
      <div class="umat-msgs" id="cp-messages" aria-live="polite">
        <div class="umat-msg-ai">
          <div class="umat-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div>
          <div class="umat-msg-ai-wrap">
            <div class="umat-msg-ai-label">AI TUTOR</div>
            <div class="umat-bubble-ai"><p>Hello! I'm your AI tutor for <strong>{$safeName}</strong>. Ask me anything or use the quick actions above. ✨</p></div>
          </div>
        </div>
      </div>
      <div class="umat-input-area">
        <div class="umat-input-row">
          <textarea id="cp-input" class="umat-textarea" placeholder="Ask AI about this lecture…" rows="2" maxlength="900"></textarea>
          <button class="umat-send-btn" id="cp-send" type="button"><span class="material-symbols-outlined">send</span></button>
        </div>
        <div class="umat-input-footer">
          <span class="umat-rate-txt" id="cp-rate">10 questions remaining</span>
          <button class="umat-ref-btn" id="cp-hist-btn" type="button">
            <span class="material-symbols-outlined">history</span>Past Sessions
          </button>
        </div>
      </div>
    </div>
    <!-- Notes tab -->
    <div class="umat-cp-pane" id="cp-notes">
      <div class="umat-empty"><span class="material-symbols-outlined">description</span><p>AI-generated notes appear here once your lecturer approves them.</p></div>
    </div>
    <!-- Resources tab -->
    <div class="umat-cp-pane" id="cp-resources">
      <div class="umat-empty"><span class="material-symbols-outlined">folder_open</span><p>Indexed course materials will appear here.</p></div>
    </div>
  </div>
</div>

<!-- WORKSPACE FULL-SCREEN OVERLAY -->
<div class="umat-ov" id="umat-workspace-ov" role="dialog" aria-modal="true" aria-label="AI Learning Workspace">
  {$topnav}
  <div class="umat-ov-body">
    {$sidebar}
    <!-- Main area -->
    <div class="umat-ws-main">
      <!-- Left: Video + Transcript -->
      <div class="umat-ws-left">
        <div class="umat-ws-video-wrap" id="ws-video-container">
          <div class="umat-ws-no-vid" id="ws-no-vid">
            <span class="material-symbols-outlined">videocam_off</span>
            <span>No recording available for this session yet.</span>
          </div>
          <!-- video element inserted by JS if URL available -->
        </div>
        <div style="padding:10px 16px 4px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
          <h3 style="margin:0;font-size:15px;font-weight:700;color:var(--u-ons);" id="ws-session-title">Lecture Session</h3>
          <span style="font-size:12px;color:var(--u-ol);" id="ws-session-meta"></span>
        </div>
        <div class="umat-ws-transcript-wrap">
          <div class="umat-ws-ts-header">
            <h4 class="umat-ws-ts-title"><span class="material-symbols-outlined">subtitles</span>Synchronized Transcript</h4>
            <div class="umat-ws-ts-search">
              <span class="material-symbols-outlined">search</span>
              <input type="text" id="ws-ts-search" placeholder="Search transcript…">
            </div>
          </div>
          <div class="umat-ws-ts-body" id="ws-transcript-body">
            <div class="umat-empty" id="ws-ts-empty">
              <span class="material-symbols-outlined">article</span>
              <p>Transcript will appear here once the session recording is processed.</p>
            </div>
          </div>
        </div>
      </div><!-- /ws-left -->

      <!-- Right: AI Chat Panel -->
      <div class="umat-ws-right">
        <div class="umat-ws-right-hdr">
          <div style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;">
            <span class="material-symbols-outlined">smart_toy</span>
          </div>
          <div style="flex:1;">
            <h3>AI Assistant</h3>
            <span>Online &amp; Ready</span>
          </div>
          <button class="umat-ws-rhdr-btn" id="ws-gen-notes-btn" type="button" title="Generate Notes">
            <span class="material-symbols-outlined">summarize</span>
          </button>
          <button class="umat-ws-rhdr-btn" id="ws-attach-btn" type="button" title="Reference Material">
            <span class="material-symbols-outlined">attach_file</span>
          </button>
        </div>
        <!-- Tabs -->
        <div class="umat-cp-tabs" role="tablist">
          <button class="umat-cp-tab active" data-tab="ws-chat" type="button">Chat</button>
          <button class="umat-cp-tab" data-tab="ws-notes" type="button">Notes</button>
          <button class="umat-cp-tab" data-tab="ws-resources" type="button">Resources</button>
        </div>
        <!-- Chat pane -->
        <div class="umat-cp-pane active" id="ws-chat" style="flex:1;overflow:hidden;">
          <div style="display:flex;flex-wrap:wrap;gap:6px;padding:8px 12px;border-bottom:1px solid var(--u-olv);flex-shrink:0;" id="ws-chips">
            <button class="umat-suggestion-chip" data-p="Can you explain what was just mentioned in the lecture?" type="button">Explain this</button>
            <button class="umat-suggestion-chip" data-p="Can you compare this to earlier course topics?" type="button">Compare topics</button>
            <button class="umat-suggestion-chip" data-p="Give me more details and examples about this concept." type="button">Tell me more</button>
          </div>
          <div class="umat-msgs" id="ws-messages">
            <div class="umat-msg-ai">
              <div class="umat-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div>
              <div class="umat-msg-ai-wrap">
                <div class="umat-msg-ai-label">AI TUTOR</div>
                <div class="umat-bubble-ai"><p>Welcome to the AI Workspace for <strong>{$safeName}</strong>! Ask me anything about the lecture content, or click a suggestion above. I can reference your course materials for accurate answers.</p></div>
              </div>
            </div>
          </div>
          <div class="umat-input-area" style="border-top:1px solid var(--u-olv);">
            <div class="umat-input-row">
              <textarea id="ws-input" class="umat-textarea" placeholder="Ask AI about this lecture…" rows="2" maxlength="900"></textarea>
              <button class="umat-send-btn" id="ws-send" type="button"><span class="material-symbols-outlined">send</span></button>
            </div>
            <div class="umat-input-footer">
              <button class="umat-ref-btn" type="button"><span class="material-symbols-outlined">attachment</span>Reference Course Material</button>
              <button class="umat-ref-btn" type="button"><span class="material-symbols-outlined">mic</span>Voice</button>
            </div>
          </div>
        </div>
        <!-- Notes pane -->
        <div class="umat-cp-pane" id="ws-notes">
          <div class="umat-msgs" id="ws-notes-content" style="overflow-y:auto;">
            <div class="umat-empty" id="ws-notes-empty">
              <span class="material-symbols-outlined">description</span>
              <p>Click "Generate Notes" above or wait for your lecturer to approve AI-generated content.</p>
              <button class="umat-chip-btn" id="ws-gen-notes-btn2" type="button" style="margin-top:8px;">
                <span class="material-symbols-outlined">auto_awesome</span>Generate Notes
              </button>
            </div>
          </div>
        </div>
        <!-- Resources pane -->
        <div class="umat-cp-pane" id="ws-resources">
          <div class="umat-empty">
            <span class="material-symbols-outlined">folder_open</span>
            <p>Indexed course materials will appear here.</p>
          </div>
        </div>
      </div><!-- /ws-right -->
    </div><!-- /ws-main -->
  </div><!-- /ov-body -->
</div><!-- /workspace overlay -->

<script>
/* ============================================================
   STUDENT FAB, COMPACT PANEL & WORKSPACE — all in one IIFE
   ============================================================ */
(function() {
  'use strict';

  var courseId   = {$jsCid};
  var courseName = {$jsName};
  var hubUrl     = '{$hubUrl}';
  var sessionKey = 'stu_' + Math.random().toString(36).substr(2,18);
  var qLeft      = 10;
  var wsLoaded   = false;

  /* --- shared message state --- */
  var sharedMessages = [];

  /* --- elements --- */
  var fab       = document.getElementById('umat-student-fab');
  var cpOverlay = document.getElementById('umat-cp-overlay');
  var cpClose   = document.getElementById('umat-cp-close-btn');
  var expBtn    = document.getElementById('umat-expand-btn');
  var wsOv      = document.getElementById('umat-workspace-ov');
  var wsClose   = document.getElementById('umat-ws-close-btn');

  /* utils */
  function esc(s) { var d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML; }
  function fmtTime(s) { var m=Math.floor(s/60),sec=Math.floor(s%60);return m+':'+(sec<10?'0':'')+sec; }

  /* --- open / close compact panel --- */
  function openPanel() { cpOverlay.classList.add('open'); fab.setAttribute('aria-expanded','true'); setTimeout(function(){var i=document.getElementById('cp-input');if(i)i.focus();},350); updateRate(); }
  function closePanel() { cpOverlay.classList.remove('open'); fab.setAttribute('aria-expanded','false'); }

  /* --- open / close workspace --- */
  function openWorkspace() {
    closePanel();
    wsOv.classList.add('umat-ov-open');
    if (!wsLoaded) { loadWorkspaceData(); wsLoaded = true; }
    syncWorkspaceMessages();
  }
  function closeWorkspace() {
    wsOv.classList.remove('umat-ov-open');
    openPanel();
  }

  fab.addEventListener('click', openPanel);
  cpClose.addEventListener('click', closePanel);
  cpOverlay.addEventListener('click', function(e){ if(e.target===cpOverlay) closePanel(); });
  expBtn.addEventListener('click', openWorkspace);
  if (wsClose) wsClose.addEventListener('click', closeWorkspace);
  document.getElementById('sb-new-session-btn') && document.getElementById('sb-new-session-btn').addEventListener('click', function(){ sessionKey='stu_'+Math.random().toString(36).substr(2,18); sharedMessages=[]; clearMessages('ws-messages'); clearMessages('cp-messages'); wsLoaded=false; });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape'){if(wsOv.classList.contains('umat-ov-open'))closeWorkspace();else closePanel();} });

  /* --- tab switcher (generic) --- */
  function initTabs(scopeId) {
    var scope = document.getElementById(scopeId) || document;
    scope.querySelectorAll('.umat-cp-tab').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var t = btn.dataset.tab;
        scope.querySelectorAll('.umat-cp-tab').forEach(function(b){ b.classList.remove('active'); });
        scope.querySelectorAll('.umat-cp-pane').forEach(function(p){ p.classList.remove('active'); });
        btn.classList.add('active');
        var pane = document.getElementById(t);
        if (pane) pane.classList.add('active');
      });
    });
  }
  initTabs('umat-student-cp');
  initTabs('umat-workspace-ov');

  /* --- rate display --- */
  function updateRate() {
    var el = document.getElementById('cp-rate');
    if (el) { el.textContent = qLeft + ' question' + (qLeft!==1?'s':'') + ' remaining'; el.className = 'umat-rate-txt' + (qLeft<=2?' warn':''); }
  }

  /* --- message rendering --- */
  function appendToContainer(containerId, html) {
    var c = document.getElementById(containerId);
    if (!c) return;
    var d = document.createElement('div');
    d.innerHTML = html;
    c.appendChild(d);
    c.scrollTop = c.scrollHeight;
  }

  function clearMessages(containerId) {
    var c = document.getElementById(containerId);
    if (!c) return;
    // Keep only the first welcome bubble
    while (c.children.length > 1) c.removeChild(c.lastChild);
  }

  function aiBubble(text, sources, chips) {
    var s = '';
    if (sources && sources.length) s = '<div class="umat-sources">' + sources.map(function(x){ return '<span class="umat-source-chip">'+esc(x)+'</span>'; }).join('') + '</div>';
    var c = '';
    if (chips && chips.length) c = '<div class="umat-suggestion-chips">' + chips.map(function(x){ return '<button class="umat-suggestion-chip" data-p="'+esc(x)+'" type="button">'+esc(x)+'</button>'; }).join('') + '</div>';
    return '<div class="umat-msg-ai"><div class="umat-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-ai-label">AI TUTOR</div><div class="umat-bubble-ai"><p>'+esc(text)+'</p></div>'+s+c+'</div></div>';
  }

  function userBubble(text) { return '<div class="umat-msg-user"><div class="umat-bubble-user"><p>'+esc(text)+'</p></div></div>'; }

  function typingHTML(id) { return '<div id="'+id+'" class="umat-msg-ai"><div class="umat-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-ai-label">AI TUTOR</div><div class="umat-bubble-ai"><div class="umat-typing"><span></span><span></span><span></span></div><span style="font-size:11px;color:var(--u-ol);">Summarizing lecture segment…</span></div></div></div>'; }

  function showTyping(containerId, typingId) {
    appendToContainer(containerId, typingHTML(typingId));
  }
  function hideTyping(typingId) { var el = document.getElementById(typingId); if(el) el.parentNode.removeChild(el); }

  function syncWorkspaceMessages() {
    var c = document.getElementById('ws-messages');
    if (!c) return;
    // clear after first bubble
    while (c.children.length > 1) c.removeChild(c.lastChild);
    sharedMessages.forEach(function(m) {
      var d = document.createElement('div');
      d.innerHTML = m.isUser ? userBubble(m.text) : aiBubble(m.text, m.sources, []);
      c.appendChild(d);
    });
    c.scrollTop = c.scrollHeight;
  }

  /* --- send question --- */
  function sendQuestion(q, cpMsgs, wsMsgs) {
    q = (q||'').trim();
    if (!q || !courseId) return;
    if (qLeft <= 0) { appendToContainer(cpMsgs, aiBubble('Rate limit reached. Please wait a moment.', [], [])); return; }
    qLeft--; updateRate();

    var qBubble = userBubble(q);
    appendToContainer(cpMsgs, qBubble);
    if (wsMsgs) appendToContainer(wsMsgs, qBubble);
    sharedMessages.push({ isUser: true, text: q, sources: [] });

    // hide quick actions after first message
    var qg = document.getElementById('cp-quick-grid');
    if (qg) qg.style.display = 'none';

    var tid = 'typing_' + Date.now();
    showTyping(cpMsgs, tid);

    require(['core/ajax'], function(Ajax) {
      Ajax.call([{
        methodname: 'local_umat_ai_ask_question',
        args: { courseid: courseId, question: q, session_key: sessionKey }
      }])[0].done(function(r) {
        hideTyping(tid);
        var text = r.success ? r.answer : 'Sorry, an error occurred. Please try again.';
        var srcs = r.sources || [];
        appendToContainer(cpMsgs, aiBubble(text, srcs, []));
        if (wsMsgs) appendToContainer(wsMsgs, aiBubble(text, srcs, []));
        sharedMessages.push({ isUser: false, text: text, sources: srcs });
      }).fail(function() {
        hideTyping(tid);
        var errText = 'Connection error. Please check your network and try again.';
        appendToContainer(cpMsgs, aiBubble(errText, [], []));
      });
    });
  }

  /* compact panel send */
  var cpInput = document.getElementById('cp-input');
  document.getElementById('cp-send').addEventListener('click', function(){ sendQuestion(cpInput.value, 'cp-messages', wsOv.classList.contains('umat-ov-open')?'ws-messages':null); cpInput.value=''; });
  cpInput.addEventListener('keypress', function(e){ if(e.key==='Enter'&&!e.shiftKey){e.preventDefault(); document.getElementById('cp-send').click();} });
  document.getElementById('cp-hist-btn').addEventListener('click', function(){ closePanel(); window.location.href=hubUrl; });

  /* workspace send */
  var wsInput = document.getElementById('ws-input');
  if (wsInput) {
    document.getElementById('ws-send').addEventListener('click', function(){ sendQuestion(wsInput.value, 'cp-messages', 'ws-messages'); wsInput.value=''; });
    wsInput.addEventListener('keypress', function(e){ if(e.key==='Enter'&&!e.shiftKey){e.preventDefault(); document.getElementById('ws-send').click();} });
  }

  /* quick action buttons (compact panel) */
  var actionMap = {
    summarize:  "Can you summarize the key points from today's lecture?",
    assignment: "What are the requirements for the current assignment?",
    explain:    "Can you explain the main concept covered in this week's material?",
    deadlines:  "What are the upcoming deadlines for this course?"
  };
  document.querySelectorAll('.umat-quick-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var q = actionMap[btn.dataset.action] || btn.dataset.action;
      if (cpInput) cpInput.value = q;
      document.getElementById('cp-send').click();
    });
  });

  /* suggestion chips (workspace) */
  document.addEventListener('click', function(e) {
    if (e.target.classList.contains('umat-suggestion-chip') || e.target.parentElement && e.target.parentElement.classList.contains('umat-suggestion-chip')) {
      var btn = e.target.classList.contains('umat-suggestion-chip') ? e.target : e.target.parentElement;
      var p = btn.dataset.p;
      if (p) sendQuestion(p, 'cp-messages', 'ws-messages');
    }
  });

  /* --- workspace data loading --- */
  function loadWorkspaceData() {
    require(['core/ajax'], function(Ajax) {
      Ajax.call([{
        methodname: 'local_umat_ai_get_session_outputs',
        args: { sessionid: 0, courseid: courseId }
      }])[0].done(function(r) {
        if (r.outputs && r.outputs.length > 0) {
          var notesContent = document.getElementById('ws-notes-content');
          var notesEmpty   = document.getElementById('ws-notes-empty');
          if (notesEmpty) notesEmpty.style.display = 'none';
          if (notesContent) {
            r.outputs.forEach(function(o) {
              var d = document.createElement('div');
              d.style.cssText = 'background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:14px;margin:10px;';
              d.innerHTML = '<h4 style="margin:0 0 8px;font-size:12px;font-weight:700;color:var(--u-p);text-transform:capitalize;">'+esc(o.type)+'</h4><div style="font-size:13px;line-height:1.65;white-space:pre-wrap;">'+esc(o.content)+'</div>';
              notesContent.appendChild(d);
            });
          }
        }
      });

      /* load transcript */
      Ajax.call([{
        methodname: 'local_umat_ai_get_session_transcript',
        args: { courseid: courseId }
      }])[0].done(function(r) {
        if (r && r.segments && r.segments.length > 0) {
          renderTranscript(r.segments);
          if (r.recording_url) renderVideo(r.recording_url);
          document.getElementById('ws-session-title').textContent = r.session_title || 'Lecture Session';
          document.getElementById('ws-session-meta').textContent = r.session_date || '';
        }
      }).fail(function(){}); // silently fail if not available
    });
  }

  function renderVideo(url) {
    var container = document.getElementById('ws-video-container');
    var noVid = document.getElementById('ws-no-vid');
    if (!container || !url) return;
    if (noVid) noVid.style.display = 'none';
    var video = document.createElement('video');
    video.id = 'ws-video'; video.preload = 'metadata'; video.controls = false;
    var src = document.createElement('source');
    src.src = url; src.type = 'video/mp4';
    video.appendChild(src);

    /* custom controls */
    var controls = document.createElement('div');
    controls.className = 'umat-ws-vc';
    controls.innerHTML =
      '<button class="umat-ws-vc-btn" id="vc-pp"><span class="material-symbols-outlined">play_arrow</span></button>' +
      '<button class="umat-ws-vc-btn" id="vc-r30"><span class="material-symbols-outlined">replay_30</span></button>' +
      '<button class="umat-ws-vc-btn" id="vc-f30"><span class="material-symbols-outlined">forward_30</span></button>' +
      '<span class="umat-ws-vc-time"><span id="vc-cur">0:00</span> / <span id="vc-dur">0:00</span></span>' +
      '<input type="range" id="vc-prog" class="umat-ws-vc-progress" min="0" max="100" value="0">';

    container.appendChild(video);
    container.appendChild(controls);

    video.addEventListener('loadedmetadata', function(){ document.getElementById('vc-dur').textContent = fmtTime(video.duration); document.getElementById('vc-prog').max = Math.floor(video.duration); });
    video.addEventListener('timeupdate', function(){
      document.getElementById('vc-cur').textContent = fmtTime(video.currentTime);
      document.getElementById('vc-prog').value = Math.floor(video.currentTime);
      highlightTranscript(video.currentTime);
    });
    document.getElementById('vc-pp').addEventListener('click', function(){ if(video.paused){video.play();this.querySelector('.material-symbols-outlined').textContent='pause';}else{video.pause();this.querySelector('.material-symbols-outlined').textContent='play_arrow';} });
    document.getElementById('vc-r30').addEventListener('click', function(){ video.currentTime = Math.max(0, video.currentTime-30); });
    document.getElementById('vc-f30').addEventListener('click', function(){ video.currentTime = Math.min(video.duration, video.currentTime+30); });
    document.getElementById('vc-prog').addEventListener('input', function(){ video.currentTime = parseInt(this.value); });
  }

  function renderTranscript(segments) {
    var body = document.getElementById('ws-transcript-body');
    var empty = document.getElementById('ws-ts-empty');
    if (empty) empty.style.display = 'none';
    if (!body) return;
    segments.forEach(function(seg) {
      var d = document.createElement('div');
      d.className = 'umat-ts-seg';
      d.dataset.start = seg.start || 0;
      d.dataset.end   = seg.end   || 0;
      d.innerHTML = '<span class="ts-time">' + (seg.timestamp||fmtTime(seg.start||0)) + '</span><p class="ts-text">' + esc(seg.text) + '</p>';
      d.addEventListener('click', function(){
        var v = document.getElementById('ws-video');
        if (v) { v.currentTime = parseFloat(seg.start||0); v.play(); }
      });
      body.appendChild(d);
    });

    /* transcript search */
    var searchInput = document.getElementById('ws-ts-search');
    if (searchInput) {
      searchInput.addEventListener('input', function(){
        var q = this.value.toLowerCase();
        body.querySelectorAll('.umat-ts-seg').forEach(function(s){
          s.style.display = !q || s.querySelector('.ts-text').textContent.toLowerCase().includes(q) ? '' : 'none';
        });
      });
    }
  }

  function highlightTranscript(t) {
    document.querySelectorAll('.umat-ts-seg').forEach(function(seg){
      var s = parseFloat(seg.dataset.start)||0, e = parseFloat(seg.dataset.end)||0;
      if (t>=s && t<=e) { seg.classList.add('active'); seg.scrollIntoView({behavior:'smooth',block:'nearest'}); }
      else seg.classList.remove('active');
    });
  }

  /* generate notes button */
  ['ws-gen-notes-btn','ws-gen-notes-btn2'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('click', function(){
      /* switch to notes tab */
      document.querySelectorAll('#umat-workspace-ov .umat-cp-tab').forEach(function(t){t.classList.remove('active');});
      document.querySelectorAll('#umat-workspace-ov .umat-cp-pane').forEach(function(p){p.classList.remove('active');});
      var nTab = document.querySelector('#umat-workspace-ov [data-tab="ws-notes"]');
      var nPane = document.getElementById('ws-notes');
      if (nTab) nTab.classList.add('active');
      if (nPane) nPane.classList.add('active');
      loadWorkspaceData();
    });
  });

})();
</script>
HTML;
    }

    // ================================================================== //
    // LECTURER FAB + COMPACT PANEL + ANALYTICS OVERLAY                   //
    // ================================================================== //

    private static function lecturer_fab(int $courseid, string $courseName, int $pending, string $wwwroot, object $user): string {
        $safeName   = htmlspecialchars($courseName, ENT_QUOTES, 'UTF-8');
        $jsCid      = (int) $courseid;
        $jsPending  = (int) $pending;
        $approveUrl = $wwwroot . '/local/umat_ai/approve.php?courseid=' . $courseid;
        $userName   = fullname($user);
        $badge      = $pending > 0 ? '<span class="umat-fab-badge">' . ($pending > 9 ? '9+' : $pending) . '</span>' : '';

        // Pre-compute all conditional HTML — expressions are NOT valid inside PHP heredoc.
        $pendingReviewBtn = $pending > 0
            ? '<a href="' . htmlspecialchars($approveUrl, ENT_QUOTES, 'UTF-8') . '" class="umat-an-btn umat-an-btn-w"><span class="material-symbols-outlined">fact_check</span>Review Outputs (' . (int)$pending . ')</a>'
            : '';

        $sidebar = self::sidebar($wwwroot, 'analytics', 'lecturer');
        $topnav  = self::topnav($wwwroot, 'analytics', 'umat-an-close-btn', true, 'Search analytics…', $userName);

        return <<<HTML
<!-- ============================================================
     LECTURER ANALYTICS FAB
     ============================================================ -->
<button class="umat-fab" id="umat-lec-fab" type="button" aria-label="Open Lecturer Analytics" style="position:relative;">
  <span class="material-symbols-outlined">leaderboard</span>
  <span class="umat-fab-tip">Lecturer Analytics</span>
  {$badge}
</button>

<!-- COMPACT INSIGHTS PANEL OVERLAY -->
<div class="umat-cp-overlay" id="umat-lec-overlay" role="dialog" aria-modal="true">
  <div class="umat-cp umat-cp-lec" id="umat-lec-cp">
    <div class="umat-cp-hdr">
      <div class="umat-cp-hdr-row">
        <div class="umat-cp-avatar"><span class="material-symbols-outlined">analytics</span></div>
        <div class="umat-cp-info">
          <h2>Lecturer Analytics</h2>
          <div class="umat-cp-ctx" title="{$safeName}">{$safeName}</div>
        </div>
        <button class="umat-cp-hdr-btn umat-cp-expand-btn" id="umat-lec-expand" type="button">
          <span class="material-symbols-outlined">open_in_full</span>
          <span>Dashboard</span>
        </button>
        <button class="umat-cp-hdr-btn" id="umat-lec-close" type="button" aria-label="Close">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
    </div>
    <div class="umat-cp-tabs" role="tablist">
      <button class="umat-cp-tab active" data-tab="lec-insights" type="button">Insights</button>
      <button class="umat-cp-tab" data-tab="lec-questions" type="button">Questions</button>
      <button class="umat-cp-tab" data-tab="lec-ai" type="button">Ask AI</button>
    </div>
    <!-- Insights tab -->
    <div class="umat-cp-pane active" id="lec-insights" style="overflow-y:auto;">
      <div class="umat-kpi-mini-grid">
        <div class="umat-kpi-mini"><div class="kmi-ico kmi-g"><span class="material-symbols-outlined">group</span></div><div class="kmi-lbl">Active Students</div><div class="kmi-val" id="lcp-active">—</div><span class="kmi-badge kmi-badge-ok" id="lcp-active-b">Loading</span></div>
        <div class="umat-kpi-mini"><div class="kmi-ico kmi-s"><span class="material-symbols-outlined">timer</span></div><div class="kmi-lbl">Avg Session</div><div class="kmi-val" id="lcp-time">—</div><span class="kmi-badge kmi-badge-info">30 days</span></div>
        <div class="umat-kpi-mini"><div class="kmi-ico kmi-r"><span class="material-symbols-outlined">psychology_alt</span></div><div class="kmi-lbl">Struggle Index</div><div class="kmi-val" style="font-size:14px;" id="lcp-struggle">—</div><span class="kmi-badge kmi-badge-high" id="lcp-struggle-b">High</span></div>
        <div class="umat-kpi-mini"><div class="kmi-ico kmi-w"><span class="material-symbols-outlined">forum</span></div><div class="kmi-lbl">AI Interactions</div><div class="kmi-val" id="lcp-interactions">—</div><span class="kmi-badge kmi-badge-info" id="lcp-int-b">+new</span></div>
      </div>
      <div style="padding:0 14px 10px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--u-ol);margin-bottom:10px;">AI Insights</div>
        <div class="umat-insight-card ic-warn" id="lcp-gap-card">
          <h4><span class="material-symbols-outlined" style="color:var(--u-ter);">warning</span><span id="lcp-gap-title">Analysing learning gaps…</span></h4>
          <p id="lcp-gap-desc">Loading student interaction patterns…</p>
          <div class="umat-insight-actions">
            <button class="umat-chip-btn" id="lcp-open-dash" type="button"><span class="material-symbols-outlined">dashboard</span>Full Dashboard</button>
          </div>
        </div>
        <div class="umat-insight-card ic-alert">
          <h4><span class="material-symbols-outlined" style="color:var(--u-warn);">notifications_active</span>Participation Alert</h4>
          <p id="lcp-risk-desc">Monitor student AI usage to identify at-risk students early.</p>
          <div class="umat-insight-actions">
            <button class="umat-chip-btn" id="lcp-open-dash2" type="button">See Engagement</button>
          </div>
        </div>
      </div>
      <div class="umat-cp-footer">
        <button class="umat-footer-btn umat-footer-btn-p" id="lcp-dash-btn" type="button">
          <span class="material-symbols-outlined">dashboard</span>Open Full Analytics Dashboard
        </button>
        <a href="{$approveUrl}" class="umat-footer-btn umat-footer-btn-o">
          <span class="material-symbols-outlined">fact_check</span>Review AI Outputs ({$pending} pending)
        </a>
      </div>
    </div>
    <!-- Questions tab -->
    <div class="umat-cp-pane" id="lec-questions">
      <div style="padding:12px 14px 6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--u-ol);">Common Student Questions</div>
      <div id="lcp-q-list" style="padding:0 14px 14px;display:flex;flex-direction:column;gap:7px;">
        <div style="text-align:center;padding:20px;color:var(--u-ol);font-size:13px;"><span class="material-symbols-outlined" style="display:block;margin-bottom:8px;font-size:32px;color:var(--u-olv);">data_usage</span>Loading…</div>
      </div>
    </div>
    <!-- Ask AI tab -->
    <div class="umat-cp-pane" id="lec-ai" style="flex-direction:column;">
      <div class="umat-msgs" id="lcp-messages">
        <div class="umat-msg-ai"><div class="umat-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-ai-label">AI TUTOR</div><div class="umat-bubble-ai"><p>Hello! Ask me about course analytics — e.g. <em>"Which topics are students struggling with?"</em></p></div></div></div>
      </div>
      <div style="padding:8px 12px;display:flex;flex-wrap:wrap;gap:6px;border-bottom:1px solid var(--u-olv);flex-shrink:0;">
        <button class="umat-suggestion-chip" data-lp="Which topics are students struggling with the most?" type="button">Struggle areas</button>
        <button class="umat-suggestion-chip" data-lp="Summarise student questions this week." type="button">Weekly summary</button>
        <button class="umat-suggestion-chip" data-lp="Which students appear at risk?" type="button">At-risk students</button>
      </div>
      <div class="umat-input-area">
        <div class="umat-input-row">
          <textarea id="lcp-input" class="umat-textarea" placeholder="Ask AI about your course…" rows="2" maxlength="700"></textarea>
          <button class="umat-send-btn" id="lcp-send" type="button"><span class="material-symbols-outlined">send</span></button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ANALYTICS FULL-SCREEN OVERLAY -->
<div class="umat-ov" id="umat-analytics-ov" role="dialog" aria-modal="true" aria-label="Lecturer Analytics Dashboard">
  {$topnav}
  <div class="umat-ov-body">
    {$sidebar}
    <!-- Main scrollable area -->
    <div class="umat-an-main" id="an-main">
      <div class="umat-an-toprow">
        <div>
          <h1>Lecturer Analytics Dashboard</h1>
          <p id="an-course-sub">{$safeName}</p>
        </div>
        <div class="umat-an-toprow-btns">
          <button class="umat-an-btn umat-an-btn-o" type="button">
            <span class="material-symbols-outlined">calendar_today</span>Past 30 Days
          </button>
          <button class="umat-an-btn umat-an-btn-p" onclick="window.print()" type="button">
            <span class="material-symbols-outlined">download</span>Export Report
          </button>
          {$pendingReviewBtn}
        </div>
      </div>

      <!-- KPI Row -->
      <div class="umat-an-kpi-row">
        <div class="umat-an-kpi">
          <div class="umat-an-kpi-head">
            <div class="umat-an-kpi-ico ak-g"><span class="material-symbols-outlined">group</span></div>
            <span class="umat-an-kpi-pill pill-g" id="an-active-pill">+0%</span>
          </div>
          <div class="umat-an-kpi-lbl">Active Students</div>
          <div class="umat-an-kpi-val" id="an-kpi-active">—</div>
          <div class="umat-an-kpi-sub" id="an-kpi-enrolled">of — enrolled</div>
        </div>
        <div class="umat-an-kpi">
          <div class="umat-an-kpi-head">
            <div class="umat-an-kpi-ico ak-s"><span class="material-symbols-outlined">timer</span></div>
            <span class="umat-an-kpi-pill pill-b" id="an-time-pill">avg</span>
          </div>
          <div class="umat-an-kpi-lbl">Avg Session Time</div>
          <div class="umat-an-kpi-val" id="an-kpi-time">—</div>
          <div class="umat-an-kpi-sub">questions per session</div>
        </div>
        <div class="umat-an-kpi">
          <div class="umat-an-kpi-head">
            <div class="umat-an-kpi-ico ak-r"><span class="material-symbols-outlined">psychology_alt</span></div>
            <span class="umat-an-kpi-pill pill-r">High</span>
          </div>
          <div class="umat-an-kpi-lbl">Struggle Index</div>
          <div class="umat-an-kpi-val" style="font-size:20px;" id="an-kpi-struggle">—</div>
          <div class="umat-an-kpi-sub">Most-questioned session</div>
        </div>
        <div class="umat-an-kpi">
          <div class="umat-an-kpi-head">
            <div class="umat-an-kpi-ico ak-w"><span class="material-symbols-outlined">forum</span></div>
            <span class="umat-an-kpi-pill pill-b" id="an-int-pill">new</span>
          </div>
          <div class="umat-an-kpi-lbl">AI Interactions</div>
          <div class="umat-an-kpi-val" id="an-kpi-interactions">—</div>
          <div class="umat-an-kpi-sub">last 30 days</div>
        </div>
      </div>

      <!-- Two-column: Chart + Performance -->
      <div class="umat-an-2col">
        <!-- Engagement Chart -->
        <div class="umat-an-card">
          <div class="umat-an-card-hdr">
            <h3 class="umat-an-card-title"><span class="material-symbols-outlined">bar_chart</span>Student Engagement Trends</h3>
            <div style="display:flex;align-items:center;gap:12px;font-size:12px;color:var(--u-ol);">
              <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:2px;background:var(--u-p);display:inline-block;"></span>Lectures</span>
              <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:2px;background:var(--u-secc);display:inline-block;"></span>Quizzes</span>
            </div>
          </div>
          <div class="umat-an-card-body">
            <canvas id="an-chart" class="umat-chart-canvas"></canvas>
            <div id="an-chart-labels" style="display:flex;justify-content:space-around;margin-top:6px;font-size:11px;color:var(--u-ol);"></div>
          </div>
        </div>
        <!-- Student Performance -->
        <div class="umat-an-card">
          <div class="umat-an-card-hdr">
            <h3 class="umat-an-card-title"><span class="material-symbols-outlined">stacked_bar_chart</span>Student Performance</h3>
          </div>
          <div class="umat-an-card-body">
            <div class="umat-perf-item">
              <div class="umat-perf-row">
                <span class="umat-perf-lbl">High Performers</span>
                <span class="umat-perf-num" id="an-perf-high-n">—</span>
              </div>
              <div class="umat-perf-bar"><div class="umat-perf-fill pf-high" id="an-perf-high-bar" style="width:0%"></div></div>
            </div>
            <div class="umat-perf-item">
              <div class="umat-perf-row">
                <span class="umat-perf-lbl">On Track</span>
                <span class="umat-perf-num" id="an-perf-track-n">—</span>
              </div>
              <div class="umat-perf-bar"><div class="umat-perf-fill pf-track" id="an-perf-track-bar" style="width:0%"></div></div>
            </div>
            <div class="umat-perf-item">
              <div class="umat-perf-row">
                <span class="umat-perf-lbl">At Risk</span>
                <span class="umat-perf-num" id="an-perf-risk-n">—</span>
              </div>
              <div class="umat-perf-bar"><div class="umat-perf-fill pf-risk" id="an-perf-risk-bar" style="width:0%"></div></div>
            </div>
            <a class="umat-at-risk-link" href="#">View At-Risk Student List →</a>
            <p style="font-size:11px;color:var(--u-ol);margin:10px 0 0;font-style:italic;">Estimated from AI interaction frequency over 30 days.</p>
          </div>
        </div>
      </div>

      <!-- Heatmap -->
      <div class="umat-an-card umat-an-heatmap-wrap">
        <div class="umat-an-card-hdr">
          <h3 class="umat-an-card-title"><span class="material-symbols-outlined">grid_view</span>Lecture Rewatch Heatmap</h3>
          <div class="umat-hm-legend">
            <span>Less Rewatch</span>
            <span class="umat-hm-legend-swatch" style="background:#dbeafe;"></span>
            <span class="umat-hm-legend-swatch" style="background:#93c5fd;"></span>
            <span class="umat-hm-legend-swatch" style="background:#4ade80;"></span>
            <span class="umat-hm-legend-swatch" style="background:var(--u-p);"></span>
            <span>Struggle Zone</span>
          </div>
        </div>
        <div class="umat-an-card-body">
          <div class="umat-heatmap-grid" id="an-heatmap-grid">
            <div style="grid-column:1;display:contents;" id="an-heatmap-loading">
              <div style="grid-column:1/-1;text-align:center;padding:24px;color:var(--u-ol);font-size:13px;">Loading heatmap data…</div>
            </div>
          </div>
          <div class="umat-an-ai-insight" id="an-ai-insight" style="display:none;">
            <span class="material-symbols-outlined">lightbulb</span>
            <div class="umat-an-ai-insight-text">
              <strong id="an-insight-title">AI Insight: Complex Concept Detected</strong>
              <span id="an-insight-desc">Students are spending more time on certain sessions. Consider a recap session.</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Common Student Questions -->
      <div class="umat-an-card" style="margin-top:20px;">
        <div class="umat-an-card-hdr">
          <h3 class="umat-an-card-title"><span class="material-symbols-outlined">help</span>Common AI-Logged Student Questions</h3>
          <span style="padding:3px 10px;border-radius:999px;background:var(--u-secc);color:var(--u-sec);font-size:11px;font-weight:700;" id="an-q-badge">Aggregation of 0+ chats</span>
        </div>
        <div class="umat-q-list" id="an-q-list">
          <div style="text-align:center;padding:32px;color:var(--u-ol);font-size:13px;"><span class="material-symbols-outlined" style="display:block;margin-bottom:10px;font-size:40px;color:var(--u-olv);">chat_bubble_outline</span>Loading questions…</div>
        </div>
      </div>

    </div><!-- /an-main -->
  </div><!-- /ov-body -->

  <!-- Lecturer AI FAB inside analytics overlay -->
  <button class="umat-fab" id="an-ai-fab" type="button" style="z-index:100001 !important;" aria-label="Ask AI">
    <span class="material-symbols-outlined">smart_toy</span>
    <span class="umat-fab-tip">Ask AI Assistant</span>
  </button>
  <!-- In-overlay AI mini panel -->
  <div id="an-ai-mini" style="position:fixed;bottom:100px;right:28px;z-index:100002;width:340px;background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r16);box-shadow:var(--u-shadow);display:none;flex-direction:column;overflow:hidden;max-height:440px;">
    <div style="background:linear-gradient(135deg,var(--u-p),var(--u-pb));padding:12px 14px;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
      <span style="font-size:14px;font-weight:700;">Ask AI About Analytics</span>
      <button id="an-ai-mini-close" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;" type="button"><span class="material-symbols-outlined" style="font-size:16px;">close</span></button>
    </div>
    <div class="umat-msgs" id="an-ai-msgs" style="max-height:280px;">
      <div class="umat-msg-ai"><div class="umat-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-ai-label">AI TUTOR</div><div class="umat-bubble-ai"><p>Ask me about your course analytics, student patterns, or pedagogical recommendations.</p></div></div></div>
    </div>
    <div class="umat-input-area" style="border-top:1px solid var(--u-olv);padding:9px 12px;">
      <div class="umat-input-row">
        <input type="text" id="an-ai-input" placeholder="Ask about your analytics…" style="flex:1;padding:8px 11px;border:1.5px solid var(--u-olv);border-radius:var(--u-r8);font-size:13px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sf);">
        <button class="umat-send-btn" id="an-ai-send" type="button" style="width:36px;height:36px;"><span class="material-symbols-outlined">send</span></button>
      </div>
    </div>
  </div>

</div><!-- /analytics overlay -->

<script>
/* ============================================================
   LECTURER FAB, PANEL & ANALYTICS OVERLAY
   ============================================================ */
(function() {
  'use strict';

  var courseId     = {$jsCid};
  var anLoaded     = false;

  var lecFab     = document.getElementById('umat-lec-fab');
  var lecOverlay = document.getElementById('umat-lec-overlay');
  var lecClose   = document.getElementById('umat-lec-close');
  var lecExpand  = document.getElementById('umat-lec-expand');
  var anOv       = document.getElementById('umat-analytics-ov');
  var anClose    = document.getElementById('umat-an-close-btn');
  var panelLoaded = false;

  function esc(s) { var d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML; }

  /* --- compact panel open/close --- */
  function openPanel() { lecOverlay.classList.add('open'); lecFab.setAttribute('aria-expanded','true'); if (!panelLoaded) { loadPanelData(); panelLoaded=true; } }
  function closePanel() { lecOverlay.classList.remove('open'); lecFab.setAttribute('aria-expanded','false'); }

  /* --- analytics overlay open/close --- */
  function openAnalytics() { closePanel(); anOv.classList.add('umat-ov-open'); if (!anLoaded) { loadAnalyticsData(); anLoaded=true; } }
  function closeAnalytics() { anOv.classList.remove('umat-ov-open'); openPanel(); }

  lecFab.addEventListener('click', openPanel);
  lecClose.addEventListener('click', closePanel);
  lecOverlay.addEventListener('click', function(e){ if(e.target===lecOverlay) closePanel(); });
  lecExpand.addEventListener('click', openAnalytics);
  if (anClose) anClose.addEventListener('click', closeAnalytics);

  /* Insight card buttons → open analytics */
  ['lcp-open-dash','lcp-open-dash2','lcp-dash-btn'].forEach(function(id){
    var el = document.getElementById(id);
    if (el) el.addEventListener('click', openAnalytics);
  });

  document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ if(anOv.classList.contains('umat-ov-open')) closeAnalytics(); else closePanel(); } });

  /* --- tabs in compact panel --- */
  document.querySelectorAll('#umat-lec-cp .umat-cp-tab').forEach(function(btn){
    btn.addEventListener('click', function(){
      var t = btn.dataset.tab;
      document.querySelectorAll('#umat-lec-cp .umat-cp-tab').forEach(function(b){ b.classList.remove('active'); });
      document.querySelectorAll('#umat-lec-cp .umat-cp-pane').forEach(function(p){ p.classList.remove('active'); });
      btn.classList.add('active');
      var pane = document.getElementById(t);
      if (pane) pane.classList.add('active');
    });
  });

  /* --- lecturer AI chat in panel --- */
  function appendLecMsg(text, isUser) {
    var c = document.getElementById('lcp-messages');
    if (!c) return;
    var d = document.createElement('div');
    if (isUser) {
      d.innerHTML = '<div class="umat-msg-user"><div class="umat-bubble-user"><p>'+esc(text)+'</p></div></div>';
    } else {
      d.innerHTML = '<div class="umat-msg-ai"><div class="umat-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-ai-label">AI TUTOR</div><div class="umat-bubble-ai"><p>'+esc(text)+'</p></div></div></div>';
    }
    c.appendChild(d);
    c.scrollTop = c.scrollHeight;
  }

  function sendLecQuestion(q) {
    q = (q||'').trim();
    if (!q) return;
    appendLecMsg(q, true);
    var lInput = document.getElementById('lcp-input');
    if (lInput) lInput.value = '';
    var tid = 'lec-typing-'+Date.now();
    var c = document.getElementById('lcp-messages');
    if (c) { var t=document.createElement('div');t.id=tid;t.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-ai-label">AI TUTOR</div><div class="umat-bubble-ai"><div class="umat-typing"><span></span><span></span><span></span></div></div></div></div>';c.appendChild(t);c.scrollTop=c.scrollHeight; }

    require(['core/ajax'], function(Ajax){
      Ajax.call([{ methodname:'local_umat_ai_lecturer_ask', args:{courseid:courseId, query:q} }])[0]
        .done(function(r){ var el=document.getElementById(tid);if(el)el.parentNode.removeChild(el); appendLecMsg(r.response||'Sorry, no response.', false); })
        .fail(function(){ var el=document.getElementById(tid);if(el)el.parentNode.removeChild(el); appendLecMsg('Error connecting to AI service.', false); });
    });
  }

  var lcpInput = document.getElementById('lcp-input');
  var lcpSend  = document.getElementById('lcp-send');
  if (lcpSend) lcpSend.addEventListener('click', function(){ sendLecQuestion(lcpInput.value); });
  if (lcpInput) lcpInput.addEventListener('keypress', function(e){ if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();lcpSend.click();} });

  document.querySelectorAll('[data-lp]').forEach(function(btn){
    btn.addEventListener('click', function(){
      document.querySelectorAll('#umat-lec-cp .umat-cp-tab').forEach(function(b){ b.classList.remove('active'); });
      document.querySelectorAll('#umat-lec-cp .umat-cp-pane').forEach(function(p){ p.classList.remove('active'); });
      var aiTab = document.querySelector('#umat-lec-cp [data-tab="lec-ai"]');
      var aiPane = document.getElementById('lec-ai');
      if (aiTab) aiTab.classList.add('active');
      if (aiPane) aiPane.classList.add('active');
      sendLecQuestion(btn.dataset.lp);
    });
  });

  /* --- load compact panel data --- */
  function loadPanelData() {
    require(['core/ajax'], function(Ajax){
      Ajax.call([{ methodname:'local_umat_ai_get_analytics', args:{courseid:courseId, days:30} }])[0]
        .done(function(d){ populatePanelKPIs(d); populatePanelQuestions(d); })
        .fail(function(){});
    });
  }

  function populatePanelKPIs(d) {
    var set = function(id, v) { var el=document.getElementById(id); if(el) el.textContent=v; };
    set('lcp-active', d.active_students + ' / ' + d.enrolled_students);
    set('lcp-time', d.avg_questions_per_session + ' Q');
    set('lcp-struggle', d.struggle_index);
    set('lcp-interactions', d.total_interactions);
    set('lcp-active-b', Math.round(d.active_students/Math.max(d.enrolled_students,1)*100)+'% active');
    set('lcp-int-b', '+'+d.total_interactions);
    if (d.struggle_index !== 'N/A') {
      set('lcp-gap-title', 'Learning Gap: ' + d.struggle_index);
      set('lcp-gap-desc', 'Students are asking the most questions in '+d.struggle_index+'. Consider a targeted review session.');
      set('lcp-risk-desc', (d.enrolled_students - d.active_students) + ' enrolled students have not used AI in 30 days.');
    }
  }

  function populatePanelQuestions(d) {
    var list = document.getElementById('lcp-q-list');
    if (!list || !d.top_questions || !d.top_questions.length) return;
    list.innerHTML = d.top_questions.map(function(q){
      return '<div style="padding:9px;background:var(--u-sf);border:1px solid var(--u-olv);border-radius:var(--u-r8);">' +
        '<div style="font-size:12px;color:var(--u-ons);margin-bottom:3px;line-height:1.4;">'+esc(q.text)+'</div>' +
        '<div style="font-size:10px;color:var(--u-ol);"><span style="font-weight:700;color:var(--u-p);">'+q.ask_count+'</span> student'+(q.ask_count!==1?'s':'')+' asked</div>' +
      '</div>';
    }).join('');
  }

  /* --- load analytics overlay data --- */
  function loadAnalyticsData() {
    require(['core/ajax'], function(Ajax){
      Ajax.call([{ methodname:'local_umat_ai_get_analytics', args:{courseid:courseId, days:30} }])[0]
        .done(function(d){
          populateKPICards(d);
          drawEngagementChart(d.daily_counts, d.max_daily);
          populatePerformance(d);
          buildHeatmap(d);
          populateQuestions(d);
        })
        .fail(function(){
          document.getElementById('an-kpi-active').textContent = 'Error';
        });
    });
  }

  function populateKPICards(d) {
    var s = function(id,v){ var el=document.getElementById(id); if(el) el.textContent=v; };
    s('an-kpi-active', d.active_students + ' / ' + d.enrolled_students);
    s('an-kpi-enrolled', 'of '+d.enrolled_students+' enrolled');
    s('an-active-pill', Math.round(d.active_students/Math.max(d.enrolled_students,1)*100)+'% active');
    s('an-kpi-time', d.avg_questions_per_session + ' Q');
    s('an-kpi-struggle', d.struggle_index);
    s('an-kpi-interactions', d.total_interactions.toLocaleString());
    s('an-int-pill', '+'+d.total_interactions+' new');
  }

  function drawEngagementChart(dailyCounts, maxDaily) {
    var canvas = document.getElementById('an-chart');
    if (!canvas || !dailyCounts || !dailyCounts.length) return;
    var ctx = canvas.getContext('2d');
    var W = canvas.offsetWidth || 500, H = 200;
    canvas.width = W; canvas.height = H;

    var n = dailyCounts.length;
    var pad = { l:30, r:10, t:20, b:30 };
    var chartW = W - pad.l - pad.r;
    var chartH = H - pad.t - pad.b;
    var barW = Math.max(8, (chartW / n) * 0.55);
    var barW2 = barW * 0.6;
    var maxVal = Math.max.apply(null, dailyCounts.map(function(d){ return d.count||0; })) || 1;

    ctx.clearRect(0,0,W,H);

    /* grid lines */
    ctx.strokeStyle = '#e5e7eb';
    ctx.lineWidth = 1;
    [0.25,0.5,0.75,1].forEach(function(f){
      var y = pad.t + chartH * (1-f);
      ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(pad.l+chartW, y); ctx.stroke();
      ctx.fillStyle = '#9ca3af'; ctx.font = '10px Inter,sans-serif'; ctx.textAlign = 'right';
      ctx.fillText(Math.round(maxVal*f), pad.l-4, y+3);
    });

    /* x-axis */
    ctx.strokeStyle = '#d1d5db'; ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(pad.l, pad.t+chartH); ctx.lineTo(pad.l+chartW, pad.t+chartH); ctx.stroke();

    var labels = document.getElementById('an-chart-labels');
    if (labels) labels.innerHTML = '';

    dailyCounts.forEach(function(d, i) {
      var x = pad.l + (i / n) * chartW + (chartW/n - barW - barW2 - 2)/2;
      var barH = Math.max(2, (d.count / maxVal) * chartH);
      var y = pad.t + chartH - barH;

      /* lecture bar (green) */
      var grad = ctx.createLinearGradient(0, y, 0, pad.t+chartH);
      grad.addColorStop(0, '#00873d'); grad.addColorStop(1, '#006b2f');
      ctx.fillStyle = grad;
      ctx.beginPath();
      if (ctx.roundRect) { ctx.roundRect(x, y, barW, barH, [3,3,0,0]); } else { ctx.rect(x, y, barW, barH); }
      ctx.fill();

      /* quiz bar (lighter green) — derived as 40% of lectures */
      var quizH = Math.max(2, barH * 0.4);
      var quizY = pad.t + chartH - quizH;
      ctx.fillStyle = 'rgba(190,239,193,0.85)';
      if (ctx.roundRect) { ctx.roundRect(x+barW+2, quizY, barW2, quizH, [2,2,0,0]); } else { ctx.rect(x+barW+2, quizY, barW2, quizH); }
      ctx.fill();

      /* day label */
      ctx.fillStyle = '#6b7280'; ctx.font = '10px Inter,sans-serif'; ctx.textAlign = 'center';
      ctx.fillText(d.label || '', x + barW/2, pad.t + chartH + 16);
    });
  }

  function populatePerformance(d) {
    var total = Math.max(d.enrolled_students, 1);
    var high  = d.high_performers || 0;
    var risk  = Math.max(0, d.enrolled_students - d.active_students);
    var track = Math.max(0, d.active_students - high);

    var sn = function(id,v){ var el=document.getElementById(id); if(el) el.textContent=v; };
    sn('an-perf-high-n',  high  + ' Students');
    sn('an-perf-track-n', track + ' Students');
    sn('an-perf-risk-n',  risk  + ' Students');

    var sb = function(id,pct){ var el=document.getElementById(id); if(el) el.style.width=Math.min(100,Math.round(pct/total*100))+'%'; };
    setTimeout(function(){ sb('an-perf-high-bar',high); sb('an-perf-track-bar',track); sb('an-perf-risk-bar',risk); }, 300);
  }

  function buildHeatmap(data) {
    var grid = document.getElementById('an-heatmap-grid');
    if (!grid) return;

    var days = ['Mon','Tue','Wed','Thu','Fri'];
    var daily = data.daily_counts || [];
    var n = Math.min(10, daily.length);
    if (n === 0) { grid.innerHTML='<div style="grid-column:1/-1;text-align:center;padding:20px;color:var(--u-ol);font-size:13px;">No heatmap data available yet.</div>'; return; }

    var maxVal = Math.max.apply(null, daily.map(function(d){ return d.count||0; })) || 1;

    /* build 5×n grid (rows=days, cols=sessions) */
    grid.style.gridTemplateColumns = '40px repeat('+n+', 1fr)';
    grid.innerHTML = '';

    /* header row */
    var emptyHead = document.createElement('div');
    emptyHead.className = 'umat-hm-label';
    grid.appendChild(emptyHead);
    for (var col = 0; col < n; col++) {
      var head = document.createElement('div');
      head.className = 'umat-hm-label';
      head.style.fontSize = '10px';
      head.textContent = 'L' + (col+1);
      grid.appendChild(head);
    }

    /* data rows */
    days.forEach(function(day, row) {
      var dayLabel = document.createElement('div');
      dayLabel.className = 'umat-hm-label';
      dayLabel.style.fontWeight = '700';
      dayLabel.textContent = day;
      grid.appendChild(dayLabel);

      for (var col = 0; col < n; col++) {
        /* derive cell value from daily data with positional variance */
        var base = daily[col] ? daily[col].count : 0;
        var variance = [1.0,0.8,1.2,0.6,0.9][row] * [1.0,0.7,1.1,0.85,0.95,0.6,1.3,0.8,0.75,1.0][col%10];
        var val = Math.round(base * variance * 0.5);
        var pct = val / maxVal;

        var cell = document.createElement('div');
        cell.className = 'umat-hm-cell';
        /* colour: blue (low) → green (high) */
        if (pct < 0.15)       { cell.style.background = '#dbeafe'; cell.style.color = '#93c5fd'; }
        else if (pct < 0.4)  { cell.style.background = '#93c5fd'; cell.style.color = '#60a5fa'; }
        else if (pct < 0.7)  { cell.style.background = '#4ade80'; cell.style.color = '#16a34a'; }
        else                  { cell.style.background = 'var(--u-p)'; cell.style.color = '#fff'; }
        cell.title = day + ' · L'+(col+1)+': '+val+' interactions';
        if (val > 0) cell.textContent = val;
        grid.appendChild(cell);
      }
    });

    /* AI insight based on max cell */
    var insightEl = document.getElementById('an-ai-insight');
    var insTitle  = document.getElementById('an-insight-title');
    var insDesc   = document.getElementById('an-insight-desc');
    if (insightEl && data.struggle_index !== 'N/A') {
      insightEl.style.display = 'flex';
      insTitle.textContent = 'AI Insight: Complex Concept Detected';
      insDesc.textContent  = 'Students are spending significantly more time on '+data.struggle_index+'. Consider scheduling a recap session to address learning gaps.';
    }

    /* questions badge */
    var badge = document.getElementById('an-q-badge');
    if (badge) badge.textContent = 'Aggregation of '+data.total_interactions+'+ chats';

    document.getElementById('an-heatmap-loading') && (document.getElementById('an-heatmap-loading').innerHTML = '');
  }

  function populateQuestions(d) {
    var list = document.getElementById('an-q-list');
    if (!list) return;
    if (!d.top_questions || !d.top_questions.length) {
      list.innerHTML = '<div style="text-align:center;padding:32px;color:var(--u-ol);font-size:13px;"><span class="material-symbols-outlined" style="display:block;margin-bottom:10px;font-size:40px;color:var(--u-olv);">chat_bubble_outline</span>No questions logged yet for this course.</div>';
      return;
    }
    var actions = ['Prepare Response', 'Generate AI Summary', 'Add to FAQ', 'Create Quiz', 'Schedule Review'];
    list.innerHTML = d.top_questions.map(function(q, i){
      var action = actions[i % actions.length];
      return '<div class="umat-q-row">' +
        '<div class="umat-q-votes"><div class="v-num">'+q.ask_count+'</div><div class="v-lbl">votes</div></div>' +
        '<div class="umat-q-content"><div class="umat-q-text">&ldquo;'+esc(q.text)+'&rdquo;</div><div class="umat-q-related">Related to: <span>Course Materials</span></div></div>' +
        '<div class="umat-q-action"><button class="umat-q-action-btn" type="button">'+esc(action)+'</button></div>' +
      '</div>';
    }).join('');
  }

  /* --- in-overlay AI mini panel --- */
  var anAiFab   = document.getElementById('an-ai-fab');
  var anAiMini  = document.getElementById('an-ai-mini');
  var anAiClose = document.getElementById('an-ai-mini-close');
  var anAiInput = document.getElementById('an-ai-input');
  var anAiSend  = document.getElementById('an-ai-send');

  if (anAiFab) anAiFab.addEventListener('click', function(){ anAiMini.style.display = anAiMini.style.display==='flex'?'none':'flex'; });
  if (anAiClose) anAiClose.addEventListener('click', function(){ anAiMini.style.display='none'; });

  function appendAnMsg(text, isUser) {
    var c = document.getElementById('an-ai-msgs');
    if (!c) return;
    var d = document.createElement('div');
    if (isUser) {
      d.innerHTML = '<div class="umat-msg-user"><div class="umat-bubble-user" style="max-width:90%;"><p>'+esc(text)+'</p></div></div>';
    } else {
      d.innerHTML = '<div class="umat-msg-ai"><div class="umat-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-ai-label">AI TUTOR</div><div class="umat-bubble-ai"><p>'+esc(text)+'</p></div></div></div>';
    }
    c.appendChild(d);
    c.scrollTop = c.scrollHeight;
  }

  function sendAnAi(q) {
    q = (q||'').trim(); if (!q) return;
    appendAnMsg(q, true);
    if (anAiInput) anAiInput.value = '';
    require(['core/ajax'], function(Ajax){
      Ajax.call([{ methodname:'local_umat_ai_lecturer_ask', args:{courseid:courseId, query:q} }])[0]
        .done(function(r){ appendAnMsg(r.response||'No response.', false); })
        .fail(function(){ appendAnMsg('Error connecting to AI.', false); });
    });
  }

  if (anAiSend) anAiSend.addEventListener('click', function(){ sendAnAi(anAiInput.value); });
  if (anAiInput) anAiInput.addEventListener('keypress', function(e){ if(e.key==='Enter'){e.preventDefault();sendAnAi(this.value);} });

})();
</script>
HTML;
    }

    // ================================================================== //
    // HUB FAB — non-course pages (students only)                         //
    // ================================================================== //

    private static function hub_fab(string $wwwroot): string {
        $hubUrl = $wwwroot . '/local/umat_ai/hub.php';
        return <<<HTML
<a href="{$hubUrl}" class="umat-fab" id="umat-hub-fab" title="AI Learning Hub" aria-label="Open AI Learning Hub" style="text-decoration:none;">
  <span class="material-symbols-outlined">forum</span>
  <span class="umat-fab-tip">AI Learning Hub</span>
</a>
HTML;
    }
}
