<?php
defined('MOODLE_INTERNAL') || die();
$plugin->component = 'local_umat_ai';
// Bumped for the flashcard self-service rework (approval flow removed),
// the Notebook-style AI Tutor workspace (3-panel: sessions | chat | Studio),
// the redesigned Lecturer Analytics Dashboard (consolidated API +
// glassmorphism card-grid + vendored ECharts),
// the ECharts local-first loader (RequireJS-free global build fallback),
// "Ask About Your Students" moved into the Ask AI Assistant FAB mini panel,
// the student-only filter for at-risk lists and NLQ data,
// the 3-row dashboard layout (charts | students+questions | secondary,
// cards stretched to full row widths),
// and the login-page issue form rework (self-contained JS, no RequireJS)
// plus the admin Complaints tab (list/update complaints + login issues).
// db/services.php gained local_umat_ai_admin_list_complaints and
// local_umat_ai_admin_update_complaint — Moodle only re-registers
// web services when this version increases, so this must be bumped.
// db/services.php also gained local_umat_ai_import_session_quizzes
// (Studio: import quizzes generated in past chat sessions) — same
// re-registration requirement, bumped 2026080608 -> 2026080609.
// Bumped 2026080609 -> 2026080610 for local_umat_ai_update_quiz_settings
// and local_umat_ai_reopen_quiz (quizgen reconfigure/reopen).
// Bumped 2026080610 -> 2026080611: quizgen history actions switched to a
// single delegated click handler (data-act/data-job) + error surfacing so
// every action button works reliably; invalidates AMD cache.
// Bumped 2026080611 -> 2026080614: settings/reopen modal now appends inside
// #lec-quizgen (shares the dashboard stacking context) + overlay raised to
// z-index 99999, so the modal always renders above the dashboard.
// Bumped 2026080612 -> 2026080614: modal card gets solid fallback colours (vars may be missing) + backdrop blur on the overlay.
// Moodle caches AMD modules by plugin version, so this must increase
// whenever amd/build changes or the dashboard will keep serving the
// previous JavaScript.
$plugin->version   = 2026080614;
$plugin->requires  = 2023100900;
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '2.5.1';
