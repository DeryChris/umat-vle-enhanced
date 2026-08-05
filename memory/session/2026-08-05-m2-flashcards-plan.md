# 2026-08-05 — M2 Preparation: F3 Spaced-Repetition Flashcards (SM-2)

## Outcome
**DONE — shipped and E2E-verified.** See `memory/session/2026-08-05-m2-flashcards-shipped.md`
for the full wrap-up: 4 live bugs caught via webservice E2E, SM-2 numbers verified,
and the critical material_id=fileid convention. All 5 pending decisions were
resolved as recommended: SM-2 in PHP (A), no hub (B), 4-button (C),
per-material multi-select (D), per-card + select-all approve (E).

Prepared M2 (F3 flashcards) per the next-gen plan: full codebase recon done, plan
written to `.opencode/plans/2026-08-05-m2-flashcards-plan.md`, pending user
confirmation on 5 scope decisions. No implementation started yet.

## Verified facts (frozen for M2 work)
- Student tabs are data-driven: `$tabs` array (overlay_helper.php L95-103) + mobile
  `$stuGlassTabs` (L108-116); panes `<div class="umat-tab-pane" data-tab="...">`.
- Lecturer sidebar: `data-lp` items L969-975 → `umat-cp-pane` id `lcp-*` panes via `showLcpPane()`.
- Hub panes `data-hp` L3341-3347.
- Externals: `classes/external/*.php`, registered in `db/services.php` (per-function
  `capabilities`); approval precedent `approve_output.php` (cap `local/umat_ai:approveoutput`,
  soft-reject `is_approved=-1`); generation precedent `quizgen.php` (sync AI call in external).
- Caps exist: `approveoutput`, `viewsummary`, `chatwithai`, `viewanalytics` — NO new caps needed.
- DB: no flashcard tables; upgrade pattern = `xmldb_table` + `table_exists` + `upgrade_plugin_savepoint`.
- AMD bundles rebuilt with terser (`node node_modules/terser/bin/terser --ecma 5 -c -m --source-map ...`),
  src→build copy; shared exports copied to window in each module init.
- Deploy: docroot `moodle/public`, NO `admin/cli/` → DB upgrades via bootstrapped CLI script +
  psql; AI service restart required after external ChromaDB writes (M1 incident lesson).
- version.php currently `2026080400` / `2.2.1` → M2 bump to `2026080500` / `2.3.0`.

## Design (summary — full detail in the plan file)
- Tables: `umat_ai_flashcards` (courseid, materialid, front, back, topic, status 0/1/-1,
  created_by, approved_by, timecreated, timemodified) + `umat_ai_flashcard_reviews`
  (userid+flashcardid unique, quality, ease, interval, repetitions, due_at, timereviewed).
- SM-2 canonical; recommended: PHP helper in lib.php for the review path + mirrored
  `ai_service/core/spaced_repetition.py` + tests (pending decision A).
- AI route `POST /api/v1/flashcards/generate` (lecturer only, strict grounding from indexed chunks).
- 5 Moodle externals: generate (approveoutput), get (viewsummary/viewanalytics), due (chatwithai),
  review (chatwithai), approve (approveoutput).
- Student: new "Flashcards" tab (deck grid + flip cards + SM-2 review session with 4-button grading).
- Lecturer: new `lec-flashcards` sidebar item + `lcp-flashcards` pane (generate + approval queue).
- Hub: skipped in M2 (decision B).

## Decisions pending (asked user)
A. SM-2 in PHP (+mirror Python) vs Python-only.  B. Hub flashcards in M2?  C. 4-button vs 0-5 grading.
D. Per-material multi-select vs whole-course generate.  E. Per-card+select-all vs deck-level approve.

## Next step
**COMPLETE** — committed to `main` on 2026-08-05 (see `2026-08-05-m2-flashcards-shipped.md`).
Remaining: browser verification of the UI (data already seeded in course 2).
