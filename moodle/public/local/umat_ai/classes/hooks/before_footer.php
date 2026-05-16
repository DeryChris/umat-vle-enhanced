<?php
/**
 * Hook listener: inject AI FABs before the page footer.
 *
 * Injects:
 *  • Student FAB + slide-in chat panel  →  on all course pages where user is enrolled as student.
 *  • Lecturer FAB + insights panel      →  on all course pages where user has viewanalytics capability.
 *  • Hub FAB (compact)                  →  on non-course pages for logged-in students.
 *
 * All CSS + HTML + JS is self-contained. RequireJS (core/ajax) is always
 * available on Moodle pages, so no AMD compilation step is required.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\hooks;

use core\hook\output\before_footer_html_generation;

class before_footer {

    // ------------------------------------------------------------------ //
    // Hook entry point                                                     //
    // ------------------------------------------------------------------ //

    public static function handle(before_footer_html_generation $hook): void {
        global $PAGE, $COURSE, $USER, $CFG, $DB;

        if (!isloggedin() || isguestuser()) {
            return;
        }

        $path = $PAGE->url->get_path();

        // ---- Determine whether this is a course-related page ----------- //
        $isCourseArea = (
            strpos($path, '/course/') !== false ||
            strpos($path, '/mod/')    !== false ||
            strpos($path, '/section/') !== false
        );

        // ---- Resolve course ID ----------------------------------------- //
        $courseid = 0;
        $context  = $PAGE->context;

        if ($context && $context->contextlevel === CONTEXT_COURSE) {
            $courseid = (int) $context->instanceid;
        } elseif (!empty($COURSE->id) && $COURSE->id != SITEID) {
            $courseid = (int) $COURSE->id;
        }

        $wwwroot = rtrim($CFG->wwwroot, '/');

        if ($isCourseArea && $courseid) {
            $coursecontext = \context_course::instance($courseid);
            $courseName    = format_string($COURSE->fullname ?? '', true, ['context' => $coursecontext]);

            $isLecturer = has_capability('local/umat_ai:viewanalytics', $coursecontext);
            $isStudent  = !$isLecturer && is_enrolled($coursecontext, $USER, '', false);

            if ($isLecturer) {
                // Count pending approvals for the badge.
                $pendingCount = (int) $DB->count_records_sql(
                    "SELECT COUNT(o.id) FROM {umat_ai_outputs} o
                     JOIN {umat_ai_sessions} s ON s.id = o.sessionrecordid
                     WHERE s.courseid = :cid AND o.is_approved = 0",
                    ['cid' => $courseid]
                );
                $hook->add_html(self::get_shared_css());
                $hook->add_html(self::get_lecturer_fab($courseid, $courseName, $pendingCount, $wwwroot));

            } elseif ($isStudent) {
                $hook->add_html(self::get_shared_css());
                $hook->add_html(self::get_student_fab($courseid, $courseName, $wwwroot));
            }

        } elseif (!$isCourseArea) {
            // Non-course page: show compact hub FAB for any enrolled user.
            if (has_capability('moodle/course:view', \context_system::instance(), $USER, false)
                || $DB->record_exists('user_enrolments', ['userid' => $USER->id])) {
                $hook->add_html(self::get_shared_css());
                $hook->add_html(self::get_hub_fab($wwwroot));
            }
        }
    }

    // ================================================================== //
    // SHARED CSS — injected once per page                                 //
    // ================================================================== //

    private static function get_shared_css(): string {
        return <<<'HTML'
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
<style id="umat-ai-shared-css">
/* ============================================================
   UMaT Precision Green design tokens
   ============================================================ */
:root {
  --umat-primary:          #006b2f;
  --umat-primary-bright:   #00873d;
  --umat-primary-fixed:    #81fb9c;
  --umat-on-primary:       #ffffff;
  --umat-surface:          #f5fbf0;
  --umat-surface-low:      #eff6eb;
  --umat-surface-lowest:   #ffffff;
  --umat-on-surface:       #171d17;
  --umat-on-surface-var:   #3e4a3e;
  --umat-outline:          #6e7a6d;
  --umat-outline-var:      #bdcaba;
  --umat-secondary:        #3d6844;
  --umat-sec-container:    #beefc1;
  --umat-tertiary:         #a5304d;
  --umat-error:            #ba1a1a;
  --umat-success:          #4ade80;
  --umat-shadow:           0 10px 40px rgba(0,0,0,.15);
  --umat-fab-shadow:       0 6px 20px rgba(0,107,47,.45);
  --umat-radius-sm:        8px;
  --umat-radius-md:        12px;
  --umat-radius-lg:        20px;
  --umat-radius-pill:      9999px;
  --umat-panel-width:      440px;
  --umat-panel-width-lec:  480px;
  --umat-z-overlay:        10000;
  --umat-z-fab:            9999;
}

/* ---- FAB base ---- */
.umat-fab {
  position: fixed;
  bottom: 88px;
  right: 24px;
  z-index: var(--umat-z-fab);
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--umat-primary) 0%, var(--umat-primary-bright) 100%);
  color: var(--umat-on-primary);
  border: none;
  box-shadow: var(--umat-fab-shadow);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: transform .25s, box-shadow .25s;
  font-family: inherit;
}
.umat-fab:hover { transform: scale(1.1); box-shadow: 0 8px 28px rgba(0,107,47,.55); }
.umat-fab .material-symbols-outlined { font-size: 28px; }

/* Pulse ring on the student FAB */
@keyframes umat-pulse {
  0%   { box-shadow: 0 0 0 0 rgba(0,107,47,.5); }
  70%  { box-shadow: 0 0 0 12px rgba(0,107,47,0); }
  100% { box-shadow: 0 0 0 0 rgba(0,107,47,0); }
}
.umat-fab-pulse { animation: umat-pulse 2.5s infinite; }

/* Badge on lecturer FAB */
.umat-fab-badge {
  position: absolute;
  top: -4px;
  right: -4px;
  min-width: 20px;
  height: 20px;
  padding: 0 5px;
  background: var(--umat-tertiary);
  color: #fff;
  border-radius: var(--umat-radius-pill);
  font-size: 11px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2px solid var(--umat-on-primary);
  font-family: 'Inter', -apple-system, sans-serif;
}

/* ---- Tooltip ---- */
.umat-fab-tooltip {
  position: absolute;
  right: 64px;
  white-space: nowrap;
  background: #1a1c19;
  color: #fff;
  padding: 6px 12px;
  border-radius: var(--umat-radius-sm);
  font-size: 12px;
  font-weight: 500;
  opacity: 0;
  pointer-events: none;
  transition: opacity .2s;
  font-family: 'Inter', -apple-system, sans-serif;
}
.umat-fab-tooltip::after {
  content: '';
  position: absolute;
  right: -6px;
  top: 50%;
  transform: translateY(-50%);
  border: 6px solid transparent;
  border-left-color: #1a1c19;
}
.umat-fab:hover .umat-fab-tooltip { opacity: 1; }

/* ---- Overlay backdrop ---- */
.umat-overlay {
  position: fixed;
  inset: 0;
  z-index: var(--umat-z-overlay);
  background: rgba(0,0,0,.35);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  display: none;
  justify-content: flex-end;
}
.umat-overlay.umat-open { display: flex; }

/* ---- Slide-in panel ---- */
.umat-panel {
  position: relative;
  width: var(--umat-panel-width);
  max-width: 96vw;
  height: 100%;
  background: var(--umat-surface);
  box-shadow: var(--umat-shadow);
  display: flex;
  flex-direction: column;
  transform: translateX(100%);
  transition: transform .38s cubic-bezier(.4,0,.2,1);
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  overflow: hidden;
}
.umat-overlay.umat-open .umat-panel { transform: translateX(0); }
.umat-panel.umat-panel-lec { width: var(--umat-panel-width-lec); }

/* ---- Panel header (green gradient) ---- */
.umat-panel-header {
  background: linear-gradient(135deg, var(--umat-primary) 0%, var(--umat-primary-bright) 100%);
  color: var(--umat-on-primary);
  padding: 18px 20px 14px;
  flex-shrink: 0;
}
.umat-panel-header-row {
  display: flex;
  align-items: center;
  gap: 12px;
}
.umat-avatar {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: rgba(255,255,255,.2);
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  flex-shrink: 0;
}
.umat-avatar .material-symbols-outlined { font-size: 24px; }
.umat-status-dot {
  position: absolute;
  bottom: 1px;
  right: 1px;
  width: 11px;
  height: 11px;
  border-radius: 50%;
  background: var(--umat-success);
  border: 2px solid var(--umat-primary);
}
@keyframes umat-status-pulse {
  0%,100% { opacity: 1; } 50% { opacity: .5; }
}
.umat-status-dot { animation: umat-status-pulse 1.8s infinite; }
.umat-header-info { flex: 1; min-width: 0; }
.umat-header-info h2 {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  line-height: 1.2;
}
.umat-header-info .umat-status-text {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  opacity: .9;
  margin-top: 2px;
}
.umat-header-info .umat-course-ctx {
  font-size: 11px;
  opacity: .75;
  margin-top: 2px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.umat-icon-btn {
  background: rgba(255,255,255,.2);
  border: none;
  color: var(--umat-on-primary);
  width: 34px;
  height: 34px;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background .2s;
  flex-shrink: 0;
}
.umat-icon-btn .material-symbols-outlined { font-size: 18px; }
.umat-icon-btn:hover { background: rgba(255,255,255,.3); }
.umat-expand-btn {
  border-radius: var(--umat-radius-sm);
  width: auto;
  padding: 0 12px;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
}
.umat-expand-btn .material-symbols-outlined { font-size: 16px; }

/* ---- Tab bar ---- */
.umat-tabs {
  display: flex;
  background: var(--umat-surface-lowest);
  border-bottom: 1px solid var(--umat-outline-var);
  flex-shrink: 0;
}
.umat-tab {
  flex: 1;
  padding: 12px 8px;
  border: none;
  background: none;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  color: var(--umat-outline);
  border-bottom: 3px solid transparent;
  transition: all .2s;
  font-family: inherit;
}
.umat-tab:hover { color: var(--umat-primary); background: var(--umat-surface-low); }
.umat-tab.active { color: var(--umat-primary); border-bottom-color: var(--umat-primary); font-weight: 700; }

/* ---- Tab content ---- */
.umat-tab-content {
  flex: 1;
  display: none;
  flex-direction: column;
  overflow: hidden;
}
.umat-tab-content.active { display: flex; }

/* ---- Chat messages ---- */
.umat-messages {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: var(--umat-surface);
}
.umat-messages::-webkit-scrollbar { width: 5px; }
.umat-messages::-webkit-scrollbar-thumb { background: var(--umat-outline-var); border-radius: 4px; }

/* AI message bubble */
.umat-msg-ai {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  max-width: 92%;
}
.umat-msg-ai-icon {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: rgba(0,107,47,.12);
  color: var(--umat-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.umat-msg-ai-icon .material-symbols-outlined { font-size: 16px; }
.umat-bubble-ai {
  background: var(--umat-surface-lowest);
  border-left: 3px solid var(--umat-primary);
  padding: 11px 13px;
  border-radius: 0 var(--umat-radius-md) var(--umat-radius-md) var(--umat-radius-md);
  font-size: 13px;
  line-height: 1.55;
  color: var(--umat-on-surface);
  box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
.umat-bubble-ai p { margin: 0; }

/* Student message bubble */
.umat-msg-student {
  display: flex;
  justify-content: flex-end;
}
.umat-bubble-student {
  background: linear-gradient(135deg, #dcfce7, #bbf7d0);
  color: #052e16;
  padding: 11px 14px;
  border-radius: var(--umat-radius-md) 0 var(--umat-radius-md) var(--umat-radius-md);
  font-size: 13px;
  line-height: 1.55;
  max-width: 88%;
}
.umat-bubble-student p { margin: 0; }

/* Source citation chips */
.umat-sources {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  margin-top: 8px;
  padding-left: 42px;
}
.umat-source-chip {
  padding: 3px 9px;
  background: var(--umat-sec-container);
  color: var(--umat-secondary);
  border-radius: var(--umat-radius-pill);
  font-size: 11px;
  font-weight: 600;
}

/* Typing indicator */
@keyframes umat-bounce {
  0%,60%,100% { transform: translateY(0); }
  30%          { transform: translateY(-6px); }
}
.umat-typing { display: flex; gap: 5px; align-items: center; padding: 10px 0; }
.umat-typing-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--umat-primary);
  animation: umat-bounce 1.2s infinite;
}
.umat-typing-dot:nth-child(2) { animation-delay: .2s; }
.umat-typing-dot:nth-child(3) { animation-delay: .4s; }

/* ---- Quick actions grid ---- */
.umat-quick-actions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  padding: 12px 16px 0;
  flex-shrink: 0;
}
.umat-quick-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 5px;
  padding: 11px 8px;
  border: 1px solid var(--umat-outline-var);
  background: var(--umat-surface-lowest);
  border-radius: var(--umat-radius-md);
  cursor: pointer;
  transition: all .2s;
  font-family: inherit;
}
.umat-quick-btn .material-symbols-outlined { font-size: 22px; color: var(--umat-primary); }
.umat-quick-btn span.label { font-size: 11px; color: var(--umat-on-surface); text-align: center; line-height: 1.3; }
.umat-quick-btn:hover { border-color: var(--umat-primary); background: rgba(129,251,156,.12); }

/* ---- Chat input area ---- */
.umat-input-area {
  padding: 12px 16px;
  background: var(--umat-surface-lowest);
  border-top: 1px solid var(--umat-outline-var);
  flex-shrink: 0;
}
.umat-input-row {
  display: flex;
  gap: 8px;
  align-items: flex-end;
}
.umat-textarea {
  flex: 1;
  padding: 10px 13px;
  border: 1.5px solid var(--umat-outline-var);
  border-radius: var(--umat-radius-md);
  font-size: 13px;
  font-family: inherit;
  resize: none;
  outline: none;
  line-height: 1.45;
  color: var(--umat-on-surface);
  background: var(--umat-surface);
  transition: border-color .2s;
}
.umat-textarea:focus { border-color: var(--umat-primary); box-shadow: 0 0 0 3px rgba(0,107,47,.1); }
.umat-send-btn {
  width: 42px;
  height: 42px;
  border-radius: var(--umat-radius-md);
  background: var(--umat-primary);
  color: var(--umat-on-primary);
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background .2s, transform .15s;
  flex-shrink: 0;
}
.umat-send-btn .material-symbols-outlined { font-size: 20px; }
.umat-send-btn:hover { background: var(--umat-primary-bright); transform: scale(1.05); }
.umat-input-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 7px;
}
.umat-rate-info { font-size: 10px; color: var(--umat-outline); }
.umat-rate-info.warn { color: var(--umat-tertiary); font-weight: 600; }
.umat-history-btn {
  background: none;
  border: none;
  color: var(--umat-primary);
  font-size: 11px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 3px;
  font-family: inherit;
}
.umat-history-btn .material-symbols-outlined { font-size: 14px; }

/* ---- Empty state ---- */
.umat-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 40px 24px;
  gap: 10px;
  color: var(--umat-outline);
  text-align: center;
  font-size: 13px;
}
.umat-empty-state .material-symbols-outlined { font-size: 48px; color: var(--umat-outline-var); }

/* ---- Lecturer panel extras ---- */
.umat-kpi-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  padding: 16px;
  flex-shrink: 0;
}
.umat-kpi-card {
  background: var(--umat-surface-lowest);
  border: 1px solid var(--umat-outline-var);
  border-radius: var(--umat-radius-md);
  padding: 12px;
}
.umat-kpi-card .kpi-icon {
  width: 30px;
  height: 30px;
  border-radius: var(--umat-radius-sm);
  background: rgba(0,107,47,.1);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--umat-primary);
  margin-bottom: 8px;
}
.umat-kpi-card .kpi-icon .material-symbols-outlined { font-size: 18px; }
.umat-kpi-card .kpi-label { font-size: 11px; color: var(--umat-outline); margin-bottom: 3px; }
.umat-kpi-card .kpi-value { font-size: 20px; font-weight: 700; color: var(--umat-on-surface); line-height: 1; }
.umat-kpi-card .kpi-badge {
  display: inline-flex;
  align-items: center;
  padding: 2px 7px;
  border-radius: var(--umat-radius-pill);
  font-size: 10px;
  font-weight: 700;
  margin-top: 4px;
}
.kpi-badge-ok   { background: #d1fae5; color: #065f46; }
.kpi-badge-warn { background: #fef3c7; color: #92400e; }
.kpi-badge-high { background: #fee2e2; color: #991b1b; }
.kpi-badge-info { background: var(--umat-sec-container); color: var(--umat-secondary); }

.umat-section-label {
  padding: 0 16px 8px;
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--umat-outline);
  flex-shrink: 0;
}

/* Insights cards */
.umat-insights-scroll {
  overflow-y: auto;
  flex: 1;
  padding: 0 16px 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.umat-insight-card {
  background: var(--umat-surface-lowest);
  border: 1px solid var(--umat-outline-var);
  border-radius: var(--umat-radius-md);
  padding: 14px;
}
.umat-insight-card.warn { border-left: 3px solid var(--umat-tertiary); }
.umat-insight-card.alert { border-left: 3px solid #f59e0b; }
.umat-insight-card.info  { border-left: 3px solid var(--umat-primary); }
.umat-insight-title {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 13px;
  font-weight: 700;
  color: var(--umat-on-surface);
  margin-bottom: 5px;
}
.umat-insight-title .material-symbols-outlined { font-size: 18px; }
.warn  .umat-insight-title .material-symbols-outlined { color: var(--umat-tertiary); }
.alert .umat-insight-title .material-symbols-outlined { color: #f59e0b; }
.info  .umat-insight-title .material-symbols-outlined { color: var(--umat-primary); }
.umat-insight-desc { font-size: 12px; color: var(--umat-on-surface-var); line-height: 1.5; margin-bottom: 10px; }
.umat-insight-actions { display: flex; flex-wrap: wrap; gap: 6px; }
.umat-chip-btn {
  padding: 5px 12px;
  border-radius: var(--umat-radius-pill);
  border: 1.5px solid var(--umat-primary);
  background: none;
  color: var(--umat-primary);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all .2s;
  font-family: inherit;
}
.umat-chip-btn:hover { background: var(--umat-primary); color: var(--umat-on-primary); }

/* Questions list */
.umat-q-item {
  padding: 10px 14px;
  background: var(--umat-surface);
  border-radius: var(--umat-radius-sm);
  margin-bottom: 6px;
  border: 1px solid var(--umat-outline-var);
}
.umat-q-text { font-size: 13px; color: var(--umat-on-surface); margin-bottom: 3px; line-height: 1.4; }
.umat-q-count { font-size: 11px; color: var(--umat-outline); }
.umat-q-count span { font-weight: 700; color: var(--umat-primary); }

/* Panel footer actions */
.umat-panel-footer {
  padding: 12px 16px;
  border-top: 1px solid var(--umat-outline-var);
  display: flex;
  flex-direction: column;
  gap: 8px;
  flex-shrink: 0;
  background: var(--umat-surface-lowest);
}
.umat-footer-btn {
  width: 100%;
  padding: 11px;
  border-radius: var(--umat-radius-md);
  border: none;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  transition: all .2s;
  font-family: inherit;
  text-decoration: none;
}
.umat-footer-btn .material-symbols-outlined { font-size: 18px; }
.umat-footer-btn-primary { background: var(--umat-primary); color: var(--umat-on-primary); }
.umat-footer-btn-primary:hover { background: var(--umat-primary-bright); }
.umat-footer-btn-outline {
  background: none;
  border: 1.5px solid var(--umat-primary);
  color: var(--umat-primary);
}
.umat-footer-btn-outline:hover { background: var(--umat-surface-low); }

/* Hub FAB mini panel */
.umat-hub-panel {
  background: var(--umat-surface-lowest);
  border-radius: var(--umat-radius-lg);
  padding: 16px;
  box-shadow: 0 8px 30px rgba(0,0,0,.15);
}
.umat-hub-session-item {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  padding: 10px;
  border-radius: var(--umat-radius-sm);
  cursor: pointer;
  transition: background .15s;
}
.umat-hub-session-item:hover { background: var(--umat-surface-low); }

/* Divider */
.umat-divider {
  height: 1px;
  background: var(--umat-outline-var);
  margin: 0 16px;
  flex-shrink: 0;
}

@media (max-width: 600px) {
  .umat-panel, .umat-panel.umat-panel-lec { width: 100vw; max-width: 100vw; }
}
</style>
HTML;
    }

    // ================================================================== //
    // STUDENT FAB                                                          //
    // ================================================================== //

    private static function get_student_fab(int $courseid, string $courseName, string $wwwroot): string {
        $safeCourseName = htmlspecialchars($courseName, ENT_QUOTES, 'UTF-8');
        $jsCourseName   = json_encode($courseName);
        $workspaceUrl   = $wwwroot . '/local/umat_ai/index.php?courseid=' . $courseid;
        $hubUrl         = $wwwroot . '/local/umat_ai/hub.php';

        return <<<HTML
<!-- ============================================================
     UMaT Student AI FAB — injected by before_footer hook
     ============================================================ -->

<!-- FAB Button -->
<button class="umat-fab umat-fab-pulse" id="umat-student-fab" aria-label="Open AI Assistant" type="button">
  <span class="material-symbols-outlined">smart_toy</span>
  <span class="umat-fab-tooltip">Ask UMaT AI Assistant</span>
</button>

<!-- Overlay + Side Panel -->
<div class="umat-overlay" id="umat-student-overlay" role="dialog" aria-modal="true" aria-label="AI Learning Assistant">
  <div class="umat-panel" id="umat-student-panel">

    <!-- Header -->
    <div class="umat-panel-header">
      <div class="umat-panel-header-row">
        <div class="umat-avatar">
          <span class="material-symbols-outlined">smart_toy</span>
          <span class="umat-status-dot" title="Online"></span>
        </div>
        <div class="umat-header-info">
          <h2>UMaT AI Assistant</h2>
          <div class="umat-status-text">
            <span style="width:6px;height:6px;border-radius:50%;background:#4ade80;display:inline-block;"></span>
            Online &amp; Ready
          </div>
          <div class="umat-course-ctx" title="{$safeCourseName}">{$safeCourseName}</div>
        </div>
        <button class="umat-icon-btn umat-expand-btn" id="umat-expand-btn" type="button" title="Expand to full workspace">
          <span class="material-symbols-outlined">open_in_full</span>
          <span>Expand</span>
        </button>
        <button class="umat-icon-btn" id="umat-close-btn" type="button" aria-label="Close">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
    </div>

    <!-- Tab Bar -->
    <div class="umat-tabs" role="tablist">
      <button class="umat-tab active" data-tab="chat" role="tab" type="button">
        <span class="material-symbols-outlined" style="font-size:15px;vertical-align:middle;margin-right:3px;">chat</span>Chat
      </button>
      <button class="umat-tab" data-tab="notes" role="tab" type="button">
        <span class="material-symbols-outlined" style="font-size:15px;vertical-align:middle;margin-right:3px;">description</span>Notes
      </button>
      <button class="umat-tab" data-tab="resources" role="tab" type="button">
        <span class="material-symbols-outlined" style="font-size:15px;vertical-align:middle;margin-right:3px;">folder</span>Resources
      </button>
    </div>

    <!-- ── TAB: Chat ── -->
    <div class="umat-tab-content active" id="umat-tab-chat" role="tabpanel">
      <!-- Quick actions -->
      <div class="umat-quick-actions" id="umat-quick-actions">
        <button class="umat-quick-btn" data-action="summarize" type="button">
          <span class="material-symbols-outlined">summarize</span>
          <span class="label">Summarize Lecture</span>
        </button>
        <button class="umat-quick-btn" data-action="assignment" type="button">
          <span class="material-symbols-outlined">quiz</span>
          <span class="label">About Assignment</span>
        </button>
        <button class="umat-quick-btn" data-action="explain" type="button">
          <span class="material-symbols-outlined">lightbulb</span>
          <span class="label">Explain Concept</span>
        </button>
        <button class="umat-quick-btn" data-action="deadlines" type="button">
          <span class="material-symbols-outlined">schedule</span>
          <span class="label">Deadlines</span>
        </button>
      </div>

      <!-- Messages -->
      <div class="umat-messages" id="umat-messages" aria-live="polite">
        <!-- Welcome bubble -->
        <div class="umat-msg-ai">
          <div class="umat-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div>
          <div class="umat-bubble-ai">
            <p>Hello! I'm your AI tutor for <strong>{$safeCourseName}</strong>. Ask me anything about your course materials or use the quick actions above. ✨</p>
          </div>
        </div>
      </div>

      <!-- Input area -->
      <div class="umat-input-area">
        <div class="umat-input-row">
          <textarea
            id="umat-question-input"
            class="umat-textarea"
            placeholder="Type your academic question…"
            rows="2"
            aria-label="Question input"
            maxlength="1000"
          ></textarea>
          <button class="umat-send-btn" id="umat-send-btn" type="button" aria-label="Send">
            <span class="material-symbols-outlined">send</span>
          </button>
        </div>
        <div class="umat-input-footer">
          <span class="umat-rate-info" id="umat-rate-info">10 questions remaining this minute</span>
          <button class="umat-history-btn" id="umat-history-btn" type="button">
            <span class="material-symbols-outlined">history</span>Past Sessions
          </button>
        </div>
      </div>
    </div>

    <!-- ── TAB: Notes ── -->
    <div class="umat-tab-content" id="umat-tab-notes" role="tabpanel">
      <div class="umat-empty-state">
        <span class="material-symbols-outlined">description</span>
        <p>AI-generated notes will appear here after your lecturer processes a lecture recording.</p>
      </div>
    </div>

    <!-- ── TAB: Resources ── -->
    <div class="umat-tab-content" id="umat-tab-resources" role="tabpanel">
      <div class="umat-empty-state">
        <span class="material-symbols-outlined">folder_open</span>
        <p>Course materials indexed for AI will appear here.</p>
      </div>
    </div>

  </div><!-- /umat-panel -->
</div><!-- /umat-student-overlay -->

<script>
(function() {
  'use strict';

  /* --- state --- */
  var courseId         = {$courseid};
  var courseName       = {$jsCourseName};
  var workspaceUrl     = '{$workspaceUrl}';
  var hubUrl           = '{$hubUrl}';
  var questionsLeft    = 10;
  var lastWindowStart  = Date.now();
  var sessionKey       = 'sess_' + Math.random().toString(36).substr(2, 16);

  /* --- elements --- */
  var fab      = document.getElementById('umat-student-fab');
  var overlay  = document.getElementById('umat-student-overlay');
  var panel    = document.getElementById('umat-student-panel');
  var closeBtn = document.getElementById('umat-close-btn');
  var expBtn   = document.getElementById('umat-expand-btn');
  var input    = document.getElementById('umat-question-input');
  var sendBtn  = document.getElementById('umat-send-btn');
  var messages = document.getElementById('umat-messages');
  var rateInfo = document.getElementById('umat-rate-info');
  var histBtn  = document.getElementById('umat-history-btn');

  /* --- helpers --- */
  function escHtml(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
  }

  function refreshRateDisplay() {
    var now = Date.now();
    if (now - lastWindowStart >= 60000) {
      questionsLeft  = 10;
      lastWindowStart = now;
    }
    var info = questionsLeft + ' question' + (questionsLeft !== 1 ? 's' : '') + ' remaining this minute';
    rateInfo.textContent = info;
    rateInfo.className   = 'umat-rate-info' + (questionsLeft <= 2 ? ' warn' : '');
  }

  function appendAiBubble(text, sources) {
    var sourcesHtml = '';
    if (sources && sources.length > 0) {
      sourcesHtml = '<div class="umat-sources">' +
        sources.map(function(s) { return '<span class="umat-source-chip">' + escHtml(s) + '</span>'; }).join('') +
        '</div>';
    }
    var div = document.createElement('div');
    div.innerHTML =
      '<div class="umat-msg-ai">' +
        '<div class="umat-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div>' +
        '<div class="umat-bubble-ai"><p>' + escHtml(text) + '</p></div>' +
      '</div>' + sourcesHtml;
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
  }

  function appendStudentBubble(text) {
    var div = document.createElement('div');
    div.className = 'umat-msg-student';
    div.innerHTML = '<div class="umat-bubble-student"><p>' + escHtml(text) + '</p></div>';
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
  }

  function showTyping() {
    var div = document.createElement('div');
    div.id  = 'umat-typing-el';
    div.className = 'umat-msg-ai';
    div.innerHTML =
      '<div class="umat-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div>' +
      '<div class="umat-bubble-ai"><div class="umat-typing">' +
        '<div class="umat-typing-dot"></div><div class="umat-typing-dot"></div><div class="umat-typing-dot"></div>' +
      '</div></div>';
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
  }

  function hideTyping() {
    var el = document.getElementById('umat-typing-el');
    if (el) el.parentNode.removeChild(el);
  }

  function sendQuestion(q) {
    q = (q || '').trim();
    if (!q) return;
    refreshRateDisplay();
    if (questionsLeft <= 0) {
      appendAiBubble('You\'ve reached the rate limit. Please wait a moment before asking another question.', []);
      return;
    }
    questionsLeft--;
    refreshRateDisplay();

    /* hide quick actions after first question */
    var qa = document.getElementById('umat-quick-actions');
    if (qa) qa.style.display = 'none';

    appendStudentBubble(q);
    if (input) input.value = '';
    showTyping();

    require(['core/ajax'], function(Ajax) {
      Ajax.call([{
        methodname: 'local_umat_ai_ask_question',
        args: { courseid: courseId, question: q, session_key: sessionKey }
      }])[0].done(function(r) {
        hideTyping();
        if (r.success) {
          appendAiBubble(r.answer, r.sources || []);
        } else {
          appendAiBubble('Sorry, something went wrong. Please try again.', []);
        }
      }).fail(function() {
        hideTyping();
        appendAiBubble('Error connecting to the AI service. Please check your connection.', []);
      });
    });
  }

  /* --- open/close --- */
  function openPanel() {
    overlay.classList.add('umat-open');
    fab.setAttribute('aria-expanded', 'true');
    setTimeout(function() { if (input) input.focus(); }, 350);
    refreshRateDisplay();
  }
  function closePanel() {
    overlay.classList.remove('umat-open');
    fab.setAttribute('aria-expanded', 'false');
  }

  /* --- tab switching --- */
  document.querySelectorAll('#umat-student-panel .umat-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
      var name = tab.dataset.tab;
      document.querySelectorAll('#umat-student-panel .umat-tab').forEach(function(t) { t.classList.remove('active'); });
      document.querySelectorAll('#umat-student-panel .umat-tab-content').forEach(function(c) { c.classList.remove('active'); });
      tab.classList.add('active');
      var tc = document.getElementById('umat-tab-' + name);
      if (tc) tc.classList.add('active');
    });
  });

  /* --- quick actions --- */
  document.querySelectorAll('.umat-quick-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var map = {
        summarize:  'Can you summarize the key points from this week\'s lecture?',
        assignment: 'What are the requirements for the current assignment?',
        explain:    'Can you explain the main concept covered in this week\'s material?',
        deadlines:  'What are the upcoming deadlines in this course?'
      };
      var q = map[btn.dataset.action] || btn.dataset.action;
      openPanel();
      sendQuestion(q);
    });
  });

  /* --- event wiring --- */
  fab.addEventListener('click', openPanel);
  closeBtn.addEventListener('click', closePanel);
  overlay.addEventListener('click', function(e) { if (e.target === overlay) closePanel(); });

  expBtn.addEventListener('click', function() {
    closePanel();
    window.location.href = workspaceUrl;
  });

  sendBtn.addEventListener('click', function() { sendQuestion(input.value); });
  input.addEventListener('keypress', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendQuestion(input.value); }
  });

  histBtn.addEventListener('click', function() {
    closePanel();
    window.location.href = hubUrl;
  });

  document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closePanel(); });

})();
</script>
HTML;
    }

    // ================================================================== //
    // LECTURER FAB                                                         //
    // ================================================================== //

    private static function get_lecturer_fab(int $courseid, string $courseName, int $pendingCount, string $wwwroot): string {
        $safeCourseName  = htmlspecialchars($courseName, ENT_QUOTES, 'UTF-8');
        $jsCourseName    = json_encode($courseName);
        $dashboardUrl    = $wwwroot . '/local/umat_ai/lecturer_dashboard.php?courseid=' . $courseid;
        $approveUrl      = $wwwroot . '/local/umat_ai/approve.php?courseid=' . $courseid;
        $badgeHtml       = $pendingCount > 0
            ? '<span class="umat-fab-badge">' . ($pendingCount > 9 ? '9+' : $pendingCount) . '</span>'
            : '';

        return <<<HTML
<!-- ============================================================
     UMaT Lecturer Analytics FAB — injected by before_footer hook
     ============================================================ -->

<!-- FAB -->
<button class="umat-fab" id="umat-lecturer-fab" aria-label="Open Lecturer Analytics" type="button" style="position:relative;">
  <span class="material-symbols-outlined">leaderboard</span>
  <span class="umat-fab-tooltip">Lecturer Analytics</span>
  {$badgeHtml}
</button>

<!-- Overlay + Side Panel -->
<div class="umat-overlay" id="umat-lecturer-overlay" role="dialog" aria-modal="true" aria-label="Lecturer Analytics Panel">
  <div class="umat-panel umat-panel-lec" id="umat-lecturer-panel">

    <!-- Header -->
    <div class="umat-panel-header">
      <div class="umat-panel-header-row">
        <div class="umat-avatar">
          <span class="material-symbols-outlined">analytics</span>
        </div>
        <div class="umat-header-info">
          <h2>Lecturer Analytics</h2>
          <div class="umat-course-ctx" title="{$safeCourseName}">{$safeCourseName}</div>
        </div>
        <button class="umat-icon-btn" id="umat-lec-close-btn" type="button" aria-label="Close">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
    </div>

    <!-- Tab Bar -->
    <div class="umat-tabs" role="tablist">
      <button class="umat-tab active" data-tab="lec-insights" role="tab" type="button">
        <span class="material-symbols-outlined" style="font-size:15px;vertical-align:middle;margin-right:3px;">bolt</span>Insights
      </button>
      <button class="umat-tab" data-tab="lec-questions" role="tab" type="button">
        <span class="material-symbols-outlined" style="font-size:15px;vertical-align:middle;margin-right:3px;">quiz</span>Questions
      </button>
      <button class="umat-tab" data-tab="lec-ai" role="tab" type="button">
        <span class="material-symbols-outlined" style="font-size:15px;vertical-align:middle;margin-right:3px;">smart_toy</span>Ask AI
      </button>
    </div>

    <!-- ── TAB: Insights ── -->
    <div class="umat-tab-content active" id="umat-tab-lec-insights" role="tabpanel" style="overflow-y:auto;">

      <!-- KPI cards (populated by JS) -->
      <div class="umat-kpi-grid" id="umat-kpi-grid">
        <div class="umat-kpi-card">
          <div class="kpi-icon"><span class="material-symbols-outlined">group</span></div>
          <div class="kpi-label">Active Students</div>
          <div class="kpi-value" id="kpi-active">—</div>
          <div class="kpi-badge kpi-badge-ok" id="kpi-active-badge">Loading…</div>
        </div>
        <div class="umat-kpi-card">
          <div class="kpi-icon" style="background:rgba(61,104,68,.12);color:var(--umat-secondary);">
            <span class="material-symbols-outlined">forum</span>
          </div>
          <div class="kpi-label">AI Interactions</div>
          <div class="kpi-value" id="kpi-interactions">—</div>
          <div class="kpi-badge kpi-badge-info" id="kpi-interactions-badge">30 days</div>
        </div>
        <div class="umat-kpi-card">
          <div class="kpi-icon" style="background:rgba(165,48,77,.1);color:var(--umat-tertiary);">
            <span class="material-symbols-outlined">psychology_alt</span>
          </div>
          <div class="kpi-label">Struggle Index</div>
          <div class="kpi-value" style="font-size:15px;" id="kpi-struggle">—</div>
          <div class="kpi-badge kpi-badge-warn" id="kpi-struggle-badge">Most asked</div>
        </div>
        <div class="umat-kpi-card">
          <div class="kpi-icon" style="background:rgba(245,158,11,.12);color:#d97706;">
            <span class="material-symbols-outlined">pending_actions</span>
          </div>
          <div class="kpi-label">Pending Review</div>
          <div class="kpi-value" id="kpi-pending">{$pendingCount}</div>
          <div class="kpi-badge kpi-badge-warn" id="kpi-pending-badge">{$pendingCount} outputs</div>
        </div>
      </div>

      <div class="umat-divider"></div>

      <!-- AI Insight Cards (static, always relevant) -->
      <div style="padding:14px 16px 6px;">
        <div class="umat-section-label" style="padding:0 0 10px;">AI Insights</div>
        <div style="display:flex;flex-direction:column;gap:10px;">

          <div class="umat-insight-card warn" id="lec-insight-gap">
            <div class="umat-insight-title">
              <span class="material-symbols-outlined">warning</span>
              <span id="insight-gap-title">Checking for learning gaps…</span>
            </div>
            <div class="umat-insight-desc" id="insight-gap-desc">Analysing student question patterns to identify struggle zones.</div>
            <div class="umat-insight-actions">
              <button class="umat-chip-btn" onclick="window.location.href='{$dashboardUrl}'">View Full Dashboard</button>
              <button class="umat-chip-btn" onclick="window.location.href='{$approveUrl}'">Review AI Outputs</button>
            </div>
          </div>

          <div class="umat-insight-card alert">
            <div class="umat-insight-title">
              <span class="material-symbols-outlined">notifications_active</span>
              Student Engagement Alert
            </div>
            <div class="umat-insight-desc">Monitor student AI usage patterns on the full dashboard to identify at-risk students early.</div>
            <div class="umat-insight-actions">
              <button class="umat-chip-btn" onclick="window.location.href='{$dashboardUrl}#engagement'">See Engagement</button>
            </div>
          </div>

        </div>
      </div>

      <!-- Footer actions -->
      <div class="umat-panel-footer" style="margin-top:auto;">
        <a href="{$dashboardUrl}" class="umat-footer-btn umat-footer-btn-primary">
          <span class="material-symbols-outlined">dashboard</span>
          Open Full Analytics Dashboard
        </a>
        <a href="{$approveUrl}" class="umat-footer-btn umat-footer-btn-outline">
          <span class="material-symbols-outlined">fact_check</span>
          Review AI Outputs ({$pendingCount} pending)
        </a>
      </div>

    </div><!-- /lec-insights -->

    <!-- ── TAB: Student Questions ── -->
    <div class="umat-tab-content" id="umat-tab-lec-questions" role="tabpanel">
      <div style="padding:14px 16px 6px 16px;flex-shrink:0;">
        <div class="umat-section-label" style="padding:0 0 10px;">Common Student Questions</div>
        <div id="umat-questions-list" style="display:flex;flex-direction:column;gap:6px;">
          <div style="text-align:center;padding:20px;color:var(--umat-outline);font-size:13px;">
            <span class="material-symbols-outlined" style="font-size:36px;display:block;margin-bottom:8px;color:var(--umat-outline-var);">data_usage</span>
            Loading student questions…
          </div>
        </div>
      </div>
    </div>

    <!-- ── TAB: Ask AI ── -->
    <div class="umat-tab-content" id="umat-tab-lec-ai" role="tabpanel" style="flex-direction:column;">
      <div class="umat-messages" id="umat-lec-messages" style="flex:1;">
        <div class="umat-msg-ai">
          <div class="umat-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div>
          <div class="umat-bubble-ai">
            <p>Hello! I can help you understand your course analytics. Ask me things like <em>"Which topics are students struggling with?"</em> or <em>"Summarise this week's student questions."</em></p>
          </div>
        </div>
      </div>

      <!-- Quick prompts for lecturer -->
      <div style="padding:10px 16px 0;display:flex;flex-wrap:wrap;gap:6px;flex-shrink:0;">
        <button class="umat-chip-btn" data-lec-prompt="Which topics are students struggling with the most?" type="button">Struggle areas</button>
        <button class="umat-chip-btn" data-lec-prompt="Summarise the most common student questions this week." type="button">Weekly summary</button>
        <button class="umat-chip-btn" data-lec-prompt="Which students are at risk based on AI interaction patterns?" type="button">At-risk students</button>
      </div>

      <div class="umat-input-area">
        <div class="umat-input-row">
          <textarea
            id="umat-lec-input"
            class="umat-textarea"
            placeholder="Ask AI about your course…"
            rows="2"
            maxlength="800"
          ></textarea>
          <button class="umat-send-btn" id="umat-lec-send-btn" type="button">
            <span class="material-symbols-outlined">send</span>
          </button>
        </div>
      </div>
    </div>

  </div><!-- /umat-panel -->
</div><!-- /umat-lecturer-overlay -->

<script>
(function() {
  'use strict';

  var courseId  = {$courseid};
  var fab       = document.getElementById('umat-lecturer-fab');
  var overlay   = document.getElementById('umat-lecturer-overlay');
  var closeBtn  = document.getElementById('umat-lec-close-btn');
  var analyticsLoaded = false;

  function escHtml(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
  }

  /* --- open/close --- */
  function openPanel() {
    overlay.classList.add('umat-open');
    fab.setAttribute('aria-expanded', 'true');
    if (!analyticsLoaded) loadAnalytics();
  }
  function closePanel() {
    overlay.classList.remove('umat-open');
    fab.setAttribute('aria-expanded', 'false');
  }

  fab.addEventListener('click', openPanel);
  closeBtn.addEventListener('click', closePanel);
  overlay.addEventListener('click', function(e) { if (e.target === overlay) closePanel(); });
  document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closePanel(); });

  /* --- tab switching --- */
  document.querySelectorAll('#umat-lecturer-panel .umat-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
      var name = tab.dataset.tab;
      document.querySelectorAll('#umat-lecturer-panel .umat-tab').forEach(function(t) { t.classList.remove('active'); });
      document.querySelectorAll('#umat-lecturer-panel .umat-tab-content').forEach(function(c) { c.classList.remove('active'); });
      tab.classList.add('active');
      var tc = document.getElementById('umat-tab-' + name);
      if (tc) tc.classList.add('active');
    });
  });

  /* --- Load analytics data via AJAX --- */
  function loadAnalytics() {
    require(['core/ajax'], function(Ajax) {
      Ajax.call([{
        methodname: 'local_umat_ai_get_analytics',
        args: { courseid: courseId, days: 30 }
      }])[0].done(function(data) {
        analyticsLoaded = true;

        /* KPI cards */
        var activeEl = document.getElementById('kpi-active');
        var intEl    = document.getElementById('kpi-interactions');
        var strEl    = document.getElementById('kpi-struggle');
        var pendEl   = document.getElementById('kpi-pending');
        var abEl     = document.getElementById('kpi-active-badge');

        if (activeEl) activeEl.textContent = data.active_students + ' / ' + data.enrolled_students;
        if (intEl)    intEl.textContent    = data.total_interactions;
        if (strEl)    strEl.textContent    = data.struggle_index;
        if (pendEl)   pendEl.textContent   = data.pending_approvals;
        if (abEl)     abEl.textContent     = Math.round(data.active_students / Math.max(data.enrolled_students, 1) * 100) + '% active';

        /* Insight card */
        var gapTitle = document.getElementById('insight-gap-title');
        var gapDesc  = document.getElementById('insight-gap-desc');
        if (gapTitle && data.struggle_index !== 'N/A') {
          gapTitle.textContent = 'Learning Gap: ' + data.struggle_index;
          gapDesc.textContent  = 'Students are asking the most questions in ' + data.struggle_index + '. Consider scheduling a review or adding supplementary material.';
        }

        /* Top questions list */
        var qList = document.getElementById('umat-questions-list');
        if (qList && data.top_questions && data.top_questions.length > 0) {
          qList.innerHTML = data.top_questions.map(function(q) {
            return '<div class="umat-q-item">' +
              '<div class="umat-q-text">' + escHtml(q.text) + '</div>' +
              '<div class="umat-q-count"><span>' + q.ask_count + '</span> student' + (q.ask_count !== 1 ? 's' : '') + ' asked this</div>' +
            '</div>';
          }).join('');
        } else if (qList) {
          qList.innerHTML = '<div style="text-align:center;padding:20px;color:var(--umat-outline);font-size:13px;">No questions logged yet for this course.</div>';
        }

      }).fail(function() {
        var gapTitle = document.getElementById('insight-gap-title');
        if (gapTitle) gapTitle.textContent = 'Analytics unavailable';
      });
    });
  }

  /* --- Lecturer AI chat --- */
  var lecMessages = document.getElementById('umat-lec-messages');
  var lecInput    = document.getElementById('umat-lec-input');
  var lecSendBtn  = document.getElementById('umat-lec-send-btn');

  function appendLecAi(text) {
    var div = document.createElement('div');
    div.innerHTML =
      '<div class="umat-msg-ai">' +
        '<div class="umat-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div>' +
        '<div class="umat-bubble-ai"><p>' + escHtml(text) + '</p></div>' +
      '</div>';
    lecMessages.appendChild(div);
    lecMessages.scrollTop = lecMessages.scrollHeight;
  }

  function appendLecUser(text) {
    var div = document.createElement('div');
    div.className = 'umat-msg-student';
    div.innerHTML = '<div class="umat-bubble-student"><p>' + escHtml(text) + '</p></div>';
    lecMessages.appendChild(div);
    lecMessages.scrollTop = lecMessages.scrollHeight;
  }

  function showLecTyping() {
    var div = document.createElement('div');
    div.id  = 'umat-lec-typing';
    div.innerHTML =
      '<div class="umat-msg-ai"><div class="umat-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div>' +
      '<div class="umat-bubble-ai"><div class="umat-typing">' +
        '<div class="umat-typing-dot"></div><div class="umat-typing-dot"></div><div class="umat-typing-dot"></div>' +
      '</div></div></div>';
    lecMessages.appendChild(div);
    lecMessages.scrollTop = lecMessages.scrollHeight;
  }

  function hideLecTyping() {
    var el = document.getElementById('umat-lec-typing');
    if (el) el.parentNode.removeChild(el);
  }

  function sendLecQuestion(q) {
    q = (q || '').trim();
    if (!q) return;
    appendLecUser(q);
    if (lecInput) lecInput.value = '';
    showLecTyping();

    require(['core/ajax'], function(Ajax) {
      Ajax.call([{
        methodname: 'local_umat_ai_lecturer_ask',
        args: { courseid: courseId, query: q }
      }])[0].done(function(r) {
        hideLecTyping();
        appendLecAi(r.response || 'Sorry, the AI could not process your request.');
      }).fail(function() {
        hideLecTyping();
        appendLecAi('Error contacting AI service. Please make sure the AI service is running.');
      });
    });
  }

  if (lecSendBtn) lecSendBtn.addEventListener('click', function() { sendLecQuestion(lecInput.value); });
  if (lecInput)   lecInput.addEventListener('keypress', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendLecQuestion(lecInput.value); }
  });

  /* Quick prompts */
  document.querySelectorAll('[data-lec-prompt]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      /* Switch to Ask AI tab first */
      document.querySelectorAll('#umat-lecturer-panel .umat-tab').forEach(function(t) { t.classList.remove('active'); });
      document.querySelectorAll('#umat-lecturer-panel .umat-tab-content').forEach(function(c) { c.classList.remove('active'); });
      var aiTab = document.querySelector('[data-tab="lec-ai"]');
      if (aiTab) aiTab.classList.add('active');
      var aiContent = document.getElementById('umat-tab-lec-ai');
      if (aiContent) aiContent.classList.add('active');
      sendLecQuestion(btn.dataset.lecPrompt);
    });
  });

})();
</script>
HTML;
    }

    // ================================================================== //
    // HUB FAB — non-course pages                                          //
    // ================================================================== //

    private static function get_hub_fab(string $wwwroot): string {
        $hubUrl = $wwwroot . '/local/umat_ai/hub.php';

        return <<<HTML
<!-- UMaT Hub FAB (non-course pages) -->
<a
  href="{$hubUrl}"
  id="umat-hub-fab"
  class="umat-fab"
  title="AI Learning Hub"
  aria-label="Open AI Learning Hub"
  style="text-decoration:none;"
>
  <span class="material-symbols-outlined">forum</span>
  <span class="umat-fab-tooltip">AI Learning Hub</span>
</a>
HTML;
    }
}
