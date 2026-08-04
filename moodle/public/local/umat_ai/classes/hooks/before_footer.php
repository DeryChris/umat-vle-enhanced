<?php

namespace local_umat_ai\hooks;

use core\hook\output\before_footer_html_generation;

class before_footer {

    private static $lecturerCheckCache = null;

    public static function handle(before_footer_html_generation $hook): void {
        global $PAGE, $COURSE, $USER, $CFG, $DB;
        try {
            $wwwroot = rtrim($CFG->wwwroot, '/');
            $pagelayout = $PAGE->pagelayout;
            $path = $PAGE->url->get_path();

/* ---- Login page: inject Login Issue Report toggle (main login form only) ---- */
            $isLoginPage = in_array($path, ['/login/', '/login/index.php']);
            if ($isLoginPage && !isloggedin()) {
                $cssPath = $wwwroot . '/local/umat_ai/styles/umat-login-report.css';
                $cssVer  = filemtime(__DIR__ . '/../../styles/umat-login-report.css');
                $hook->add_html('<link rel="preconnect" href="https://fonts.gstatic.com">');
                $hook->add_html('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">');
                $hook->add_html('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">');
                /* Load overlay theme CSS so login page matches site design */
                $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-overlay.css?v=' . filemtime(__DIR__ . '/../../styles/umat-overlay.css') . '">');
                $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-responsive.css?v=' . filemtime(__DIR__ . '/../../styles/umat-responsive.css') . '">');
                $hook->add_html('<link rel="stylesheet" href="' . $cssPath . '?v=' . $cssVer . '">');
                $hook->add_html(\local_umat_ai\overlay_helper::login_report_overlay());
                return;
            }

            if (!isloggedin() || isguestuser()) return;
            $isCourseArea = strpos($path, '/course/') !== false || strpos($path, '/mod/') !== false || strpos($path, '/section/') !== false;
            $courseid = 0;
            $ctx = $PAGE->context;
            if ($ctx && $ctx->contextlevel === CONTEXT_COURSE) {
                $courseid = (int)$ctx->instanceid;
            } elseif (!empty($COURSE->id) && $COURSE->id != SITEID) {
                $courseid = (int)$COURSE->id;
            }
            $platformName = get_config('local_umat_ai', 'platform_name') ?: 'UMaT';
            // Core CSS (always loaded)
            $ocv = filemtime(__DIR__ . '/../../styles/umat-overlay.css');
            $rcv = filemtime(__DIR__ . '/../../styles/umat-responsive.css');
            $gnv = filemtime(__DIR__ . '/../../styles/umat-glassmorph-nav.css');
            $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-overlay.css?v=' . $ocv . '">');
            $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-responsive.css?v=' . $rcv . '">');
            $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-glassmorph-nav.css?v=' . $gnv . '">');
            $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-viewers.css">');
            $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-notes.css">');
            $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-dashboard.css?v=' . filemtime(__DIR__ . '/../../styles/umat-dashboard.css') . '">');
            $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-cs-overlay.css">');
            $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-yt-grid.css">');
            $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-admin.css?v=' . filemtime(__DIR__ . '/../../styles/umat-admin.css') . '">');
            $hook->add_html('<link rel="preconnect" href="https://cdnjs.cloudflare.com">');
            $hook->add_html('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">');
            $hook->add_html('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">');
            /* Admin check — site admins see only the admin FAB, bypass all other overlays */
            $isAdmin = has_capability('local_umat_ai:adminpanel', \context_system::instance());
            $isCourseIssueManager = $courseid && has_capability(
                'local_umat_ai:viewanalytics',
                \context_course::instance($courseid)
            );
            if ($isAdmin && get_config('local_umat_ai', 'enable_admin_fab') && !$isCourseIssueManager) {
                $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-admin.css?v=' . filemtime(__DIR__ . '/../../styles/umat-admin.css') . '">');
                $hook->add_html(\local_umat_ai\overlay_helper::admin_overlay($wwwroot, $USER, $platformName));
                $hook->add_html(\local_umat_ai\overlay_helper::glassmorph_init_js());
                return;
            }
            if ($isCourseArea && $courseid) {
                $courseCtx  = \context_course::instance($courseid);
                $courseName = format_string($COURSE->fullname ?? '', true, ['context' => $courseCtx]);
                $isLecturer = has_capability('local/umat_ai:viewanalytics', $courseCtx);
                $isStudent  = !$isLecturer && is_enrolled($courseCtx, $USER, '', true) &&
                    has_capability('local/umat_ai:reportissue', $courseCtx);
                if ($isLecturer && get_config('local_umat_ai', 'enable_lecturer_fab')) {
                    $userData = \local_umat_ai\user_data::preload_user_data($USER->id, $wwwroot);
                    $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-dashboard.css?v=' . filemtime(__DIR__ . '/../../styles/umat-dashboard.css') . '">');
                    $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-viewers.css?v=' . filemtime(__DIR__ . '/../../styles/umat-viewers.css') . '">');
                    $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-cs-overlay.css?v=' . filemtime(__DIR__ . '/../../styles/umat-cs-overlay.css') . '">');
                    $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-yt-grid.css?v=' . filemtime(__DIR__ . '/../../styles/umat-yt-grid.css') . '">');
                    $hook->add_html(\local_umat_ai\overlay_helper::lecturer_overlay($courseid, $courseName, $wwwroot, $USER, $userData, $platformName));
                } elseif ($isStudent && get_config('local_umat_ai','enable_student_fab')) {
                    $userData = \local_umat_ai\user_data::preload_user_data($USER->id, $wwwroot);
                    $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-viewers.css?v=' . filemtime(__DIR__ . '/../../styles/umat-viewers.css') . '">');
                    $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-notes.css?v=' . filemtime(__DIR__ . '/../../styles/umat-notes.css') . '">');
                    $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-struggle-dashboard.css?v=' . filemtime(__DIR__ . '/../../styles/umat-struggle-dashboard.css') . '">');
                    $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-yt-grid.css?v=' . filemtime(__DIR__ . '/../../styles/umat-yt-grid.css') . '">');
                    $hook->add_html(\local_umat_ai\overlay_helper::student_overlay($courseid,$courseName,$wwwroot,$USER,$userData,$platformName));
                }
            } elseif (!$isCourseArea) {
                if (self::$lecturerCheckCache === null) {
                    self::$lecturerCheckCache = $DB->record_exists_sql("SELECT 1 FROM {role_assignments} ra JOIN {role} r ON r.id=ra.roleid WHERE ra.userid=:uid AND r.shortname IN ('editingteacher','teacher','manager')",['uid'=>$USER->id]);
                }
                $isLecturerAnywhere = self::$lecturerCheckCache;
                if ($isLecturerAnywhere && get_config('local_umat_ai','enable_lecturer_fab')) {
                    $userData = \local_umat_ai\user_data::preload_user_data($USER->id,$wwwroot);
                    $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-dashboard.css?v=' . filemtime(__DIR__ . '/../../styles/umat-dashboard.css') . '">');
                    $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-viewers.css?v=' . filemtime(__DIR__ . '/../../styles/umat-viewers.css') . '">');
                    $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-cs-overlay.css?v=' . filemtime(__DIR__ . '/../../styles/umat-cs-overlay.css') . '">');
                    $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-yt-grid.css?v=' . filemtime(__DIR__ . '/../../styles/umat-yt-grid.css') . '">');
                    $hook->add_html(\local_umat_ai\overlay_helper::lecturer_overlay(0, 'All Courses', $wwwroot, $USER, $userData, $platformName));
                } elseif (get_config('local_umat_ai','enable_hub_fab')) {
                    $userData = \local_umat_ai\user_data::preload_user_data($USER->id,$wwwroot);
                    $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-dashboard.css?v=' . filemtime(__DIR__ . '/../../styles/umat-dashboard.css') . '">');
                    $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-viewers.css?v=' . filemtime(__DIR__ . '/../../styles/umat-viewers.css') . '">');
                    $hook->add_html('<link rel="stylesheet" href="' . $wwwroot . '/local/umat_ai/styles/umat-yt-grid.css?v=' . filemtime(__DIR__ . '/../../styles/umat-yt-grid.css') . '">');
                    $hook->add_html(\local_umat_ai\overlay_helper::hub_overlay($wwwroot,$USER,$userData,$platformName));
                }
            }
            $hook->add_html(\local_umat_ai\overlay_helper::glassmorph_init_js());
        } catch (\Throwable $e) { error_log('local_umat_ai hook err: '.$e->getMessage().' line:'.$e->getLine().' file:'.$e->getFile()); }
    }
}
