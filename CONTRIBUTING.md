# Contributing to UMaT VLE Enhanced

This document explains how the five-person team works together on this project.

We are using a **simple GitHub workflow** — one branch (`main`), no pull requests, no complicated branching. Just commit, push, and pull.

---

## Team Members and Responsibilities

| Name | Role | GitHub | Area of Work |
|------|------|--------|-------------|
| **Seidu** | Project Lead, Developer | [@kinseidu](https://github.com/kinseidu) | Overall direction, Python AI service, PostgreSQL schemas, code reviews |
| **Ackon Emmanuel** | Developer | [@ackonemmanuel](https://github.com/ackonemmanuel) | Moodle PHP plugin (db, classes, external services, privacy) |
| **Chrispen** | Developer | [@derychris](https://github.com/derychris) | Plugin settings/version, SCSS theme, API keys & secrets holder |
| **Agartha** | Researcher, UI/UX Designer | [@agartha](https://github.com/agartha) | Mustache templates, theme layouts, SCSS components, system research |
| **Johnson** | Researcher, UI/UX Designer | [@johnson](https://github.com/johnson) | AMD JavaScript modules, language strings, documentation |

> **API keys, tokens, and passwords** — all secrets are held by Chrispen ([@derychris](https://github.com/derychris)). Request them via the WhatsApp group.

---

## Our GitHub Workflow (Simple Version)

We use **one branch — `main`**. Everyone commits directly to main. There are no feature branches and no pull requests.

### The Three Commands You Need Every Day

**Step 1 — Pull before you start working:**
```bash
git pull origin main
```

**Step 2 — Save your work and describe what you did:**
```bash
git add .
git commit -m "describe what you changed"
```

**Step 3 — Upload your changes so the team can see them:**
```bash
git push origin main
```

---

## Day-by-Day Routine

**1. Navigate to the project folder:**
```bash
cd C:\Projects\umat-vle-enhanced
```

**2. Pull latest changes first — always:**
```bash
git pull origin main
```

**3. Do your work.**

**4. Save and upload at end of session:**
```bash
git add .
git commit -m "what I did today"
git push origin main
```

**Tip:** Push at the end of every session even if work is unfinished. It is better to push incomplete work than to lose it.

---

## Writing Good Commit Messages

Write in plain English. Be specific enough that a teammate understands without asking.

**Good:**
```
added BigBlueButton plugin to moodle
fixed PHP error in the event observer
updated README with setup instructions
created the AI chat panel mustache template
fixed database connection issue in config.py
added Whisper transcription module
```

**Bad:**
```
update
fix
done
stuff
asdfgh
```

---

## What to Do if `git push` is Rejected

A rejection means a teammate pushed after you last pulled.

```bash
git pull origin main
git push origin main
```

If `git pull` shows conflict markers (`<<<<<<<`, `=======`, `>>>>>>>`), see the conflict resolution section below.

---

## Resolving Conflicts

A conflict happens when two people edit the same part of the same file.

**A conflicted file looks like this:**
```
<<<<<<< HEAD
your version of the code
=======
your teammate's version of the code
>>>>>>> origin/main
```

**To fix it:**
1. Open the file in VS Code
2. VS Code shows buttons: **Accept Current Change** | **Accept Incoming Change** | **Accept Both Changes**
3. Choose the correct version, or manually merge both
4. Delete all `<<<<<<<`, `=======`, `>>>>>>>` markers
5. Save, then:

```bash
git add .
git commit -m "resolved conflict in filename"
git push origin main
```

**Best way to avoid conflicts:** Stay in your own files. If a teammate is actively editing a file, wait until they push before editing the same file.

---

## What Each Person Should Work On

Stay in your own area to minimise conflicts.

**Seidu ([@kinseidu](https://github.com/kinseidu)) — Project Lead:**
- `ai_service/` — all Python FastAPI service files
- `ai_service/core/` — transcription, RAG, LLM, document loader, vector store
- `ai_service/api/` — all API routes
- `ai_service/models/` — schemas and database models
- `ai_service/tests/` — Python unit tests
- Overall code review and architecture decisions

**Ackon Emmanuel ([@ackonemmanuel](https://github.com/ackonemmanuel)):**
- `moodle/public/local/umat_ai/db/` — database tables, capabilities, tasks, events, services
- `moodle/public/local/umat_ai/classes/task/` — scheduled tasks
- `moodle/public/local/umat_ai/classes/event/` — event handlers
- `moodle/public/local/umat_ai/classes/external/` — web service external functions
- `moodle/public/local/umat_ai/classes/privacy/` — privacy provider

**Chrispen ([@derychris](https://github.com/derychris)):**
- `moodle/public/local/umat_ai/settings.php`, `version.php`, `lib.php`
- `moodle/public/theme/umat/scss/` — SCSS theme styling
- `moodle/public/theme/umat/version.php`, `config.php`
- Holds all API keys, tokens, and passwords — share via WhatsApp group only

**Agartha ([@agartha](https://github.com/agartha)):**
- `moodle/public/local/umat_ai/templates/` — Mustache templates
- `moodle/public/theme/umat/layout/` — theme layout overrides
- `docs/architecture.md` — system architecture documentation
- UI wireframes and design specifications

**Johnson ([@johnson](https://github.com/johnson)):**
- `moodle/public/local/umat_ai/amd/` — JavaScript AMD modules
- `moodle/public/local/umat_ai/lang/` — language strings
- `docs/api.md` — API documentation
- `docs/setup.md` — setup guide maintenance

**Everyone:**
- `README.md` — update as needed
- `docs/troubleshooting.md` — add problems and solutions as you find them

---

## Secrets and Sensitive Information

**Never commit:**
- `.env` files
- `moodle/config.php`
- API keys or tokens
- Database passwords

All secrets are managed by **Chrispen** ([@derychris](https://github.com/derychris)). Request them via the WhatsApp group and store them only in your local `.env` file, which is gitignored.

---

## Getting Help

If you are stuck for more than 30 minutes:
1. Post in the WhatsApp group — describe the problem and what you already tried
2. For code issues, paste the **exact error message** (not a photo of your screen)
3. If Seidu is unavailable, Ackon and Chrispen can help with code; Agartha and Johnson can help with UI/documentation