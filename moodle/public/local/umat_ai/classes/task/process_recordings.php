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
                foreach (['summary','notes','quiz'] as $type) {
                    $content = $result['outputs'][$type] ?? null;
                    if (!$content || $DB->record_exists('umat_ai_outputs',
                        ['sessionrecordid'=>$sess->id,'output_type'=>$type])) continue;
                    $DB->insert_record('umat_ai_outputs', (object)[
                        'sessionrecordid'=>$sess->id,'courseid'=>$sess->courseid,
                        'output_type'=>$type,'content'=>$content,'is_approved'=>0,
                        'approved_by'=>null,'timecreated'=>time(),'timepublished'=>null,
                    ]);
                }
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
}
