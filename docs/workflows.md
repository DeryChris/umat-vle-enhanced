# UMaT VLE Enhanced — Development Workflow

**Last Updated**: 2026-06-11  
**Audience**: All team members (Seidu, Ackon, Chrispen, Agartha, Johnson)

---

## Table of Contents

1. [Daily Development Cycle](#1-daily-development-cycle)
2. [Git Workflow](#2-git-workflow)
3. [Team Coordination](#3-team-coordination)
4. [Testing Process](#4-testing-process)
5. [Deployment Pipeline](#5-deployment-pipeline)
6. [Secrets Management](#6-secrets-management)
7. [Communication Protocols](#7-communication-protocols)
8. [Release Process](#8-release-process)

---

## 1. Daily Development Cycle

### 1.1 Morning Startup (~2 min)

Required every day when starting work. All 4 services must run:

**Terminal 1** — Apache (XAMPP)
```powershell
# XAMPP Control Panel → Start Apache
# OR via CLI:
& "C:\xampp\apache\bin\httpd.exe"
```

**Terminal 2** — PostgreSQL
```powershell
# Auto-starts as Windows service. Verify:
psql -U postgres -d moodle -c "SELECT 1;"
# → Returns: ?column? ────────── 1
```

**Terminal 3** — AI Service
```powershell
cd C:\Projects\umat-vle-enhanced\ai_service
venv\Scripts\activate
python main.py
# Verify: http://localhost:8000/api/v1/health
# → {"status": "healthy", "version": "1.0.0", ...}
```

**Terminal 4** — Moodle Cron
```powershell
cd C:\Projects\umat-vle-enhanced
.\cron.bat
# OR:
php moodle\admin\cli\cron.php
```

### 1.2 Pre-Work Checklist

- [ ] Pull latest code: `git pull origin main`
- [ ] All 4 services running (Apache, PostgreSQL, AI Service, Cron)
- [ ] Health endpoint responds: `GET http://localhost:8000/api/v1/health`
- [ ] Moodle loads: `http://localhost`
- [ ] Swagger UI loads: `http://localhost:8000/docs`
- [ ] No uncommitted changes from yesterday: `git status`

### 1.3 End-of-Day Checklist

- [ ] All changes committed with clear messages
- [ ] Tests passing: `pytest ai_service/tests/ -v`
- [ ] `get_errors` shows no lint/syntax issues
- [ ] No secrets exposed in code (`git diff` check)
- [ ] Session memory saved to `memories/session/` if significant work done
- [ ] AI Service stopped (Ctrl+C)
- [ ] Cron stopped (Ctrl+C)

---

## 2. Git Workflow

### 2.1 Branch Strategy

**Single `main` branch. No feature branches. No PRs.**

```
main ──●─────●──────●──────●─────●──►
        \    / \    / \    / \    /
         C1     C2     C3     C4
```

### 2.2 Commit Cycle

```bash
# 1. Always pull first
git pull origin main

# 2. Make changes (edit files)

# 3. Check what changed
git status
git diff

# 4. Stage specific files (NEVER stage .env, config.php, or secrets)
git add path/to/file1.php path/to/file2.js

# 5. Commit with descriptive message
git commit -m "Clear description of what changed and why"

# 6. Push
git push origin main
```

### 2.3 Commit Message Convention

```
<component>: <brief description of change>

Good examples:
  AI Service: Add rate limiting to Q&A endpoint
  Moodle: Fix ChromaDB collection cleanup on course delete
  Frontend: Add analysis status indicator to material tiles
  Theme: Fix login page layout on mobile
  Docs: Update API reference with new analysis endpoints

Bad examples:
  Update
  Fix bug
  Changes
  WIP
```

### 2.4 Conflict Resolution

1. Check team ownership in AGENTS.md — who owns the conflicting file?
2. Coordinate with that team member via WhatsApp/comment
3. Resolve conflicts manually:
   ```bash
   git pull origin main
   # Resolve conflicts in editor
   git add resolved-file.php
   git commit -m "Resolve merge conflict in resolved-file.php"
   git push origin main
   ```
4. Test after resolving

### 2.5 What NOT to Commit

| File | Reason | If Committed |
|------|--------|-------------|
| `ai_service/.env` | API keys, tokens | `git filter-branch` to remove |
| `moodle/config.php` | DB password | Reset all passwords immediately |
| `moodle/public/local/umat_ai/.env` | If exists | Remove and add to `.gitignore` |
| `*.log` | Debug output | `git rm --cached` |
| `__pycache__/` | Cached bytecode | Already in `.gitignore` |
| `node_modules/` | Dependencies | Already in `.gitignore` |
| `chroma_db/` | Generated data | Already in `.gitignore` |
| Large binaries (>50MB) | Repository bloat | Use Git LFS or external storage |

---

## 3. Team Coordination

### 3.1 Ownership Map

| Owner | Components | File Patterns | Coordination |
|-------|-----------|---------------|--------------|
| **Seidu** | AI Service architecture, transcription, RAG, LLM | `ai_service/**` | Independent |
| **Ackon** | PHP backend, events, tasks, external services | `moodle/.../classes/**` | Check with Johnson on WS contracts |
| **Chrispen** | Settings, theme, secrets, configuration | `settings.php`, `version.php`, `theme/umat/**`, `.env` | Holds all secrets |
| **Agartha** | Mustache templates, theme layouts | `moodle/.../templates/**` | Coordinate with Johnson on JS integration |
| **Johnson** | AMD JavaScript modules, language strings | `amd/src/**`, `lang/**`, `lib.php` | Coordinate with Agartha on template data |

### 3.2 Handoff Protocol

When your work depends on another team member's component:

1. **Document the contract**: Define the interface (function signature, endpoint, template variable)
2. **Create a stub**: Implement a minimal working version on your side
3. **Notify**: Send WhatsApp message with the contract + expected timeline
4. **Follow up**: If not completed within 2 days, escalate in group chat

### 3.3 Code Review (Informal)

Since there are no PRs, use these lightweight review practices:

- **Before committing**: Run `git diff` and review your own changes
- **For cross-component changes**: Message the component owner for a quick review
- **For AI Service changes**: Run all tests: `pytest ai_service/tests/ -v`
- **For Moodle changes**: Test via browser and check Moodle admin notifications
- **For sensitive changes**: Ask Chrispen to review (security, settings, secrets)

---

## 4. Testing Process

### 4.1 AI Service Tests

```powershell
cd C:\Projects\umat-vle-enhanced\ai_service
venv\Scripts\activate
pytest tests/ -v
```

**Test files**:
| File | Tests | Type |
|------|-------|------|
| `tests/test_api.py` | 11 tests: health, query, auth, rate limiting, error handling | Unit (mocked) |
| `tests/test_recording_pipeline.py` | 8-step end-to-end recording pipeline | Integration |

**What to test before committing AI Service changes**:
- [ ] `pytest tests/ -v` — all tests pass
- [ ] New endpoints respond correctly (test via Swagger UI)
- [ ] Error cases handled (missing auth, invalid input, service failures)
- [ ] Rate limiting works (10 requests/min per user)
- [ ] Graceful degradation on ChromaDB/LLM failure

### 4.2 Moodle Testing

**Manual browser tests**:
- [ ] Plugin settings page loads: Site Admin → Plugins → Local → UMaT AI
- [ ] AI FAB appears on course pages with correct permissions
- [ ] Chat panel opens and sends messages
- [ ] Approval page loads and functions
- [ ] Hub page displays sessions and AI content
- [ ] Material viewer loads files correctly

**PHP checks**:
- [ ] No PHP errors in Moodle admin notifications
- [ ] Check `error.log` in XAMPP for any FATAL errors
- [ ] Web service calls return expected responses

### 4.3 Cross-Component Tests

- [ ] Moodle → AI Service: Web service calls reach AI service and return data
- [ ] AI Service → Moodle: Callback endpoints work (e.g., `analysis_sync.php`)
- [ ] End-to-end: Upload material → auto-index → query AI → get answer

---

## 5. Deployment Pipeline

### 5.1 Local Development Setup

```
┌─────────────────────────────┐
│  Windows Machine            │
│                             │
│  XAMPP (Apache + PHP 8.2)   │
│  ├─ Moodle 5.1.3x           │
│  │  ├─ local/umat_ai/       │
│  │  ├─ theme/umat/          │
│  │  └─ mod/bigbluebuttonbn/  │
│  │                          │
│  PostgreSQL 15/16            │
│  ├─ moodle database          │
│  └─ umat_ai_db database      │
│                              │
│  AI Service (Python 3.11)    │
│  └─ FastAPI on :8000         │
│     ├─ ChromaDB (vector)     │
│     └─ Whisper (local ASR)   │
│                              │
│  ffmpeg (audio extraction)   │
└─────────────────────────────┘
```

### 5.2 Production Deployment (Future)

Target architecture:

```
┌──────────┐      ┌──────────┐       ┌──────────┐
│  Nginx    │────▶│  Moodle  │────▶│ PostgreSQL│
│  (reverse │     │  (PHP)   │      │  (moodle) │
│  proxy)   │     └────┬─────┘      └──────────┘
└──────────┘          │
                      │ HTTP (Bearer)
                      ▼
               ┌──────────┐     ┌──────────┐
               │AI Service│────▶│ PostgreSQL│
               │(FastAPI) │     │(umat_ai_db)│
               └────┬─────┘     └──────────┘
                    │
                    ▼
               ┌──────────┐
               │ ChromaDB │
               │(persisted)│
               └──────────┘
```

**Production considerations**:
- Replace `BackgroundTasks` with Celery + Redis
- Add Redis-backed rate limiting
- Configure HTTPS via Let's Encrypt
- Set up Sentry/error monitoring
- Database backup strategy (pg_dump)
- Environment-specific config via environment variables

### 5.3 Environment Separation

| Environment | Database | AI Service URL | Debug Mode |
|-------------|----------|----------------|------------|
| Development | Local PostgreSQL | `http://localhost:8000` | Developer |
| Staging | Server PostgreSQL | `https://staging-api.example.com` | Normal |
| Production | Server PostgreSQL | `https://api.example.com` | None |

---

## 6. Secrets Management

### 6.1 Secrets Inventory

| Secret | Location | Access | Rotated |
|--------|----------|--------|---------|
| `AI_SERVICE_TOKEN` | `ai_service/.env` + Moodle settings | Chrispen distributes | Monthly |
| `GOOGLE_API_KEY` | `ai_service/.env` | Chrispen distributes | Per project |
| `BBB_SECRET` | Moodle BBB plugin settings | Chrispen distributes | Per semester |
| `DB_PASSWORD` | Moodle `config.php` + `.env` | Chrispen distributes | Per semester |
| Moodle `$CFG->dataroot` | `config.php` | Local only | N/A |

### 6.2 Rules

1. **Never commit** secrets to Git
2. **Never hardcode** secrets in PHP or Python files
3. **Never share** secrets in group chat — only via Chrispen
4. **If exposed**: Contact Chrispen immediately, rotate the secret, scrub Git history
5. **Local `.env`** is for development only; production uses real environment variables

### 6.3 Recovery: Secret Exposed in Git

```bash
# Emergency scrub (run immediately if .env was committed)
git filter-branch --force --index-filter `
    "git rm --cached --ignore-unmatch ai_service/.env" `
    --prune-empty --tag-name-filter cat -- --all

# Force push (coordinate with team first)
git push origin --force --all
```

---

## 7. Communication Protocols

### 7.1 Channels

| Purpose | Channel | Response Time |
|---------|---------|---------------|
| Daily standup | WhatsApp group | < 1 hour |
| Code questions | WhatsApp group | < 2 hours |
| Blockers | WhatsApp @mention | < 30 min |
| Secret distribution | Chrispen only | < 1 day |
| Meeting scheduling | WhatsApp poll | < 1 day |
| Emergency (site down) | WhatsApp call | Immediate |

### 7.2 Scrum Framework

The project follows a **hybrid DSR + Agile Scrum** methodology. The development lifecycle uses Scrum with **2-week sprints**.

**Scrum Artifacts**:
- **Product Backlog**: Master list of all features (tracked in roadmap.md)
- **Sprint Backlog**: Current tasks selected for each 2-week sprint
- **Project Increment**: The actual growing system delivered each sprint

**Sprint Ceremonies**:
- **Sprint Planning** (biweekly): Select backlog items, assign owners, estimate effort
- **Daily Scrum** (daily, async via WhatsApp): What I did, what I'll do, blockers
- **Sprint Review** (biweekly): Demo working features to stakeholders
- **Sprint Retrospective** (biweekly): What went well, what to improve

**Development Lifecycle (6-step)**:
1. Problem Identification & Motivation
2. Definition of Objectives
3. Design & Development (Agile Sprints) — 2-week iterations
4. Demonstration — Real UMaT course environment
5. Evaluation — DSR standards + performance analysis
6. Communication — Architecture and findings documented in thesis report

### 7.3 Meeting Cadence

| Meeting | Frequency | Duration | Attendees | Agenda |
|---------|-----------|----------|-----------|--------|
| Daily standup | Daily (async) | — | All | What I did yesterday, what I'm doing today, blockers |
| Sprint planning | Biweekly | 30 min | All | Select tasks from roadmap, assign owners |
| Sprint review | Biweekly | 30 min | All + stakeholders | Demo working features, collect feedback |
| Code sync | Weekly | 15 min | Ackon + Johnson + Agartha | Cross-component issues, template data contracts |
| Architecture review | Monthly | 30 min | All | Design decisions, tech debt, production planning |

### 7.3 Escalation

```
Level 1: Component owner — try to resolve independently (2 hours)
Level 2: WhatsApp group — ask team for input (same day)
Level 3: Chrispen — escalation for blocked decisions or secrets
Level 4: Supervisor (Dr./) — academic or scope decisions
```

---

## 8. Release Process

### 8.1 Version Numbering

Follows plugin `version.php` format: `YYYYMMDDXX`

```
2026060100  →  Year: 2026, Month: 06, Day: 01, Build: 00
```

Increment `version.php` and `db/upgrade.php` for every schema change.

### 8.2 Release Checklist

- [ ] All Phase milestones completed (check roadmap.md)
- [ ] All tests pass: `pytest ai_service/tests/ -v`
- [ ] AI Service health check passes
- [ ] Moodle admin confirms no plugin errors
- [ ] All web services respond correctly
- [ ] Frontend modules load without browser console errors
- [ ] Theme renders correctly across pages
- [ ] No secrets exposed in code
- [ ] Database schema up to date (`db/install.xml` matches `db/upgrade.php`)
- [ ] `version.php` bumped
- [ ] `docs/` updated with any new endpoints, settings, or architecture changes
- [ ] Session memory saved to `memories/session/`

### 8.3 Hotfix Process

For critical bugs:

```bash
git pull origin main
# Fix the bug
git add fixed-file.php
git commit -m "HOTFIX: Description of critical fix"
git push origin main
```

No formal process — speed is priority. Post in WhatsApp group after pushing.

---

## Appendix: Quick Reference

### Common Commands

```powershell
# Start AI Service
cd ai_service && venv\Scripts\activate && python main.py

# Run tests
cd ai_service && venv\Scripts\activate && pytest tests/ -v

# Purge Moodle cache
php moodle\admin\cli\purge_caches.php

# Run Moodle cron
php moodle\admin\cli\cron.php

# Check Git status
git status

# Check diff before commit
git diff

# Undo last commit (keep changes)
git reset --soft HEAD~1
```

### Key URLs

| URL | Purpose |
|-----|---------|
| `http://localhost` | Moodle |
| `http://localhost:8000/docs` | AI Service Swagger UI |
| `http://localhost:8000/api/v1/health` | Health check |
| `http://localhost/admin` | Moodle admin |
| `http://localhost/local/umat_ai/hub.php` | AI Hub |
| `http://localhost/local/umat_ai/approve.php` | Approval page |

### Useful PostgreSQL Commands

```powershell
# Connect to moodle
psql -U postgres -d moodle

# Check plugin tables
\dt mdl_umat_ai_*

# Check AI service tables (connect to umat_ai_db first)
psql -U postgres -d umat_ai_db
\dt

# Query pending sessions
SELECT * FROM mdl_umat_ai_sessions WHERE status = 'pending';

# Query processing jobs
SELECT * FROM processing_jobs ORDER BY created_at DESC LIMIT 5;
```
