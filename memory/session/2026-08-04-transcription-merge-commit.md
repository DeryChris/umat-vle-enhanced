# 2026-08-04 — Pull-insight: nothing new from Seidu; committed transcription feature + dashboard fixes

## Context
User asked to pull/merge Seidu's pushed changes, keeping the quizgen UI (their own changes)
and the transcription/conflicting areas, keeping the best features of both.

## Key Findings
- **Remote was in sync**: `git ls-remote origin` → `main` = `4b9dd683`, which is an ancestor of
  local HEAD (`dfb28ea5`). **There were NO new commits from Seidu to pull/merge.**
  The last real merge (ort strategy) was `bb2c3cd1` on 2026-07-23.
- The user's quizgen UI commit `e4d060cc` ("merge 14 cards into 8, enhance UI/UX") is **already
  on origin/main** — nothing conflicted.
- `quizgen_review.js` working-tree difference vs HEAD is **line-ending noise only**
  (`git diff -w` empty); source content is byte-identical — preserved as-is.

## Committed (5 chunks, pushed to origin/main)
1. `825dc6dd` — AI service transcription pipeline: `core/api_transcription.py` (new) with
   VAD chunking (FFmpeg silencedetect, 2s overlap stitching), 16kHz mono MP3 compression,
   content-hash caching, parallel chunks, cost tracking, local Whisper fallback;
   config: `transcription_provider/api_key/model/max_chunk_secs`, cache toggle.
2. `c6e16745` — Moodle integration: transcription.php returns segments + provider/model/cost/duration;
   process_recordings persists transcription metadata; DB upgrade `2026072300` adds
   `transcription_provider/model/cost`, `audio_duration_secs`, `chunk_count`;
   new web services `local_umat_ai_get_transcription_costs` + `local_umat_ai_reprocess_recording`;
   new cost dashboard page (`cost_dashboard.php` + AMD module + Chart.js) and re-transcribe UI.
3. `fa8fc535` — dashboard fixes: removed nested `define()` UMD wrapper in
   `analytics_dashboard.js` (caused double AMD load/blank dashboard), rebuilt min via terser,
   dropped stale `.map`; overlay_helper simplified; enriched risk narrative in
   `get_struggle_insights`/`course_data`/`analytics.py`; expanded `umat-dashboard.css`;
   removed `struggle_dashboard` module; rebuilt ALL AMD min bundles.
4. `3f0e2cca` — LLM client timeouts: `request_timeout/timeout=30.0`, `max_retries=1`
   (OpenAI/OpenRouter/Gemini) to stop hanging jobs.
5. `20d4d6b7` — removed struggle dashboard styles/template (folded into analytics dashboard).

## Build notes
- `grunt rollup` still broken (`write EPIPE`); minified builds via `npx terser -c -m`
  with `--source-map "root='../src/',includeSources=true"`.
- Built new `cost_dashboard.min.js` + `.map` (10,355 B) — validated with `new Function(s)`.
- All 18 min.js bundles pass `node` syntax check.
- New/modified AMD builds must be re-verified after any src change (line endings on Windows).

## Verification
- `pytest tests/ -q` → **19 passed** (11 API + 8 recording pipeline), 60s, Python 3.11.9.
- Only pre-existing deprecation warnings (Pydantic/SQLAlchemy) remain.

## Final state
- `git status` clean on `main`; pushed to `origin/main`.
- Nudge: confirm with Seidu whether a transcription/cost feature was also pushed from his side
  (repo was fully in sync at time of check).