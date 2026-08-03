<?php
defined('MOODLE_INTERNAL') || die();
$plugin->component = 'local_umat_ai';
// Bumped for the Phase 1 analytics correction. Moodle caches AMD modules by
// plugin version, so this must increase whenever amd/build changes or the
// dashboard will keep serving the previous JavaScript.
$plugin->version   = 2026072701;
$plugin->requires  = 2023100900;
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '2.2.1';
