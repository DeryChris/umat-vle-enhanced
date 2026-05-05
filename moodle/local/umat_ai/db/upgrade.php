<?php
// ============================================================
// Database upgrade script — runs when $plugin->version is incremented
// Always increment version.php version number when making DB changes
// ============================================================

defined('MOODLE_INTERNAL') || die();

function xmldb_local_umat_ai_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // ---- Example migration: version 2024120200 ----
    // Add a job_id column to umat_ai_sessions to track Python-side job IDs
    if ($oldversion < 2024120200) {
        $table = new xmldb_table('umat_ai_sessions');
        $field = new xmldb_field('job_id', XMLDB_TYPE_CHAR, '100', null, false, null, null, 'status');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2024120200, 'local', 'umat_ai');
    }

    // Add more migration blocks here as the schema evolves, e.g.:
    // if ($oldversion < 2024120300) { ... upgrade_plugin_savepoint(..., 2024120300, ...); }

    return true;
}
