<?php
namespace local_umat_ai\task;
defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

class process_recordings extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('pluginname', 'local_umat_ai') . ': Process Recordings';
    }
    public function execute(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $cfg = local_umat_ai_get_service_config();
        $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($cfg['url'])]);
        $client->setHeader(['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['token'], 'X-Request-Id: ' . local_umat_ai_request_id()]);
        $client->setopt(['CURLOPT_TIMEOUT' => 25]);
        $pending = $DB->get_records_select('umat_ai_sessions',
            "status IN ('pending','processing','queued','downloading','transcribing','processing_ai','uploading')",
            [], 'timecreated ASC', '*', 0, 20);
        foreach ($pending as $sess) {
            $isUpload = ($sess->source_type ?? '') === 'upload';
            $statusUrl = $isUpload
                ? $cfg['url'] . '/api/v1/transcription/' . urlencode($sess->sessionid)
                : $cfg['url'] . '/api/v1/recording/status/' . urlencode($sess->sessionid);
            $raw    = $client->get($statusUrl);
            $result = json_decode($raw, true);
            if (empty($result['status'])) { mtrace("  [umat_ai] No status for {$sess->sessionid}"); continue; }
            $aiStatus = $result['status'];
            if ($aiStatus === 'completed') {
                $tjson = (!empty($result['transcript'])) ? $result['transcript'] : null;
                $DB->set_field('umat_ai_sessions', 'status',         'completed', ['id'=>$sess->id]);
                $DB->set_field('umat_ai_sessions', 'timemodified',   time(),      ['id'=>$sess->id]);

                // Store transcript segments.
                if ($tjson) {
                    $segments = self::parse_transcript_to_segments($tjson);
                    $DB->set_field('umat_ai_sessions', 'transcript_json', json_encode($segments), ['id'=>$sess->id]);
                }

                // Store transcription metadata from AI service.
                $transMeta = $result['transcription'] ?? [];
                if (!empty($transMeta)) {
                    if (isset($transMeta['provider'])) {
                        $DB->set_field('umat_ai_sessions', 'transcription_provider', $transMeta['provider'], ['id'=>$sess->id]);
                    }
                    if (isset($transMeta['model'])) {
                        $DB->set_field('umat_ai_sessions', 'transcription_model', $transMeta['model'], ['id'=>$sess->id]);
                    }
                    if (isset($transMeta['cost'])) {
                        $DB->set_field('umat_ai_sessions', 'transcription_cost', (float)$transMeta['cost'], ['id'=>$sess->id]);
                    }
                    if (isset($transMeta['duration_secs'])) {
                        $DB->set_field('umat_ai_sessions', 'audio_duration_secs', (float)$transMeta['duration_secs'], ['id'=>$sess->id]);
                    }
                    if (isset($transMeta['chunk_count'])) {
                        $DB->set_field('umat_ai_sessions', 'chunk_count', (int)$transMeta['chunk_count'], ['id'=>$sess->id]);
                    }
                }

                // Store segments from AI service directly if available (more precise than parse).
                if (!empty($result['segments'])) {
                    $DB->set_field('umat_ai_sessions', 'transcript_json', json_encode($result['segments']), ['id'=>$sess->id]);
                }

                // Store AI-generated title if provided.
                if (!empty($result['title'])) {
                    $DB->set_field('umat_ai_sessions', 'recording_url', $result['title'], ['id'=>$sess->id]);
                    // Note: title is stored in a separate field in the future; for now we don't
                    // have a dedicated title column, so it's kept in the AI service.
                }
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
            } elseif ($aiStatus === 'uploading') {
                $DB->set_field('umat_ai_sessions','timemodified',time(),['id'=>$sess->id]);
                mtrace("  [umat_ai] {$sess->sessionid} still uploading");
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

    /**
     * Parse formatted transcript text (e.g. "[00:00] Hello world") into
     * structured segments [{start, end, text}] for the transcript viewer.
     *
     * @param string $formatted Transcript text with [MM:SS] timestamps
     * @return array Array of segment objects
     */
    private static function parse_transcript_to_segments(string $formatted): array {
        $segments = [];
        $lines = explode("\n", $formatted);
        $prevStart = 0.0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            if (preg_match('/^\[(\d{1,2}):(\d{2})\]\s*(.*)$/', $line, $m)) {
                $start = (float)(intval($m[1]) * 60 + intval($m[2]));
                $text  = trim($m[3]);
                if (!empty($segments)) {
                    $segments[count($segments) - 1]['end'] = $start;
                }
                $segments[] = ['start' => $start, 'end' => $start + 30.0, 'text' => $text];
                $prevStart = $start;
            } elseif (!empty($segments)) {
                $segments[count($segments) - 1]['text'] .= ' ' . $line;
            }
        }
        return $segments;
    }
}
