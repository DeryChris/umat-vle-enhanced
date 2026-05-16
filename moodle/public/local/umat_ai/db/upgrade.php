<?php
/**
 * UMaT AI plugin database upgrade.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_umat_ai_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // v1.1.0 — 2026-05-17: add session_key + role columns to chat_logs;
    //                        add lecturer_notes table; add index to ai_outputs.
    if ($oldversion < 2026051700) {

        // 1. Add session_key to umat_ai_chat_logs if missing.
        $table = new xmldb_table('umat_ai_chat_logs');

        $field = new xmldb_field('session_key', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('role', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'student', 'session_key');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // 2. Add index on session_key.
        $index = new xmldb_index('session_key', XMLDB_INDEX_NOTUNIQUE, ['session_key']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // 3. Add index on timecreated.
        $index = new xmldb_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // 4. Create umat_ai_lecturer_notes table if it doesn't exist.
        $table = new xmldb_table('umat_ai_lecturer_notes');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null,           null);
            $table->add_field('courseid',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null,           null);
            $table->add_field('query',       XMLDB_TYPE_TEXT,    null,  null, XMLDB_NOTNULL, null,           null);
            $table->add_field('response',    XMLDB_TYPE_TEXT,    null,  null, null,          null,           null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null,          '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_courseid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            $dbman->create_table($table);
        }

        // 5. Add composite index to umat_ai_outputs (courseid, is_approved).
        $table = new xmldb_table('umat_ai_outputs');
        $index = new xmldb_index('courseid_approved', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'is_approved']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026051700, 'local', 'umat_ai');
    }

    return true;
}
