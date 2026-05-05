# AI Service API Reference

**Base URL:** `http://localhost:8000`

All endpoints except `GET /api/v1/health` require Bearer token authentication.

**Authentication header:**
```
Authorization: Bearer your-service-token
```

The token is set in `ai_service/.env` as `AI_SERVICE_TOKEN` and must match the value configured in Moodle's plugin settings (Site Admin → Plugins → Local plugins → UMaT AI Academic Support → AI Service Token).

**Full interactive API documentation** with request/response examples is available at `http://localhost:8000/docs` when the service is running (Swagger UI).

---

## Endpoints

### GET /api/v1/health

Check if the service is running. No authentication required. Use this to confirm the service is up before testing other endpoints.

**Request:** No body required

**Response:**
```json
{
  "status": "healthy",
  "version": "1.0.0",
  "whisper_model": "base",
  "llm_model": "gpt-4o-mini"
}
```

**Test in Postman:**
- Method: GET
- URL: `http://localhost:8000/api/v1/health`
- No Authorization header needed

---

### POST /api/v1/materials/index

Upload a course material file (PDF or text) and index it into ChromaDB so students can ask questions about it.

**Request:** `multipart/form-data`

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| course_id | integer | Yes | Moodle course ID |
| material_id | integer | Yes | Moodle file/resource ID |
| filename | string | Yes | Original filename (e.g., `lecture1.pdf`) |
| file | file | Yes | The actual PDF or .txt file |

**Supported file types:** `.pdf`, `.txt`, `.md`

**Response:**
```json
{
  "success": true,
  "chunks_indexed": 47,
  "message": "Successfully indexed 47 chunks from lecture1.pdf"
}
```

**Error responses:**
- `400` — Unsupported file type or empty file
- `401` — Invalid or missing bearer token

**Test in Postman:**
- Method: POST
- URL: `http://localhost:8000/api/v1/materials/index`
- Authorization: Bearer `your-token`
- Body: form-data with fields: `course_id=2`, `material_id=1`, `filename=test.pdf`, `file=[select a PDF]`

---

### POST /api/v1/query

Answer a student question using RAG. The service searches ChromaDB for relevant content from the course's indexed materials and lecture transcripts, then sends those chunks to the LLM to generate a contextually grounded answer.

**Request body (JSON):**
```json
{
  "question": "What is the difference between RAM and ROM?",
  "course_id": 3,
  "user_id": 42
}
```

| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| question | string | 3–1000 characters | The student's question |
| course_id | integer | — | Moodle course ID |
| user_id | integer | — | Moodle user ID (for logging) |

**Response:**
```json
{
  "answer": "Based on your course materials, RAM (Random Access Memory) is volatile memory that loses its contents when power is removed, while ROM (Read-Only Memory) retains its contents without power...",
  "sources": ["lecture3_transcript", "computer_architecture_notes.pdf"],
  "confidence": 0.85
}
```

**Response when no materials are indexed:**
```json
{
  "answer": "No course materials have been indexed for this course yet. Please ask your lecturer to upload course materials.",
  "sources": [],
  "confidence": 0.0
}
```

**Test in Postman:**
- Method: POST
- URL: `http://localhost:8000/api/v1/query`
- Authorization: Bearer `your-token`
- Body: raw JSON (see above)

---

### POST /api/v1/recording/process

Submit a BBB recording URL for full processing: download → audio extraction → Whisper transcription → ChromaDB indexing → AI summary/notes/quiz generation.

Processing happens in the background. The endpoint returns a `job_id` immediately. Use the status endpoint to check progress.

**Request body (JSON):**
```json
{
  "session_id": "abc123-bbb-meeting-id",
  "recording_url": "https://bbb.server.com/recording/abc123.mp4",
  "course_id": 3,
  "material_ids": [1, 2, 3]
}
```

| Field | Type | Description |
|-------|------|-------------|
| session_id | string | BBB meeting ID |
| recording_url | string | Direct URL to the recording file |
| course_id | integer | Moodle course ID |
| material_ids | array of int | IDs of already-indexed materials (for additional context) |

**Response:**
```json
{
  "job_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "queued",
  "message": "Recording processing started"
}
```

**Test in Postman:**
- Method: POST
- URL: `http://localhost:8000/api/v1/recording/process`
- Authorization: Bearer `your-token`
- Body: raw JSON (see above)

---

### GET /api/v1/recording/status/{job_id}

Check the status of a recording processing job.

**Path parameter:** `job_id` — the UUID returned by `/api/v1/recording/process`

**Response:**
```json
{
  "job_id": "550e8400-e29b-41d4-a716-446655440000",
  "session_id": "abc123-bbb-meeting-id",
  "status": "completed",
  "created_at": "2024-12-01T10:00:00",
  "completed_at": "2024-12-01T10:15:32",
  "error": null
}
```

**Possible status values:**

| Status | Meaning |
|--------|---------|
| `queued` | Job is waiting to be processed |
| `downloading` | Downloading the recording from BBB |
| `transcribing` | Extracting audio and running Whisper |
| `processing_ai` | Generating summary, notes, quiz via LLM |
| `completed` | All processing done successfully |
| `failed` | An error occurred — see `error` field |

**Test in Postman:**
- Method: GET
- URL: `http://localhost:8000/api/v1/recording/status/550e8400-e29b-41d4-a716-446655440000`
- Authorization: Bearer `your-token`

---

### DELETE /api/v1/materials/{material_id}

Remove a material from the ChromaDB vector index. Does not delete the file from Moodle — only removes it from the AI search index.

**Path parameter:** `material_id` — the Moodle material ID

**Query parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| course_id | integer | Moodle course ID |
| filename | string | The original filename used when indexing |

**Response:**
```json
{
  "success": true,
  "message": "Material removed from index"
}
```

---

## Postman Collection Setup

Create a collection named **"UMaT AI Service"** with these collection-level variables:

| Variable | Value |
|----------|-------|
| `base_url` | `http://localhost:8000` |
| `token` | your AI service token |

Then add requests as described above using `{{base_url}}` and `{{token}}` in your URLs and Authorization headers. This makes it easy to switch between environments without changing every request manually.

---

## Error Reference

| HTTP Code | Meaning |
|-----------|---------|
| 200 | Success |
| 400 | Bad request — invalid input (e.g., unsupported file type, empty file) |
| 401 | Unauthorized — wrong or missing bearer token |
| 403 | Forbidden — no Authorization header at all |
| 404 | Not found — job_id or material does not exist |
| 422 | Validation error — missing required field or wrong data type |
| 500 | Internal server error — check `ai_service.log` for details |

---

## Cost Estimates (OpenAI API)

| Operation | Approximate Cost |
|-----------|-----------------|
| 1-hour lecture summary + notes + quiz | $0.05 – $0.10 |
| 10 student Q&A questions | $0.01 – $0.02 |
| Indexing a 50-page PDF (embeddings) | $0.005 |

Whisper transcription is **free** — it runs locally on your machine using the downloaded model.

Set a hard spending limit on your OpenAI account dashboard during development to avoid unexpected charges.