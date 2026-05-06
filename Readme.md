# UMaT Generative AI-Enhanced Virtual Learning Environment

> A Final Year Project by the Department of Cyber Security and Information Systems,
> Faculty of Computing and Mathematical Sciences,
> University of Mines and Technology (UMaT), Tarkwa, Ghana.

---

## Project Overview

This project enhances the existing UMaT Moodle Virtual Learning Environment (VLE) by integrating two major features:

1. **Native Live Class Module** — Integrated BigBlueButton video conferencing directly within Moodle, eliminating the need for external platforms like Zoom or Google Meet. Includes automatic attendance tracking.

2. **Generative AI Academic Support** — A RAG-based AI assistant that answers student questions using only official course materials and lecture transcripts. Includes automatic lecture summarisation, structured note generation, and practice question creation.

These features are built as Moodle plugins, meaning they survive Moodle core updates and remain integrated regardless of platform version changes.

---

## Team Members

| Name | Role | GitHub |
|------|------|--------|
| Seidu | Project Lead, Developer | [@kinseidu](https://github.com/kinseidu) |
| Ackon Emmanuel | Developer | [@ackonemmanuel](https://github.com/ackonemmanuel) |
| Chrispen | Developer | [@derychris](https://github.com/derychris) |
| Agartha | Researcher, UI/UX Designer | [@agartha](https://github.com/agartha) |
| Johnson | Researcher, UI/UX Designer | [@johnson](https://github.com/johnson) |

**Supervisor:** Dr. Emmanuel Effah

> **API keys, tokens, and passwords** — contact [@derychris](https://github.com/derychris) (Chrispen)

---

## System Architecture

```
[Moodle Frontend] <-> [Moodle PHP Plugin] <-> [Python FastAPI AI Service]
                            |                           |
                   [BigBlueButton Server]        [ChromaDB + Gemini]
                            |
                      [PostgreSQL]
```

- **Moodle** handles all user authentication, course management, and UI rendering
- **Python FastAPI Service** handles all AI processing (transcription, RAG, summarisation)
- **BigBlueButton** provides live video conferencing
- **PostgreSQL** stores all data
- **ChromaDB** stores vector embeddings for RAG retrieval

For detailed architecture documentation, see [docs/architecture.md](docs/architecture.md).

---

## Technology Stack

| Layer | Technology |
|-------|-----------|
| LMS Platform | Moodle 5.1.3x |
| Plugin Language | PHP 8.2 |
| AI Service | Python 3.11, FastAPI |
| Live Classes | BigBlueButton |
| Database | PostgreSQL 15/16 |
| Vector Store | ChromaDB |
| LLM | Gemini-1.5-Flash |
| Speech-to-Text | OpenAI Whisper |
| Embeddings | Google Generative AI Embeddings |
| Local Server | XAMPP (Apache) |
| Version Control | Git + GitHub |

---

## Repository Structure

```
umat-vle-enhanced/
├── moodle/                        # Moodle installation
│   ├── public/                    # Moodle 5.x public directory
│   │   ├── local/umat_ai/         # UMaT AI plugin (Moodle scans here)
│   │   └── theme/umat/            # UMaT theme (Moodle scans here)
│   └── mod/bigbluebuttonbn/       # BigBlueButton plugin
├── ai_service/                    # Python FastAPI AI processing service
├── docs/                          # Project documentation
├── .gitignore
├── cron.bat                       # Runs Moodle cron continuously (Windows)
├── README.md                      # This file
└── CONTRIBUTING.md                # Contribution guidelines
```

---

## Getting Started

### For New Team Members

Follow the complete setup guide: **[docs/setup.md](docs/setup.md)**

### Quick Start (If Already Set Up)

**Start Moodle:**
1. Open XAMPP Control Panel → Start Apache
2. Run `umat-vle-enhanced\cron.bat` (double-click, press `Ctrl+C` to stop)
3. Open browser → `http://localhost`

**Start AI Service:**
```bash
cd C:\Projects\umat-vle-enhanced\ai_service
venv\Scripts\activate
python main.py
```

**Verify everything is running:**
- Moodle: `http://localhost`
- AI Service Swagger UI: `http://localhost:8000/docs`
- pgAdmin: PostgreSQL on port 5432

---

## How We Use GitHub

We use **one branch — `main`**. No feature branches, no pull requests.

```bash
# Before you start — get the latest from GitHub
git pull origin main

# After you make changes — save and describe your work
git add .
git commit -m "describe what you changed"

# Upload to GitHub so the team can see it
git push origin main
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full workflow and conflict resolution guide.

---

## API Documentation

- **Interactive (Swagger UI):** `http://localhost:8000/docs` (when AI service is running)
- **Reference:** [docs/api.md](docs/api.md)

---

## Project Supervisor

Dr. Emmanuel Effah
Department of Cyber Security and Information Systems
University of Mines and Technology, Tarkwa, Ghana

---

## Academic Context

Submitted in partial fulfilment of the requirements for the award of the
**Bachelor of Science in Information Systems and Technology**
University of Mines and Technology, Tarkwa — 2026