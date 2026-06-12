# UMaT VLE Enhanced — Development Roadmap

**Project**: AI-Enhanced Virtual Learning Environment for University of Mines and Technology  
**Repository**: `github.com/derychris/umat-vle-enhanced`  
**Last Updated**: 2026-06-11

---

## Project Context

This project is an **academic thesis/research project** at the University of Mines and Technology (UMaT), Tarkwa. It addresses the fragmentation of learning activities across multiple platforms (Zoom, Microsoft Teams, general-purpose LLMs) by embedding live class functionality and Generative AI-assisted academic support directly within the UMaT Moodle VLE.

### Aim
Strengthen the UMaT-VLE by embedding live class functionality and Generative AI-assisted academic support directly within the platform, to improve how lecture knowledge is captured, organised, and made available for student revision.

### Objectives
1. **Live Class Integration** — Develop and integrate a native live class feature within UMaT-VLE that enables real-time instruction, student interaction, and attendance tracking without reliance on external conferencing tools.
2. **GenAI Academic Support** — Build Generative AI-assisted academic support that draws from ASR-generated lecture transcripts, session discussions, and lecturer-approved course materials to produce structured notes and concise summaries under defined academic controls.

### Methodology
The project follows a **hybrid DSR + Agile Scrum** approach:
- **Design Science Research (DSR)** — Provides a scientific method for creating a new artifact to address learning fragmentation
- **Agile Scrum (2-week sprints)** — Handles technical development with Sprint Planning, Daily Scrums, Sprint Reviews, and three artifacts: Product Backlog, Sprint Backlog, and Project Increment

### System Architecture (5-Layer)
| Layer | Technology | Responsibility |
|-------|-----------|----------------|
| **Presentation** | Moodle HTML/CSS/JS | User-facing interface |
| **Application** | Moodle PHP | Auth, course activities, session handling, request distribution |
| **Integration** | RESTful APIs | Communication between Moodle, AI Service, BigBlueButton |
| **Processing AI** | Python/FastAPI + LLM | Transcription, NLP, summarization, RAG-based answering |
| **Data** | PostgreSQL | User data, courses, lectures, interactions, GenAI outputs |

---

## Vision

Transform UMaT's Moodle VLE into an AI-powered learning platform where:
- Live classes (BigBlueButton) are automatically transcribed, summarized, and indexed
- Students ask natural-language questions and get answers grounded in course materials
- Lecturers get analytics on student struggles and topic comprehension
- All AI-generated content requires lecturer approval before reaching students

---

## Development Phases

### Phase 1: Foundation (Complete ✅)

> Core infrastructure — Moodle plugin, AI service, databases, and basic integration.

| Milestone | Status | Key Deliverables |
|-----------|--------|------------------|
| Moodle plugin scaffold | ✅ | `local_umat_ai` plugin with settings, DB tables, capabilities |
| AI service scaffold | ✅ | FastAPI app with health check, auth middleware, DB models |
| Database setup | ✅ | PostgreSQL databases (`moodle` + `umat_ai_db`), ChromaDB vector store |
| BigBlueButton integration | ✅ | Event observer on `meeting_ended`, session tracking in DB |
| Recording processing pipeline | ✅ | Download → ffmpeg audio extraction → Whisper transcription → ChromaDB indexing |
| LLM integration (Gemini) | ✅ | Summary, notes, quiz generation from transcripts |
| Student Q&A (RAG) | ✅ | Embed query → ChromaDB retrieval → Gemini answer with source citations |
| Theme setup | ✅ | `theme_umat` extending Boost with UMaT navy/gold colors |
| AJAX chat panel | ✅ | AMD JS module for AI Q&A in course pages |

---

### Phase 2: Material Intelligence (Complete ✅)

> Course material indexing, analysis, and multi-format support.

| Milestone | Status | Key Deliverables |
|-----------|--------|------------------|
| Multi-format document loader | ✅ | PDF, DOCX, PPTX, XLSX, CSV, TXT/MD, code, audio, video |
| Material indexing pipeline | ✅ | Auto-index new files into ChromaDB via scheduled task |
| Material analysis endpoints | ✅ | `POST /analyze`, `GET /analyses`, `GET /analysis/{id}`, `POST /batch` |
| 5 analysis modes | ✅ | Full analysis, summary, key concepts, quiz, custom |
| Analysis sync to Moodle | ✅ | AI service → Moodle callback via `analysis_sync.php` |
| Auto-indexing cron | ✅ | `index_course_materials` task (runs every 30 min) |
| Analysis status UI | ✅ | Status indicators and "Analyze" button on material tiles |
| Moodle web services | ✅ | `get_analysis_status`, `request_analysis` WS endpoints |

---

### Phase 3: Analytics & Intelligence (Partially Complete 🔄)

> Student analytics, lecturer insights, and adaptive learning.

| Milestone | Status | Key Deliverables |
|-----------|--------|------------------|
| Question classification | ✅ | `POST /analytics/classify-questions` — conceptual/procedural/clarity/application |
| Struggle topic analysis | ✅ | `POST /analytics/struggle-topics` — per-topic struggle scores + lecturer recommendations |
| Student risk assessment | ✅ | `POST /analytics/student-risk` — per-student risk scores with factors |
| Lecture dashboard | ⬜ Not started | Visual dashboards for lecturers (struggle topics, at-risk students) |
| Student progress tracker | ⬜ Not started | Per-student engagement and comprehension metrics |
| Adaptive content recommendations | ⬜ Not started | AI-suggested materials based on student performance |
| Real-time analytics push | ⬜ Not started | WebSocket or polling for live dashboard updates |

---

### Phase 4: UI/UX Enhancement (Partially Complete 🔄)

> Student and lecturer interfaces for AI features.

| Milestone | Status | Key Deliverables |
|-----------|--------|------------------|
| AI FAB (Floating Action Button) | ✅ | Course page button opening AI panel |
| Student Q&A panel | ✅ | Chat-style interface with source citations |
| Attachment drawer | ✅ | File upload in chat panel |
| Material viewer panel | ✅ | View PDF, DOCX, images, video, audio in overlay |
| Summary/notes/quiz display | ✅ | Approved AI content visible to students |
| Lecturer approval workflow | ✅ | Approve/reject AI-generated content before publishing |
| Analytics dashboard UI | ⬜ Not started | Lecturer-facing visual analytics |
| Material analysis display | ⬜ Not started | Analysis results shown in viewer panel |
| Workspace view | ⬜ Not started | Full-page AI workspace for students |
| Better mobile responsiveness | ⬜ Not started | Mobile-optimized AI chat and viewer |

---

### Phase 5: Production Hardening (Not Started ⬜)

> Security, performance, monitoring, and deployment readiness.

| Milestone | Priority | Key Deliverables |
|-----------|----------|------------------|
| Rate limiting (AI service side) | High | In-memory rate limiter already implemented; add Redis-backed for production |
| Background task queue | High | Replace FastAPI `BackgroundTasks` with Celery or RQ |
| Error monitoring | High | Structured logging, Sentry integration, admin email alerts |
| Input sanitization audit | High | Audit all endpoints for injection vectors (prompt injection, path traversal) |
| API pagination | Medium | Add pagination to analysis list, chat log queries |
| HTTPS/TLS | Medium | Production HTTPS for Moodle and AI service |
| Backup strategy | Medium | Database backup, ChromaDB snapshot, file backup |
| Load testing | Medium | Concurrent user simulation for Q&A and indexing |
| Docker containerization | Low | Docker Compose for Moodle + AI Service + PostgreSQL + ChromaDB |
| CI/CD pipeline | Low | GitHub Actions for linting, tests, deployment |

---

### Phase 6: Advanced Features (Not Started ⬜)

> Future enhancements beyond the core scope.

| Feature | Description | Priority |
|---------|-------------|----------|
| Multi-language support | French, Twi, Ga, Ewe for lecture summaries | Medium |
| Voice interface | Speech-to-text Q&A for accessibility | Low |
| Offline/mobile app | React Native or Flutter wrapper | Low |
| Peer learning analytics | Group study patterns and recommendations | Low |
| Auto-tagging of course materials | AI-generated taxonomy for materials | Low |
| Integration with other LMS | Canvas, Sakai export | Low |

---

## Timeline (Target)

| Phase | Target | Status |
|-------|--------|--------|
| Phase 1: Foundation | 2026 Q1 | ✅ Complete |
| Phase 2: Material Intelligence | 2026 Q2 | ✅ Complete |
| Phase 3: Analytics & Intelligence | 2026 Q2-Q3 | 🔄 In Progress |
| Phase 4: UI/UX Enhancement | 2026 Q3 | 🔄 In Progress |
| Phase 5: Production Hardening | 2026 Q3-Q4 | ⬜ Not Started |
| Phase 6: Advanced Features | 2026 Q4+ | ⬜ Not Started |

## Academic Deliverables

| Deliverable | Status | Description |
|------------|--------|-------------|
| Chapter 1: Introduction | ✅ Complete | Background, problem statement, aim, objectives, scope |
| Chapter 2: Literature Review | — | Existing work on VLEs, live learning, AI-assisted support |
| Chapter 3: System Design & Methodology | ✅ Complete | Architecture, DSR + Scrum approach, data sources, UI, requirements |
| Chapter 4: Implementation & Testing | 🔄 In Progress | Build, integration, usability testing, performance analysis |
| Chapter 5: Conclusions & Recommendations | ⬜ Not Started | Findings, limitations, future work |

---

## Technology Stack

| Layer | Technology | Purpose |
|-------|-----------|---------|
| LMS | Moodle 5.1.3x LTS | Course management, auth, UI |
| Plugin | `local_umat_ai` (PHP) | All custom functionality |
| Theme | `theme_umat` (SCSS) | UMaT branding |
| AI Service | FastAPI (Python 3.11) | Backend AI processing |
| LLM | Gemini 1.5 Flash / 2.0 Flash | Text generation, embeddings |
| Vector Store | ChromaDB 0.5 | Semantic search |
| Transcription | OpenAI Whisper (local) | Speech-to-text |
| Live Classes | BigBlueButton | Video conferencing |
| Database | PostgreSQL 15/16 | Moodle + AI service data |
| Frontend | AMD JS (RequireJS) | Browser UI components |

---

## Key Metrics

| Metric | Current | Target |
|--------|---------|--------|
| AI Service Uptime | — | 99.5% |
| Q&A Response Time | — | < 3 seconds |
| Recording Processing Time | — | < 30 min for 1hr lecture |
| Material Indexing Time | — | < 10 sec for 50-page PDF |
| Test Coverage | ~15 tests | > 80% coverage |
| Concurrent Users | 1 (dev) | 500+ |
