# 2026-08-04 - M1 Browser-Verification Prep (server-side) + ChromaDB incident

## Outcome
M1 (citations + smart search) verified end-to-end server-side. Ready for browser demo on course 2
("Electronic Commerce", id=2) with accounts: **johnson** (student, roleid 5), **dr.johnson** (lecturer,
roleid 3). All AI endpoints return citations with resolvable material IDs.

## Verified
- Apache 200, PostgreSQL 18 running (psql at `C:\Program Files\PostgreSQL\18\bin\psql.exe`,
  PGPASSWORD=0000), AI service healthy on :8000 (model openrouter gpt-oss-20b:free).
- Moodle plugin DB upgraded to `2026080400`: `citations` TEXT column on `mdl_umat_ai_chat_logs` +
  `mdl_umat_ai_lecturer_notes` (applied manually via psql; deployed tree lacks `admin/cli/` so CLI
  upgrade impossible — the served root is `moodle/public`, Moodle core is gitignored).
- `POST /api/v1/search` course 2 → clean results, citations with title/material_id/location/snippet.
- `POST /api/v1/query/stream` course 2 → `meta`+`done` carry citations; tokens stream; `remaining` decrements.
- Served bundles contain the code: `umatshared.min.js` (citation cards/links), `umat_student.min.js`
  + `umat_lecturer.min.js` (smart search), `umat_hub.min.js` (citation-aware resume).
- **ID resolution chain confirmed**: citation `material_id` == `mdl_files.id` (5459 EC 1.pdf, 5463 MP4,
  5471 EC 2.pptx) == `_umatMaterialLookup` key (from `get_course_materials`, which returns `id` =
  file id and `material_id` = `mdl_umat_ai_materials.id`) → viewer deep-link works.

## ⚠️ INCIDENT: wiped the course_2 ChromaDB collection (self-inflicted)
Tried to purge "stale" material_ids, but ChromaDB metadata `material_id` values are **STRINGS**
while my `keep` set was ints → `5459 not in {5459,...}` → deleted EVERYTHING (668 chunks → 0).
**Recovery**: files still in `moodledata/filedir/<h[:2]>/<h[2:4]>/<hash>` (contenthash from
`mdl_files`); re-ingested all 3 via `POST /api/v1/materials/index` multipart (course_id=2,
material_id=<fileid>, filename, file) → 42 + 7 + 33 = 82 chunks restored, identical to before.

## Lesson learned (IMPORTANT)
- ChromaDB metadata values are stored as strings: `where={"material_id": X}` and `$in` filters need
  **string** comparisons. Always cast: `str(material_id)`.
- After writing to ChromaDB from an EXTERNAL process while the AI service is running, the service's
  in-memory chromadb client + `_hybrid_retriever` singleton (`_bm25_cache`) can hold stale state →
  search errors `'NoneType' object has no attribute 'get'` / empty results. **Restart the AI service
  after external ChromaDB changes.** (Direct python repro in a fresh process worked fine; restart
  fixed the running instance.)
- Deployment oddity: Apache docroot is `moodle/public` (has config.php, lib/, version.php, plugin);
  parent `moodle/` also has admin+lib but NO version.php/local. Moodle core is NOT in git (only the
  plugin is tracked). `cron.bat` at repo root is EMPTY (0 bytes). `admin/cli` missing from docroot.
- AI service restart recipe (tool kills pwsh tree but Start-Process child survives):
  `Stop-Process` the PID on :8000, then `Start-Process venv\Scripts\python.exe main.py` in ai_service
  with output redirect to run_stdout/run_stderr.log (both gitignored).
- Re-ingest recipe (PowerShell): `Invoke-RestMethod -Form @{course_id="2"; material_id="5459";
  filename="EC 1.pdf"; file=Get-Item $path}` with Bearer token from `.env` (never print token).

## Next
Browser demo script handed to user: login johnson / dr.johnson → course 2 → Library → Smart Search,
Q&A chat, citation cards, Open → viewer deep-link, resume session shows cards.
