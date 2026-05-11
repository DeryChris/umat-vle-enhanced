# TODO — UMaT VLE Enhanced Project

**Last Updated:** May 11, 2026  
**Priority Levels:** 🔴 Critical | 🟠 High | 🟡 Medium | 🟢 Low  
**Status Labels:** `todo` | `in-progress` | `blocked` | `on-hold`

---

## 🔴 CRITICAL — MVP Blockers (Must Complete Before Release)

### Recording Processing Pipeline Integration
- [ ] **Implement BBB recording URL fetching** `todo` 🔴
  - [ ] Study BBB API documentation for recording retrieval
  - [ ] Add method to query BBB server for recording metadata
  - [ ] Handle recording availability delays (BBB queues processing)
  - [ ] Extract download URL and store in `mdl_umat_ai_sessions.recording_url`
  - **Owner:** Ackon | **Effort:** 4-6 hours | **File:** `moodle/public/local/umat_ai/classes/external/process_recording.php`

- [ ] **Test end-to-end recording pipeline** `todo` 🔴
  - [ ] Setup test course with BBB activity
  - [ ] Record test video, end meeting
  - [ ] Verify event fires and pending record created
  - [ ] Verify scheduled task fetches recording URL
  - [ ] Verify AI Service receives processing request
  - [ ] Verify transcription completes
  - [ ] Verify ChromaDB collection created
  - [ ] Verify AI outputs (summary, notes, quiz) generated
  - **Owner:** Seidu + Ackon | **Effort:** 6-8 hours | **Files:** Integration test script

- [ ] **Create lecturer approval UI** `todo` 🔴
  - [ ] Create page: `moodle/public/local/umat_ai/approve.php`
  - [ ] Query `mdl_umat_ai_outputs` where `approved = 0`
  - [ ] Display generated content (summary, notes, quiz)
  - [ ] Add approve/reject buttons
  - [ ] Store approval in `mdl_umat_ai_approvals`
  - [ ] Add notification to lecturer email on pending approval
  - [ ] Test approval workflow
  - **Owner:** Ackon | **Effort:** 6-8 hours | **Files:** `approve.php`, `approval` table

- [ ] **Validate rate limiting** `todo` 🔴
  - [ ] Test 10 Q&A limit per minute per user in Moodle
  - [ ] Verify AI Service doesn't have conflicting limits
  - [ ] Test concurrent user requests
  - [ ] Ensure graceful error message when limit exceeded
  - **Owner:** Seidu + Ackon | **Effort:** 2-3 hours | **Files:** `external/ai_query.php`, tests

### Error Handling & Recovery
- [ ] **Add retry logic for failed AI jobs** `todo` 🔴
  - [ ] Implement exponential backoff (3 retries, 1s/2s/4s)
  - [ ] Handle API timeouts gracefully
  - [ ] Log detailed errors for debugging
  - [ ] Notify admin if job fails after retries
  - **Owner:** Seidu | **Effort:** 3-4 hours | **File:** `api/v1/routes/recording.py`

- [ ] **Handle network failures** `todo` 🔴
  - [ ] Test BBB API timeout
  - [ ] Test Gemini API timeout
  - [ ] Test PostgreSQL connection loss
  - [ ] Test ChromaDB unavailability
  - Verify graceful degradation in each case
  - **Owner:** Seidu + Ackon | **Effort:** 4-6 hours | **Files:** All API routes

### Security & Secrets
- [ ] **Verify no secrets in repository** `todo` 🔴
  - [ ] Scan for `.env` files committed
  - [ ] Scan for API keys in code
  - [ ] Verify `.gitignore` excludes secrets
  - [ ] If found, use `git filter-branch` to remove from history
  - **Owner:** Chrispen | **Effort:** 1 hour | **Files:** `.gitignore`, all Python/PHP files

- [ ] **Setup environment variable validation** `todo` 🔴
  - [ ] Verify all required `.env` vars on startup
  - [ ] Exit with clear error message if missing
  - [ ] Document all required variables
  - **Owner:** Seidu | **Effort:** 1-2 hours | **File:** `config.py`

---

## 🟠 HIGH PRIORITY (Must Complete Next Sprint)

### Testing & Quality Assurance
- [ ] **Add comprehensive unit tests for AI Service** `todo` 🟠
  - [ ] Test transcription module (mock Whisper)
  - [ ] Test vector store similarity search
  - [ ] Test LLM prompt generation
  - [ ] Test document chunking edge cases
  - [ ] Achieve 60%+ code coverage
  - **Owner:** Seidu | **Effort:** 8-10 hours | **File:** `tests/` directory

- [ ] **Add integration tests for Moodle plugin** `todo` 🟠
  - [ ] Test web service authentication
  - [ ] Test web service calls from JavaScript
  - [ ] Test rate limiting enforcement
  - [ ] Test event observer triggers
  - [ ] Test scheduled task execution
  - **Owner:** Ackon | **Effort:** 8-10 hours | **Files:** Tests in `classes/` or separate test suite

- [ ] **Test chat panel UI functionality** `todo` 🟠
  - [ ] Verify template renders on course page
  - [ ] Test sending a question
  - [ ] Test receiving and displaying answer
  - [ ] Test error message display
  - [ ] Test loading states
  - **Owner:** Johnson | **Effort:** 4-6 hours | **Files:** `templates/ai_chat_panel.mustache`, `amd/src/ai_chat_panel.js`

### AI Service Stability
- [ ] **Optimize Whisper model loading** `todo` 🟠
  - [ ] Measure memory usage with different model sizes
  - [ ] Consider lazy-loading for memory-constrained environments
  - [ ] Document memory requirements
  - **Owner:** Seidu | **Effort:** 2-3 hours | **File:** `core/transcription.py`

- [ ] **Test ChromaDB performance** `todo` 🟠
  - [ ] Load test with 1000+ documents per course
  - [ ] Measure query latency
  - [ ] Test concurrent access patterns
  - [ ] Document performance bottlenecks
  - **Owner:** Seidu | **Effort:** 4-6 hours | **Files:** Tests, benchmarks

- [ ] **Validate Gemini API integration** `todo` 🟠
  - [ ] Test prompt generation quality
  - [ ] Test edge cases (very short transcript, very long transcript)
  - [ ] Test timeout/quota handling
  - [ ] Document cost estimates
  - **Owner:** Seidu | **Effort:** 3-4 hours | **Files:** `core/llm_processor.py`, tests

### Documentation
- [ ] **Create admin setup guide** `todo` 🟠
  - [ ] Step-by-step: install plugin, configure settings
  - [ ] Screenshots of admin page
  - [ ] Troubleshooting common setup issues
  - **Owner:** Chrispen | **Effort:** 2-3 hours | **Files:** `docs/admin-setup.md`

- [ ] **Create lecturer guide** `todo` 🟠
  - [ ] How to upload BBB recordings
  - [ ] How to review/approve AI outputs
  - [ ] How to share outputs with students
  - [ ] Troubleshooting Q&A issues
  - **Owner:** Agartha | **Effort:** 2-3 hours | **Files:** `docs/lecturer-guide.md`

- [ ] **Create student user guide** `todo` 🟠
  - [ ] How to access AI chat panel
  - [ ] How to ask questions
  - [ ] Q&A tips (specific vs general, etc.)
  - [ ] What sources are used
  - **Owner:** Agartha | **Effort:** 1-2 hours | **Files:** `docs/student-guide.md`

- [ ] **Update API documentation with examples** `todo` 🟠
  - [ ] Add cURL examples for all endpoints
  - [ ] Add Postman collection
  - [ ] Add response examples
  - **Owner:** Seidu | **Effort:** 2-3 hours | **Files:** `docs/api.md`

---

## 🟡 MEDIUM PRIORITY (Sprint After Next)

### Performance Optimization
- [ ] **Implement caching for common Q&A** `todo` 🟡
  - [ ] Cache frequently asked questions
  - [ ] Cache LLM responses (use hash of question)
  - [ ] Set appropriate TTL (e.g., 24 hours)
  - [ ] Monitor cache hit rate
  - **Owner:** Seidu | **Effort:** 4-6 hours | **File:** `core/llm_processor.py`

- [ ] **Optimize vector search queries** `todo` 🟡
  - [ ] Implement pagination for large result sets
  - [ ] Add query result re-ranking
  - [ ] Consider parent-child document retrieval
  - **Owner:** Seidu | **Effort:** 6-8 hours | **File:** `core/vector_store.py`

- [ ] **Profile and optimize database queries** `todo` 🟡
  - [ ] Identify slow queries
  - [ ] Add indexes where needed
  - [ ] Test with large datasets
  - **Owner:** Seidu | **Effort:** 3-4 hours | **Files:** `models/database.py`, migration scripts

### Attendance Tracking
- [ ] **Implement automatic attendance recording** `todo` 🟡
  - [ ] Query BBB API for meeting participant list
  - [ ] Map BBB users to Moodle users
  - [ ] Create attendance records in Moodle attendance module
  - [ ] Handle user mapping edge cases
  - **Owner:** Ackon | **Effort:** 8-10 hours | **File:** `classes/external/attendance.php`

- [ ] **Test attendance tracking end-to-end** `todo` 🟡
  - [ ] Create test meeting, join as multiple users
  - [ ] Verify attendance recorded correctly
  - [ ] Check Moodle attendance report
  - **Owner:** Ackon | **Effort:** 2-3 hours | **Files:** Tests

### Material Management
- [ ] **Build material upload UI** `todo` 🟡
  - [ ] Create page for batch uploading PDFs
  - [ ] Show indexing progress
  - [ ] Allow deletion of indexed materials
  - [ ] Display material metadata
  - **Owner:** Agartha | **Effort:** 6-8 hours | **File:** `moodle/public/local/umat_ai/materials.php`

- [ ] **Implement material versioning** `todo` 🟡
  - [ ] Track material versions in database
  - [ ] Allow rollback to previous version
  - [ ] Show update history to lecturers
  - **Owner:** Ackon | **Effort:** 3-4 hours | **File:** `db/upgrade.php` (new table)

---

## 🟢 LOW PRIORITY (Future Enhancements)

### Advanced AI Features
- [ ] **Train custom LLM on UMaT academic content** `on-hold` 🟢
  - [ ] Collect training dataset
  - [ ] Fine-tune Gemini model
  - [ ] Evaluate performance vs base model
  - [ ] Document cost-benefit analysis
  - **Owner:** Seidu | **Effort:** 20+ hours | **Files:** N/A (external)

- [ ] **Implement semantic caching** `on-hold` 🟢
  - [ ] Cache semantically similar queries together
  - [ ] Reuse cached answers for similar questions
  - [ ] Measure cache hit improvement
  - **Owner:** Seidu | **Effort:** 6-8 hours

### Dashboard & Analytics
- [ ] **Build lecturer dashboard** `todo` 🟢
  - [ ] Show processing status of recordings
  - [ ] Show Q&A volume and trending questions
  - [ ] Show student engagement metrics
  - [ ] Show model performance stats
  - **Owner:** Chrispen | **Effort:** 8-10 hours | **File:** `moodle/public/local/umat_ai/dashboard.php`

- [ ] **Build admin analytics page** `todo` 🟢
  - [ ] System health metrics (API latency, error rates)
  - [ ] Cost tracking (API calls, tokens used)
  - [ ] User statistics (active users, total Q&A)
  - [ ] Performance metrics (average response time)
  - **Owner:** Chrispen | **Effort:** 6-8 hours | **File:** `moodle/public/local/umat_ai/analytics.php`

### Export & Sharing
- [ ] **Export AI outputs to PDF** `todo` 🟢
  - [ ] Generate PDF for summary, notes, quiz
  - [ ] Include formatting and branding
  - [ ] Add download button in UI
  - **Owner:** Agartha | **Effort:** 3-4 hours | **File:** `classes/pdf_generator.php`

- [ ] **Share outputs with student groups** `todo` 🟢
  - [ ] Create sharing interface
  - [ ] Track who has viewed outputs
  - [ ] Allow students to provide feedback
  - **Owner:** Ackon | **Effort:** 4-5 hours | **File:** `classes/sharing.php`

### Multilingual Support
- [ ] **Add support for other languages** `on-hold` 🟢
  - [ ] Translate UI to Twi, Hausa (African languages)
  - [ ] Test with multilingual courses
  - [ ] Add language selection to settings
  - **Owner:** Johnson | **Effort:** 10+ hours | **Files:** `lang/` directory

### Mobile
- [ ] **Build mobile app (future)** `on-hold` 🟢
  - [ ] Native iOS app
  - [ ] Native Android app
  - [ ] Offline Q&A capability
  - [ ] Push notifications
  - **Owner:** TBD | **Effort:** 40+ hours | **Files:** Separate repo

---

## 🔧 INFRASTRUCTURE & DEVOPS

### Deployment
- [ ] **Containerize AI Service with Docker** `todo` 🟠
  - [ ] Create Dockerfile for FastAPI app
  - [ ] Create docker-compose.yml with PostgreSQL, ChromaDB
  - [ ] Test container build and startup
  - [ ] Document container environment vars
  - **Owner:** Chrispen | **Effort:** 4-6 hours | **Files:** `Dockerfile`, `docker-compose.yml`

- [ ] **Setup CI/CD pipeline** `todo` 🟡
  - [ ] GitHub Actions workflow for tests
  - [ ] Automated deployment to staging
  - [ ] Automated deployment to production (manual trigger)
  - [ ] Linting and code quality checks
  - **Owner:** Chrispen | **Effort:** 6-8 hours | **Files:** `.github/workflows/`

### Monitoring & Logging
- [ ] **Setup application logging** `todo` 🟡
  - [ ] Centralize logs from AI Service and Moodle
  - [ ] Setup log rotation
  - [ ] Add structured logging (JSON)
  - [ ] Create log analysis dashboards
  - **Owner:** Chrispen | **Effort:** 4-6 hours | **Files:** Logging config

- [ ] **Setup monitoring & alerting** `todo` 🟡
  - [ ] Monitor API response times
  - [ ] Monitor error rates
  - [ ] Monitor disk/memory usage
  - [ ] Setup alerts for critical issues
  - [ ] Create runbook for on-call support
  - **Owner:** Chrispen | **Effort:** 6-8 hours | **Files:** Monitoring config

### Backup & Disaster Recovery
- [ ] **Implement database backup strategy** `todo` 🟡
  - [ ] Automated daily backups of PostgreSQL
  - [ ] Store backups to external location
  - [ ] Test restore procedure
  - [ ] Document backup/restore process
  - **Owner:** Chrispen | **Effort:** 3-4 hours | **Files:** Backup scripts

- [ ] **Implement ChromaDB backup strategy** `todo` 🟡
  - [ ] Regular snapshots of vector database
  - [ ] Test restore procedure
  - [ ] Document recovery time objective (RTO)
  - **Owner:** Chrispen | **Effort:** 2-3 hours | **Files:** Backup scripts

---

## 🔐 SECURITY

### Code Security
- [ ] **Perform security audit** `todo` 🟠
  - [ ] Review all API endpoints for auth/authz issues
  - [ ] Check for SQL injection vulnerabilities
  - [ ] Check for XSS vulnerabilities in UI
  - [ ] Verify rate limiting prevents abuse
  - [ ] Check token expiration and rotation
  - **Owner:** All (peer review) | **Effort:** 8-10 hours

- [ ] **Add OWASP security headers** `todo` 🟡
  - [ ] Add CSP (Content Security Policy)
  - [ ] Add CORS properly configured
  - [ ] Add security headers (HSTS, X-Frame-Options, etc.)
  - [ ] Test with security scanning tools
  - **Owner:** Chrispen | **Effort:** 2-3 hours | **File:** `main.py`, Apache config

### Data Privacy
- [ ] **Implement GDPR compliance** `todo` 🟡
  - [ ] User data export functionality (complete)
  - [ ] User data deletion (complete)
  - [ ] Data retention policy
  - [ ] Privacy policy update
  - [ ] Document data processing activities
  - **Owner:** Ackon + Chrispen | **Effort:** 6-8 hours

- [ ] **Encrypt sensitive data at rest** `todo` 🟡
  - [ ] Encrypt API keys in database
  - [ ] Encrypt chat logs
  - [ ] Document encryption strategy
  - **Owner:** Seidu | **Effort:** 3-4 hours

---

## 📝 DOCUMENTATION & KNOWLEDGE TRANSFER

### User Documentation
- [ ] **Create comprehensive user manual** `todo` 🟡
  - [ ] Admin setup guide (done ✓)
  - [ ] Lecturer guide (done ✓)
  - [ ] Student guide (done ✓)
  - [ ] Troubleshooting appendix
  - [ ] FAQ section

- [ ] **Create video tutorials** `todo` 🟢
  - [ ] Setup video for admins
  - [ ] Getting started video for lecturers
  - [ ] Q&A tutorial for students

### Developer Documentation
- [ ] **Update architecture documentation** `todo` 🟡
  - [ ] Add sequence diagrams for key workflows
  - [ ] Document database schema
  - [ ] Add deployment architecture
  - **Files:** `docs/architecture.md`

- [ ] **Create developer onboarding guide** `todo` 🟡
  - [ ] How to setup dev environment
  - [ ] How to run tests
  - [ ] How to debug issues
  - [ ] Team conventions and code standards
  - **Files:** `docs/developer-guide.md`

- [ ] **Document API contracts** `todo` 🟡
  - [ ] List all endpoints with versions
  - [ ] Document breaking changes policy
  - [ ] Add deprecation notice template
  - **Files:** `docs/api-contracts.md`

---

## 📊 WEEKLY SPRINT PLANNING

### Sprint Template

Each sprint should:
1. Start with Monday planning meeting
2. Focus on 1-2 critical items from 🔴 list
3. Include 1-2 items from 🟠 list for progress
4. Include 1 item from 🟡 list for future work
5. End with Friday review & retrospective

### Recommended Sprint Schedule

**Sprint 1 (This Week):** Recording pipeline integration + approval UI  
**Sprint 2:** Testing & quality assurance  
**Sprint 3:** Attendance tracking + material management  
**Sprint 4:** Deployment & DevOps  
**Sprint 5:** Performance optimization  
**Sprint 6+:** Features & enhancements  

---

## ✅ COMPLETION CHECKLIST (Before Going Live)

- [ ] All 🔴 critical items completed and tested
- [ ] Code coverage >60% (Python), >50% (PHP)
- [ ] No open security issues
- [ ] All endpoints documented in Swagger
- [ ] Admin setup guide complete and tested
- [ ] Lecturer guide complete and tested
- [ ] Student guide complete and tested
- [ ] Email notifications working
- [ ] Error logging & monitoring setup
- [ ] Backup/restore tested
- [ ] Load testing passed
- [ ] GDPR compliance verified
- [ ] Performance acceptable (response time <2s for Q&A)
- [ ] All team members trained on troubleshooting
- [ ] Go/no-go decision made by supervisor

---

## 📞 QUESTIONS & ESCALATIONS

- **For technical blockers:** Tag Seidu ([@kinseidu](https://github.com/kinseidu))
- **For infrastructure issues:** Tag Chrispen ([@derychris](https://github.com/derychris))
- **For UI/UX issues:** Tag Agartha & Johnson
- **For project direction:** Dr. Emmanuel Effah (Supervisor)

See [AGENTS.md](AGENTS.md) for quick decision trees on common issues.
