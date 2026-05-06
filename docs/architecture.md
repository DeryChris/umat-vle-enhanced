# System Architecture

## Overview

The UMaT VLE Enhanced system extends Moodle through a plugin-based architecture. All new functionality is contained in Moodle plugins and an external Python service. Moodle core is never modified — this ensures the system survives Moodle updates.

---

## Components

### 1. Moodle Core

The existing Moodle 4.3 LTS installation. We do not touch any core files. All customisation happens through plugins.

### 2. local_umat_ai Plugin

A Moodle local plugin located at `moodle/public/local/umat_ai/`. It handles:

- Admin settings (AI service URL, API keys, model selection)
- Database table definitions for AI data (sessions, outputs, materials, chat logs)
- Scheduled tasks that communicate with the AI service every 5–15 minutes
- Event observers that react to BBB session endings and file uploads
- Web services that the frontend JavaScript calls for AI queries
- Mustache templates rendered inside Moodle course pages
- AMD JavaScript modules for the chat panel and summary displays
- GDPR privacy provider for data export and deletion

### 3. theme_umat Plugin

A Moodle theme at `moodle/public/theme/umat/`. It:

- Extends Boost (the default Moodle theme)
- Applies UMaT brand colours (navy blue `#003580`, gold `#C8A951`) via SCSS variables
- Overrides specific layout files for the login page and main columns
- Adds no functionality — purely visual

### 4. BigBlueButton Integration

The existing BigBlueButtonBN Moodle plugin (`moodle/mod/bigbluebuttonbn/`) connects Moodle to a BBB server. We do not modify this plugin. Our `local_umat_ai` plugin listens for events that BBB fires (meeting_ended) and triggers AI processing in response.

### 5. Python FastAPI AI Service

A separate Python service at `ai_service/`. It:

- Runs independently on port 8000
- Receives requests from Moodle via HTTP (authenticated with a bearer token)
- Downloads BBB recordings and extracts audio using ffmpeg
- Transcribes audio using OpenAI Whisper (local model, no additional API cost)
- Indexes course materials (PDFs) into ChromaDB vector store
- Answers student questions using RAG (semantic retrieval from ChromaDB + Gemini LLM)
- Generates summaries, notes, and quiz questions from lecture transcripts

### 6. PostgreSQL

Two databases:

- `moodledb` — All Moodle data including the AI plugin's tables (`umat_ai_sessions`, `umat_ai_outputs`, `umat_ai_materials`, `umat_ai_chat_logs`)
- `umat_ai_db` — AI service's own data (processing jobs, indexed documents, chat logs)

### 7. ChromaDB

An embedded vector database stored in `ai_service/chroma_db/`. Each Moodle course gets its own ChromaDB collection. Stores text embeddings of course material chunks for fast semantic search during Q&A.

---

## Data Flow Diagrams

### When a Lecturer Uploads a PDF

```
Lecturer uploads PDF to Moodle course
    ↓
core\event\course_module_created fires
    ↓
local_umat_ai event observer records the file in umat_ai_materials table
    ↓
Scheduled task (index_materials, runs every 15 min) picks it up
    ↓
Moodle plugin sends file to Python service POST /api/v1/materials/index
    ↓
Python service parses PDF, splits into chunks, generates embeddings (Gemini)
    ↓
Embeddings stored in ChromaDB under the course's collection
    ↓
Moodle table updated: is_indexed = 1
```

### When a BBB Live Session Ends

```
Lecturer ends BBB session
    ↓
mod_bigbluebuttonbn\event\meeting_ended fires
    ↓
local_umat_ai event observer creates record in umat_ai_sessions (status: pending)
    ↓
Scheduled task (process_recording, runs every 5 min) picks it up
    ↓
Moodle plugin retrieves BBB recording URL from BBB API
    ↓
Sends recording URL to Python service POST /api/v1/recording/process
    ↓
Python service: downloads recording → extracts audio (ffmpeg) → transcribes (Whisper)
    ↓
Transcript chunked and indexed in ChromaDB
    ↓
LLM generates summary, notes, quiz from transcript (OpenAI)
    ↓
Results stored, awaiting lecturer approval
    ↓
Lecturer approves in Moodle → content published to students
```

### When a Student Asks the AI a Question

```
Student types question in Moodle chat panel
    ↓
AMD JavaScript calls Moodle web service local_umat_ai_ask_question
    ↓
Moodle web service validates: is user enrolled? has capability? rate limit OK?
    ↓
Moodle PHP calls Python service POST /api/v1/query
    ↓
Python service: embeds question → searches ChromaDB for relevant chunks (top 5)
    ↓
Relevant chunks + question sent to Gemini LLM with RAG prompt
    ↓
LLM generates answer constrained to course content only
    ↓
Answer returned to Moodle → displayed in chat panel with source citations
    ↓
Interaction logged in umat_ai_chat_logs table for analytics
```

---

## Database Schema

### Moodle Plugin Tables (in moodledb)

**`mdl_umat_ai_sessions`** — Tracks each BBB session and its processing status

| Column | Type | Description |
|--------|------|-------------|
| id | int | Primary key |
| sessionid | varchar(100) | BBB meeting ID |
| courseid | int | Moodle course ID |
| cmid | int | Course module ID |
| recording_url | text | URL of the BBB recording |
| status | varchar(30) | pending / processing / completed / error |
| timecreated | int | Unix timestamp |
| timemodified | int | Unix timestamp |

**`mdl_umat_ai_outputs`** — Stores AI-generated content per session

| Column | Type | Description |
|--------|------|-------------|
| id | int | Primary key |
| sessionrecordid | int | FK to umat_ai_sessions |
| courseid | int | Moodle course ID |
| output_type | varchar(30) | summary / notes / quiz |
| content | text | The AI-generated content |
| is_approved | tinyint | 0 = pending, 1 = approved |
| approved_by | int | Moodle user ID of approving lecturer |
| timecreated | int | Unix timestamp |
| timepublished | int | Unix timestamp when published |

**`mdl_umat_ai_materials`** — Tracks uploaded course files indexed for RAG

| Column | Type | Description |
|--------|------|-------------|
| id | int | Primary key |
| courseid | int | Moodle course ID |
| fileid | int | Moodle file ID |
| filename | varchar(255) | Original filename |
| is_indexed | tinyint | 0 = not yet indexed, 1 = indexed |
| timecreated | int | Unix timestamp |

**`mdl_umat_ai_chat_logs`** — Student Q&A interactions with AI

| Column | Type | Description |
|--------|------|-------------|
| id | int | Primary key |
| userid | int | Moodle user ID |
| courseid | int | Moodle course ID |
| question | text | The question asked |
| answer | text | The AI response |
| sources | text | JSON array of source document names |
| timecreated | int | Unix timestamp |

### AI Service Tables (in umat_ai_db)

**`processing_jobs`** — Tracks background recording processing

| Column | Type | Description |
|--------|------|-------------|
| id | int | Primary key |
| job_id | varchar(100) | UUID assigned to this job |
| session_id | varchar(100) | BBB meeting ID |
| course_id | int | Moodle course ID |
| recording_url | text | URL of the recording |
| status | varchar(30) | queued / downloading / transcribing / processing_ai / completed / failed |
| transcript | text | Full transcribed text |
| error_message | text | Error details if failed |
| created_at | datetime | When job was created |
| completed_at | datetime | When job completed |

**`indexed_documents`** — Tracks what has been indexed in ChromaDB

| Column | Type | Description |
|--------|------|-------------|
| id | int | Primary key |
| material_id | int | Moodle material ID |
| course_id | int | Moodle course ID |
| filename | varchar(255) | Original filename |
| chunk_count | int | Number of chunks stored in ChromaDB |
| collection_name | varchar(100) | ChromaDB collection name |
| indexed_at | datetime | When indexing was completed |

---

## Security Design

### Authentication and Authorisation

- All Moodle plugin web services use Moodle's built-in capability system (`local/umat_ai:chatwithai`, `local/umat_ai:viewsummary`, `local/umat_ai:approveoutput`, `local/umat_ai:viewanalytics`)
- The Python AI service uses bearer token authentication — the same token is configured in Moodle's plugin settings and the AI service's `.env` file
- API keys (Gemini) are stored in the `.env` file on the server and never exposed to the client

### Rate Limiting

- Students are limited to 10 AI questions per minute per user (enforced in the Moodle web service before calling the AI service)

### Content Approval

- AI-generated summaries, notes, and quiz questions require lecturer approval before students can view them (controlled by the `require_approval` plugin setting)

### Data Privacy (Ghana Data Protection Act 2012)

- Student questions are sent to Gemini servers for processing — this is disclosed in the plugin's privacy provider and the UI
- All student data can be exported and deleted via Moodle's standard privacy tools (implemented in `classes/privacy/provider.php`)

---

## Why Plugins Survive Moodle Updates

When Moodle releases an update:

1. Core files update — your plugin folders (`local/umat_ai/`, `theme/umat/`) are not touched
2. Admin visits `/admin` — Moodle runs its own core database upgrade
3. Your plugin's `db/upgrade.php` runs only if your plugin's version number changed
4. All capabilities, events, tasks, and web services remain registered

The only risk is if Moodle deprecates an API your plugin calls. Monitor `docs.moodle.org/dev/Upgrade_notes` before any core update. This project uses only stable, documented Moodle APIs (external API, scheduled tasks, events, Moodle's CURL wrapper).

---

## Technology Choice Rationale

| Choice | Reason |
|--------|--------|
| Moodle 4.3 LTS | Long-term support — security patches for years; widely used in African universities |
| PostgreSQL over MySQL | Better concurrency and JSON support; the AI service also uses PostgreSQL for consistency |
| FastAPI | Modern, fast, async-capable Python framework; automatic Swagger UI documentation |
| OpenAI Whisper | Runs locally — no per-minute transcription cost; good accuracy on English academic speech |
| ChromaDB | Embedded, no separate server required; easy to reset per-course; good Python integration |
| Gemini-1.5-Flash | Cost-effective — approximately $0.05–0.10 per lecture session for summary + notes + quiz |
| BigBlueButton | Open-source; designed for education; Moodle plugin is maintained by the BBB team |