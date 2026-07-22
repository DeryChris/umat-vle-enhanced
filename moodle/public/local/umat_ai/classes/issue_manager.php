<?php
/**
 * Shared authorization, receipt, attachment, and notification logic for Student Issues.
 *
 * @package local_umat_ai
 */

namespace local_umat_ai;

defined('MOODLE_INTERNAL') || die();

class issue_manager {
    public const ROLE_STUDENT = 'student';
    public const ROLE_LECTURER = 'lecturer';

    /** @var string[] Categories accepted by the messaging workflow. */
    public const CATEGORIES = [
        'course_material',
        'assignment',
        'quiz_examination',
        'grade_feedback',
        'live_class_recording',
        'technical_problem',
        'access_permission',
        'other',
    ];

    /**
     * Require an enrolled student who may report an issue in a course.
     */
    public static function require_student_course(int $courseid): \context_course {
        global $USER;

        $context = \context_course::instance($courseid);
        if (!is_enrolled($context, $USER, '', true)) {
            throw new \moodle_exception('notenrolled', 'error');
        }
        require_capability('local/umat_ai:reportissue', $context);
        return $context;
    }

    /**
     * Whether a user is an active course lecturer or a site administrator.
     */
    public static function can_manage_course(\context_course $context, ?object $user = null): bool {
        global $USER;

        $user = $user ?? $USER;
        if (!has_capability('local/umat_ai:manageissues', $context, $user)) {
            return false;
        }
        return is_siteadmin($user) || is_enrolled($context, $user, '', true);
    }

    /**
     * Require active lecturer access to private conversations in a course.
     */
    public static function require_manage_course(int $courseid): \context_course {
        $context = \context_course::instance($courseid);
        if (!self::can_manage_course($context)) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        return $context;
    }

    /**
     * Return a conversation and the current participant role after authorization.
     *
     * @return array{0: object, 1: string, 2: \context_course}
     */
    public static function require_conversation(int $conversationid): array {
        global $DB, $USER;

        $conversation = $DB->get_record('umat_ai_issue_conversations', ['id' => $conversationid], '*', MUST_EXIST);
        $context = \context_course::instance($conversation->courseid);

        if (self::can_manage_course($context)) {
            return [$conversation, self::ROLE_LECTURER, $context];
        }
        if ((int)$conversation->studentid === (int)$USER->id) {
            self::require_student_course((int)$conversation->courseid);
            return [$conversation, self::ROLE_STUDENT, $context];
        }

        // Use a generic denial so an ID probe does not reveal another student's conversation.
        throw new \moodle_exception('nopermissions', 'error');
    }

    /**
     * Determine which inbox role the current user has for a course.
     */
    public static function require_course_role(int $courseid): string {
        global $USER;

        $context = \context_course::instance($courseid);
        if (self::can_manage_course($context)) {
            return self::ROLE_LECTURER;
        }
        if (is_enrolled($context, $USER, '', true) && has_capability('local/umat_ai:reportissue', $context)) {
            return self::ROLE_STUDENT;
        }
        throw new \moodle_exception('nopermissions', 'error');
    }

    /**
     * Validate a browser-generated idempotency key.
     */
    public static function clean_clientid(string $clientid): string {
        $clientid = trim($clientid);
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $clientid)) {
            throw new \invalid_parameter_exception('Invalid message identifier.');
        }
        return $clientid;
    }

    /**
     * Return a stable receipt state for a message.
     */
    public static function receipt(object $message): string {
        if (!empty($message->viewedat)) {
            return 'viewed';
        }
        if (!empty($message->deliveredat)) {
            return 'delivered';
        }
        return 'sent';
    }

    /**
     * Mark incoming messages as delivered when an authorized recipient fetches the thread.
     */
    public static function mark_delivered(int $conversationid, string $recipientrole): int {
        global $DB;

        $now = time();
        $select = 'conversationid = :conversationid AND senderrole <> :recipientrole AND deliveredat = 0';
        $params = [
            'conversationid' => $conversationid,
            'recipientrole' => $recipientrole,
        ];
        $count = $DB->count_records_select('umat_ai_issue_messages', $select, $params);
        if ($count) {
            $DB->execute(
                "UPDATE {umat_ai_issue_messages} SET deliveredat = :deliveredat WHERE {$select}",
                ['deliveredat' => $now] + $params
            );
        }
        return $count;
    }

    /**
     * Serialize one message for an authorized participant.
     */
    public static function export_message(object $message, string $viewerrole, \context_course $context): array {
        global $DB;

        $sendername = $message->senderrole === self::ROLE_LECTURER ? get_string('issue_course_lecturer', 'local_umat_ai') : '';
        if (!empty($message->senderid)) {
            $sender = $DB->get_record('user', ['id' => $message->senderid], 'id,firstname,lastname', IGNORE_MISSING);
            if ($sender) {
                $sendername = fullname($sender);
            }
        }

        return [
            'id' => (int)$message->id,
            'conversationid' => (int)$message->conversationid,
            'senderid' => (int)$message->senderid,
            'senderrole' => $message->senderrole,
            'sendername' => $sendername,
            'body' => (string)$message->body,
            'timecreated' => (int)$message->timecreated,
            'deliveredat' => (int)$message->deliveredat,
            'viewedat' => (int)$message->viewedat,
            'receipt' => self::receipt($message),
            'ismine' => $message->senderrole === $viewerrole,
            'attachments' => self::get_attachments((int)$message->id, $context),
        ];
    }

    /**
     * Serialize a conversation row for an inbox.
     */
    public static function export_conversation(object $conversation, string $viewerrole): array {
        global $DB;

        $context = \context_course::instance($conversation->courseid);
        $course = $DB->get_record('course', ['id' => $conversation->courseid], 'id,fullname,shortname', MUST_EXIST);
        $student = $DB->get_record('user', ['id' => $conversation->studentid], 'id,firstname,lastname,idnumber', MUST_EXIST);
        $lastmessage = $DB->get_record_sql(
            'SELECT * FROM {umat_ai_issue_messages} WHERE conversationid = :conversationid ORDER BY id DESC',
            ['conversationid' => $conversation->id],
            IGNORE_MULTIPLE
        );
        $latestsent = $DB->get_record_sql(
            'SELECT * FROM {umat_ai_issue_messages}
              WHERE conversationid = :conversationid AND senderrole = :senderrole
              ORDER BY id DESC',
            ['conversationid' => $conversation->id, 'senderrole' => $viewerrole],
            IGNORE_MULTIPLE
        );
        $unread = $DB->count_records_select(
            'umat_ai_issue_messages',
            'conversationid = :conversationid AND senderrole <> :senderrole AND viewedat = 0',
            ['conversationid' => $conversation->id, 'senderrole' => $viewerrole]
        );
        $preview = $lastmessage ? trim((string)$lastmessage->body) : '';
        if (mb_strlen($preview) > 140) {
            $preview = mb_substr($preview, 0, 137) . '...';
        }

        return [
            'id' => (int)$conversation->id,
            'courseid' => (int)$conversation->courseid,
            'coursename' => format_string($course->fullname, true, ['context' => $context]),
            'courseshortname' => format_string($course->shortname, true, ['context' => $context]),
            'studentid' => (int)$conversation->studentid,
            'studentname' => fullname($student),
            'studentidnumber' => has_capability('moodle/user:viewhiddendetails', $context) ? (string)$student->idnumber : '',
            'title' => (string)$conversation->title,
            'category' => (string)$conversation->category,
            'timecreated' => (int)$conversation->timecreated,
            'lastmessagetime' => (int)$conversation->lastmessagetime,
            'lastmessage' => $preview,
            'unreadcount' => (int)$unread,
            'latestsentmessageid' => $latestsent ? (int)$latestsent->id : 0,
            'latestsentreceipt' => $latestsent ? self::receipt($latestsent) : '',
        ];
    }

    /**
     * Get secure File API attachments for a message.
     */
    public static function get_attachments(int $messageid, \context_course $context): array {
        $files = get_file_storage()->get_area_files(
            $context->id,
            'local_umat_ai',
            'issue_attachments',
            $messageid,
            'filename ASC',
            false
        );
        $attachments = [];
        foreach ($files as $file) {
            $attachments[] = [
                'filename' => $file->get_filename(),
                'mimetype' => $file->get_mimetype() ?: 'application/octet-stream',
                'filesize' => (int)$file->get_filesize(),
                'url' => \moodle_url::make_pluginfile_url(
                    $context->id,
                    'local_umat_ai',
                    'issue_attachments',
                    $messageid,
                    '/',
                    $file->get_filename()
                )->out(false),
            ];
        }
        return $attachments;
    }

    /**
     * Return the unread count for one authorized role and optional course.
     */
    public static function unread_count(string $role, int $courseid = 0): int {
        global $DB, $USER;

        $records = $DB->get_records('umat_ai_issue_conversations', $courseid ? ['courseid' => $courseid] : null);
        $count = 0;
        foreach ($records as $conversation) {
            $context = \context_course::instance($conversation->courseid);
            if ($role === self::ROLE_STUDENT) {
                if ((int)$conversation->studentid !== (int)$USER->id ||
                        !is_enrolled($context, $USER, '', true) ||
                        !has_capability('local/umat_ai:reportissue', $context)) {
                    continue;
                }
            } else if (!self::can_manage_course($context)) {
                continue;
            }
            $count += $DB->count_records_select(
                'umat_ai_issue_messages',
                'conversationid = :conversationid AND senderrole <> :senderrole AND viewedat = 0',
                ['conversationid' => $conversation->id, 'senderrole' => $role]
            );
        }
        return $count;
    }

    /**
     * Send a metadata-only Moodle notification for a newly persisted message.
     */
    public static function notify_message(object $conversation, object $message): void {
        global $DB;

        if (empty($message->senderid)) {
            return;
        }
        $context = \context_course::instance($conversation->courseid);
        $course = $DB->get_record('course', ['id' => $conversation->courseid], 'id,fullname', MUST_EXIST);
        $student = $DB->get_record('user', ['id' => $conversation->studentid], '*', MUST_EXIST);
        $sender = $DB->get_record('user', ['id' => $message->senderid], '*', MUST_EXIST);
        $url = new \moodle_url('/course/view.php', [
            'id' => $conversation->courseid,
            'umat_issue' => $conversation->id,
        ]);

        if ($message->senderrole === self::ROLE_STUDENT) {
            $recipients = get_enrolled_users($context, 'local/umat_ai:manageissues', 0, 'u.*', null, 0, 0, true);
            $subject = get_string('issue_notification_lecturer_subject', 'local_umat_ai', format_string($course->fullname));
            $body = get_string('issue_notification_lecturer_body', 'local_umat_ai', (object)[
                'student' => fullname($student),
                'course' => format_string($course->fullname),
                'title' => $conversation->title,
                'url' => $url->out(false),
            ]);
        } else {
            $recipients = [$student->id => $student];
            $subject = get_string('issue_notification_student_subject', 'local_umat_ai', format_string($course->fullname));
            $body = get_string('issue_notification_student_body', 'local_umat_ai', (object)[
                'course' => format_string($course->fullname),
                'title' => $conversation->title,
                'url' => $url->out(false),
            ]);
        }

        foreach ($recipients as $recipient) {
            if ((int)$recipient->id === (int)$sender->id) {
                continue;
            }
            try {
                $eventdata = new \core\message\message();
                $eventdata->component = 'local_umat_ai';
                $eventdata->name = 'studentissues';
                $eventdata->userfrom = $sender;
                $eventdata->userto = $recipient;
                $eventdata->subject = $subject;
                $eventdata->fullmessage = $body;
                $eventdata->fullmessageformat = FORMAT_PLAIN;
                $eventdata->fullmessagehtml = '';
                $eventdata->smallmessage = $subject;
                $eventdata->notification = 1;
                $eventdata->courseid = (int)$conversation->courseid;
                $eventdata->contexturl = $url->out(false);
                $eventdata->contexturlname = get_string('issue_open_conversation', 'local_umat_ai');
                message_send($eventdata);
            } catch (\Throwable $e) {
                debugging('Student Issues notification failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
