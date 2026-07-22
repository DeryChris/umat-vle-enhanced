<?php
/**
 * Tests for private Student Issues conversations and message receipts.
 *
 * @package local_umat_ai
 */

namespace local_umat_ai;

defined('MOODLE_INTERNAL') || die();

use local_umat_ai\external\issue_conversation;

final class issue_conversation_test extends \advanced_testcase {
    private object $course;
    private object $othercourse;
    private object $student;
    private object $otherstudent;
    private object $lecturer;
    private object $unauthorizedlecturer;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->othercourse = $generator->create_course();
        $this->student = $generator->create_user(['idnumber' => 'STU-001']);
        $this->otherstudent = $generator->create_user(['idnumber' => 'STU-002']);
        $this->lecturer = $generator->create_user();
        $this->unauthorizedlecturer = $generator->create_user();

        $generator->enrol_user($this->student->id, $this->course->id, 'student');
        $generator->enrol_user($this->otherstudent->id, $this->course->id, 'student');
        $generator->enrol_user($this->lecturer->id, $this->course->id, 'editingteacher');
        $generator->enrol_user($this->unauthorizedlecturer->id, $this->othercourse->id, 'editingteacher');
    }

    public function test_student_and_lecturer_message_flow_with_receipts(): void {
        $this->setUser($this->student);
        $created = issue_conversation::create_conversation(
            $this->course->id,
            'Week 4 assignment access',
            'assignment',
            'I cannot access the Week 4 assignment.',
            'conversation_0001'
        );
        $this->assertTrue($created['success']);
        $this->assertFalse($created['duplicate']);
        $this->assertSame('sent', $created['message']['receipt']);
        $conversationid = $created['conversationid'];
        $studentmessageid = $created['messageid'];

        $studentlist = issue_conversation::list_conversations('student', $this->course->id);
        $this->assertCount(1, $studentlist['conversations']);
        $this->assertArrayNotHasKey('status', $studentlist['conversations'][0]);
        $this->assertArrayNotHasKey('priority', $studentlist['conversations'][0]);

        $this->setUser($this->lecturer);
        $lecturerlist = issue_conversation::list_conversations('lecturer', $this->course->id);
        $this->assertSame(1, $lecturerlist['totalunread']);

        // Loading is not enough to create a read receipt; the UI reports displayed message IDs separately.
        $opened = issue_conversation::get_messages($conversationid);
        $this->assertSame('delivered', $opened['messages'][0]['receipt']);
        $this->assertSame(0, $opened['messages'][0]['viewedat']);
        issue_conversation::mark_viewed($conversationid, [$studentmessageid]);

        $reply = issue_conversation::send_message(
            $conversationid,
            'The assignment has been reopened. Please check again.',
            'lecturer_message_0001'
        );
        $this->assertSame('sent', $reply['message']['receipt']);
        $lecturermessageid = $reply['message']['id'];

        $this->setUser($this->student);
        $studentview = issue_conversation::get_messages($conversationid);
        $this->assertSame('viewed', $studentview['messages'][0]['receipt']);
        $this->assertSame('delivered', $studentview['messages'][1]['receipt']);
        $this->assertSame(1, issue_conversation::get_unread_count('student', $this->course->id)['count']);
        issue_conversation::mark_viewed($conversationid, [$lecturermessageid]);

        $followup = issue_conversation::send_message(
            $conversationid,
            'It is available now. Thank you.',
            'student_message_0002'
        );
        $this->assertSame($conversationid, $followup['message']['conversationid']);

        $this->setUser($this->lecturer);
        $lecturerview = issue_conversation::get_messages($conversationid);
        $replymessages = array_values(array_filter($lecturerview['messages'], static function(array $message): bool {
            return $message['senderrole'] === issue_manager::ROLE_LECTURER;
        }));
        $this->assertSame('viewed', $replymessages[0]['receipt']);
    }

    public function test_viewing_one_conversation_does_not_mark_another_viewed(): void {
        $first = $this->create_as_student('First issue', 'conversation_1001');
        $second = $this->create_as_student('Second issue', 'conversation_1002');

        $this->setUser($this->lecturer);
        issue_conversation::get_messages($first['conversationid']);
        $firstreply = issue_conversation::send_message($first['conversationid'], 'First response', 'lecturer_reply_1001');
        issue_conversation::get_messages($second['conversationid']);
        $secondreply = issue_conversation::send_message($second['conversationid'], 'Second response', 'lecturer_reply_1002');

        $this->setUser($this->student);
        issue_conversation::get_messages($first['conversationid']);
        issue_conversation::mark_viewed($first['conversationid'], [$firstreply['message']['id']]);
        $list = issue_conversation::list_conversations('student', $this->course->id);
        $byid = [];
        foreach ($list['conversations'] as $conversation) {
            $byid[$conversation['id']] = $conversation;
        }
        $this->assertSame(0, $byid[$first['conversationid']]['unreadcount']);
        $this->assertSame(1, $byid[$second['conversationid']]['unreadcount']);
        $this->assertSame(1, issue_conversation::get_unread_count('student', $this->course->id)['count']);

        // A user cannot manufacture a recipient receipt on their own sent message.
        $secondmessages = issue_conversation::get_messages($second['conversationid']);
        issue_conversation::mark_viewed($second['conversationid'], [$second['messageid']]);
        $messages = issue_conversation::get_messages($second['conversationid']);
        $this->assertNotEmpty($secondmessages['messages'][1]['deliveredat']);
        $this->assertSame(0, $messages['messages'][0]['viewedat']);
        $this->assertSame('delivered', $messages['messages'][0]['receipt']);
        $this->assertSame('sent', $secondreply['message']['receipt']);
    }

    public function test_retries_are_idempotent(): void {
        $this->setUser($this->student);
        $first = issue_conversation::create_conversation(
            $this->course->id,
            'Duplicate prevention',
            'technical_problem',
            'The page did not respond when I clicked submit.',
            'conversation_retry_01'
        );
        $retry = issue_conversation::create_conversation(
            $this->course->id,
            'Duplicate prevention',
            'technical_problem',
            'The page did not respond when I clicked submit.',
            'conversation_retry_01'
        );
        $this->assertTrue($retry['duplicate']);
        $this->assertSame($first['conversationid'], $retry['conversationid']);

        $sent = issue_conversation::send_message($first['conversationid'], 'Retry this follow-up.', 'message_retry_0001');
        $resent = issue_conversation::send_message($first['conversationid'], 'Retry this follow-up.', 'message_retry_0001');
        $this->assertTrue($resent['duplicate']);
        $this->assertSame($sent['message']['id'], $resent['message']['id']);
        $this->assertSame(2, $this->count_messages($first['conversationid']));
    }

    public function test_cross_student_and_unauthorized_lecturer_access_is_denied(): void {
        $created = $this->create_as_student('Private issue', 'conversation_private1');

        $this->setUser($this->otherstudent);
        try {
            issue_conversation::get_messages($created['conversationid']);
            $this->fail('Another student accessed a private conversation.');
        } catch (\moodle_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        $this->setUser($this->unauthorizedlecturer);
        try {
            issue_conversation::get_messages($created['conversationid']);
            $this->fail('An unauthorized lecturer accessed another course conversation.');
        } catch (\moodle_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $inbox = issue_conversation::list_conversations('lecturer', 0);
        $this->assertSame(0, $inbox['total']);
    }

    public function test_notifications_are_routed_once_per_new_message(): void {
        $sink = $this->redirectMessages();
        $created = $this->create_as_student('Notification routing', 'conversation_notify1');
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame((int)$this->lecturer->id, (int)$messages[0]->useridto);
        $this->assertStringNotContainsString('A detailed private course issue description.', $messages[0]->fullmessage);

        issue_conversation::create_conversation(
            $this->course->id,
            'Notification routing',
            'other',
            'A detailed private course issue description.',
            'conversation_notify1'
        );
        $this->assertCount(1, $sink->get_messages());
        $sink->close();

        $this->setUser($this->lecturer);
        $replysink = $this->redirectMessages();
        issue_conversation::send_message($created['conversationid'], 'A private lecturer response.', 'notify_reply_0001');
        $replies = $replysink->get_messages();
        $this->assertCount(1, $replies);
        $this->assertSame((int)$this->student->id, (int)$replies[0]->useridto);
        $this->assertStringNotContainsString('A private lecturer response.', $replies[0]->fullmessage);
        $replysink->close();
    }

    public function test_receipt_state_meanings(): void {
        $message = (object)['deliveredat' => 0, 'viewedat' => 0];
        $this->assertSame('sent', issue_manager::receipt($message));
        $message->deliveredat = time();
        $this->assertSame('delivered', issue_manager::receipt($message));
        $message->viewedat = time();
        $this->assertSame('viewed', issue_manager::receipt($message));
    }

    private function create_as_student(string $title, string $clientid): array {
        $this->setUser($this->student);
        return issue_conversation::create_conversation(
            $this->course->id,
            $title,
            'other',
            'A detailed private course issue description.',
            $clientid
        );
    }

    private function count_messages(int $conversationid): int {
        global $DB;
        return $DB->count_records('umat_ai_issue_messages', ['conversationid' => $conversationid]);
    }
}
