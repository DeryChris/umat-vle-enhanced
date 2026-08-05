# 2026-08-05 — Smart Search redesign: modal → inline dropdown

## Context
Two Smart Search bugs in the UMaT VLE overlay UI: (1) the search modal rendered
behind the full overlay, (2) searches returned nothing. User asked to drop the
modal entirely and integrate Smart Search into the existing library/drawer bars.

## Root causes
1. **Modal behind overlay**: modal appended to `document.body` with
   `.umat-cs-overlay` (z-index 50) while `.umat-ov` sits at z-index 99998.
2. **"Nothing happens"**: `local_umat_ai_smart_search` was declared in
   `db/services.php` but had **0 rows** in `mdl_external_functions` — the DB was
   never upgraded (no `admin/cli/` on the deploy).

## What changed
### DB fix
- Bootstrapped CLI script (`C:\Users\amkch\AppData\Local\Temp\opencode\umat_register_services.php`)
  calling `external_update_descriptions('local_umat_ai')` + `purge_all_caches()`.
- `local_umat_ai_smart_search` now registered (id=857); service count 75 → 82
  functions on "UMaT AI Service" (service id 2). Verified via psql join.

### Frontend (inline dropdown, inside overlay DOM)
- **umatshared.js**: new shared `_umatSmartSearch(input, opts)` helper —
  debounced (350ms) AJAX to `local_umat_ai_smart_search`, renders an inline
  results dropdown anchored below the input (never on `document.body`, so no
  z-index war). Supports `getCourseId`, `onOpen` (default `_umatOpenCitation`),
  `onPick` (drawers), `panelMode: 'attached'` for drawers. Guard
  `input._umatSsCtrl` prevents double-wiring (boot script + AMD module both run).
  Export added.
- **umatshared.js `_umatInitAttachDrawer`**: drawer search inputs now get smart
  suggestions; picking a result checks the matching material (`.umat-drawer-item[data-id=…]`).
- **umat_student.js**: removed entire `_ss*` modal block + `ws-smart-search-btn`
  wiring; wired `ws-lib-search` (local filter kept).
- **umat_lecturer.js**: removed `_ssEnsureModalLec/_ssOpenLec/_ssRunSearchLec` +
  button delegation; wired `lec-lib-search` at end of IIFE (AMD module is the
  reliable path — `_lecBoot` runs BEFORE module init, so the PHP-inline wiring
  alone would be skipped).
- **umat_hub.js + hub PHP inline**: `hub-lib-search` (rebuilt per course) wired
  in both the AMD `loadLibrary` and the PHP inline `loadLibrary`.
- **overlay_helper.php**: removed `ws-smart-search-btn` and `lec-smart-search-btn`
  markup; lecturer inline search handler now also attaches `_umatSmartSearch`
  (harmless fallback, guarded).
- **CSS**: `umat-overlay.css` — replaced old modal styles with `.umat-ss-wrap`,
  `.umat-ss-panel`, `.umat-ss-item`, etc. `umat-responsive.css` — wrap behaves
  like `.umat-lib-search` on mobile.

### Build & cache
- Rebuilt `amd/build/{umatshared,umat_student,umat_lecturer,umat_hub}.js` +
  `.min.js` + `.map` via `node node_modules/terser/bin/terser --ecma 5 -c -m`.
- `node --check` all 4; stale `_ss*`/button strings gone from build.
- Purged Moodle caches (bootstrapped `umat_purge_caches.php`, CLI_SCRIPT defined).

## Verification
- AI service healthy: `GET http://localhost:8000/api/v1/health` → healthy;
  `/api/v1/search` present in OpenAPI.
- PHP lint clean for `overlay_helper.php`, `db/services.php`.
- DB: smart_search registered + assigned to UMaT AI Service.

## Notes for next session
- Manual browser test still recommended: type 2+ chars in student/lecturer/hub
  library search and drawer search; expect AI dropdown below input; click opens
  the material viewer via `_umatOpenCitation` (needs materials registered via
  `_umatRegisterMaterials` — hub calls it in `loadLibrary`; student registers
  in `loadLibrary`).
- Temp helper scripts live in `C:\Users\amkch\AppData\Local\Temp\opencode\`
  (umat_register_services.php, umat_purge_caches.php) — reusable if DB rows or
  caches regress.
