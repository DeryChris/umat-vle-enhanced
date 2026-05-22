<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_umat_ai_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026051700) {
        $table = new xmldb_table('umat_ai_chat_logs');
        $f = new xmldb_field('session_key', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'courseid');
        if (!$dbman->field_exists($table, $f)) $dbman->add_field($table, $f);
        $f = new xmldb_field('role', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'student', 'session_key');
        if (!$dbman->field_exists($table, $f)) $dbman->add_field($table, $f);
        $idx = new xmldb_index('session_key', XMLDB_INDEX_NOTUNIQUE, ['session_key']);
        if (!$dbman->index_exists($table, $idx)) $dbman->add_index($table, $idx);

        $lt = new xmldb_table('umat_ai_lecturer_notes');
        if (!$dbman->table_exists($lt)) {
            $lt->add_field('id', XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,XMLDB_SEQUENCE,null);
            $lt->add_field('userid', XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,null,null);
            $lt->add_field('courseid', XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,null,null);
            $lt->add_field('query', XMLDB_TYPE_TEXT,null,null,XMLDB_NOTNULL,null,null);
            $lt->add_field('response', XMLDB_TYPE_TEXT,null,null,null,null,null);
            $lt->add_field('timecreated', XMLDB_TYPE_INTEGER,'10',null,XMLDB_NOTNULL,null,'0');
            $lt->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $lt->add_index('userid_courseid', XMLDB_INDEX_NOTUNIQUE, ['userid','courseid']);
            $dbman->create_table($lt);
        }
        upgrade_plugin_savepoint(true, 2026051700, 'local', 'umat_ai');
    }

    if ($oldversion < 2026051800) {
        $table = new xmldb_table('umat_ai_sessions');
        $f = new xmldb_field('transcript_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'recording_path');
        if (!$dbman->field_exists($table, $f)) $dbman->add_field($table, $f);
        upgrade_plugin_savepoint(true, 2026051800, 'local', 'umat_ai');
    }

    if ($oldversion < 2026051900) {
        // Add timecreated index to chat_logs for session queries.
        $table = new xmldb_table('umat_ai_chat_logs');
        $idx = new xmldb_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->index_exists($table, $idx)) $dbman->add_index($table, $idx);
        upgrade_plugin_savepoint(true, 2026051900, 'local', 'umat_ai');
    }

    return true;
}
