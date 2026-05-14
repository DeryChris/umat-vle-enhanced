<?php
/**
 * Inject global FAB on all pages via output callback.
 * This is a fallback for when hooks don't work.
 */

namespace local_umat_ai\output;

use moodle_page;

class before_output {

    /**
     * Called by Moodle to allow plugins to inject content.
     * This runs before the page is rendered.
     *
     * @param moodle_page $page The page being rendered.
     * @param string $html The current HTML.
     * @return string Modified HTML.
     */
    public static function inject_fab(moodle_page $page, $html) {
        global $PAGE, $COURSE, $USER;

        // Only run once
        static $injected = false;
        if ($injected) {
            return $html;
        }
        $injected = true;

        // Only for logged-in non-guest users
        if (!isloggedin() || isguestuser()) {
            return $html;
        }

        // Only inject on course pages
        $path = $PAGE->url->get_path();
        if (strpos($path, '/course/') === false && strpos($path, '/mod/') === false) {
            return $html;
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
            return $html;
        }

        // Check enrollment
        $coursecontext = \context_course::instance($courseid);
        if (!is_enrolled($coursecontext, $USER, '', false)) {
            return $html;
        }

        $coursename = format_string($COURSE->fullname, true, ['context' => $coursecontext]);

        // Inject before </body>
        $fab = self::get_fab_html($courseid, $coursename);
        $html = str_replace('</body>', $fab . '</body>', $html);

        return $html;
    }

    private static function get_fab_html($courseid, $coursename) {
        return '
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />
<button id="umat-fab-btn" style="position:fixed;bottom:80px;right:24px;z-index:9999;width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#006b2f,#00873d);color:white;border:none;box-shadow:0 6px 20px rgba(0,107,47,0.4);cursor:pointer;display:flex;align-items:center;justify-content:center;">
    <span style="font-size:32px" class="material-symbols-outlined">smart_toy</span>
</button>
<div id="umat-workspace" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.5);">
    <div style="position:absolute;right:0;top:0;bottom:0;width:400px;max-width:95vw;background:#f8faf7;box-shadow:-10px 0 40px rgba(0,0,0,0.15);padding:20px;border-radius:16px 0 0 0;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
            <div style="width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;">
                <span class="material-symbols-outlined" style="font-size:28px;color:white">smart_toy</span>
            </div>
            <div>
                <h3 style="margin:0;color:white;font-size:18px;">AI Learning Assistant</h3>
                <div style="color:rgba(255,255,255,0.8);font-size:12px;">' . htmlspecialchars($coursename) . '</div>
            </div>
            <button id="umat-close-ws" style="margin-left:auto;background:rgba(255,255,255,0.2);border:none;color:white;width:36px;height:36px;border-radius:50%;cursor:pointer;">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div id="chat-messages" style="flex:1;overflow-y:auto;max-height:300px;margin-bottom:16px;">
            <div style="padding:12px;border-radius:12px;background:white;border-left:3px solid #006b2f;max-width:85%;font-size:13px;">
                Hello! I\'m your AI learning assistant. Ask me anything about this course.
            </div>
        </div>
        <div style="display:flex;gap:8px;">
            <textarea id="umat-input" placeholder="Ask a question..." rows="2" style="flex:1;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;resize:none;"></textarea>
            <button id="umat-send" style="width:40px;height:40px;border-radius:8px;background:#006b2f;color:white;border:none;cursor:pointer;">
                <span class="material-symbols-outlined">send</span>
            </button>
        </div>
    </div>
</div>
<script>
(function() {
    var fab = document.getElementById(\'umat-fab-btn\');
    var ws = document.getElementById(\'umat-workspace\');
    var close = document.getElementById(\'umat-close-ws\');
    var input = document.getElementById(\'umat-input\');
    var send = document.getElementById(\'umat-send\');
    var courseId = ' . $courseid . ';
    var msgs = document.getElementById(\'chat-messages\');

    if (!fab || !ws) return;

    fab.addEventListener(\'click\', function() { ws.style.display = \'block\'; setTimeout(function(){input.focus();}, 100); });
    close.addEventListener(\'click\', function() { ws.style.display = \'none\'; });
    ws.addEventListener(\'click\', function(e) { if(e.target === ws) ws.style.display = \'none\'; });

    function sendQ() {
        var q = input.value.trim();
        if (!q) return;
        var um = document.createElement(\'div\');
        um.style.cssText = \'align-self:flex-end;background:#d1fae5;padding:12px;border-radius:12px;max-width:85%;font-size:13px;margin:8px 0;\';
        um.textContent = q;
        msgs.appendChild(um);
        input.value = \'\';
        var typ = document.createElement(\'div\');
        typ.style.cssText = \'padding:12px;border-radius:12px;background:white;border-left:3px solid #006b2f;max-width:85%;font-size:13px;margin:8px 0;\';
        typ.innerHTML = \'<em>Thinking...</em>\';
        msgs.appendChild(typ);
        msgs.scrollTop = msgs.scrollHeight;

        require([\'core/ajax\'], function(Ajax) {
            Ajax.call([{methodname:\'local_umat_ai_ask_question\', args:{courseid:courseId, question:q}}])[0].done(function(r) {
                typ.remove();
                if (r.success) {
                    var am = document.createElement(\'div\');
                    am.style.cssText = \'padding:12px;border-radius:12px;background:white;border-left:3px solid #006b2f;max-width:85%;font-size:13px;margin:8px 0;\';
                    am.textContent = r.answer || \'Got response\';
                    msgs.appendChild(am);
                } else {
                    var err = document.createElement(\'div\');
                    err.style.cssText = \'padding:12px;border-radius:12px;background:white;border-left:3px solid #006b2f;max-width:85%;font-size:13px;margin:8px 0;\';
                    err.textContent = \'Error: \' + (r.error || \'Something went wrong\');
                    msgs.appendChild(err);
                }
                msgs.scrollTop = msgs.scrollHeight;
            }).fail(function() {
                typ.remove();
                var err = document.createElement(\'div\');
                err.style.cssText = \'padding:12px;border-radius:12px;background:white;border-left:3px solid #006b2f;max-width:85%;font-size:13px;margin:8px 0;\';
                err.textContent = \'Connection error\';
                msgs.appendChild(err);
                msgs.scrollTop = msgs.scrollHeight;
            });
        });
    }

    send.addEventListener(\'click\', sendQ);
    input.addEventListener(\'keypress\', function(e) { if(e.key === \'Enter\' && !e.shiftKey) { e.preventDefault(); sendQ(); }});
})();
</script>';
    }
}