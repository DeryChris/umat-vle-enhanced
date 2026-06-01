# CLAUDE.md — Fullstack AI Agent Guidance

**AI Agent Instructions** | For: Claude Code, Copilot, OpenCode  
**When to use this**: Working on features, fixes, or improvements in the codebase  
**Key principle**: Think deeply, ask questions, break work into chunks, validate thoroughly

---

## 🎯 Project Overview

**UMaT Generative AI-Enhanced VLE** — Final year project enhancing Moodle with:
- Native BigBlueButton live class integration + automatic attendance
- RAG-based AI assistant: Q&A, lecture summarization, note generation, quiz creation

**Tech Stack**: Moodle (PHP) · FastAPI (Python) · ChromaDB · Gemini LLM · PostgreSQL · BigBlueButton

**Team**: Seidu (AI Service), Ackon (PHP/Events), Chrispen (Settings/Theme), Agartha (Templates), Johnson (AMD JS)

---

## 📐 System Architecture

```
┌─────────────────────────────────────────────────────┐
│  Moodle (PHP)                                       │
│  - Auth, course mgmt, UI, web services              │
│  - Plugin: moodle/public/local/umat_ai/             │
│  - Theme: moodle/public/theme/umat/                 │
├─────────────────────────────────────────────────────┤
│  AI Service (FastAPI, port 8000)                    │
│  - Transcription, RAG, summarization, quiz gen      │
│  - Code: ai_service/core/                           │
├─────────────────────────────────────────────────────┤
│  Vector Store (ChromaDB)                            │
│  - 1 collection per course                          │
├─────────────────────────────────────────────────────┤
│  LLM (Gemini API)                                   │
├─────────────────────────────────────────────────────┤
│  Live Video (BigBlueButton)                         │
├─────────────────────────────────────────────────────┤
│  Databases (PostgreSQL, port 5432)                  │
│  - moodle: Moodle core + mdl_umat_ai_* tables       │
│  - umat_ai_db: AI service job tracking              │
└─────────────────────────────────────────────────────┘
```

**Design principle**: All custom code lives in Moodle plugins. Core Moodle files never modified—plugins survive updates.

---

## 🤖 Before You Start: Agent Decision Tree

**Use this checklist BEFORE making changes:**

1. **Understand the task**
   - [ ] Read the request completely
   - [ ] Identify the component (Moodle UI / PHP backend / AI Service / Vector store / Theme)
   - [ ] Check AGENTS.md for team ownership (avoid conflicts)
   - [ ] Analyse this dir "C:\Users\amkch\ruflo-main\.agents\skills" and pick the best agent skill or skills for the task and load and use it in the project. 
   - [ ] Review existing docs/ folder for context

2. **Plan the work**
   - [ ] Break into 3-5 small, testable chunks
   - [ ] Create a todo list with `TaskCreate` / `TaskUpdate`
   - [ ] Save plan to `memory/` for reference
   - [ ] Ask clarifying questions if intent is unclear

3. **Explore before coding**
   - [ ] Read relevant source files (don't modify yet)
   - [ ] Check for existing similar patterns (DRY principle)
   - [ ] Search for side effects (tests, configs, dependencies)
   - [ ] Verify file paths match workspace structure

4. **Suggest improvements**
   - [ ] Does the request align with modern best practices?
   - [ ] Are there more efficient, beautiful, or secure approaches?
   - [ ] Does this fit the team's architecture?
   - [ ] Consider performance, security, maintainability

5. **Implement incrementally**
   - [ ] Make one change at a time
   - [ ] Mark todo as in-progress, then completed
   - [ ] Test/validate after EACH change
   - [ ] Use `Edit` tool for modifications, `Write` for new files

6. **Validate thoroughly**
   - [ ] Run tests (`pytest tests/ -v` for AI service)
   - [ ] Verify endpoints work (Swagger, health checks)
   - [ ] Check for syntax/lint errors
   - [ ] Update memory with lessons learned

---

## 📍 Key File Locations

| Path | Purpose | Owner |
|------|---------|-------|
| `ai_service/api/v1/routes/` | FastAPI endpoints (recording, query, materials, health) | Seidu |
| `ai_service/core/` | AI modules: transcription, RAG, LLM, vector store | Seidu |
| `ai_service/models/` | Pydantic schemas, SQLAlchemy ORM models | Seidu |
| `ai_service/middleware/auth.py` | Bearer token validation | Seidu |
| `moodle/public/local/umat_ai/classes/` | PHP backend: events, tasks, external services | Ackon |
| `moodle/public/local/umat_ai/templates/` | Mustache templates | Agartha |
| `moodle/public/theme/umat/` | Theme (extends Boost, navy #003580, gold #C8A951) | Chrispen |
| `moodle/public/local/umat_ai/amd/src/` | AMD JavaScript modules (define/require pattern) | Johnson |
| `moodle/public/local/umat_ai/lang/` | Language strings | Johnson |
| `moodle/public/local/umat_ai/lib.php` | PHP entry point - loads AMD modules | Johnson |
| `ai_service/.env` | Secrets (API keys, tokens) — contact Chrispen | Chrispen |

---

## 🚀 Getting Started: Project Startup (Daily)

**Prerequisites**: XAMPP, PostgreSQL, Python venv, Git

**Startup sequence** (all required, takes ~2 min):

```powershell
# 1. Start Apache (XAMPP Control Panel → Start)
# 2. PostgreSQL auto-starts as Windows service (port 5432)

# 3. Terminal 1: AI Service
cd C:\Projects\umat-vle-enhanced\ai_service
venv\Scripts\activate
python main.py
# Verify: GET http://localhost:8000/api/v1/health → {"status": "healthy"}

# 4. Terminal 2: Moodle cron (keep running)
cd C:\Projects\umat-vle-enhanced
.\cron.bat  # Or: php moodle/admin/cli/cron.php
```

**Key endpoints** (verify these work):
- Moodle: `http://localhost`
- AI Swagger UI: `http://localhost:8000/docs`
- AI Health: `GET http://localhost:8000/api/v1/health` (no auth required)

---

## 🔄 Agent Workflow: How to Approach Tasks

### Step 1: Analyze Before Acting
- Read the full request + context
- Check AGENTS.md "Team Work Areas" — is this your component?
- Search `docs/` folder for existing patterns
- Decide: Is this clearly defined or ambiguous?
  - **Clear**: Proceed to planning
  - **Ambiguous**: Ask clarifying questions first

### Step 2: Plan & Break Down
Use `manage_todo_list` to structure work:
```
Todo 1: Understand requirements & file locations (in-progress)
Todo 2: Write/modify feature in component A (not-started)
Todo 3: Add tests (not-started)
Todo 4: Verify no side effects in other components (not-started)
Todo 5: Final validation & commit (not-started)
```

**Update status as you go**: Mark as `in-progress` when starting, `completed` immediately after finishing each chunk.

### Step 3: Explore the Codebase
Before writing code:
- [ ] Use `explore_subagent` or `semantic_search` to find similar patterns
- [ ] Read existing tests to understand testing style
- [ ] Check for imports and dependencies
- [ ] Look for configuration or feature flags
- [ ] Understand error handling patterns

### Step 4: Suggest Improvements
Before implementing, consider:
- **Efficiency**: Can this be done with fewer lines/fewer API calls?
- **Security**: Are secrets safe? Is auth handled correctly?
- **Maintainability**: Will future devs understand this code?
- **Modern approaches**: Use existing libraries? Follow team conventions?
- **Performance**: Will this scale? Any N+1 queries or blocking calls?

### Step 5: Implement Incrementally
- [ ] Use `multi_replace_string_in_file` for bulk edits (parallel processing)
- [ ] Edit one file at a time
- [ ] Keep changes small and focused
- [ ] Mark todo as completed after EACH chunk

### Step 6: Test Thoroughly
- **AI Service**: `pytest tests/ -v` (Python)
- **Moodle PHP**: Test via browser or web service calls
- **Endpoints**: Verify with Swagger (`http://localhost:8000/docs`)
- **No errors**: Run `get_errors` to check for lint/syntax issues

---

## 💾 Memory & Caching Strategy for Agents

**Use agent memory to maintain context and avoid repeated work:**

### Session Memory (`memory/session/`)
- **When**: Use during this conversation only
- **What**: Task plans, current progress, findings
- **Example**: `planning.md` - tracks which files need changes
- **Benefit**: Keeps work visible if conversation is long
- **Location**: `C:\Users\amkch\.claude\projects\umat-vle-enhanced\memory\`

### User Memory (`memory/`)
- **When**: Use for patterns that recur across projects
- **What**: Common errors, debugging tricks, best practices
- **Example**: `debugging.md` - "PostgreSQL connection issues resolution"
- **Benefit**: Build a knowledge base; avoid repeating mistakes

### Repository Memory (`memory/repo/`)
- **When**: Use for project-specific facts
- **What**: Build commands, database schema, API contracts
- **Example**: `api_contracts.md` - endpoint params and responses
- **Benefit**: Single source of truth for the team

**Store memory**: Document your memories after each chat/conversation in `memory/session/` and/or `memory/`

---

## 🐛 Common Issues & Troubleshooting

### Startup Issues

| Issue | Cause | Solution |
|-------|-------|----------|
| `ConnectionRefused :8000` | AI Service not running | Terminal 1: `python main.py` in `ai_service/` |
| `ConnectionRefused :5432` | PostgreSQL not running | Start PostgreSQL service or check port |
| Health check fails | AI Service crashed | Check `ai_service/` terminal for errors; check `.env` secrets |
| Moodle blank page | Apache not started | XAMPP Control Panel → Start Apache |
| `ModuleNotFoundError` | Venv not activated | Run: `venv\Scripts\activate` |

### API Issues

| Issue | Cause | Solution |
|-------|-------|----------|
| `401 Unauthorized` | Bearer token mismatch | Verify `ai_service/.env` `AI_SERVICE_TOKEN` matches Moodle plugin settings |
| `500 Internal Error` | Backend exception | Check AI Service terminal logs for traceback |
| ChromaDB empty | Recording not processed | Verify `POST /api/v1/recording/process` was called; check job status |
| Slow queries | Vector search inefficient | Check ChromaDB collection size; may need pagination |

### Development Issues

| Issue | Cause | Solution |
|-------|-------|----------|
| Git conflict | Multiple edits to same file | Check AGENTS.md team areas; coordinate with team |
| Tests fail | Code change broke test | Read test file to understand expectations; fix incrementally |
| Dependencies missing | `requirements.txt` not updated | Run: `pip install -r requirements.txt` |
| Secrets exposed | `.env` committed to Git | Delete from Git history: `git filter-branch --force --index-filter 'git rm --cached --ignore-unmatch .env'` |

---

## ✅ Testing & Validation Strategy

### AI Service (Python)

**Unit tests**:
```powershell
cd ai_service
venv\Scripts\activate
pytest tests/ -v
```

**What to test**:
- [ ] API endpoint returns correct status codes (200, 400, 401, 500)
- [ ] Request validation (invalid params rejected)
- [ ] Database queries (insert, select, update)
- [ ] Transcription & RAG workflows

**Integration tests**:
- [ ] Health check returns `{"status": "healthy"}`
- [ ] Recording process creates job + returns job_id
- [ ] ChromaDB stores and retrieves embeddings
- [ ] Gemini API integration (if using mocked client)

### Moodle (PHP)

**Manual testing** (test via browser):
- [ ] Plugin settings page loads
- [ ] Web service calls work (check Moodle logs)
- [ ] Theme renders correctly (check CSS colors, navy #003580, gold #C8A951)
- [ ] AMD JS modules load (browser console shows no errors)

**What to verify**:
- [ ] No PHP syntax errors (check Moodle admin panel)
- [ ] Database queries work (check mdl_umat_ai_* tables)
- [ ] Rate limiting works (10 questions/minute per user)
- [ ] Lecturer approval workflow functioning

### Cross-Component

**Before committing**:
- [ ] Run `php -l` on any modified PHP files
- [ ] `get_errors` shows no lint/syntax issues
- [ ] No secrets in code (check for API keys, tokens)
- [ ] File paths use forward slashes or `\` consistently
- [ ] Commit message is clear and descriptive
- [ ] No large files committed (keep < 50MB)

**Integration testing checklist**:
1. Start Apache + PostgreSQL + AI Service
2. Visit a course page and verify FAB loads
3. Test a simple AI question via the chat panel
4. Check browser console for AMD module errors

---

## 📚 Data Flows & Workflows

### Recording Processing Pipeline

1. **Trigger**: Lecturer uploads BBB recording → Moodle event fired
2. **Job creation**: Moodle calls `POST /api/v1/recording/process` (returns job_id immediately)
3. **Background processing** (async):
   - Download video from BBB
   - Extract audio with ffmpeg
   - Transcribe with Whisper (OpenAI)
   - Chunk transcript (512 tokens, 50-token overlap)
   - Embed chunks (Gemini embeddings)
   - Store in ChromaDB (one collection per course)
4. **AI generation**:
   - Gemini generates summary (5 min lecture summary)
   - Gemini generates study notes (key concepts, definitions)
   - Gemini generates quiz (5-10 MCQ questions)
5. **Status check**: `GET /api/v1/recording/status/{job_id}` returns progress + results
6. **Approval**: Lecturer reviews generated content before students see it

### Student Q&A Pipeline

1. **Question**: Student types question in sidebar → AMD JS calls Moodle web service
2. **Moodle layer**:
   - Validates auth
   - Rate limits (10 questions/minute per user)
   - Calls `POST /api/v1/query` with question + course_id
3. **AI Service**:
   - Embed question (Gemini embeddings)
   - Query ChromaDB: find top 5 similar chunks from course materials
   - Construct RAG prompt: question + context + system prompt
   - Call Gemini API: get grounded answer
4. **Response**: Return answer + source references to student

### Material Management

- Files uploaded to course: Moodle stores in `mdl_umat_ai_*` tables
- AI Service: Can call `POST /api/v1/materials/ingest` to embed course materials
- Vector store: ChromaDB maintains 1 collection per course_id
- Cleanup: Deleting course deletes associated ChromaDB collection

---

## 🔐 Security & Best Practices

### Authentication & Authorization
- **AI Service**: Expects `Authorization: Bearer {token}` header
- **Token source**: `ai_service/.env` `AI_SERVICE_TOKEN` (held by Chrispen)
- **Moodle**: Stores token in plugin settings (encrypted)
- **Rate limiting**: Moodle layer enforces 10 Q&A/minute per user

### Secrets Management
- **Never commit**: `.env`, `config.php`, API keys, tokens
- **Where to store**: Contact Chrispen; secrets go in `ai_service/.env` only
- **In code**: Use environment variables, never hardcode
- **Gemini API key**: Env-only, never exposed to client browser

### Data Privacy
- **Transcripts**: Stored in ChromaDB (local, not cloud-synced)
- **User questions**: Logged for analytics but anonymized
- **Generated content**: Stored in `mdl_umat_ai_*` tables; students see only approved content
- **Compliance**: GDPR-aware (student data deletion removes from all stores)

---

## 📖 Additional Resources

- **Architecture deep dive**: See [docs/architecture.md](docs/architecture.md)
- **API reference**: See [docs/api.md](docs/api.md) or Swagger: `http://localhost:8000/docs`
- **Setup troubleshooting**: See [docs/setup.md](docs/setup.md)
- **Debugging tips**: See [docs/troubleshooting.md](docs/troubleshooting.md)

---

## 📋 Git Workflow (Single `main` Branch)

**Before starting work**:
```bash
git pull origin main
```

**While working**:
- Make changes in your editor
- Test thoroughly
- Use `manage_todo_list` to track progress

**When done**:
```bash
git add .
git commit -m "Brief description of change"
# Example: "Add RAG context filtering for better accuracy"
git push origin main
```

**Good commit messages**:
- ✅ "Fix rate limiting bug in Q&A endpoint"
- ✅ "Add memory strategy to CLAUDE.md"
- ✅ "Refactor ChromaDB collection naming"
- ❌ "Update" (too vague)
- ❌ "bug fix" (missing context)

**If conflicts occur**:
1. Check AGENTS.md: Who owns this file?
2. Coordinate with that team member
3. Resolve conflicts manually (merge tool or editor)
4. Test after resolving
5. Commit: `git commit -m "Resolve merge conflict in [file]"`

---

## 🎯 AI Agent Quick Reference

When you're stuck:
1. **Check memory**: `memory/session/` (current task), `memory/` (lessons learned)
2. **Read docs**: [docs/](docs/) folder has troubleshooting & architecture
3. **Check examples**: `Grep` for similar patterns in codebase
4. **Verify endpoints**: Test with Swagger: `http://localhost:8000/docs`
5. **Run tests**: `pytest tests/ -v` to catch regressions
6. **Ask questions**: Use `AskUserQuestion` if task is ambiguous
7. **Store memory**: Document your memories after each chat/conversation in `memory/session/` and/or `memory/`


**Remember**: Think deeply, plan before coding, test after changes, document lessons learned.

---

## 🛠️ Moodle AMD Module Pattern

Moodle uses RequireJS for AMD modules. Structure:
```javascript
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    'use strict';

    function init(options) {
        // Your code here
    }

    return { init: init };
});
```

**Loading from PHP** (`lib.php`):
```php
$PAGE->requires->js_amd_inline("
    require(['local_umat_ai/ai_fab'], function(Fab) {
        Fab.init($courseid, '$coursename');
    });
");
```