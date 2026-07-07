<?php
// ============================================================
// Helper class: fetches BigBlueButton recording URLs by meeting ID.
// Used by the scheduled task before sending recordings to the AI service.
// ============================================================

namespace local_umat_ai\local;

use mod_bigbluebuttonbn\local\proxy\recording_proxy;

defined('MOODLE_INTERNAL') || die();

class bbb_recording_helper {

    /**
     * Fetch the best available recording URL for a BBB meeting.
     *
     * BBB recordings are processed asynchronously — the recording may not
     * be available immediately after the meeting ends. This function polls
     * the BBB API and returns the first available video download URL.
     *
     * @param string $meetingid  The BBB meeting ID (same as sessionid in umat_ai_sessions)
     * @param int    $cmid       Course module ID (for logging/debugging)
     * @return string|null       Direct URL to the recording file, or null if not yet available
     */
    public static function get_recording_url(string $recordid, int $cmid): ?string {
        $result = recording_proxy::fetch_recording($recordid);

        if (empty($result)) {
            return null;
        }

        $recording = $result;

        // Prefer presentation/video playback format, fall back to any video format.
        $url = self::extract_video_url($recording);

        return $url;
    }

    /**
     * Check if a recording exists and is ready for processing.
     *
     * @param string $meetingid
     * @return bool
     */
    public static function recording_available(string $recordid): bool {
        $result = recording_proxy::fetch_recording($recordid);
        return !empty($result);
    }

    /**
     * Build a direct URL to the raw WebM video file published by BBB.
     *
     * BBB 2.3+ publishes recordings at:
     *   https://bbb.example.com/presentation/<recordid>/video/webcams.webm
     *
     * @param string $recordid  The full BBB recordID (meetingID-timestamp)
     * @return string|null
     */
    protected static function get_direct_media_url(string $recordid): ?string {
        global $CFG;
        $bbbapiurl = $CFG->bigbluebuttonbn_server_url ?? '';
        if (empty($bbbapiurl)) {
            return null;
        }
        // Strip /bigbluebutton/ suffix to get the base host.
        $bbbhost = preg_replace('#/bigbluebutton/?$#', '', rtrim($bbbapiurl, '/'));
        return $bbbhost . '/presentation/' . $recordid . '/video/webcams.webm';
    }

    /**
     * Extract the best video download URL from a parsed recording array.
     *
     * Priority order:
     *   1. Direct WebM media (slides + webcam) via published file
     *   2. presentation (slides with audio) — best for AI processing
     *   3. video (raw webcam/screen share)
     *   4. any available format with a URL
     *
     * @param array $recording  Parsed recording from recording_proxy
     * @return string|null
     */
    protected static function extract_video_url(array $recording): ?string {
        $recordid = $recording['recordID'] ?? '';
        if ($recordid) {
            $direct = self::get_direct_media_url($recordid);
            if ($direct) {
                return $direct;
            }
        }

        if (empty($recording['playbacks'])) {
            return null;
        }

        // Check presentation format first — it embeds the shared screen/slides.
        foreach ($recording['playbacks'] as $playback) {
            if (($playback['type'] ?? '') === 'presentation' && !empty($playback['url'])) {
                return $playback['url'];
            }
        }

        // Fall back to video format.
        foreach ($recording['playbacks'] as $playback) {
            if (($playback['type'] ?? '') === 'video' && !empty($playback['url'])) {
                return $playback['url'];
            }
        }

        // Last resort: any format that has a URL.
        foreach ($recording['playbacks'] as $playback) {
            if (!empty($playback['url'])) {
                return $playback['url'];
            }
        }

        return null;
    }

    /**
     * Get the best download URL for storing in Moodle.
     * Prefers direct WebM over presentation playback URL.
     *
     * @param array $recording Parsed recording from recording_proxy
     * @return string|null
     */
    protected static function extract_download_url(array $recording): ?string {
        $recordid = $recording['recordID'] ?? '';
        if ($recordid) {
            $direct = self::get_direct_media_url($recordid);
            if ($direct) {
                return $direct;
            }
        }

        // Fall back to video/presentation format from API.
        if (empty($recording['playbacks'])) {
            return null;
        }
        // Prefer video (MP4/WebM) format for download.
        foreach ($recording['playbacks'] as $playback) {
            if (($playback['type'] ?? '') === 'video' && !empty($playback['url'])) {
                return $playback['url'];
            }
        }
        // Fall back to presentation URL.
        foreach ($recording['playbacks'] as $playback) {
            if (!empty($playback['url'])) {
                return $playback['url'];
            }
        }
        return null;
    }

    /**
     * Download a BBB recording MP4 into Moodle's file storage.
     *
     * @param string $meetingid BBB meeting ID
     * @param int    $cmid      Course module ID
     * @param int    $sessionid umat_ai_sessions record ID
     * @return string|null Moodle pluginfile URL, or null on failure
     */
    public static function download_to_moodle(string $recordid, int $cmid, int $sessionid): ?string {
        global $DB;

        $recording = recording_proxy::fetch_recording($recordid);
        if (empty($recording)) {
            return null;
        }
        $downloadurl = self::extract_download_url($recording);
        if (!$downloadurl) {
            return null;
        }

        $session = $DB->get_record('umat_ai_sessions', ['id' => $sessionid]);
        if (!$session) {
            return null;
        }

        $coursecontext = \context_course::instance($session->courseid);
        $tempdir = make_temp_directory('local_umat_ai_recordings');
        $tempfile = $tempdir . '/' . $recordid . '.webm';

        $client = new \curl(['ignoresecurity' => true]);
        $content = $client->get($downloadurl);
        if ($client->get_errno() !== 0 || empty($content)) {
            mtrace("  [umat_ai] Failed to download recording {$recordid}: curl errno " . $client->get_errno());
            return null;
        }
        file_put_contents($tempfile, $content);
        if (!file_exists($tempfile) || filesize($tempfile) === 0) {
            mtrace("  [umat_ai] Downloaded file is empty for {$recordid}");
            return null;
        }

        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $coursecontext->id,
            'component' => 'local_umat_ai',
            'filearea'  => 'recordings',
            'itemid'    => $sessionid,
            'filepath'  => '/',
            'filename'  => $recordid . '.webm',
        ];

        $fs->create_file_from_pathname($filerecord, $tempfile);
        unlink($tempfile);

        $url = \moodle_url::make_pluginfile_url(
            $coursecontext->id,
            'local_umat_ai',
            'recordings',
            $sessionid,
            '/',
            $recordid . '.webm'
        );

        mtrace("  [umat_ai] Recording {$recordid} stored locally: " . $url->out(false));
        return $url->out(false);
    }
}