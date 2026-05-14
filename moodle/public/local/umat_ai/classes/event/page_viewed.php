<?php
/**
 * Event handler to inject AI FAB on course pages.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\event;

defined('MOODLE_INTERNAL') || die();

class page_viewed {

    /**
     * Handle page view event - inject FAB if on a course page.
     *
     * @param \core\event\base $event The event object.
     * @return bool True if FAB was injected, false otherwise.
     */
    public static function handle(\core\event\base $event) {
        global $PAGE, $COURSE, $USER, $OUTPUT;

        // Only for logged-in non-guest users
        if (!isloggedin() || isguestuser()) {
            return true;
        }

        // Only for logged-in non-guest users
        if (!isloggedin() || isguestuser()) {
            return true;
        }

        // Get the context
        $context = $PAGE->context;
        if (!$context) {
            return true;
        }

        // Check if we're in a course context or on a course-related page
        $courseid = 0;

        if ($context->contextlevel === CONTEXT_COURSE) {
            $courseid = $context->instanceid;
        } elseif (!empty($COURSE->id) && $COURSE->id != SITEID) {
            // Check if this is a child context of a course (module, section, etc.)
            $parentcontexts = $context->get_parent_contexts();
            foreach ($parentcontexts as $parent) {
                if ($parent->contextlevel === CONTEXT_COURSE) {
                    $courseid = $parent->instanceid;
                    break;
                }
            }
        }

        // Also check URL path for course-related pages
        if (!$courseid) {
            $path = $PAGE->url->get_path();
            if (strpos($path, '/course/') !== false ||
                strpos($path, '/mod/') !== false ||
                strpos($path, '/section/') !== false) {
                $courseid = !empty($COURSE->id) ? $COURSE->id : 0;
            }
        }

        if (!$courseid || $courseid == SITEID) {
            return true;
        }

        // Check if user is enrolled
        $coursecontext = \context_course::instance($courseid);
        $isenrolled = is_enrolled($coursecontext, $USER, '', false);

        if (!$isenrolled) {
            return true;
        }

        // Render the FAB template
        try {
            $templateData = [
                'courseid' => $courseid,
                'coursename' => format_string($COURSE->fullname, true, ['context' => $coursecontext]),
                'has_capability' => true,
            ];

            $fabhtml = $OUTPUT->render_from_template('local_umat_ai/ai_fab', $templateData);

            // Add to page requires - will be added to footer
            $PAGE->requires->js_amd_inline("(function() {
                if (document.body) {
                    document.body.insertAdjacentHTML('beforeend', " . json_encode($fabhtml) . ");
                }
            })();");

        } catch (\Exception $e) {
            // Silently fail - don't break the page
            return true;
        }

        return true;
    }
}