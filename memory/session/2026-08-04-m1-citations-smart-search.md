# 2026-08-04 - M1 Next-Gen: Source-Cited Q&A + Smart Search (full stack)

## Context
User approved the next-gen plan (`.opencode/plans/2026-08-04-nextgen-features-plan.md`) and asked
to implement M1 (F1 Source-Cited Q&A + F2 Smart Search). This session completed the entire M1
surface: AI service, Moodle backend, student/lecturer/hub frontends, CSS, tests, and rebuilt
production AMD bundles.

## Key Decisions & Patterns
- **Citations are derived from actual retrieval chunks** (`build_citations` in
  `api/v1/query_pipeline.py`), NEVER LLM-invented; dedup by `material_id`, 1-based numbering,
  cap 6 (Q&A) / 12 (search). Location labels from loader markers: `[Page N]`,
  `--- Slide N ---`, `[mm:ss - ...]` timestamps, fallback `Section {n+1}`.
- **Backward compatibility everywhere**: `sources` (filename strings) still flow alongside
  new `citations`; UI renders legacy chips only when citations are absent.
- **SSE contract**: `meta` + `done` events carry `citations`; `chat_stream.php` proxies SSE
  untouched and persists citations to DB on `done`; resuming history renders cards via
  `_umatRenderCitations` (JSON embedded in a `data-cites` zone then rendered post-insert).
- **Citation "Open"** resolves `window._umatMaterialLookup` (populated by `_umatRegisterMaterials`
  from each module's `loadLibrary`), then opens `umatMaterialViewer` with deep-link `location`.
- **Smart Search**: NEW AI route `POST /api/v1/search` (student rate-limited + sensitive-query
  blocked; lecturer bypasses) + Moodle external `local_umat_ai_smart_search` (student:
  `local/umat_ai:chatwithai` + rate limit; lecturer: `local/umat_ai:viewanalytics`, no limit).
- **Lecturer header is rebuilt per course in JS** — the Smart Search button must be re-added in
  `loadLibrary()`'s innerHTML rebuild (PHP markup alone gets wiped). Same for hub/lecturer.
- **Bug found & fixed**: timestamp regex had two capture groups → location showed `01` instead of
  `01:23`. Fixed with a single outer group `\[((?:\d{1,2}:)?\d{1,2}:\d{2})\s*-\s*...\]`.

## Verification Status
- `php -l` clean (8 PHP files); `node --check` clean (4 src + 4 min bundles).
- pytest: **11 new M1 tests pass** (`tests/test_api.py`): search auth/validation/rate-limit/
  lecturer-bypass/sensitive-block, query citations shape, build_citations unit tests.
- **Full suite: 30/30 PASSED** with the real `.env` (verified 2026-08-04, no env overrides needed).
  IMPORTANT LESSON: earlier "password authentication failed" runs were caused by an injected
  dummy `$env:AI_DB_PASSWORD` during the test run OVERRIDING pydantic's .env values — the `.env`
  itself was always correct. The user confirmed the postgres password (`0000` also connects; the
  49-char value in `.env` is the real working one). Never inject `AI_DB_PASSWORD` unless you mean to.
- For local tests that must avoid touching the DB, mock
  `api.v1.query_pipeline.get_student_profile` (route calls it for students; `log_chat` is
  already try/except-wrapped).

## Build/Verification Recipes
- Rebuild bundles: copy `amd/src/X.js` → `amd/build/X.js`; minify with
  `npx -y esbuild src --minify --sourcemap=external`, then regex-prefix
  `define([` → `define("local_umat_ai/X",[` (esbuild 0.28 has no `--format=amd`; named define
  matches the repo convention; sourcemaps regenerate at `build/X.min.js.map`).
- Tests: `cd ai_service && venv\Scripts\python.exe -m pytest tests/ -v`.

## Next Steps (hand-off)
- **Full pytest suite is GREEN (30/30)** with the real `.env` — no remaining code blocker.
- No commit was made this session (user to confirm). Before commit per AGENTS.md:
  `git pull origin main` → test → commit → push.
- M1 scope for M2+: spaced repetition, mastery, oral practice, audio overview (see plan).
