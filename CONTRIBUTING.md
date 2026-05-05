# Contributing to UMaT VLE Enhanced

This document explains how the five-person team works together on this project.

We are using a **simple GitHub workflow** — one branch (`main`), no pull requests, no complicated branching. Just commit, push, and pull.

---

## Team Members and Responsibilities

| Name | Role | Area of Work |
|------|------|-------------|
| **Seidu** | Project Lead, Developer | Overall direction, Moodle PHP plugin, PostgreSQL schemas, code reviews |
| **Ackon Emmanuel** | Developer | Moodle PHP plugin, SCSS theme styling, plugin settings and capabilities |
| **Chrispen** | Developer | Python AI service, FastAPI routes, ChromaDB, Whisper transcription |
| **Agartha** | Researcher, UI/UX Designer | UI wireframes, Mustache templates, SCSS components, system research |
| **Johnson** | Researcher, UI/UX Designer | AMD JavaScript modules, frontend interactions, system research, documentation |

---

## Our GitHub Workflow (Simple Version)

We use **one branch — `main`**. Everyone commits directly to main. There are no feature branches and no pull requests.

### The Three Commands You Need Every Day

**Step 1 — Pull before you start working** (always do this first to get the latest code):
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

That's it. Do these three things every time you work on the project.

---

## Day-by-Day Routine

Every time you sit down to work on the project:

**1. Open your terminal and navigate to the project folder:**
```bash
cd C:\Projects\umat-vle-enhanced
```

**2. Pull the latest changes from GitHub:**
```bash
git pull origin main
```
This downloads any changes your teammates made since you last worked. Always do this before you start — otherwise you may overwrite their work.

**3. Do your work** — edit files, write code, update documents.

**4. When you are done (or at the end of each working session), save and upload:**
```bash
git add .
git commit -m "what I did today"
git push origin main
```

**Tip:** Push at the end of every session, even if the work is not finished. It is better to push unfinished work than to lose it.

---

## Writing Good Commit Messages

A commit message is a short note that tells your teammates what you changed. Write it in plain English. Be specific.

**Good examples:**
```
added BigBlueButton plugin to moodle
fixed the PHP error in the event observer
updated README with setup instructions
created the ai chat panel mustache template
fixed database connection issue in config.py
added whisper transcription module
updated team names in README
```

**Bad examples:**
```
update
fix
done
stuff
asdfgh
```

There is no strict format — just describe what you actually did, clearly enough that a teammate understands without asking you.

---

## What to Do if `git push` is Rejected

If you try to push and get an error like `rejected` or `non-fast-forward`, it means a teammate pushed changes after you last pulled. Fix it like this:

```bash
# Step 1: Pull their changes first
git pull origin main

# Step 2: If there are no conflicts, just push again
git push origin main
```

If you see conflict markers in a file (lines with `<<<<<<<`, `=======`, `>>>>>>>`), see the conflict resolution section below.

---

## Resolving Conflicts

A conflict happens when two people edit the same part of the same file. Git cannot decide which version to keep, so it marks the file and asks you to decide.

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
3. Click the one that makes sense, or manually edit the file to combine both versions correctly
4. Delete all the `<<<<<<<`, `=======`, `>>>>>>>` markers
5. Save the file
6. Then run:

```bash
git add .
git commit -m "resolved conflict in filename"
git push origin main
```

**How to avoid conflicts:** Work in your own files as much as possible. If you know a teammate is editing a file right now, wait until they push before you edit the same file.

---

## What Each Person Should Work On

To minimise conflicts, each person should stick to their area:

**Seidu (Project Lead):**
- `ai_service/` — all Python FastAPI service files
- `ai_service/core/` — transcription, RAG, LLM, document loader, vector store
- `ai_service/api/` — all API routes
- `ai_service/models/` — schemas and database models
- `ai_service/tests/` — Python unit tests

**Ackon Emmanuel:**
- `moodle/local/umat_ai/db/` — database tables, capabilities, tasks, events, services
- `moodle/local/umat_ai/classes/task/` — scheduled tasks
- `moodle/local/umat_ai/classes/event/` — event handlers
- `moodle/local/umat_ai/classes/external/` — web service external functions
- `moodle/local/umat_ai/classes/privacy/` — privacy provider
- Overall code review and architecture decisions

**Chrispen:**
- `moodle/local/umat_ai/settings.php`, `version.php`, `lib.php`
- `moodle/theme/umat/scss/` — SCSS theme styling
- `moodle/theme/umat/version.php`, `config.php`

**Agartha:**
- `moodle/local/umat_ai/templates/` — Mustache templates
- `moodle/theme/umat/layout/` — theme layout overrides
- `docs/` — research documentation and architecture docs
- UI wireframes and design specifications

**Johnson:**
- `moodle/local/umat_ai/amd/src/` — JavaScript AMD modules
- `moodle/local/umat_ai/lang/` — language strings
- `docs/api.md` — API documentation
- `docs/setup.md` — setup guide maintenance

**Everyone:**
- `README.md` — update as needed
- `docs/troubleshooting.md` — add problems and solutions as you discover them

---

## Secrets and Sensitive Information

**Never commit:**
- `.env` files
- `moodle/config.php`
- API keys
- Database passwords

Share secrets through your WhatsApp group chat. Each person puts them in their local `.env` file which is gitignored.

---

## Getting Help

If you are stuck for more than 30 minutes on something:
1. Post in the WhatsApp group with a clear description of the problem and what you already tried
2. If it is a code problem, copy and paste the exact error message — not a photo of your screen
3. If Seidu is unavailable, Ackon and Chrispen can help with code issues; Agartha and Johnson can help with UI/documentation issues