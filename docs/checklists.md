# UMaT VLE Enhanced — Development Checklists

**Last Updated**: 2026-06-09  
**Purpose**: Quick-reference checklists for common development activities

---

## 1. Daily Startup Checklist

- [ ] **Apache** running (XAMPP Control Panel → Start)
- [ ] **PostgreSQL** running (verify: `psql -U postgres -d moodle -c "SELECT 1;"`)
- [ ] **AI Service** running (`python ai_service/main.py`, verify: `GET :8000/health`)
- [ ] **Moodle cron** running (`.\cron.bat` or `php admin/cli/cron.php`)
- [ ] **Git pull**: `git pull origin main`
- [ ] **Health check**: `http://localhost:8000/api/v1/health` → `{"status":"healthy"}`
- [ ] **Moodle loads**: `http://localhost` renders correctly

---

## 2. Pre-Development Checklist

Before starting ANY new feature or fix:

- [ ] **Read the full request** — understand scope, component, and requirements
- [ ] **Check team ownership** — is this your component? (see AGENTS.md)
- [ ] **Review existing docs** — `docs/roadmap.md`, `docs/implementation-plan.md`, `docs/architecture.md`
- [ ] **Check session memory** — `memories/session/` for related previous work
- [ ] **Find existing patterns** — search codebase for similar implementations
- [ ] **Break into small chunks** — create todo list (3-5 items max per session)
- [ ] **Clarify ambiguity** — if requirements are unclear, ask in WhatsApp group
- [ ] **Save plan** — write plan to `memories/session/{date}-{description}.md`

---

## 3. AI Service — Development Checklist

### New Endpoint Checklist

- [ ] Define Pydantic request/response models in `models/schemas.py`
- [ ] Create route file in `api/v1/routes/` or add to existing route file
- [ ] Register route in `main.py` app.include_router()
- [ ] Add authentication via `Depends(verify_token)` (unless public)
- [ ] Add input validation (length limits, type checks, range checks)
- [ ] Handle error cases: 400 (bad input), 401 (auth), 404 (not found), 500 (internal)
- [ ] Add graceful degradation for external service failures (ChromaDB, Gemini, DB)
- [ ] Add logging for key operations (start, complete, error)
- [ ] Write tests in `tests/test_api.py` (happy path + error cases)
- [ ] Verify via Swagger UI at `http://localhost:8000/docs`

### Core Service Change Checklist

- [ ] Import new dependencies in `requirements.txt` (if any)
- [ ] Check for lazy imports (heavy deps like PyPDF, whisper, etc.)
- [ ] Handle errors gracefully — never let exceptions propagate to the endpoint
- [ ] Add cleanup in `finally` blocks (temp files, connections)
- [ ] Update docstrings for public methods
- [ ] Run all tests: `pytest tests/ -v`

### Database Change Checklist

- [ ] Add new model to `models/database.py`
- [ ] Update `init_db()` in `main.py` if needed (SQLAlchemy creates tables from models)
- [ ] Test table creation on service startup
- [ ] Test CRUD operations

### Test Checklist

- [ ] `pytest tests/ -v` — all tests pass
- [ ] Test health endpoint returns correct response
- [ ] Test auth: missing token → 401, wrong token → 401, valid token → 200
- [ ] Test input validation: missing fields → 422, invalid values → 400/422
- [ ] Test error handling: ChromaDB failure → graceful message, LLM failure → graceful message
- [ ] Test rate limiting: 10 requests allowed, 11th blocked (429)
- [ ] Test 404 for non-existent resources (job_id, material_id)

---

## 4. Moodle Plugin — Development Checklist

### New Web Service Checklist

- [ ] Create class in `classes/external/` extending `\external_api`
- [ ] Define `parameters()` returning `\external_function_parameters`
- [ ] Define `returns()` returning `\external_single_structure` or `\external_multiple_structure`
- [ ] Implement `execute()` with input validation + capability checks
- [ ] Register in `db/services.php` with function name, class, method, capability
- [ ] Test via Moodle web service call or browser
- [ ] Verify capability check works (wrong role → denied)

### New Event Observer Checklist

- [ ] Add observer method to existing handler or create new handler class
- [ ] Register in `db/events.php`: event name, handler class, handler method
- [ ] Test by triggering the event (e.g., end a BBB session, upload a file)
- [ ] Verify observer fires correctly (check DB records created)

### New Scheduled Task Checklist

- [ ] Create class in `classes/task/` extending `\core\task\scheduled_task`
- [ ] Implement `get_name()` returning readable task name
- [ ] Implement `execute()` with the task logic
- [ ] Register in `db/tasks.php`: classname, schedule (minute, hour, etc.)
- [ ] Test by running: `php admin/cli/scheduled_task.php --execute=\\local_umat_ai\\task\\YourTask`

### Database Schema Change Checklist

- [ ] Add table/column to `db/install.xml` (used for fresh installs)
- [ ] Add upgrade step in `db/upgrade.php` (used for existing installs)
- [ ] Bump version in `version.php`
- [ ] Test fresh install from `install.xml`
- [ ] Test upgrade from previous version
- [ ] Visit `http://localhost/admin` → Click "Upgrade Moodle database now"

### Settings Change Checklist

- [ ] Add settings in `settings.php` using admin settings classes
- [ ] Use appropriate setting types: `admin_setting_configtext`, `admin_setting_configpasswordunmask`, etc.
- [ ] For passwords: use `admin_setting_configpasswordunmask` for encrypted storage
- [ ] Verify setting appears in: Site Admin → Plugins → Local → UMaT AI
- [ ] Verify setting persists after save

---

## 5. Frontend (AMD JS) — Development Checklist

### New AMD Module Checklist

- [ ] Create file in `amd/src/` using Moodle AMD pattern:

```javascript
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    'use strict';
    function init(options) {
        // Your code
    }
    return { init: init };
});
```

- [ ] Load module in `lib.php`:
```php
$PAGE->requires->js_amd_inline("
    require(['local_umat_ai/your_module'], function(Module) {
        Module.init({...});
    });
");
```

- [ ] Build/compile (if using non-standard AMD patterns)
- [ ] Verify no browser console errors
- [ ] Test with different user roles (student, lecturer, admin)
- [ ] Test responsive behavior (desktop + tablet)

### New Mustache Template Checklist

- [ ] Create file in `templates/` with `.mustache` extension
- [ ] Use double braces `{{variable}}` for escaped output, `{{{variable}}}` for raw HTML
- [ ] Add CSS classes that match existing design patterns
- [ ] Include relevant language strings using `{{#str}}` syntax
- [ ] Render template in PHP:
```php
$OUTPUT->render_from_template('local_umat_ai/template_name', $data);
```
- [ ] Verify all variables in template are provided in data array
- [ ] Test with both empty data and populated data

### CSS Change Checklist

- [ ] Edit files in `styles/` directory
- [ ] Use CSS custom properties (var(--color-name)) for consistency
- [ ] Follow mobile-first responsive approach
- [ ] Test in at least 2 browsers (Chrome + Firefox)
- [ ] Check for conflicts with Moodle core CSS
- [ ] Verify dark/light theme compatibility if applicable

---

## 6. Pre-Commit Checklist

- [ ] **Git diff review**: `git diff` — verify only intended changes
- [ ] **No secrets**: Check for API keys, tokens, passwords in code
- [ ] **No debug code**: Remove `var_dump()`, `console.log()`, `print()`, `dd()`
- [ ] **No commented-out code**: Remove commented-out blocks (use Git history if needed)
- [ ] **Tests pass**: `pytest ai_service/tests/ -v`
- [ ] **PHP syntax**: `php -l path/to/file.php` (for PHP files)
- [ ] **Browser console**: No JavaScript errors
- [ ] **Moodle notifications**: Check /admin for plugin errors
- [ ] **Clear commit message**: Follow `<component>: <description>` convention
- [ ] **Stage only intended files**: `git add file1.php file2.js` (not `git add .`)
- [ ] **No .env, config.php, or secrets in staged files**

---

## 7. Pre-Deployment Checklist

### Code Quality
- [ ] All tests pass: `pytest ai_service/tests/ -v`
- [ ] No lint/syntax errors
- [ ] No PHP warnings in Moodle admin
- [ ] No JavaScript console errors
- [ ] No secrets exposed in repository

### Core Functionality
- [ ] AI Service health check responds
- [ ] Q&A endpoint returns answers for indexed courses
- [ ] Recording processing pipeline works end-to-end
- [ ] Material indexing works for ALL supported formats (PDF, DOCX, PPTX, XLSX, etc.)
- [ ] Material analysis works (all 5 analysis types)
- [ ] Analytics endpoints return valid data
- [ ] Moodle web services all respond correctly
- [ ] FAB and chat panel load on course pages
- [ ] AI Hub page displays correctly
- [ ] Approval workflow works (approve/reject)

### UI/UX
- [ ] LMS renders with UMaT theme correctly
- [ ] Chat panel opens/closes without layout shift
- [ ] Material viewer opens files correctly
- [ ] Attachment drawer works
- [ ] All Mustache templates render with correct data
- [ ] Mobile responsive (test at 768px and 320px widths)

### Security
- [ ] Bearer token auth works on all AI Service endpoints (except health)
- [ ] Capability checks enforced on all Moodle web services
- [ ] Rate limiting active (10 Q&A per minute per user)
- [ ] Path traversal protection in file serving
- [ ] Input validation on all public-facing endpoints

### Documentation
- [ ] `version.php` bumped if schema changed
- [ ] `docs/api.md` updated with new/changed endpoints
- [ ] `docs/architecture.md` updated if architecture changed
- [ ] `docs/roadmap.md` updated with completion status
- [ ] `AGENTS.md` updated if team areas changed

---

## 8. Production Go-Live Checklist

### Infrastructure
- [ ] PostgreSQL configured for production (connection pooling, max connections)
- [ ] AI Service behind reverse proxy (Nginx/Caddy) with HTTPS
- [ ] Moodle behind HTTPS (Let's Encrypt or Cloudflare)
- [ ] Redis/Celery setup for background job processing
- [ ] Backups configured (database dump + ChromaDB snapshot)
- [ ] Monitoring/alerting configured (Sentry, uptime monitoring)
- [ ] Rate limiting switched to Redis-backed (not in-memory)

### Configuration
- [ ] Moodle debug mode OFF (Site Admin → Development → Debugging → NONE)
- [ ] AI Service env vars set correctly for production
- [ ] `AI_SERVICE_TOKEN` matches between Moodle and AI Service
- [ ] `GOOGLE_API_KEY` valid with sufficient quota
- [ ] BBB server URL and secret configured correctly
- [ ] PostgreSQL passwords strong and rotated

### Performance
- [ ] Load tested with expected concurrent users
- [ ] ChromaDB indexed with course materials
- [ ] Background tasks processed and verified
- [ ] Page load times acceptable (< 3s)

### Final Verification
- [ ] Lecturer can create course → upload materials → index → analyze
- [ ] Lecturer can conduct BBB class → recording processed → AI content generated
- [ ] Lecturer can approve/reject AI content
- [ ] Student can view approved summaries, notes, quizzes
- [ ] Student can ask AI questions and get grounded answers
- [ ] Student questions logged for analytics
- [ ] Lecturer can view analytics

---

## 9. Emergency Rollback Checklist

### If AI Service is broken:

```bash
# 1. Stop the AI Service (Ctrl+C in terminal)
# 2. Identify the breaking commit
git log --oneline -10
# 3. Revert
git revert <bad-commit-hash>
# 4. Restart
cd ai_service
venv\Scripts\activate
python main.py
# 5. Verify
curl http://localhost:8000/api/v1/health
```

### If Moodle is broken:

```bash
# 1. Check error details
# Site Admin → Development → Debugging → set to DEVELOPER
# Check XAMPP error logs: C:\xampp\apache\logs\error.log

# 2. Identify the breaking commit
git log --oneline -10

# 3. Revert
git revert <bad-commit-hash>

# 4. Purge caches
php admin/cli/purge_caches.php

# 5. Test
Visit http://localhost
```

### If database migration fails:

```bash
# 1. Check the error in Moodle admin notifications
# 2. Fix the upgrade.php file
# 3. Visit http://localhost/admin again
# 4. Moodle retries the upgrade automatically
```

---

## 10. GDPR / Data Privacy Checklist

- [ ] Privacy provider implemented in `classes/privacy/provider.php`
- [ ] `export_user_data()` returns all AI-related data for a user
- [ ] `delete_data_for_user()` removes all AI data for a user
- [ ] All personal data in `umat_ai_chat_logs` exportable
- [ ] Content approval status preserved during export
- [ ] Student aware that questions sent to Google Gemini servers (disclosed in UI)
- [ ] Data retention policy documented

---

## 11. Post-Completion Memory Save Template

After completing any significant task, save a session memory file:

**File**: `memories/session/{YYYY-MM-DD}-{brief-description}.md`

```markdown
# Session Memory: {Title}

**Date**: {YYYY-MM-DD}
**Task**: {Brief description}
**Owner**: {Your name}

## What Was Done
- {Change 1}
- {Change 2}
- {Change 3}

## Files Changed
- {file path 1}
- {file path 2}

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| {Choice} | {Reason} |

## Known Issues / Follow-ups
- {Issue 1}
- {Issue 2}

## Verification
- [ ] Tests pass
- [ ] Verified via Swagger/browser
- [ ] No regressions detected
```

---

## 12. Quick Reference: Common Fixes

### "ConnectionRefused :8000"
```
→ AI Service not running
→ Fix: cd ai_service && venv\Scripts\activate && python main.py
```

### "ConnectionRefused :5432"
```
→ PostgreSQL not running
→ Fix: Start PostgreSQL Windows service
```

### "401 Unauthorized"
```
→ Bearer token mismatch
→ Fix: Check AI_SERVICE_TOKEN in ai_service/.env matches Moodle plugin settings
```

### "Module not found" (Python)
```
→ Venv not activated or missing dependency
→ Fix: venv\Scripts\activate && pip install -r requirements.txt
```

### Moodle blank page
```
→ Apache not running or PHP error
→ Fix: Start Apache in XAMPP; check error log at C:\xampp\apache\logs\error.log
```

### Cache permission error (Windows)
```
→ PHP cannot delete mustache cache
→ Fix: .\scripts\purge-cache.ps1
```

### Tests fail after changes
```
→ Read the test file to understand assertions
→ Fix incrementally, one failing test at a time
→ Roll back if stuck: git checkout -- <file>
```
