<?php
/**
 * External API for private Student Issues conversations.
 *
 * @package local_umat_ai
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use local_umat_ai\issue_manager;

class issue_conversation extends \external_api {
    public static function create_conversation_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'title' => new \external_value(PARAM_TEXT, 'Issue title'),
            'category' => new \external_value(PARAM_ALPHANUMEXT, 'Issue category'),
            'description' => new \external_value(PARAM_RAW, 'First message'),
            'clientid' => new \external_value(PARAM_ALPHANUMEXT, 'Idempotency key'),
        ]);
    }

    public static function create_conversation($courseid, $title, $category, $description, $clientid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::create_conversation_parameters(), [
            'courseid' => $courseid,
            'title' => $title,
            'category' => $category,
            'description' => $description,
            'clientid' => $clientid,
        ]);
        $context = issue_manager::require_student_course((int)$params['courseid']);
        self::validate_context($context);

        $title = trim(clean_param($params['title'], PARAM_TEXT));
        $description = trim(clean_param($params['description'], PARAM_TEXT));
        $category = $params['category'];
        $clientid = issue_manager::clean_clientid($params['clientid']);
        if ($title === '' || \core_text::strlen($title) > 255) {
            throw new \invalid_parameter_exception('Enter an issue title of 255 characters or fewer.');
        }
        if (!in_array($category, issue_manager::CATEGORIES, true)) {
            throw new \invalid_parameter_exception('Invalid issue category.');
        }
        if (\core_text::strlen($description) < 3 || \core_text::strlen($description) > 10000) {
            throw new \invalid_parameter_exception('Enter a description between 3 and 10,000 characters.');
        }

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_umat_ai');
        $lock = $lockfactory->get_lock(
            'issue-create-' . sha1($USER->id . ':' . $params['courseid'] . ':' . $clientid),
            10
        );
        if (!$lock) {
            throw new \moodle_exception('locktimeout', 'error');
        }

        $existing = $DB->get_record('umat_ai_issue_conversations', [
            'studentid' => $USER->id,
            'courseid' => (int)$params['courseid'],
            'clientid' => $clientid,
        ]);
        if ($existing) {
            $messages = $DB->get_records('umat_ai_issue_messages', ['conversationid' => $existing->id], 'id ASC', '*', 0, 1);
            $message = reset($messages);
            if (!$message) {
                $lock->release();
                throw new \moodle_exception('invalidrecord', 'error');
            }
            if ($existing->title !== $title || $existing->category !== $category || $message->body !== $description) {
                $lock->release();
                throw new \invalid_parameter_exception('This submission identifier was already used for different content.');
            }
            $lock->release();
            return self::create_result($existing, $message, true, $context);
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $conversation = (object)[
            'courseid' => (int)$params['courseid'],
            'studentid' => (int)$USER->id,
            'title' => $title,
            'category' => $category,
            'clientid' => $clientid,
            'legacyissueid' => null,
            'timecreated' => $now,
            'lastmessagetime' => $now,
        ];
        $conversation->id = $DB->insert_record('umat_ai_issue_conversations', $conversation);
        $message = (object)[
            'conversationid' => $conversation->id,
            'senderid' => (int)$USER->id,
            'senderrole' => issue_manager::ROLE_STUDENT,
            'body' => $description,
            'clientid' => $clientid,
            'attachmentcount' => 0,
            'timecreated' => $now,
            'deliveredat' => 0,
            'viewedat' => 0,
        ];
        $message->id = $DB->insert_record('umat_ai_issue_messages', $message);
        $DB->insert_record('umat_ai_activity_log', (object)[
            'userid' => (int)$USER->id,
            'courseid' => (int)$params['courseid'],
            'cmid' => null,
            'event_type' => 'issue_reported',
            'event_data' => json_encode([
                'category' => $category,
                'conversation_id' => $conversation->id,
            ]),
            'timecreated' => $now,
        ]);
        $transaction->allow_commit();
        $lock->release();

        issue_manager::notify_message($conversation, $message);
        try {
            \cache::make('local_umat_ai', 'struggle_insights')->delete('struggle_' . $conversation->courseid . '_60');
        } catch (\Throwable $e) {
            debugging('Could not purge Student Issues analytics cache: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return self::create_result($conversation, $message, false, $context);
    }

    private static function create_result(object $conversation, object $message, bool $duplicate,
            \context_course $context): array {
        return [
            'success' => true,
            'conversationid' => (int)$conversation->id,
            'messageid' => (int)$message->id,
            'duplicate' => $duplicate,
            'notice' => get_string('issue_message_sent', 'local_umat_ai'),
            'message' => issue_manager::export_message($message, issue_manager::ROLE_STUDENT, $context),
        ];
    }

    public static function create_conversation_returns(): \external_single_structure {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Whether the conversation is available'),
            'conversationid' => new \external_value(PARAM_INT, 'Conversation ID'),
            'messageid' => new \external_value(PARAM_INT, 'First message ID'),
            'duplicate' => new \external_value(PARAM_BOOL, 'Existing result returned for the same client key'),
            'notice' => new \external_value(PARAM_TEXT, 'Confirmation notice'),
            'message' => self::message_structure(),
        ]);
    }

    public static function list_conversations_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'inbox' => new \external_value(PARAM_ALPHA, 'student or lecturer'),
            'courseid' => new \external_value(PARAM_INT, 'Course ID, or zero for all authorized courses', VALUE_DEFAULT, 0),
            'category' => new \external_value(PARAM_ALPHANUMEXT, 'Category filter', VALUE_DEFAULT, ''),
            'query' => new \external_value(PARAM_TEXT, 'Search query', VALUE_DEFAULT, ''),
        ]);
    }

    public static function list_conversations($inbox, $courseid = 0, $category = '', $query = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::list_conversations_parameters(), [
            'inbox' => $inbox,
            'courseid' => $courseid,
            'category' => $category,
            'query' => $query,
        ]);
        $role = self::validate_inbox($params['inbox']);
        $courseid = (int)$params['courseid'];
        if ($courseid) {
            $context = \context_course::instance($courseid);
            self::validate_context($context);
            if ($role === issue_manager::ROLE_STUDENT) {
                issue_manager::require_student_course($courseid);
            } else {
                issue_manager::require_manage_course($courseid);
            }
        }
        if ($params['category'] !== '' && !in_array($params['category'], issue_manager::CATEGORIES, true)) {
            throw new \invalid_parameter_exception('Invalid issue category.');
        }

        $conditions = [];
        if ($courseid) {
            $conditions['courseid'] = $courseid;
        }
        if ($role === issue_manager::ROLE_STUDENT) {
            $conditions['studentid'] = (int)$USER->id;
        }
        if ($params['category'] !== '') {
            $conditions['category'] = $params['category'];
        }
        $records = $DB->get_records('umat_ai_issue_conversations', $conditions, 'lastmessagetime DESC, id DESC');
        $query = \core_text::strtolower(trim($params['query']));
        $conversations = [];
        foreach ($records as $conversation) {
            $context = \context_course::instance($conversation->courseid);
            if ($role === issue_manager::ROLE_STUDENT) {
                if (!is_enrolled($context, $USER, '', true) || !has_capability('local/umat_ai:reportissue', $context)) {
                    continue;
                }
            } else if (!issue_manager::can_manage_course($context)) {
                continue;
            }
            $exported = issue_manager::export_conversation($conversation, $role);
            if ($query !== '') {
                $haystack = \core_text::strtolower(implode(' ', [
                    $exported['title'],
                    $exported['studentname'],
                    $exported['studentidnumber'],
                    $exported['coursename'],
                    $exported['courseshortname'],
                    $exported['lastmessage'],
                ]));
                if (strpos($haystack, $query) === false) {
                    continue;
                }
            }
            $conversations[] = $exported;
        }

        return [
            'role' => $role,
            'total' => count($conversations),
            'totalunread' => array_sum(array_column($conversations, 'unreadcount')),
            'conversations' => $conversations,
        ];
    }

    public static function list_conversations_returns(): \external_single_structure {
        return new \external_single_structure([
            'role' => new \external_value(PARAM_ALPHA, 'Authorized inbox role'),
            'total' => new \external_value(PARAM_INT, 'Conversation count'),
            'totalunread' => new \external_value(PARAM_INT, 'Unread message count'),
            'conversations' => new \external_multiple_structure(self::conversation_structure()),
        ]);
    }

    public static function get_messages_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'conversationid' => new \external_value(PARAM_INT, 'Conversation ID'),
        ]);
    }

    public static function get_messages($conversationid): array {
        global $DB;

        $params = self::validate_parameters(self::get_messages_parameters(), ['conversationid' => $conversationid]);
        [$conversation, $role, $context] = issue_manager::require_conversation((int)$params['conversationid']);
        self::validate_context($context);
        issue_manager::mark_delivered((int)$conversation->id, $role);
        $records = $DB->get_records('umat_ai_issue_messages', ['conversationid' => $conversation->id], 'id ASC');
        $messages = [];
        foreach ($records as $message) {
            $messages[] = issue_manager::export_message($message, $role, $context);
        }
        return [
            'role' => $role,
            'conversation' => issue_manager::export_conversation($conversation, $role),
            'messages' => $messages,
        ];
    }

    public static function get_messages_returns(): \external_single_structure {
        return new \external_single_structure([
            'role' => new \external_value(PARAM_ALPHA, 'Authorized participant role'),
            'conversation' => self::conversation_structure(),
            'messages' => new \external_multiple_structure(self::message_structure()),
        ]);
    }

    public static function send_message_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'conversationid' => new \external_value(PARAM_INT, 'Conversation ID'),
            'body' => new \external_value(PARAM_RAW, 'Message body'),
            'clientid' => new \external_value(PARAM_ALPHANUMEXT, 'Idempotency key'),
        ]);
    }

    public static function send_message($conversationid, $body, $clientid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::send_message_parameters(), [
            'conversationid' => $conversationid,
            'body' => $body,
            'clientid' => $clientid,
        ]);
        [$conversation, $role, $context] = issue_manager::require_conversation((int)$params['conversationid']);
        self::validate_context($context);
        $body = trim(clean_param($params['body'], PARAM_TEXT));
        $clientid = issue_manager::clean_clientid($params['clientid']);
        if ($body === '' || \core_text::strlen($body) > 10000) {
            throw new \invalid_parameter_exception('Enter a message of 10,000 characters or fewer.');
        }

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_umat_ai');
        $lock = $lockfactory->get_lock(
            'issue-message-' . sha1($conversation->id . ':' . $USER->id . ':' . $clientid),
            10
        );
        if (!$lock) {
            throw new \moodle_exception('locktimeout', 'error');
        }

        $existing = $DB->get_record('umat_ai_issue_messages', [
            'conversationid' => $conversation->id,
            'senderid' => (int)$USER->id,
            'clientid' => $clientid,
        ]);
        if ($existing) {
            if ($existing->body !== $body || $existing->senderrole !== $role) {
                $lock->release();
                throw new \invalid_parameter_exception('This message identifier was already used for different content.');
            }
            $lock->release();
            return [
                'success' => true,
                'duplicate' => true,
                'message' => issue_manager::export_message($existing, $role, $context),
            ];
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $message = (object)[
            'conversationid' => (int)$conversation->id,
            'senderid' => (int)$USER->id,
            'senderrole' => $role,
            'body' => $body,
            'clientid' => $clientid,
            'attachmentcount' => 0,
            'timecreated' => $now,
            'deliveredat' => 0,
            'viewedat' => 0,
        ];
        $message->id = $DB->insert_record('umat_ai_issue_messages', $message);
        $conversation->lastmessagetime = $now;
        $DB->update_record('umat_ai_issue_conversations', $conversation);
        $transaction->allow_commit();
        $lock->release();

        issue_manager::notify_message($conversation, $message);
        return [
            'success' => true,
            'duplicate' => false,
            'message' => issue_manager::export_message($message, $role, $context),
        ];
    }

    public static function send_message_returns(): \external_single_structure {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Whether the message is available'),
            'duplicate' => new \external_value(PARAM_BOOL, 'Existing result returned for the same client key'),
            'message' => self::message_structure(),
        ]);
    }

    public static function mark_viewed_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'conversationid' => new \external_value(PARAM_INT, 'Conversation ID'),
            'messageids' => new \external_multiple_structure(
                new \external_value(PARAM_INT, 'Visible message ID'),
                'Messages that were actually displayed'
            ),
        ]);
    }

    public static function mark_viewed($conversationid, $messageids): array {
        global $DB;

        $params = self::validate_parameters(self::mark_viewed_parameters(), [
            'conversationid' => $conversationid,
            'messageids' => $messageids,
        ]);
        [$conversation, $role, $context] = issue_manager::require_conversation((int)$params['conversationid']);
        self::validate_context($context);
        $messageids = array_values(array_unique(array_filter(array_map('intval', $params['messageids']))));
        if (!$messageids) {
            return ['success' => true, 'viewed' => 0, 'viewedat' => 0];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($messageids, SQL_PARAMS_NAMED, 'messageid');
        $selectparams = array_merge($inparams, [
            'conversationid' => (int)$conversation->id,
            'senderrole' => $role,
        ]);
        $select = "conversationid = :conversationid AND senderrole <> :senderrole
                    AND deliveredat > 0 AND viewedat = 0 AND id {$insql}";
        $viewed = $DB->count_records_select('umat_ai_issue_messages', $select, $selectparams);
        $now = time();
        if ($viewed) {
            $DB->execute(
                "UPDATE {umat_ai_issue_messages} SET viewedat = :now WHERE {$select}",
                array_merge($selectparams, ['now' => $now])
            );
        }
        return [
            'success' => true,
            'viewed' => (int)$viewed,
            'viewedat' => $viewed ? $now : 0,
        ];
    }

    public static function mark_viewed_returns(): \external_single_structure {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Whether the request was accepted'),
            'viewed' => new \external_value(PARAM_INT, 'Messages newly marked viewed'),
            'viewedat' => new \external_value(PARAM_INT, 'Viewed timestamp, or zero'),
        ]);
    }

    public static function get_unread_count_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'inbox' => new \external_value(PARAM_ALPHA, 'student or lecturer'),
            'courseid' => new \external_value(PARAM_INT, 'Course ID, or zero for all authorized courses', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_unread_count($inbox, $courseid = 0): array {
        $params = self::validate_parameters(self::get_unread_count_parameters(), [
            'inbox' => $inbox,
            'courseid' => $courseid,
        ]);
        $role = self::validate_inbox($params['inbox']);
        $courseid = (int)$params['courseid'];
        if ($courseid) {
            $context = \context_course::instance($courseid);
            self::validate_context($context);
            if ($role === issue_manager::ROLE_STUDENT) {
                issue_manager::require_student_course($courseid);
            } else {
                issue_manager::require_manage_course($courseid);
            }
        }
        return ['count' => issue_manager::unread_count($role, $courseid)];
    }

    public static function get_unread_count_returns(): \external_single_structure {
        return new \external_single_structure([
            'count' => new \external_value(PARAM_INT, 'Unread message count'),
        ]);
    }

    private static function validate_inbox(string $inbox): string {
        if (!in_array($inbox, [issue_manager::ROLE_STUDENT, issue_manager::ROLE_LECTURER], true)) {
            throw new \invalid_parameter_exception('Invalid Student Issues inbox.');
        }
        return $inbox;
    }

    private static function conversation_structure(): \external_single_structure {
        return new \external_single_structure([
            'id' => new \external_value(PARAM_INT, 'Conversation ID'),
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'coursename' => new \external_value(PARAM_TEXT, 'Course name'),
            'courseshortname' => new \external_value(PARAM_TEXT, 'Course short name'),
            'studentid' => new \external_value(PARAM_INT, 'Student user ID'),
            'studentname' => new \external_value(PARAM_TEXT, 'Student name'),
            'studentidnumber' => new \external_value(PARAM_TEXT, 'Student ID when permitted'),
            'title' => new \external_value(PARAM_TEXT, 'Issue title'),
            'category' => new \external_value(PARAM_ALPHANUMEXT, 'Issue category'),
            'timecreated' => new \external_value(PARAM_INT, 'Created timestamp'),
            'lastmessagetime' => new \external_value(PARAM_INT, 'Last-message timestamp'),
            'lastmessage' => new \external_value(PARAM_TEXT, 'Last-message preview'),
            'unreadcount' => new \external_value(PARAM_INT, 'Unread messages in this conversation'),
            'latestsentmessageid' => new \external_value(PARAM_INT, 'Latest message sent by this participant'),
            'latestsentreceipt' => new \external_value(PARAM_ALPHA, 'Sent, delivered, viewed, or empty'),
        ]);
    }

    private static function message_structure(): \external_single_structure {
        return new \external_single_structure([
            'id' => new \external_value(PARAM_INT, 'Message ID'),
            'conversationid' => new \external_value(PARAM_INT, 'Conversation ID'),
            'senderid' => new \external_value(PARAM_INT, 'Sender user ID'),
            'senderrole' => new \external_value(PARAM_ALPHA, 'Student or lecturer'),
            'sendername' => new \external_value(PARAM_TEXT, 'Sender display name'),
            'body' => new \external_value(PARAM_RAW, 'Message body'),
            'timecreated' => new \external_value(PARAM_INT, 'Sent timestamp'),
            'deliveredat' => new \external_value(PARAM_INT, 'Delivered timestamp'),
            'viewedat' => new \external_value(PARAM_INT, 'Viewed timestamp'),
            'receipt' => new \external_value(PARAM_ALPHA, 'sent, delivered, or viewed'),
            'ismine' => new \external_value(PARAM_BOOL, 'Whether the viewer sent this message'),
            'attachments' => new \external_multiple_structure(new \external_single_structure([
                'filename' => new \external_value(PARAM_FILE, 'File name'),
                'mimetype' => new \external_value(PARAM_RAW, 'MIME type'),
                'filesize' => new \external_value(PARAM_INT, 'File size'),
                'url' => new \external_value(PARAM_URL, 'Authorized pluginfile URL'),
            ])),
        ]);
    }
}
