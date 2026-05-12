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
    public static function get_recording_url(string $meetingid, int $cmid): ?string {
        $result = recording_proxy::fetch_recording_by_meeting_id([$meetingid]);

        if (empty($result['recordings'])) {
            return null;
        }

        $recording = reset($result['recordings']);

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
    public static function recording_available(string $meetingid): bool {
        $result = recording_proxy::fetch_recording_by_meeting_id([$meetingid]);
        return !empty($result['recordings']);
    }

    /**
     * Extract the best video download URL from a parsed recording array.
     *
     * Priority order:
     *   1. presentation (slides with audio) — best for AI processing
     *   2. video (raw webcam/screen share)
     *   3. any available format with a URL
     *
     * @param array $recording  Parsed recording from recording_proxy
     * @return string|null
     */
    protected static function extract_video_url(array $recording): ?string {
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
}