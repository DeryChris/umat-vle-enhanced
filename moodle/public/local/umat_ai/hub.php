<?php
/**
 * hub.php — AI Learning Hub.
 * The hub UI is now rendered entirely as an overlay injected by before_footer.php.
 * This page is kept as a fallback and redirects to the Moodle home page.
 */
require_once(__DIR__ . '/../../config.php');
require_login();
redirect(new moodle_url('/'));
