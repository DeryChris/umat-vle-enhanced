<?php

namespace local_umat_ai\hooks;

use core\hook\output\before_footer_html_generation;

class before_footer {

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
        $ts = filemtime(__DIR__ . '/../../styles/umat-dashboard.css');
        $sdts = filemtime(__DIR__ . '/../../styles/umat-struggle-dashboard.css');

        $hook->add_html('<link rel="preconnect" href="https://cdnjs.cloudflare.com">');
        $hook->add_html('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">');
        $hook->add_html('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">');
        $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-overlay.css?v=' . $ts . '">');
        $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-yt-grid.css?v=' . $ts . '">');
        $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-viewers.css?v=' . $ts . '">');
        $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-dashboard.css?v=' . $ts . '">');
        $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-notes.css?v=' . $ts . '">');
        $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-cs-overlay.css?v=' . $ts . '">');
        $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-responsive.css?v=' . $ts . '">');
        $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-glassmorph-nav.css?v=' . $ts . '">');
        $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-struggle-dashboard.css?v=' . $sdts . '">');

        if ($isCourseArea && $courseid) {
            $courseCtx  = \context_course::instance($courseid);
            $courseName = format_string($COURSE->fullname ?? '', true, ['context' => $courseCtx]);
            $isLecturer = has_capability('local/umat_ai:viewanalytics', $courseCtx);
            $isStudent  = !$isLecturer && is_enrolled($courseCtx, $USER, '', false);

            if ($isLecturer && get_config('local_umat_ai', 'enable_lecturer_fab')) {
                $userData = \local_umat_ai\user_data::preload_user_data($USER->id, $wwwroot);
                $hook->add_html(\local_umat_ai\overlay_helper::lecturer_overlay($courseid, $courseName, $wwwroot, $USER, $userData));
            } elseif ($isStudent && get_config('local_umat_ai', 'enable_student_fab')) {
                $userData = \local_umat_ai\user_data::preload_user_data($USER->id, $wwwroot);
                $hook->add_html(\local_umat_ai\overlay_helper::student_overlay($courseid, $courseName, $wwwroot, $USER, $userData));
            }
        } elseif (!$isCourseArea) {
            $isLecturerAnywhere = $DB->record_exists_sql(
                "SELECT 1 FROM {role_assignments} ra
                 JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.userid = :uid AND r.shortname IN ('editingteacher','teacher','manager')",
                ['uid' => $USER->id]
            );
            if ($isLecturerAnywhere && get_config('local_umat_ai', 'enable_lecturer_fab')) {
                $userData = \local_umat_ai\user_data::preload_user_data($USER->id, $wwwroot);
                $hook->add_html(\local_umat_ai\overlay_helper::lecturer_overlay(0, 'All Courses', $wwwroot, $USER, $userData));
            } elseif (get_config('local_umat_ai', 'enable_hub_fab')) {
                $userData = \local_umat_ai\user_data::preload_user_data($USER->id, $wwwroot);
                $hook->add_html(\local_umat_ai\overlay_helper::hub_overlay($wwwroot, $USER, $userData));
            }
        }

        $hook->add_html(\local_umat_ai\overlay_helper::glassmorph_init_js());
    }
}
