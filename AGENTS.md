# AGENTS.md — Operational Checklist & Team Coordination

**For**: AI agents, OpenCode, Copilot, human developers  
**Purpose**: Quick reference for startup, validation, and team coordination  
**Full guidance**: See [CLAUDE.md](CLAUDE.md) for comprehensive AI agent workflow

---

## ✅ Pre-Work Checklist

Before you start ANY task:

- [ ] **Read the request completely** — understand scope and requirements
   - [ ] **Load Agent Skills** - Analyse this dir "C:\Users\amkch\ruflo-main\.agents\skills" and pick the best agent skill or skills for the task and load and use it in the project. 
- [ ] **Check team ownership** (see "Team Work Areas" below) — coordinate with owner if needed
- [ ] **Review docs/** folder — existing patterns, architecture, setup
- [ ] **Ask clarifying questions** if task is ambiguous (use `vscode_askQuestions`)
- [ ] **Plan with `manage_todo_list`** — break into 3-5 small chunks
- [ ] **Save plan to `/memories/session/`** — make progress visible

---

## 🚀 Startup Commands (Daily)

All 4 services required; ~2 min total:

```powershell
# Terminal 1: Apache
# XAMPP Control Panel → Start Apache (or already running)

# Terminal 2: PostgreSQL
# Auto-starts as Windows service on port 5432
# Verify: psql -U postgres -d moodle -c "SELECT 1;"

# Terminal 3: AI Service
cd C:\Projects\umat-vle-enhanced\ai_service
venv\Scripts\activate
python main.py
# Verify: GET http://localhost:8000/api/v1/health → {"status": "healthy"}

# Terminal 4: Moodle cron (keep running in background)
cd C:\Projects\umat-vle-enhanced
.\cron.bat
```

**Key endpoints** (test these):
- Moodle: `http://localhost`
- AI Swagger: `http://localhost:8000/docs`
- Health (no auth): `GET http://localhost:8000/api/v1/health`

---

## 🧪 Testing & Validation

### AI Service (Python)
```powershell
cd ai_service
venv\Scripts\activate
pytest tests/ -v
```

### Moodle (PHP)
- Test via browser: `http://localhost`
- Check Moodle admin panel for PHP errors
- Test web service calls via Swagger or postman

### Before Committing
- [ ] `get_errors` shows no lint/syntax issues
- [ ] No secrets in code (check for `.env`, API keys, tokens)
- [ ] Tests pass
- [ ] All changes follow team conventions

---

## 👥 Team Work Areas (Avoid Conflicts)

| Owner | Components | Key Files |
|-------|-----------|-----------|
| **Seidu** | AI Service architecture, transcription, RAG, LLM | `ai_service/` |
| **Ackon** | PHP backend, events, tasks, external services | `moodle/public/local/umat_ai/classes/` |
| **Chrispen** | Settings, theme, secrets, configuration | Theme SCSS, `settings.php`, `version.php`, `ai_service/.env` |
| **Agartha** | Mustache templates, theme layouts | `moodle/public/local/umat_ai/templates/` |
| **Johnson** | AMD JavaScript modules, language strings | `moodle/public/local/umat_ai/amd/src/`, `lang/` |

**Coordination**: If your task touches another team member's area, coordinate first (chat or comment in code).

---

## 🔐 Secrets Management

**Golden rule**: Never commit `.env`, `config.php`, or API keys.

- **Holder**: Chrispen holds all secrets
- **Location**: `ai_service/.env` (Python only)
- **Moodle integration**: Secrets stored encrypted in `mdl_umat_ai_*` DB tables
- **If exposed**: Contact Chrispen immediately
- **Using secrets**: Always read from environment; use `.env` locally, CI/CD variables in production

**Secrets to guard**:
- `AI_SERVICE_TOKEN` — Bearer token for AI service auth
- `GEMINI_API_KEY` — LLM API key
- `BBB_SECRET` — BigBlueButton shared secret
- `DB_PASSWORD` — PostgreSQL credentials

---

## 🐛 Quick Troubleshooting

| Issue | Quick Fix |
|-------|-----------|
| `ConnectionRefused :8000` | Is AI Service running? `cd ai_service && python main.py` |
| `ConnectionRefused :5432` | Is PostgreSQL running? Check Services or restart |
| Health check fails | Check `ai_service/.env` exists + Bearer token is set |
| Moodle blank page | Is Apache running? XAMPP Control Panel → Start Apache |
| Tests fail after my changes | Roll back last change, run tests again; read test file |
| Git conflict | Check team areas (above); coordinate with owner; resolve manually |
| PHP syntax error | Check Moodle admin panel (Site Admin → Notifications) |
| Secrets exposed in Git | Run: `git filter-branch --force --index-filter 'git rm --cached --ignore-unmatch .env'` |

**For detailed troubleshooting**: See [docs/troubleshooting.md](docs/troubleshooting.md)

---

## 🔄 Git Workflow (Main Branch Only)

**No PRs. Single `main` branch.**

```bash
git pull origin main    # Always pull first
# Make your changes
pytest tests/ -v        # Test before committing
git add .
git commit -m "Clear description of what changed"
git push origin main
```

**Good commit messages**:
- ✅ "Add rate limiting validation to Q&A endpoint"
- ✅ "Fix ChromaDB collection cleanup on course delete"
- ❌ "Update" or "Fix bug"

---

## 📊 Key Endpoints & Services

### AI Service
| Endpoint | Purpose | Auth |
|----------|---------|------|
| `GET /api/v1/health` | Service health check | None |
| `POST /api/v1/recording/process` | Start recording processing job | Bearer |
| `GET /api/v1/recording/status/{job_id}` | Poll job progress | Bearer |
| `POST /api/v1/query` | Student Q&A query | Bearer |
| `POST /api/v1/materials/ingest` | Embed course materials | Bearer |

**Swagger UI**: `http://localhost:8000/docs` (interactive testing)

### Databases
- **`moodle`** (PostgreSQL): Moodle core + `mdl_umat_ai_*` tables
- **`umat_ai_db`** (PostgreSQL): AI service job tracking
- **Port**: 5432
- **Instance**: Same PostgreSQL server

### Moodle Web Services
- Plugin settings: `Site Admin → Plugins → Local Plugins → UMaT AI`
- Rate limiting: 10 Q&A per minute per student (enforced in Moodle)
- Approval workflow: Lecturer must approve AI content before students see it

---

## 📚 Resources

| Resource | Purpose |
|----------|---------|
| [CLAUDE.md](CLAUDE.md) | **Full AI agent guidance** — comprehensive workflow, memory strategy, testing |
| [docs/architecture.md](docs/architecture.md) | System design, data flows, component interactions |
| [docs/api.md](docs/api.md) | API reference, request/response examples |
| [docs/setup.md](docs/setup.md) | Detailed installation & environment setup |
| [docs/troubleshooting.md](docs/troubleshooting.md) | Common issues and solutions |
| `http://localhost:8000/docs` | Live Swagger UI for API testing |

---

## 💡 Quick Reference

**When blocked**:
1. Check `/memories/session/` — current task plan
2. Check `/memories/` — lessons from previous tasks
3. Search `docs/` — might have answer
4. Run tests — catch regressions early
5. Ask questions — unclear requirements? Use `vscode_askQuestions`

**When merging changes**:
1. Test thoroughly before commit
2. Write clear commit message
3. Push to `main` (no separate PR process)
4. Verify health endpoint: `GET http://localhost:8000/api/v1/health`

**When adding features**:
1. Check team areas — avoid conflicts
2. Read existing tests — understand patterns
3. Break work into small chunks (3-5 todos)
4. Test each chunk independently
5. Document in code and memory