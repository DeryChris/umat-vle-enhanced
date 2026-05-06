# UMaT Generative AI-Enhanced Virtual Learning Environment

> A Final Year Project by the Department of Cyber Security and Information Systems,
> Faculty of Computing and Mathematical Sciences,
> University of Mines and Technology (UMaT), Tarkwa, Ghana.

---

## Project Overview

This project enhances the existing UMaT Moodle Virtual Learning Environment (VLE) by integrating two major features:

1. **Native Live Class Module** — Integrated BigBlueButton video conferencing directly within Moodle, eliminating the need for external platforms like Zoom or Google Meet. Includes automatic attendance tracking.

2. **Generative AI Academic Support** — A RAG-based AI assistant that answers student questions using only official course materials and lecture transcripts. Includes automatic lecture summarization, structured note generation, and practice question creation.

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

---

## System Architecture

The system consists of three integrated components:

```
[Moodle Frontend] <-> [Moodle PHP Plugin] <-> [Python FastAPI AI Service]
                            |                           |command:codium.auth.portalAccountSignInNotificationButton
                   [BigBlueButton Server]        [ChromaDB + OpenAI]
                            |
                      [PostgreSQL]
```

- **Moodle** handles all user authentication, course management, and UI rendering
- **Python FastAPI Service** handles all AI processing (transcription, RAG, summarization)
- **BigBlueButton** provides live video conferencing
- **PostgreSQL** stores all data
- **ChromaDB** stores vector embeddings for RAG retrieval

For detailed architecture documentation, see [docs/architecture.md](docs/architecture.md).

---

## Technology Stack

| Layer | Technology |
|-------|-----------|
| LMS Platform | Moodle 4.3 LTS |
| Plugin Language | PHP 8.2 |
| AI Service | Python 3.11, FastAPI |
| Live Classes | BigBlueButton |
| Database | PostgreSQL 15/16 |
| Vector Store | ChromaDB |
| LLM | Gemini-1.5-Flash |
| Speech-to-Text | OpenAI Whisper |
| Embeddings | OpenAI text-embedding-3-small |
| Local Server | XAMPP (Apache) |
| Version Control | Git + GitHub |

---

## Repository Structure

```
umat-vle-enhanced/
├── moodle/                    # Full Moodle installation + custom plugins
│    
│   ├── mod/bigbluebuttonbn/   # BigBlueButton plugin
│   └── public/                # Custom UMaT theme
|        ├── theme/umat/
|        └── local/umat_ai/    # Custom Moodle plugin (AI features)
├── ai_service/                # Python FastAPI AI processing service
├── docs/                      # Project documentation
├── .github/                   # GitHub configuration
├── .gitignore
├── cron.bat                   # Scheduled tasks - continuously run cron.php
├── README.md                  # This file
└── CONTRIBUTING.md            # Contribution guidelines
```

---

## Getting Started

### For New Team Members

Follow the complete setup guide here: **[docs/setup.md](docs/setup.md)**

The setup guide covers everything from installing required software to running the project locally.

### Quick Start (If Already Set Up)

**Start Moodle:**
1. Open XAMPP Control Panel
2. Start Apache
3. Open browser → `http://localhost`
4. Run this file `umat-vle-enhanced\cron.bat`.


**Start AI Service:**
```bash
cd C:\Projects\umat-vle-enhanced\ai_service
venv\Scripts\activate
python main.py
```

**Verify everything is running:**
- Moodle: `http://localhost`
- AI Service: `http://localhost:8000/docs`
- pgAdmin: PostgreSQL on port 5432

---

## How We Use GitHub (Simple Guide)

We use **one branch only — `main`**. Every team member commits directly to main. No pull requests, no feature branches.

**The three commands you need every day:**

```bash
# 1. Before you start work — get the latest code from GitHub
git pull origin main

# 2. After you make changes — save your work locally
git add .
git commit -m "describe what you changed"

# 3. Upload your changes to GitHub so the team can see them
git push origin main
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full simple workflow.

---

## API Documentation

The AI Service API is documented at:
- **Interactive (Swagger UI):** `http://localhost:8000/docs` (when service is running)
- **Reference:** [docs/api.md](docs/api.md)

---

## Project Supervisor

Dr. Emmanuel Effah
Department of Cyber Security and Information Systems
University of Mines and Technology, Tarkwa, Ghana

---

## Academic Context

This project is submitted in partial fulfilment of the requirements for the award of the
**Bachelor of Science in Information Systems and Technology**
University of Mines and Technology, Tarkwa — 2026