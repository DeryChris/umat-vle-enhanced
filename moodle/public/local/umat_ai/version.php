<?php
defined('MOODLE_INTERNAL') || die();
$plugin->component = 'local_umat_ai';
// Bumped for the flashcard self-service rework (approval flow removed),
// the Notebook-style AI Tutor workspace (3-panel: sessions | chat | Studio),
// the redesigned Lecturer Analytics Dashboard (consolidated API +
// glassmorphism card-grid + vendored ECharts),
// and the ECharts local-first loader (RequireJS-free global build fallback).
// Moodle caches AMD modules by plugin version, so this must increase
// whenever amd/build changes or the dashboard will keep serving the
// previous JavaScript.
$plugin->version   = 2026080604;
$plugin->requires  = 2023100900;
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '2.5.0';
