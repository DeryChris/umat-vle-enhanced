<?php
// ============================================================
// GDPR compliance — export, delete, and declare user data
// Required under Ghana's Data Protection Act (2012) and Moodle standards
// ============================================================

namespace local_umat_ai\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'umat_ai_chat_logs',
            [
                'userid'      => 'privacy:metadata:umat_ai_chat_logs:userid',
                'question'    => 'privacy:metadata:umat_ai_chat_logs:question',
                'answer'      => 'privacy:metadata:umat_ai_chat_logs:answer',
                'timecreated' => 'privacy:metadata:umat_ai_chat_logs:timecreated',
            ],
            'privacy:metadata:umat_ai_chat_logs'
        );

        $collection->add_database_table(
            'umat_ai_issue_conversations',
            [
                'studentid' => 'privacy:metadata:umat_ai_issue_conversations:studentid',
                'title' => 'privacy:metadata:umat_ai_issue_conversations:title',
                'category' => 'privacy:metadata:umat_ai_issue_conversations:category',
                'timecreated' => 'privacy:metadata:umat_ai_issue_conversations:timecreated',
            ],
            'privacy:metadata:umat_ai_issue_conversations'
        );
        $collection->add_database_table(
            'umat_ai_issue_reports',
            [
                'userid' => 'privacy:metadata:umat_ai_issue_reports:userid',
                'topic' => 'privacy:metadata:umat_ai_issue_reports:topic',
                'description' => 'privacy:metadata:umat_ai_issue_reports:description',
                'lecturer_notes' => 'privacy:metadata:umat_ai_issue_reports:lecturer_notes',
                'lecturer_response' => 'privacy:metadata:umat_ai_issue_reports:lecturer_response',
                'timecreated' => 'privacy:metadata:umat_ai_issue_reports:timecreated',
            ],
            'privacy:metadata:umat_ai_issue_reports'
        );
        $collection->add_database_table(
            'umat_ai_issue_messages',
            [
                'senderid' => 'privacy:metadata:umat_ai_issue_messages:senderid',
                'body' => 'privacy:metadata:umat_ai_issue_messages:body',
                'timecreated' => 'privacy:metadata:umat_ai_issue_messages:timecreated',
                'deliveredat' => 'privacy:metadata:umat_ai_issue_messages:deliveredat',
                'viewedat' => 'privacy:metadata:umat_ai_issue_messages:viewedat',
            ],
            'privacy:metadata:umat_ai_issue_messages'
        );
        $collection->add_database_table(
            'umat_ai_activity_log',
            [
                'userid' => 'privacy:metadata:umat_ai_activity_log:userid',
                'event_type' => 'privacy:metadata:umat_ai_activity_log:event_type',
                'event_data' => 'privacy:metadata:umat_ai_activity_log:event_data',
                'timecreated' => 'privacy:metadata:umat_ai_activity_log:timecreated',
            ],
            'privacy:metadata:umat_ai_activity_log'
        );

        $collection->add_external_location_link(
            'google_gemini_api',
            ['question' => 'privacy:metadata:umat_ai_chat_logs:question'],
            'privacy:metadata:google_gemini_api'
        );

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {umat_ai_chat_logs} cl ON cl.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :chatcontextlevel AND cl.userid = :chatuserid
                 UNION
                SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {umat_ai_issue_conversations} c ON c.courseid = ctx.instanceid
             LEFT JOIN {umat_ai_issue_messages} m ON m.conversationid = c.id
                 WHERE ctx.contextlevel = :issuecontextlevel
                   AND (c.studentid = :studentid OR m.senderid = :senderid)
                 UNION
                SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {umat_ai_issue_reports} r ON r.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :legacycontextlevel AND r.userid = :legacyuserid
                 UNION
                SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {umat_ai_activity_log} a ON a.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :activitycontextlevel AND a.userid = :activityuserid";

        $contextlist->add_from_sql($sql, [
            'chatcontextlevel' => CONTEXT_COURSE,
            'chatuserid' => $userid,
            'issuecontextlevel' => CONTEXT_COURSE,
            'studentid' => $userid,
            'senderid' => $userid,
            'legacycontextlevel' => CONTEXT_COURSE,
            'legacyuserid' => $userid,
            'activitycontextlevel' => CONTEXT_COURSE,
            'activityuserid' => $userid,
        ]);

        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_course) return;

        $sql = "SELECT userid FROM {umat_ai_chat_logs} WHERE courseid = :chatcourseid
                UNION SELECT studentid AS userid FROM {umat_ai_issue_conversations} WHERE courseid = :studentcourseid
                UNION SELECT userid FROM {umat_ai_issue_reports} WHERE courseid = :legacycourseid AND userid > 0
                UNION SELECT userid FROM {umat_ai_activity_log} WHERE courseid = :activitycourseid AND userid > 0
                UNION SELECT m.senderid AS userid
                        FROM {umat_ai_issue_messages} m
                        JOIN {umat_ai_issue_conversations} c ON c.id = m.conversationid
                       WHERE c.courseid = :messagecourseid AND m.senderid > 0";
        $userlist->add_from_sql('userid', $sql, [
            'chatcourseid' => $context->instanceid,
            'studentcourseid' => $context->instanceid,
            'legacycourseid' => $context->instanceid,
            'activitycourseid' => $context->instanceid,
            'messagecourseid' => $context->instanceid,
        ]);
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) continue;

            $records = $DB->get_records('umat_ai_chat_logs', [
                'userid'   => $userid,
                'courseid' => $context->instanceid,
            ]);

            if (!empty($records)) {
                writer::with_context($context)->export_data(
                    ['UMaT AI Chat Logs'],
                    (object)['chatlogs' => array_values($records)]
                );
            }

            $conversations = $DB->get_records('umat_ai_issue_conversations', [
                'studentid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            foreach ($conversations as $conversation) {
                $messages = $DB->get_records('umat_ai_issue_messages', ['conversationid' => $conversation->id], 'id ASC');
                $subcontext = ['Student Issues', 'Conversation ' . $conversation->id];
                writer::with_context($context)->export_data(
                    $subcontext,
                    (object)[
                        'conversation' => $conversation,
                        'messages' => array_values($messages),
                    ]
                );
                foreach ($messages as $message) {
                    writer::with_context($context)->export_area_files(
                        $subcontext,
                        'local_umat_ai',
                        'issue_attachments',
                        $message->id
                    );
                }
            }

            $legacyreports = $DB->get_records('umat_ai_issue_reports', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            if ($legacyreports) {
                writer::with_context($context)->export_data(
                    ['Student Issues', 'Legacy reports'],
                    (object)['reports' => array_values($legacyreports)]
                );
            }

            $sentmessages = $DB->get_records_sql(
                'SELECT m.* FROM {umat_ai_issue_messages} m
                  JOIN {umat_ai_issue_conversations} c ON c.id = m.conversationid
                 WHERE c.courseid = :courseid AND m.senderid = :senderid',
                ['courseid' => $context->instanceid, 'senderid' => $userid]
            );
            if ($sentmessages) {
                $lecturersubcontext = ['Student Issues', 'Messages sent as lecturer'];
                writer::with_context($context)->export_data(
                    $lecturersubcontext,
                    (object)['messages' => array_values($sentmessages)]
                );
                foreach ($sentmessages as $message) {
                    writer::with_context($context)->export_area_files(
                        $lecturersubcontext,
                        'local_umat_ai',
                        'issue_attachments',
                        $message->id
                    );
                }
            }

            $activity = $DB->get_records('umat_ai_activity_log', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            if ($activity) {
                writer::with_context($context)->export_data(
                    ['UMaT AI Activity'],
                    (object)['events' => array_values($activity)]
                );
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!$context instanceof \context_course) return;
        $conversationids = array_keys($DB->get_records('umat_ai_issue_conversations', ['courseid' => $context->instanceid], '', 'id'));
        self::delete_conversations($conversationids, $context);
        $DB->delete_records('umat_ai_chat_logs', ['courseid' => $context->instanceid]);
        $DB->delete_records('umat_ai_issue_reports', ['courseid' => $context->instanceid]);
        $DB->delete_records('umat_ai_activity_log', ['courseid' => $context->instanceid]);
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) continue;
            $DB->delete_records('umat_ai_chat_logs', [
                'userid'   => $userid,
                'courseid' => $context->instanceid,
            ]);
            $conversationids = array_keys($DB->get_records('umat_ai_issue_conversations', [
                'studentid' => $userid,
                'courseid' => $context->instanceid,
            ], '', 'id'));
            self::delete_conversations($conversationids, $context);
            self::delete_sender_messages($userid, $context);
            $DB->delete_records('umat_ai_issue_reports', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            $DB->delete_records('umat_ai_activity_log', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_course) return;

        $userids = $userlist->get_userids();
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['courseid']   = $context->instanceid;

        $DB->delete_records_select('umat_ai_chat_logs', "userid $insql AND courseid = :courseid", $params);
        $DB->delete_records_select('umat_ai_activity_log', "userid $insql AND courseid = :courseid", $params);
        foreach ($userids as $userid) {
            $conversationids = array_keys($DB->get_records('umat_ai_issue_conversations', [
                'studentid' => $userid,
                'courseid' => $context->instanceid,
            ], '', 'id'));
            self::delete_conversations($conversationids, $context);
            self::delete_sender_messages((int)$userid, $context);
        }
        $DB->delete_records_select('umat_ai_issue_reports', "userid $insql AND courseid = :courseid", $params);
    }

    /**
     * Delete conversations, their messages, and File API attachments.
     */
    private static function delete_conversations(array $conversationids, \context_course $context): void {
        global $DB;
        foreach ($conversationids as $conversationid) {
            $messages = $DB->get_records('umat_ai_issue_messages', ['conversationid' => $conversationid], '', 'id');
            foreach ($messages as $message) {
                get_file_storage()->delete_area_files($context->id, 'local_umat_ai', 'issue_attachments', $message->id);
            }
            $DB->delete_records('umat_ai_issue_messages', ['conversationid' => $conversationid]);
            $DB->delete_records('umat_ai_issue_conversations', ['id' => $conversationid]);
        }
    }

    /**
     * Delete messages authored by a lecturer while preserving student-owned conversations.
     */
    private static function delete_sender_messages(int $userid, \context_course $context): void {
        global $DB;
        $messages = $DB->get_records_sql(
            'SELECT m.* FROM {umat_ai_issue_messages} m
              JOIN {umat_ai_issue_conversations} c ON c.id = m.conversationid
             WHERE c.courseid = :courseid AND m.senderid = :senderid',
            ['courseid' => $context->instanceid, 'senderid' => $userid]
        );
        foreach ($messages as $message) {
            get_file_storage()->delete_area_files($context->id, 'local_umat_ai', 'issue_attachments', $message->id);
            $DB->delete_records('umat_ai_issue_messages', ['id' => $message->id]);
        }
    }
}
