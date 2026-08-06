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
// and the 3-row dashboard layout (charts | students+questions | secondary,
// cards stretched to full row widths).
// Moodle caches AMD modules by plugin version, so this must increase
// whenever amd/build changes or the dashboard will keep serving the
// previous JavaScript.
$plugin->version   = 2026080606;
$plugin->requires  = 2023100900;
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '2.5.0';
