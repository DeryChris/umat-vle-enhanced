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

    if ($oldversion < 2026070900) {
        // Defensively create any tables that may have been missed on partial upgrades.
        // Each table is verified via old-style install.xml definition.
        $defs = [
            'chat_log_helpfulness' => [
                'fields' => [
                    ['id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null],
                    ['chatlogid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['rating', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1'],
                    ['timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                ],
                'keys' => [
                    ['primary', XMLDB_KEY_PRIMARY, ['id']],
                    ['chatlog_fk', XMLDB_KEY_FOREIGN, ['chatlogid'], 'umat_ai_chat_logs', ['id'], 'cascade'],
                ],
                'indexes' => [
                    ['userid', XMLDB_INDEX_NOTUNIQUE, ['userid']],
                    ['timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']],
                ],
            ],
            'material_progress' => [
                'fields' => [
                    ['id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null],
                    ['userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['materialid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['progress_pct', XMLDB_TYPE_NUMBER, '5, 1', null, XMLDB_NOTNULL, null, '0.0'],
                    ['time_spent_sec', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                    ['last_position', XMLDB_TYPE_INTEGER, '10', null, null, null, null],
                    ['timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                ],
                'keys' => [
                    ['primary', XMLDB_KEY_PRIMARY, ['id']],
                    ['material_fk', XMLDB_KEY_FOREIGN, ['materialid'], 'umat_ai_materials', ['id'], 'cascade'],
                ],
                'indexes' => [
                    ['user_course_material', XMLDB_INDEX_UNIQUE, ['userid', 'courseid', 'materialid']],
                    ['courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']],
                ],
            ],
            'topic_friction' => [
                'fields' => [
                    ['id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null],
                    ['courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['topic_label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, ''],
                    ['question_volume', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                    ['friction_score', XMLDB_TYPE_NUMBER, '5, 1', null, XMLDB_NOTNULL, null, '0.0'],
                    ['student_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                    ['severity', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'minor'],
                    ['computed_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                ],
                'keys' => [['primary', XMLDB_KEY_PRIMARY, ['id']]],
                'indexes' => [
                    ['course_topic', XMLDB_INDEX_UNIQUE, ['courseid', 'topic_label']],
                    ['course_severity', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'severity']],
                ],
            ],
            'metric_trends' => [
                'fields' => [
                    ['id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null],
                    ['courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['engagement_score', XMLDB_TYPE_NUMBER, '5, 1', null, XMLDB_NOTNULL, null, '0.0'],
                    ['at_risk_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                    ['total_students', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                    ['snapshot_date', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                ],
                'keys' => [['primary', XMLDB_KEY_PRIMARY, ['id']]],
                'indexes' => [['course_date', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'snapshot_date']]],
            ],
            'issue_reports' => [
                'fields' => [
                    ['id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null],
                    ['userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['category', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'other'],
                    ['topic', XMLDB_TYPE_CHAR, '255', null, null, null, null],
                    ['description', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null],
                    ['status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'open'],
                    ['lecturer_notes', XMLDB_TYPE_TEXT, null, null, null, null, null],
                    ['lecturer_response', XMLDB_TYPE_TEXT, null, null, null, null, null],
                    ['response_seen', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0'],
                    ['timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                    ['timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                ],
                'keys' => [['primary', XMLDB_KEY_PRIMARY, ['id']]],
                'indexes' => [
                    ['user_course', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']],
                    ['course_status', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'status']],
                ],
            ],
            'quizgen_jobs' => [
                'fields' => [
                    ['id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null],
                    ['courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['material_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null],
                    ['source_text', XMLDB_TYPE_TEXT, null, null, null, null, null],
                    ['config_json', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null],
                    ['category_name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null],
                    ['status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'pending'],
                    ['questions_json', XMLDB_TYPE_TEXT, null, null, null, null, null],
                    ['xml_content', XMLDB_TYPE_TEXT, null, null, null, null, null],
                    ['quiz_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null],
                    ['failure_reason', XMLDB_TYPE_TEXT, null, null, null, null, null],
                    ['timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                    ['timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                ],
                'keys' => [['primary', XMLDB_KEY_PRIMARY, ['id']]],
                'indexes' => [
                    ['course_status', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'status']],
                    ['user_course', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']],
                ],
            ],
            'student_metrics' => [
                'fields' => [
                    ['id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null],
                    ['userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['logins', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                    ['avg_quiz_grade', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0'],
                    ['ai_questions_asked', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                    ['risk_score', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0'],
                    ['last_active', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                ],
                'keys' => [['primary', XMLDB_KEY_PRIMARY, ['id']]],
                'indexes' => [
                    ['course_user', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid']],
                    ['course_risk', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'risk_score']],
                ],
            ],
            'interventions' => [
                'fields' => [
                    ['id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null],
                    ['userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['lecturerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['action_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null],
                    ['timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                ],
                'keys' => [['primary', XMLDB_KEY_PRIMARY, ['id']]],
                'indexes' => [
                    ['user_course_action', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid', 'action_type', 'timecreated']],
                ],
            ],
            'videos' => [
                'fields' => [
                    ['id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null],
                    ['materialid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null],
                    ['job_id', XMLDB_TYPE_CHAR, '100', null, null, null, null],
                    ['status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'queued'],
                    ['video_url', XMLDB_TYPE_TEXT, null, null, null, null, null],
                    ['timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                    ['timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'],
                ],
                'keys' => [
                    ['primary', XMLDB_KEY_PRIMARY, ['id']],
                    ['material_fk', XMLDB_KEY_FOREIGN, ['materialid'], 'umat_ai_materials', ['id']],
                ],
                'indexes' => [['courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']]],
            ],
        ];

        foreach ($defs as $short_name => $def) {
            $tname = "umat_ai_{$short_name}";
            $table = new xmldb_table($tname);
            if ($dbman->table_exists($table)) {
                continue;
            }
            foreach ($def['fields'] as $fdef) {
                // xmldb_table::add_field(string $name, $type, $precision, ...)
                $table->add_field(...$fdef);
            }
            foreach ($def['keys'] as $kdef) {
                // xmldb_table::add_key(string $name, $type, array $fields, ...)
                $table->add_key(...$kdef);
            }
            foreach ($def['indexes'] as $idef) {
                // xmldb_table::add_index(string $name, $type, array $fields)
                $table->add_index(...$idef);
            }
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026070900, 'local', 'umat_ai');
    }

    if ($oldversion < 2026071600) {
        // Add source_type, userid, upload_filename to umat_ai_sessions for lecture transcription uploads.
        $table = new xmldb_table('umat_ai_sessions');
        $f1 = new xmldb_field('source_type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'bbb', 'cmid');
        $f2 = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'courseid');
        $f3 = new xmldb_field('upload_filename', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'recording_url');
        if (!$dbman->field_exists($table, $f1)) $dbman->add_field($table, $f1);
        if (!$dbman->field_exists($table, $f2)) $dbman->add_field($table, $f2);
        if (!$dbman->field_exists($table, $f3)) $dbman->add_field($table, $f3);
        upgrade_plugin_savepoint(true, 2026071600, 'local', 'umat_ai');
    }

    if ($oldversion < 2026072000) {
        // Add session_key and sources to umat_ai_lecturer_notes for lecturer AI session tracking.
        $table = new xmldb_table('umat_ai_lecturer_notes');
        $f1 = new xmldb_field('session_key', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'courseid');
        $f2 = new xmldb_field('sources', XMLDB_TYPE_TEXT, null, null, null, null, null, 'response');
        if (!$dbman->field_exists($table, $f1)) $dbman->add_field($table, $f1);
        if (!$dbman->field_exists($table, $f2)) $dbman->add_field($table, $f2);
        $idx = new xmldb_index('session_key', XMLDB_INDEX_NOTUNIQUE, ['session_key']);
        if (!$dbman->index_exists($table, $idx)) $dbman->add_index($table, $idx);
        upgrade_plugin_savepoint(true, 2026072000, 'local', 'umat_ai');
    }

    if ($oldversion < 2026072100) {
        // Add reporter columns to issue_reports for unauthenticated login-page reports.
        $table = new xmldb_table('umat_ai_issue_reports');
        $f1 = new xmldb_field('reporter_name', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'lecturer_notes');
        $f2 = new xmldb_field('reporter_username', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'reporter_name');
        if (!$dbman->field_exists($table, $f1)) $dbman->add_field($table, $f1);
        if (!$dbman->field_exists($table, $f2)) $dbman->add_field($table, $f2);

        // Create rate-limit log table for login-page course lookups.
        $logTable = new xmldb_table('umat_ai_login_lookup_log');
        if (!$dbman->table_exists($logTable)) {
            $logTable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $logTable->add_field('ip_address', XMLDB_TYPE_CHAR, '45', null, XMLDB_NOTNULL, null, null);
            $logTable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $logTable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $logTable->add_index('ip_time', XMLDB_INDEX_NOTUNIQUE, ['ip_address', 'timecreated']);
            $dbman->create_table($logTable);
        }

        upgrade_plugin_savepoint(true, 2026072100, 'local', 'umat_ai');
    }

    if ($oldversion < 2026072101) {
        $table = new xmldb_table('umat_resource_items');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null);
            $table->add_field('parentid', XMLDB_TYPE_INTEGER, '10', null, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, null, null);
            $table->add_field('filesize', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('mimetype', XMLDB_TYPE_CHAR, '100', null, null, null);
            $table->add_field('isfolder', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('fileid', XMLDB_TYPE_INTEGER, '10', null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_rb_user', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('idx_rb_parent', XMLDB_INDEX_NOTUNIQUE, ['parentid']);
            $table->add_index('idx_rb_course', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026072101, 'local', 'umat_ai');
    }

    // Migrate legacy issue_reports rows into conversation/message tables.
    $migratelegacyissues = static function() use ($DB): void {
        $categorymap = [
            'concept_confusion' => 'course_material',
            'material_error' => 'course_material',
            'technical_issue' => 'technical_problem',
            'suggestion' => 'other',
        ];
        $legacyreports = $DB->get_recordset('umat_ai_issue_reports', null, 'id ASC');
        foreach ($legacyreports as $legacy) {
            $isunverified = $legacy->category === 'login_issue' ||
                !empty($legacy->reporter_name) || !empty($legacy->reporter_username);
            if ($isunverified || empty($legacy->userid) ||
                    !$DB->record_exists('user', ['id' => $legacy->userid]) ||
                    !$DB->record_exists('course', ['id' => $legacy->courseid])) {
                continue;
            }

            $transaction = $DB->start_delegated_transaction();
            $category = $categorymap[$legacy->category] ?? 'other';
            $fallbacktitle = ucwords(str_replace('_', ' ', $legacy->category ?: 'Course issue'));
            $title = trim((string)($legacy->topic ?? '')) ?: $fallbacktitle;
            $lasttime = !empty($legacy->lecturer_response) ?
                max((int)$legacy->timecreated, (int)$legacy->timemodified) : (int)$legacy->timecreated;

            $conversation = $DB->get_record('umat_ai_issue_conversations', ['legacyissueid' => $legacy->id]);
            if (!$conversation) {
                $conversation = (object)[
                    'courseid' => (int)$legacy->courseid,
                    'studentid' => (int)$legacy->userid,
                    'title' => \core_text::substr($title, 0, 255),
                    'category' => $category,
                    'clientid' => 'legacy-' . $legacy->id,
                    'legacyissueid' => (int)$legacy->id,
                    'timecreated' => (int)$legacy->timecreated,
                    'lastmessagetime' => $lasttime,
                ];
                $conversation->id = $DB->insert_record('umat_ai_issue_conversations', $conversation);
            }

            $studentclientid = 'legacy-student-' . $legacy->id;
            if (!$DB->record_exists('umat_ai_issue_messages', [
                    'conversationid' => $conversation->id,
                    'clientid' => $studentclientid,
                ])) {
                $DB->insert_record('umat_ai_issue_messages', (object)[
                    'conversationid' => $conversation->id,
                    'senderid' => (int)$legacy->userid,
                    'senderrole' => 'student',
                    'body' => (string)$legacy->description,
                    'clientid' => $studentclientid,
                    'attachmentcount' => 0,
                    'timecreated' => (int)$legacy->timecreated,
                    'deliveredat' => (int)$legacy->timecreated,
                    'viewedat' => !empty($legacy->lecturer_response) ? $lasttime : 0,
                ]);
            }

            $lecturerclientid = 'legacy-lecturer-' . $legacy->id;
            if (!empty($legacy->lecturer_response) && !$DB->record_exists('umat_ai_issue_messages', [
                    'conversationid' => $conversation->id,
                    'clientid' => $lecturerclientid,
                ])) {
                $DB->insert_record('umat_ai_issue_messages', (object)[
                    'conversationid' => $conversation->id,
                    'senderid' => 0,
                    'senderrole' => 'lecturer',
                    'body' => (string)$legacy->lecturer_response,
                    'clientid' => $lecturerclientid,
                    'attachmentcount' => 0,
                    'timecreated' => $lasttime,
                    'deliveredat' => $lasttime,
                    'viewedat' => !empty($legacy->response_seen) ? $lasttime : 0,
                ]);
            }

            $newestmessagetime = (int)$DB->get_field_sql(
                'SELECT MAX(timecreated) FROM {umat_ai_issue_messages} WHERE conversationid = :conversationid',
                ['conversationid' => $conversation->id]
            );
            $lasttime = max($lasttime, $newestmessagetime);
            if ((int)$conversation->lastmessagetime !== $lasttime) {
                $DB->set_field('umat_ai_issue_conversations', 'lastmessagetime', $lasttime, ['id' => $conversation->id]);
            }
            $transaction->allow_commit();
        }
        $legacyreports->close();
    };

    if ($oldversion < 2026072200) {
        // Repair legacy schema drift before mapping reports into conversations.
        $legacytable = new xmldb_table('umat_ai_issue_reports');
        $responsefield = new xmldb_field('lecturer_response', XMLDB_TYPE_TEXT, null, null, null, null, null, 'lecturer_notes');
        $seenfield = new xmldb_field('response_seen', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'lecturer_response');
        if (!$dbman->field_exists($legacytable, $responsefield)) {
            $dbman->add_field($legacytable, $responsefield);
        }
        if (!$dbman->field_exists($legacytable, $seenfield)) {
            $dbman->add_field($legacytable, $seenfield);
        }

        $conversationtable = new xmldb_table('umat_ai_issue_conversations');
        if (!$dbman->table_exists($conversationtable)) {
            $conversationtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $conversationtable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $conversationtable->add_field('studentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $conversationtable->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $conversationtable->add_field('category', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'other');
            $conversationtable->add_field('clientid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $conversationtable->add_field('legacyissueid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $conversationtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $conversationtable->add_field('lastmessagetime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $conversationtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $conversationtable->add_key('course_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $conversationtable->add_key('student_fk', XMLDB_KEY_FOREIGN, ['studentid'], 'user', ['id']);
            $conversationtable->add_index('student_client', XMLDB_INDEX_UNIQUE, ['studentid', 'clientid']);
            $conversationtable->add_index('legacyissue', XMLDB_INDEX_UNIQUE, ['legacyissueid']);
            $conversationtable->add_index('course_lastmessage', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'lastmessagetime']);
            $conversationtable->add_index('student_course_lastmessage', XMLDB_INDEX_NOTUNIQUE, ['studentid', 'courseid', 'lastmessagetime']);
            $dbman->create_table($conversationtable);
        }

        $messagetable = new xmldb_table('umat_ai_issue_messages');
        if (!$dbman->table_exists($messagetable)) {
            $messagetable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $messagetable->add_field('conversationid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $messagetable->add_field('senderid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $messagetable->add_field('senderrole', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $messagetable->add_field('body', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $messagetable->add_field('clientid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $messagetable->add_field('attachmentcount', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $messagetable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $messagetable->add_field('deliveredat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $messagetable->add_field('viewedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $messagetable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $messagetable->add_key('conversation_fk', XMLDB_KEY_FOREIGN, ['conversationid'], 'umat_ai_issue_conversations', ['id']);
            $messagetable->add_index('conversation_client', XMLDB_INDEX_UNIQUE, ['conversationid', 'clientid']);
            $messagetable->add_index('conversation_time', XMLDB_INDEX_NOTUNIQUE, ['conversationid', 'timecreated']);
            $messagetable->add_index('conversation_receipt', XMLDB_INDEX_NOTUNIQUE, ['conversationid', 'senderrole', 'viewedat']);
            $dbman->create_table($messagetable);
        }

        $migratelegacyissues();

        upgrade_plugin_savepoint(true, 2026072200, 'local', 'umat_ai');
    }

    if ($oldversion < 2026072201) {
        // Scope idempotency keys to the course and sender, then reconcile any partial migration.
        $conversationtable = new xmldb_table('umat_ai_issue_conversations');
        $oldconversationindex = new xmldb_index('student_client', XMLDB_INDEX_UNIQUE, ['studentid', 'clientid']);
        if ($dbman->index_exists($conversationtable, $oldconversationindex)) {
            $dbman->drop_index($conversationtable, $oldconversationindex);
        }
        $conversationindex = new xmldb_index(
            'student_course_client',
            XMLDB_INDEX_UNIQUE,
            ['studentid', 'courseid', 'clientid']
        );
        if (!$dbman->index_exists($conversationtable, $conversationindex)) {
            $dbman->add_index($conversationtable, $conversationindex);
        }

        $messagetable = new xmldb_table('umat_ai_issue_messages');
        $oldmessageindex = new xmldb_index('conversation_client', XMLDB_INDEX_UNIQUE, ['conversationid', 'clientid']);
        if ($dbman->index_exists($messagetable, $oldmessageindex)) {
            $dbman->drop_index($messagetable, $oldmessageindex);
        }
        $messageindex = new xmldb_index(
            'conversation_sender_client',
            XMLDB_INDEX_UNIQUE,
            ['conversationid', 'senderid', 'clientid']
        );
        if (!$dbman->index_exists($messagetable, $messageindex)) {
            $dbman->add_index($messagetable, $messageindex);
        }

        $migratelegacyissues();
        upgrade_plugin_savepoint(true, 2026072201, 'local', 'umat_ai');
    }

    if ($oldversion < 2026072202) {
        // Add studentvisible and resource_type to umat_ai_sessions for recording visibility and type.
        $table = new xmldb_table('umat_ai_sessions');
        $f1 = new xmldb_field('resource_type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'bbb_recording', 'source_type');
        $f2 = new xmldb_field('studentvisible', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'status');
        if (!$dbman->field_exists($table, $f1)) $dbman->add_field($table, $f1);
        if (!$dbman->field_exists($table, $f2)) $dbman->add_field($table, $f2);

        // Add studentvisible to umat_ai_materials for lecturer-uploaded materials visibility.
        $mattable = new xmldb_table('umat_ai_materials');
        $f3 = new xmldb_field('studentvisible', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'timecreated');
        if (!$dbman->field_exists($mattable, $f3)) $dbman->add_field($mattable, $f3);

        upgrade_plugin_savepoint(true, 2026072202, 'local', 'umat_ai');
    }

    if ($oldversion < 2026072300) {
        // Add transcription metadata columns to umat_ai_sessions for Phase 2 API integration.
        $table = new xmldb_table('umat_ai_sessions');
        $f1 = new xmldb_field('transcription_provider', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'transcript_json');
        $f2 = new xmldb_field('transcription_model', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'transcription_provider');
        $f3 = new xmldb_field('transcription_cost', XMLDB_TYPE_NUMBER, '10,6', null, null, null, 0, 'transcription_model');
        $f4 = new xmldb_field('audio_duration_secs', XMLDB_TYPE_NUMBER, '10,2', null, null, null, 0, 'transcription_cost');
        $f5 = new xmldb_field('chunk_count', XMLDB_TYPE_INTEGER, '5', null, null, null, 0, 'audio_duration_secs');
        if (!$dbman->field_exists($table, $f1)) $dbman->add_field($table, $f1);
        if (!$dbman->field_exists($table, $f2)) $dbman->add_field($table, $f2);
        if (!$dbman->field_exists($table, $f3)) $dbman->add_field($table, $f3);
        if (!$dbman->field_exists($table, $f4)) $dbman->add_field($table, $f4);
        if (!$dbman->field_exists($table, $f5)) $dbman->add_field($table, $f5);
        upgrade_plugin_savepoint(true, 2026072300, 'local', 'umat_ai');
    }

    if ($oldversion < 2026072601) {
        $table = new xmldb_table('umat_ai_student_metrics');

        $f1 = new xmldb_field('risk_level', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'last_active');
        if (!$dbman->field_exists($table, $f1)) {
            $dbman->add_field($table, $f1);
        }

        $f2 = new xmldb_field('confidence', XMLDB_TYPE_NUMBER, '5, 2', null, null, null, null, 'risk_level');
        if (!$dbman->field_exists($table, $f2)) {
            $dbman->add_field($table, $f2);
        }

        $f3 = new xmldb_field('classification', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'confidence');
        if (!$dbman->field_exists($table, $f3)) {
            $dbman->add_field($table, $f3);
        }

        upgrade_plugin_savepoint(true, 2026072601, 'local', 'umat_ai');
    }

    if ($oldversion < 2026080400) {
        // M1 (Source-Cited Q&A): persist structured citation payloads alongside
        // the legacy source-name strings so chat history can resume with
        // clickable citations.
        $ct = new xmldb_table('umat_ai_chat_logs');
        $cf = new xmldb_field('citations', XMLDB_TYPE_TEXT, null, null, null, null, null, 'sources');
        if (!$dbman->field_exists($ct, $cf)) $dbman->add_field($ct, $cf);

        $lt = new xmldb_table('umat_ai_lecturer_notes');
        $lf = new xmldb_field('citations', XMLDB_TYPE_TEXT, null, null, null, null, null, 'sources');
        if (!$dbman->field_exists($lt, $lf)) $dbman->add_field($lt, $lf);

        upgrade_plugin_savepoint(true, 2026080400, 'local', 'umat_ai');
    }

    if ($oldversion < 2026080500) {
        // M2 (F3 Spaced-Repetition Flashcards): deck + per-student review state.
        // status: 0=pending lecturer approval, 1=approved (visible to students),
        //         -1=rejected by lecturer.
        $fc = new xmldb_table('umat_ai_flashcards');
        if (!$dbman->table_exists($fc)) {
            $fc->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $fc->add_field('courseid',     XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, null);
            $fc->add_field('materialid',   XMLDB_TYPE_INTEGER, '10',  null, null,  null, null);
            $fc->add_field('front',        XMLDB_TYPE_TEXT,    null,  null, XMLDB_NOTNULL,  null, null);
            $fc->add_field('back',         XMLDB_TYPE_TEXT,    null,  null, XMLDB_NOTNULL,  null, null);
            $fc->add_field('topic',        XMLDB_TYPE_CHAR,   '255', null, null,  null, '');
            $fc->add_field('status',       XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL,  null, '0');
            $fc->add_field('created_by',   XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, null);
            $fc->add_field('approved_by',  XMLDB_TYPE_INTEGER, '10',  null, null,  null, null);
            $fc->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, '0');
            $fc->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, '0');
            $fc->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $fc->add_index('course_status', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'status']);
            $fc->add_index('material',      XMLDB_INDEX_NOTUNIQUE, ['materialid']);
            $dbman->create_table($fc);
        }

        // SM-2 review state — one row per student per card (unique constraint
        // makes re-review an upsert).
        $fr = new xmldb_table('umat_ai_flashcard_reviews');
        if (!$dbman->table_exists($fr)) {
            $fr->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $fr->add_field('userid',       XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, null);
            $fr->add_field('flashcardid',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, null);
            $fr->add_field('quality',      XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL,  null, '0');
            $fr->add_field('ease',         XMLDB_TYPE_NUMBER,  '10,5', null, XMLDB_NOTNULL,  null, '2.5');
            $fr->add_field('interval',     XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, '0');
            $fr->add_field('repetitions',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, '0');
            $fr->add_field('due_at',       XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, '0');
            $fr->add_field('timereviewed', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL,  null, '0');
            $fr->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $fr->add_key('flashcard_fk', XMLDB_KEY_FOREIGN, ['flashcardid'], 'umat_ai_flashcards', ['id'], 'cascade');
            $fr->add_index('user_flashcard', XMLDB_INDEX_UNIQUE, ['userid', 'flashcardid']);
            $fr->add_index('user_due',       XMLDB_INDEX_NOTUNIQUE, ['userid', 'due_at']);
            $dbman->create_table($fr);
        }

        upgrade_plugin_savepoint(true, 2026080500, 'local', 'umat_ai');
    }

    return true;
}
