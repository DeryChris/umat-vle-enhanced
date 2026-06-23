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

    if ($oldversion < 2026060100) {
        // Add is_analyzed, timeindexed, timeanalyzed to umat_ai_materials
        $mt = new xmldb_table('umat_ai_materials');
        $f1 = new xmldb_field('is_analyzed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'is_indexed');
        $f2 = new xmldb_field('timeindexed', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'is_analyzed');
        $f3 = new xmldb_field('timeanalyzed', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timeindexed');
        if (!$dbman->field_exists($mt, $f1)) $dbman->add_field($mt, $f1);
        if (!$dbman->field_exists($mt, $f2)) $dbman->add_field($mt, $f2);
        if (!$dbman->field_exists($mt, $f3)) $dbman->add_field($mt, $f3);

        // Create umat_ai_analysis table
        $at = new xmldb_table('umat_ai_analysis');
        if (!$dbman->table_exists($at)) {
            $at->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $at->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $at->add_field('materialid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $at->add_field('fileid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $at->add_field('analysis_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $at->add_field('scope', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'full');
            $at->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
            $at->add_field('ai_analysis_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $at->add_field('model_version', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $at->add_field('token_count', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $at->add_field('summary', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $at->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $at->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $at->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $at->add_key('material_fk', XMLDB_KEY_FOREIGN, ['materialid'], 'umat_ai_materials', ['id']);
            $at->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $at->add_index('material_type', XMLDB_INDEX_NOTUNIQUE, ['materialid', 'analysis_type']);
            $dbman->create_table($at);
        }
        upgrade_plugin_savepoint(true, 2026060100, 'local', 'umat_ai');
    }

    if ($oldversion < 2026061300) {
        // Create umat_ai_notes table — student's own notes with tagging support
        $nt = new xmldb_table('umat_ai_notes');
        if (!$dbman->table_exists($nt)) {
            $nt->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $nt->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $nt->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $nt->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $nt->add_field('content', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $nt->add_field('pinned', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $nt->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $nt->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $nt->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $nt->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $nt->add_index('userid_courseid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            $dbman->create_table($nt);
        }

        // Create umat_ai_note_tags table — polymorphic tags on notes
        $tt = new xmldb_table('umat_ai_note_tags');
        if (!$dbman->table_exists($tt)) {
            $tt->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $tt->add_field('noteid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $tt->add_field('tag_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $tt->add_field('tag_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $tt->add_field('tag_label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $tt->add_field('tag_value', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $tt->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $tt->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $tt->add_key('note_fk', XMLDB_KEY_FOREIGN, ['noteid'], 'umat_ai_notes', ['id']);
            // tag_type+tag_id lookup — noteid index is auto-created by the FK
            $tt->add_index('tag_type', XMLDB_INDEX_NOTUNIQUE, ['tag_type', 'tag_id']);
            $dbman->create_table($tt);
        }
        upgrade_plugin_savepoint(true, 2026061300, 'local', 'umat_ai');
    }

    if ($oldversion < 2026061301) {
        set_config('enable_student_fab', '1', 'local_umat_ai');
        set_config('enable_lecturer_fab', '1', 'local_umat_ai');
        set_config('enable_hub_fab', '1', 'local_umat_ai');
        upgrade_plugin_savepoint(true, 2026061301, 'local', 'umat_ai');
    }

    if ($oldversion < 2026061400) {
        // Student struggle context — aggregated per user/course/module
        $ctx = new xmldb_table('umat_ai_student_context');
        if (!$dbman->table_exists($ctx)) {
            $ctx->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $ctx->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $ctx->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $ctx->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $ctx->add_field('topic_label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $ctx->add_field('struggle_reason', XMLDB_TYPE_CHAR, '50', null, null, null, null);
            $ctx->add_field('struggle_score', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
            $ctx->add_field('is_struggle', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $ctx->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $ctx->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $ctx->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $ctx->add_index('user_course', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            $ctx->add_index('user_course_cmid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid', 'cmid']);
            $dbman->create_table($ctx);
        }

        // Raw activity log for analytics webhooks
        $log = new xmldb_table('umat_ai_activity_log');
        if (!$dbman->table_exists($log)) {
            $log->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $log->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $log->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $log->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $log->add_field('event_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $log->add_field('event_data', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $log->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $log->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $log->add_index('user_course_type', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid', 'event_type']);
            $log->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $dbman->create_table($log);
        }

        upgrade_plugin_savepoint(true, 2026061400, 'local', 'umat_ai');
    }

    if ($oldversion < 2026061500) {
        // Create umat_ai_issue_reports table
        $irt = new xmldb_table('umat_ai_issue_reports');
        if (!$dbman->table_exists($irt)) {
            $irt->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $irt->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $irt->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $irt->add_field('category', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'other');
            $irt->add_field('topic', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $irt->add_field('description', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $irt->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'open');
            $irt->add_field('lecturer_notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $irt->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $irt->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $irt->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $irt->add_index('user_course', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            $irt->add_index('course_status', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'status']);
            $dbman->create_table($irt);
        }

        // Also add the new reportissue capability
        upgrade_plugin_savepoint(true, 2026061500, 'local', 'umat_ai');
    }

    return true;
}
