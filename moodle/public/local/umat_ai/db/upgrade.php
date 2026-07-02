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
            $nt->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
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
            $ctx->add_field('topic_label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $ctx->add_field('struggle_reason', XMLDB_TYPE_CHAR, '50', null, null, null, null);
            $ctx->add_field('struggle_score', XMLDB_TYPE_NUMBER, '10', null, XMLDB_NOTNULL, null, '0', null, '2');
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

    if ($oldversion < 2026062500) {
        // Fix XMLDB schema: remove invalid empty defaults on CHAR NOT NULL columns
        // (topic_label, title) and fix NUMBER comma syntax for struggle_score.
        // Tables already exist on upgraded installs — no ALTER needed.
        // This block exists only to absorb the version bump cleanly.
        upgrade_plugin_savepoint(true, 2026062500, 'local', 'umat_ai');
    }

    if ($oldversion < 2026062600) {
        // Create umat_ai_quizgen_jobs table for async quiz generation.
        $qt = new xmldb_table('umat_ai_quizgen_jobs');
        if (!$dbman->table_exists($qt)) {
            $qt->add_field('id',              XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $qt->add_field('courseid',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, null);
            $qt->add_field('userid',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, null);
            $qt->add_field('material_id',     XMLDB_TYPE_INTEGER, '10',  null, null,  null, null);
            $qt->add_field('source_text',     XMLDB_TYPE_TEXT,    null,  null, null,  null, null);
            $qt->add_field('config_json',     XMLDB_TYPE_TEXT,    null,  null, XMLDB_NOTNULL,  null, null);
            $qt->add_field('category_name',   XMLDB_TYPE_CHAR,   '255', null, XMLDB_NOTNULL,  null, null);
            $qt->add_field('status',          XMLDB_TYPE_CHAR,   '30',  null, XMLDB_NOTNULL,  null, 'pending');
            $qt->add_field('questions_json',  XMLDB_TYPE_TEXT,    null,  null, null,  null, null);
            $qt->add_field('xml_content',     XMLDB_TYPE_TEXT,    null,  null, null,  null, null);
            $qt->add_field('quiz_id',         XMLDB_TYPE_INTEGER, '10',  null, null,  null, null);
            $qt->add_field('failure_reason',  XMLDB_TYPE_TEXT,    null,  null, null,  null, null);
            $qt->add_field('timecreated',     XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, '0');
            $qt->add_field('timemodified',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, '0');
            $qt->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $qt->add_index('course_status', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'status']);
            $qt->add_index('user_course',   XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            $dbman->create_table($qt);
        }

        // Create umat_ai_group_study table
        $gs = new xmldb_table('umat_ai_group_study');
        if (!$dbman->table_exists($gs)) {
            $gs->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $gs->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $gs->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $gs->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $gs->add_field('max_members', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '5');
            $gs->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'open');
            $gs->add_field('created_by', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $gs->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $gs->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $gs->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $gs->add_key('course_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $gs->add_key('creator_fk', XMLDB_KEY_FOREIGN, ['created_by'], 'user', ['id']);
            $gs->add_index('idx_gs_status', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'status']);
            $dbman->create_table($gs);
        }

        // Create umat_ai_group_members table
        $gm = new xmldb_table('umat_ai_group_members');
        if (!$dbman->table_exists($gm)) {
            $gm->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $gm->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $gm->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $gm->add_field('role', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'member');
            $gm->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $gm->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $gm->add_key('group_fk', XMLDB_KEY_FOREIGN, ['groupid'], 'umat_ai_group_study', ['id']);
            $gm->add_key('member_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $gm->add_index('idx_gm_user', XMLDB_INDEX_NOTUNIQUE, ['userid', 'groupid']);
            $dbman->create_table($gm);
        }

        // Create umat_ai_group_messages table
        $gmsg = new xmldb_table('umat_ai_group_messages');
        if (!$dbman->table_exists($gmsg)) {
            $gmsg->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $gmsg->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $gmsg->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $gmsg->add_field('question', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $gmsg->add_field('answer', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $gmsg->add_field('sources', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $gmsg->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $gmsg->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $gmsg->add_key('gmsg_group', XMLDB_KEY_FOREIGN, ['groupid'], 'umat_ai_group_study', ['id']);
            $gmsg->add_key('gmsg_user', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $gmsg->add_index('idx_gm_time', XMLDB_INDEX_NOTUNIQUE, ['groupid', 'timecreated']);
            $dbman->create_table($gmsg);
        }
        upgrade_plugin_savepoint(true, 2026062600, 'local', 'umat_ai');
    }

    if ($oldversion < 2026062700) {
        // Create umat_ai_student_metrics table
        $metrics = new xmldb_table('umat_ai_student_metrics');
        if (!$dbman->table_exists($metrics)) {
            $metrics->add_field('id',              XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $metrics->add_field('userid',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $metrics->add_field('courseid',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $metrics->add_field('logins',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $metrics->add_field('avg_quiz_grade',  XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
            $metrics->add_field('ai_questions_asked', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $metrics->add_field('risk_score',      XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
            $metrics->add_field('last_active',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $metrics->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $metrics->add_index('course_user', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid']);
            $metrics->add_index('course_risk', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'risk_score']);
            $dbman->create_table($metrics);
        }

        $interventions = new xmldb_table('umat_ai_interventions');
        if (!$dbman->table_exists($interventions)) {
            $interventions->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $interventions->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $interventions->add_field('courseid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $interventions->add_field('lecturerid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $interventions->add_field('action_type', XMLDB_TYPE_CHAR,   '50', null, XMLDB_NOTNULL, null, null);
            $interventions->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $interventions->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $interventions->add_index('user_course_action', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid', 'action_type', 'timecreated']);
            $dbman->create_table($interventions);
        }

        // Add message field to group_messages, make question nullable
        $gmsg = new xmldb_table('umat_ai_group_messages');
        $msg = new xmldb_field('message', XMLDB_TYPE_TEXT, null, null, null, null, null, 'userid');
        if (!$dbman->field_exists($gmsg, $msg)) {
            $dbman->add_field($gmsg, $msg);
        }
        $qfield = new xmldb_field('question', XMLDB_TYPE_TEXT, null, null, null, null, null, 'message');
        $dbman->change_field_notnull($gmsg, $qfield);
        $dbman->change_field_default($gmsg, $qfield);

        upgrade_plugin_savepoint(true, 2026062700, 'local', 'umat_ai');
    }

    if ($oldversion < 2026062800) {
        $table = new xmldb_table('umat_ai_issue_reports');
        $f = new xmldb_field('lecturer_response', XMLDB_TYPE_TEXT, null, null, null, null, null, 'lecturer_notes');
        if (!$dbman->field_exists($table, $f)) {
            $dbman->add_field($table, $f);
        }
        upgrade_plugin_savepoint(true, 2026062800, 'local', 'umat_ai');
    }

    if ($oldversion < 2026062900) {
        // 1. umat_ai_chat_log_helpfulness — separate table for student ratings on AI answers
        $help = new xmldb_table('umat_ai_chat_log_helpfulness');
        if (!$dbman->table_exists($help)) {
            $help->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $help->add_field('chatlogid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $help->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $help->add_field('rating',      XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '1');
            $help->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $help->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $help->add_key('chatlog_fk', XMLDB_KEY_FOREIGN, ['chatlogid'], 'umat_ai_chat_logs', ['id'], 'cascade');
            $help->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $help->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $dbman->create_table($help);
        }

        // 2. umat_ai_material_progress — student progress through materials via beacon
        $mp = new xmldb_table('umat_ai_material_progress');
        if (!$dbman->table_exists($mp)) {
            $mp->add_field('id',              XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $mp->add_field('userid',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $mp->add_field('courseid',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $mp->add_field('materialid',      XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $mp->add_field('progress_pct',    XMLDB_TYPE_NUMBER,  '5, 1', null, XMLDB_NOTNULL, null, '0.0');
            $mp->add_field('time_spent_sec',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $mp->add_field('last_position',   XMLDB_TYPE_INTEGER, '10',  null, null, null, null);
            $mp->add_field('timemodified',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $mp->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $mp->add_key('material_fk', XMLDB_KEY_FOREIGN, ['materialid'], 'umat_ai_materials', ['id'], 'cascade');
            $mp->add_index('user_course_material', XMLDB_INDEX_UNIQUE, ['userid', 'courseid', 'materialid']);
            $mp->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $dbman->create_table($mp);
        }

        // 3. umat_ai_topic_friction — materialized per-topic friction scores
        $tf = new xmldb_table('umat_ai_topic_friction');
        if (!$dbman->table_exists($tf)) {
            $tf->add_field('id',              XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $tf->add_field('courseid',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $tf->add_field('topic_label',     XMLDB_TYPE_CHAR,   '255', null, XMLDB_NOTNULL, null, '');
            $tf->add_field('question_volume', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $tf->add_field('friction_score',  XMLDB_TYPE_NUMBER,  '5, 1', null, XMLDB_NOTNULL, null, '0.0');
            $tf->add_field('student_count',   XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $tf->add_field('severity',        XMLDB_TYPE_CHAR,   '20',  null, XMLDB_NOTNULL, null, 'minor');
            $tf->add_field('computed_at',     XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $tf->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $tf->add_index('course_topic', XMLDB_INDEX_UNIQUE, ['courseid', 'topic_label']);
            $tf->add_index('course_severity', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'severity']);
            $dbman->create_table($tf);
        }

        // 4. umat_ai_metric_trends — 30-day rolling snapshots for sparkline charts
        $mt = new xmldb_table('umat_ai_metric_trends');
        if (!$dbman->table_exists($mt)) {
            $mt->add_field('id',               XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $mt->add_field('courseid',         XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $mt->add_field('engagement_score', XMLDB_TYPE_NUMBER,  '5, 1', null, XMLDB_NOTNULL, null, '0.0');
            $mt->add_field('at_risk_count',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $mt->add_field('total_students',   XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $mt->add_field('snapshot_date',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $mt->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $mt->add_index('course_date', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'snapshot_date']);
            $dbman->create_table($mt);
        }

        // Add response_seen field to issue_reports
        $table = new xmldb_table('umat_ai_issue_reports');
        $fs = new xmldb_field('response_seen', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'lecturer_response');
        if (!$dbman->field_exists($table, $fs)) {
            $dbman->add_field($table, $fs);
        }
        upgrade_plugin_savepoint(true, 2026062900, 'local', 'umat_ai');
    }

    if ($oldversion < 2026062910) {
        // Create umat_ai_videos table
        $table = new xmldb_table('umat_ai_videos');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('materialid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('job_id', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'queued');
            $table->add_field('video_url', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('material_fk', XMLDB_KEY_FOREIGN, ['materialid'], 'umat_ai_materials', ['id']);
            $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026062910, 'local', 'umat_ai');
    }

    if ($oldversion < 2026063000) {
        // Create umat_ai_quiz_attempts table for student quiz persistence.
        $table = new xmldb_table('umat_ai_quiz_attempts');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',              XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, null);
            $table->add_field('courseid',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, null);
            $table->add_field('session_key',     XMLDB_TYPE_CHAR,   '64',  null, null,  null, null);
            $table->add_field('quiz_title',      XMLDB_TYPE_CHAR,   '255', null, null,  null, '');
            $table->add_field('questions_json',  XMLDB_TYPE_TEXT,    null,  null, null,  null, null);
            $table->add_field('answers_json',    XMLDB_TYPE_TEXT,    null,  null, null,  null, null);
            $table->add_field('graded_json',     XMLDB_TYPE_TEXT,    null,  null, null,  null, null);
            $table->add_field('score',           XMLDB_TYPE_INTEGER, '4',   null, null,  null, null);
            $table->add_field('total',           XMLDB_TYPE_INTEGER, '4',   null, null,  null, null);
            $table->add_field('status',          XMLDB_TYPE_CHAR,   '20',  null, XMLDB_NOTNULL,  null, 'in_progress');
            $table->add_field('timecreated',     XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, '0');
            $table->add_field('timemodified',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_qa_user_course', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            $table->add_index('idx_qa_status',      XMLDB_INDEX_NOTUNIQUE, ['status']);
            $dbman->create_table($table);
        }

        // Add quiz_analytics capability archetype for lecturers.
        upgrade_plugin_savepoint(true, 2026063000, 'local', 'umat_ai');
    }

    if ($oldversion < 2026070100) {
        // Add admin panel defaults.
        set_config('enable_admin_fab', '1', 'local_umat_ai');
        set_config('theme_primary',   '#006b2f', 'local_umat_ai');
        set_config('theme_secondary', '#16a34a', 'local_umat_ai');
        set_config('theme_tertiary',  '#a5304d', 'local_umat_ai');
        set_config('theme_warning',   '#d97706', 'local_umat_ai');
        set_config('theme_success',   '#16a34a', 'local_umat_ai');
        upgrade_plugin_savepoint(true, 2026070100, 'local', 'umat_ai');
    }

    return true;
}
