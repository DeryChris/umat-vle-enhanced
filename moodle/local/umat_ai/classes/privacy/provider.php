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

        $collection->add_external_location_link(
            'openai_api',
            ['question' => 'privacy:metadata:umat_ai_chat_logs:question'],
            'privacy:metadata:openai_api'
        );

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {umat_ai_chat_logs} cl ON cl.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :contextlevel
                   AND cl.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid'       => $userid,
        ]);

        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_course) return;

        $sql = "SELECT userid FROM {umat_ai_chat_logs} WHERE courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
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
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!$context instanceof \context_course) return;
        $DB->delete_records('umat_ai_chat_logs', ['courseid' => $context->instanceid]);
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
    }
}