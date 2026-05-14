<?php
/**
 * Hook to inject AI FAB on all course pages.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\hooks;

use core\hook\output\before_footer_html_generation;

/**
 * Hook listener for before_footer_html_generation.
 */
class before_footer {

    /**
     * Inject the AI FAB on course pages.
     *
     * @param before_footer_html_generation $hook The hook object.
     * @return void
     */
    public static function handle(before_footer_html_generation $hook): void {
        global $PAGE, $COURSE, $USER;

        // Debug: write to a file to confirm hook is running
        @file_put_contents(__DIR__ . '/../../../debug_hook.txt', date('Y-m-d H:i:s') . ' - ' . $PAGE->url->get_path() . "\n", FILE_APPEND);

        // Only for logged-in non-guest users
        if (!isloggedin() || isguestuser()) {
            return;
        }

        // Only inject on course-related pages
        $path = $PAGE->url->get_path();
        if (strpos($path, '/course/') === false &&
            strpos($path, '/mod/') === false &&
            strpos($path, '/section/') === false) {
            return;
        }

        // Get course ID from various sources
        $courseid = 0;
        $context = $PAGE->context;

        if ($context && $context->contextlevel === CONTEXT_COURSE) {
            $courseid = $context->instanceid;
        } elseif (!empty($COURSE->id) && $COURSE->id != SITEID) {
            $courseid = $COURSE->id;
        }

        if (!$courseid) {
            return;
        }

        // Check if user is enrolled in the course
        $coursecontext = \context_course::instance($courseid);
        if (!is_enrolled($coursecontext, $USER, '', false)) {
            return;
        }

        @file_put_contents(__DIR__ . '/../../../debug_hook.txt', "Injecting FAB for course $courseid\n", FILE_APPEND);

        // Directly inject HTML without relying on AMD module
        $fabhtml = self::get_fab_html($courseid, format_string($COURSE->fullname, true, ['context' => $coursecontext]));
        $hook->add_html($fabhtml);
    }

    private static function get_fab_html($courseid, $coursename) {
        // Simplified inline HTML
        return '
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />
        <script>
        (function() {
            if (document.getElementById("umat-fab-injected")) return;
            document.getElementById("umat-fab-injected");
        })();
        </script>
        <button id="umat-fab-btn" style="position:fixed;bottom:80px;right:24px;z-index:9999;width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#006b2f,#00873d);color:white;border:none;box-shadow:0 6px 20px rgba(0,107,47,0.4);cursor:pointer;display:flex;align-items:center;justify-content:center;">
            <span style="font-size:32px" class="material-symbols-outlined">smart_toy</span>
        </button>
        <div id="umat-workspace" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.5);align-items:flex-end;justify-content:flex-end;">
            <div style="width:400px;max-width:95vw;background:#f8faf7;height:80vh;border-radius:16px 16px 0 0;padding:20px;">
                <h3>AI Assistant - ' . addslashes($coursename) . '</h3>
                <p>Course ID: ' . $courseid . '</p>
                <button id="umat-close-ws" style="padding:8px 16px;background:#006b2f;color:white;border:none;border-radius:6px;cursor:pointer;">Close</button>
            </div>
        </div>
        <script>
        (function() {
            var fab = document.getElementById("umat-fab-btn");
            var ws = document.getElementById("umat-workspace");
            var close = document.getElementById("umat-close-ws");
            if (fab) fab.addEventListener("click", function() { ws.style.display = "flex"; });
            if (close) close.addEventListener("click", function() { ws.style.display = "none"; });
            if (ws) ws.addEventListener("click", function(e) { if(e.target === ws) ws.style.display = "none"; });
        })();
        </script>
        ';
    }
}