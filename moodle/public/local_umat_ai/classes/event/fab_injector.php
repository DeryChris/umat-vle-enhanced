<?php
/**
 * Event observer to inject FAB on course pages.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Observer class for page events.
 */
class fab_injector {

    /**
     * Handle page rendered event.
     *
     * @param \core\event\base $event The event.
     * @return bool
     */
    public static function handle_page_rendered(\core\event\base $event) {
        global $PAGE, $COURSE, $USER;

        // Only for logged-in non-guest users
        if (!isloggedin() || isguestuser()) {
            return true;
        }

        // Get course ID
        $courseid = 0;
        $context = $PAGE->context;

        if ($context && $context->contextlevel === CONTEXT_COURSE) {
            $courseid = $context->instanceid;
        } elseif (!empty($COURSE->id) && $COURSE->id != SITEID) {
            $courseid = $COURSE->id;
        }

        if (!$courseid) {
            return true;
        }

        // Check enrollment
        $coursecontext = \context_course::instance($courseid);
        if (!is_enrolled($coursecontext, $USER, '', false)) {
            return true;
        }

        // Inject FAB via JavaScript
        $coursename = format_string($COURSE->fullname, true, ['context' => $coursecontext]);
        $js = self::get_fab_js($courseid, $coursename);

        $PAGE->requires->js_amd_inline($js);

        return true;
    }

    private static function get_fab_js($courseid, $coursename) {
        return sprintf('
(function() {
    if (document.getElementById("umat-fab-btn")) return;
    var courseId = %d;
    var courseName = "%s";
    var fab = document.createElement("button");
    fab.id = "umat-fab-btn";
    fab.style.cssText = "position:fixed;bottom:80px;right:24px;z-index:9999;width:60px;height:60px;border-radius:50%%;background:linear-gradient(135deg,%%23006b2f,%%2300873d);color:white;border:none;box-shadow:0 6px 20px rgba(0,107,47,0.4);cursor:pointer;display:flex;align-items:center;justify-content:center;";
    fab.innerHTML = \'<span style="font-size:32px" class="material-symbols-outlined">smart_toy</span>\';
    fab.title = "AI Assistant";

    var ws = document.createElement("div");
    ws.id = "umat-workspace";
    ws.style.cssText = "display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.5);";

    var panel = document.createElement("div");
    panel.style.cssText = "position:absolute;right:0;top:0;bottom:0;width:400px;max-width:95vw;background:%%23f8faf7;box-shadow:-10px 0 40px rgba(0,0,0,0.15);padding:20px;border-radius:16px 0 0 0;";

    panel.innerHTML = \'<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;"><div style="width:48px;height:48px;border-radius:50%%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;"><span class="material-symbols-outlined" style="font-size:28px;color:white">smart_toy</span></div><div><h3 style="margin:0;color:white;font-size:18px;">AI Learning Assistant</h3><div style="color:rgba(255,255,255,0.8);font-size:12px;">\'+courseName+\'</div></div><button id="umat-close-ws" style="margin-left:auto;background:rgba(255,255,255,0.2);border:none;color:white;width:36px;height:36px;border-radius:50%%;cursor:pointer;"><span class="material-symbols-outlined">close</span></button></div><div id="chat-messages" style="flex:1;overflow-y:auto;max-height:300px;margin-bottom:16px;"><div style="padding:12px;border-radius:12px;background:white;border-left:3px solid %%23006b2f;max-width:85%%;font-size:13px;">Hello! I\'m your AI learning assistant. Ask me anything about this course.</div></div><div style="display:flex;gap:8px;"><textarea id="umat-input" placeholder="Ask a question..." rows="2" style="flex:1;padding:10px;border:1px solid %%23e5e7eb;border-radius:8px;font-size:13px;resize:none;"></textarea><button id="umat-send" style="width:40px;height:40px;border-radius:8px;background:%%23006b2f;color:white;border:none;cursor:pointer;"><span class="material-symbols-outlined">send</span></button></div>\';

    ws.appendChild(panel);
    document.body.appendChild(fab);
    document.body.appendChild(ws);

    var close = document.getElementById("umat-close-ws");
    var input = document.getElementById("umat-input");
    var send = document.getElementById("umat-send");
    var msgs = document.getElementById("chat-messages");

    fab.addEventListener("click", function() { ws.style.display = "block"; setTimeout(function(){input.focus();}, 100); });
    close.addEventListener("click", function() { ws.style.display = "none"; });
    ws.addEventListener("click", function(e) { if(e.target === ws) ws.style.display = "none"; });

    function sendQ() {
        var q = input.value.trim();
        if (!q) return;
        var um = document.createElement("div");
        um.style.cssText = "align-self:flex-end;background:%%23d1fae5;padding:12px;border-radius:12px;max-width:85%%;font-size:13px;margin:8px 0;";
        um.textContent = q;
        msgs.appendChild(um);
        input.value = "";
        var typ = document.createElement("div");
        typ.style.cssText = "padding:12px;border-radius:12px;background:white;border-left:3px solid %%23006b2f;max-width:85%%;font-size:13px;margin:8px 0;";
        typ.innerHTML = "<em>Thinking...</em>";
        msgs.appendChild(typ);
        msgs.scrollTop = msgs.scrollHeight;
        require(["core/ajax"], function(Ajax) {
            Ajax.call([{methodname:"local_umat_ai_ask_question", args:{courseid:courseId, question:q}}])[0].done(function(r) {
                typ.remove();
                if (r.success) {
                    var am = document.createElement("div");
                    am.style.cssText = "padding:12px;border-radius:12px;background:white;border-left:3px solid %%23006b2f;max-width:85%%;font-size:13px;margin:8px 0;";
                    am.textContent = r.answer || "Got response";
                    msgs.appendChild(am);
                }
                msgs.scrollTop = msgs.scrollHeight;
            }).fail(function() { typ.remove(); });
        });
    }

    send.addEventListener("click", sendQ);
    input.addEventListener("keypress", function(e) { if(e.key === "Enter" && !e.shiftKey) { e.preventDefault(); sendQ(); }});
})();
', $courseid, addslashes($coursename));
    }
}