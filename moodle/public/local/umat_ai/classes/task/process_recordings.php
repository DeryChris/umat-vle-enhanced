<?php
namespace local_umat_ai\task;
defined('MOODLE_INTERNAL') || die();

class process_recordings extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('pluginname', 'local_umat_ai') . ': Process Recordings';
    }
    public function execute(): void {
        global $DB;
        $cfg = local_umat_ai_get_service_config();
        $client = new \curl(['ignoresecurity' => true]);
        $client->setHeader(['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['token']]);
        $client->setopt(['CURLOPT_TIMEOUT' => 25]);
        $pending = $DB->get_records_select('umat_ai_sessions',
            "status IN ('pending','processing','queued','downloading','transcribing','processing_ai')",
            [], 'timecreated ASC', '*', 0, 20);
        foreach ($pending as $sess) {
            $raw    = $client->get($cfg['url'] . '/api/v1/recording/status/' . urlencode($sess->sessionid));
            $result = json_decode($raw, true);
            if (empty($result['status'])) { mtrace("  [umat_ai] No status for {$sess->sessionid}"); continue; }
            $aiStatus = $result['status'];
            if ($aiStatus === 'completed') {
                $tjson = (!empty($result['transcript']) && is_array($result['transcript']))
                    ? json_encode($result['transcript']) : null;
                $DB->set_field('umat_ai_sessions', 'status',         'completed', ['id'=>$sess->id]);
                $DB->set_field('umat_ai_sessions', 'timemodified',   time(),      ['id'=>$sess->id]);
                if ($tjson) $DB->set_field('umat_ai_sessions', 'transcript_json', $tjson, ['id'=>$sess->id]);
                $newtypes = [];
                foreach (['summary','notes','quiz'] as $type) {
                    $content = $result['outputs'][$type] ?? null;
                    if (!$content || $DB->record_exists('umat_ai_outputs',
                        ['sessionrecordid'=>$sess->id,'output_type'=>$type])) continue;
                    $DB->insert_record('umat_ai_outputs', (object)[
                        'sessionrecordid'=>$sess->id,'courseid'=>$sess->courseid,
                        'output_type'=>$type,'content'=>$content,'is_approved'=>0,
                        'approved_by'=>null,'timecreated'=>time(),'timepublished'=>null,
                    ]);
                    $newtypes[] = $type;
                }
                if ($newtypes) $this->notify_lecturers($sess->courseid, $newtypes);
                mtrace("  [umat_ai] {$sess->sessionid} completed.");
            } elseif (in_array($aiStatus,['queued','downloading','transcribing','processing_ai'])) {
                $DB->set_field('umat_ai_sessions','status','processing',['id'=>$sess->id]);
                $DB->set_field('umat_ai_sessions','timemodified',time(),['id'=>$sess->id]);
                mtrace("  [umat_ai] {$sess->sessionid} → {$aiStatus}");
            } elseif ($aiStatus === 'failed') {
                $DB->set_field('umat_ai_sessions','status','failed',['id'=>$sess->id]);
                $DB->set_field('umat_ai_sessions','timemodified',time(),['id'=>$sess->id]);
                mtrace("  [umat_ai] {$sess->sessionid} FAILED: ".($result['error']??'unknown'));
            }
        }
    }

    /**
     * Notify everyone who can approve outputs in the course that new
     * AI-generated content is waiting for review.
     *
     * @param int   $courseid
     * @param array $types Output types just created, e.g. ['summary','quiz']
     */
    private function notify_lecturers(int $courseid, array $types): void {
        $course  = get_course($courseid);
        $context = \context_course::instance($courseid);
        $url     = new \moodle_url('/local/umat_ai/approve.php', ['courseid' => $courseid]);

        $a = (object)[
            'types'  => implode(', ', $types),
            'course' => format_string($course->fullname, true, ['context' => $context]),
            'url'    => $url->out(false),
        ];

        $lecturers = get_users_by_capability($context, 'local/umat_ai:approveoutput', 'u.id');
        foreach ($lecturers as $lecturer) {
            $message = new \core\message\message();
            $message->component         = 'local_umat_ai';
            $message->name              = 'pendingapproval';
            $message->userfrom          = \core_user::get_noreply_user();
            $message->userto            = $lecturer->id;
            $message->subject           = get_string('pendingapproval_subject', 'local_umat_ai', $a->course);
            $message->fullmessage       = get_string('pendingapproval_body', 'local_umat_ai', $a);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml   = '';
            $message->smallmessage      = get_string('pendingapproval_short', 'local_umat_ai', $a);
            $message->notification      = 1;
            $message->contexturl        = $url->out(false);
            $message->contexturlname    = get_string('analytics_dashboard_title', 'local_umat_ai');
            $message->courseid          = $courseid;
            message_send($message);
        }
        mtrace("  [umat_ai] Notified " . count($lecturers) . " lecturer(s) of pending approvals in course {$courseid}.");
    }
}
