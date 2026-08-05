# 2026-08-05 — M2 flashcards (F3 spaced repetition / SM-2): shipped + E2E-verified

## Context
Completed milestone M2: lecturer generates AI flashcards grounded in indexed
course materials; students review them through an SM-2 spaced-repetition loop.
Full plan in `memory/session/2026-08-05-m2-flashcards-plan.md`.

## Shipped
- **AI service**: `POST /api/v1/flashcards/generate` (lecturer-only, grounded in
  Chroma chunks, count 1–30, JSON retry). Registered in `main.py`.
- **SM-2 core** (`ai_service/core/spaced_repetition.py` + tests) mirrored by
  Moodle `lib.php` helpers `local_umat_ai_sm2_review/_sm2_button_quality/_sm2_next_due`.
  Buttons: again=1, hard=3, good=4, easy=5. Lapse (q<3) → interval 1, reps 0.
- **Moodle externals** (`classes/external/flashcards.php`, ids 864–868):
  generate / approve / get_flashcards / get_due_flashcards / submit_review.
- **DB**: `mdl_umat_ai_flashcards` + `mdl_umat_ai_flashcard_reviews` (upgrade
  block 2026080500, version.php → 2.3.0).
- **UI**: student Flashcards tab (stats, deck grid, flip-card review with 4
  grading buttons, due/new counts) + lecturer pane (per-material multi-select,
  count, topic, per-card approve/reject + select-all, status filters).
- Bundles rebuilt (student grew 77345 → 80926 bytes); caches purged.

## Bugs caught & fixed during live E2E (webservice REST)
1. **`flashcards.php` never required `lib.php`** → `undefined function
   local_umat_ai_get_service_config()` on generate. Fix: `require_once(__DIR__ .
   '/../../lib.php')` (same pattern as smart_search.php/ai_query.php).
2. **`approve_flashcards` mixed SQL param types**: `get_in_or_equal($ids)`
   returns `?` placeholders + indexed array; merging with named `:courseid`/
   `:status` → `dml_exception mixedtypesqlparam`. Fix: `get_in_or_equal($ids,
   SQL_PARAMS_NAMED)`.
3. **Strict `===` vs Postgres string columns**: `$card->status === 1` is false
   when Postgres returns `"1"` → review state silently never attached. Fix:
   `(int) $card->status === 1`.
4. **Optional nullable structures need `NULL_ALLOWED`**: `review` (get_flashcards)
   and `interval/repetitions/ease` (submit_review failure path) threw
   `invalid_response_exception`. Fix: `new external_single_structure([...],
   'desc', VALUE_OPTIONAL, null, NULL_ALLOWED)`.
5. `role` is NOT a Moodle external param — unknown keys are rejected by
   validate_parameters. Moodle side hardcodes `role=lecturer` in the curl body.

## E2E proof (course 2, tokens via CLI `umat_make_tokens.php`)
- generate (real LLM, gpt-oss-20b:free) with fileids `[5459,5471]` → 5 cards;
  `[5463]` (video) → 3 more. All status 0 (pending).
- approve 7, reject 1 → lecturer status=9 view: 7×status 1, 1×status −1.
- student johnson due queue → only approved; rejected card hidden.
- submit_review verified live SM-2 math: good→ease 2.5/int 1/reps 1;
  again→ease 1.96/int 1/reps 0; easy→ease 2.6/int 1/reps 1 (first review is
  always interval 1, growth starts at 2nd success); hard→ease 2.36.
- bogus/rejected card → clean `success=false` + message.
- AI route: `POST /api/v1/flashcards/generate` 403 without valid Bearer.

## Critical convention discovered (affects ALL material-based features)
**ChromaDB `material_id` = `mdl_files.id` (fileid), NOT `mdl_umat_ai_materials.id`.**
`reindex_material` sends `material_id => fileid`; `course_data` exposes both
`id` (fileid) and `material_id` (materials.id). Frontends (quizgen chat,
flashcards pane) must pass `m.id` (fileid). Passing materials.id → AI 404
"Selected materials have no indexed content". Dev DB: fileid 5459/5463/5471 =
materials.id 14/15/16.

## Notes for next session
- Browser verify on course 2 (`dr.johnson` / `johnson`) — data already present.
- E2E tokens live in `mdl_external_tokens` (e2e-lecturer / e2e-student) —
  remove before any prod-like handover.
- `submit_review` first-review interval is always 1 day (SM-2 I(1)=1) — by design.
- Apache opcache `revalidate_freq=60` — after PHP edits wait ~65 s before
  re-testing webservice calls (CLI is never cached).
- AI service restart command used: kill python, then
  `Start-Process ai_service\venv\Scripts\python.exe -ArgumentList main.py`
  (hidden window). Health: GET http://localhost:8000/api/v1/health.
