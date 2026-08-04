# Session: Insights Dashboard Redesign (Phase 1-6 Complete)

**Date**: 2026-07-28
**Scope**: Full redesign of lecturer insights dashboard with What→So What→Now What framework

## What Was Done

### Context
The lecturer insights dashboard had two competing modules (`struggle_dashboard.js` with 4-card layout vs `analytics_dashboard.js` with 5-zone layout) and AI-generated insights that used jargon without clear actionability. Decision was made to consolidate to `analytics_dashboard.js`.

### Phase 1: AI Prompts (analytics.py)
- Rewrote all 6 prompts with plain-language requirements
- Every recommendation must include specific numbers and concrete actions
- Added CRITICAL COMMUNICATION RULES header banning analytics jargon
- `COURSE_HEALTH_PROMPT`: redesigned to return `executive_summary`, `health_grade` (A-F), `going_well`, `needs_attention`, `top_recommendation`
- `STUDENT_RISK_PROMPT`: returns `narrative` (plain-English story) instead of `risk_factors`
- Updated Python schemas: `StudentRiskItem` (added `narrative`), `CourseHealthResponse` (added 7 new fields)

### Phase 2: PHP Backend (get_struggle_insights.php)
- Student risk handler: stores `narrative` and `recommendation` per student; uses AI narrative as primary summary
- Course health: extracts all 7 new fields into top-level result
- `avg_quiz`: now computed from actual `mdl_quiz_grades` via SQL JOIN with fallback
- Added `avg_quiz_source` to indicate data origin
- Added `metric_explanations` map for JS tooltips
- Updated web service returns definition with all new fields

### Phase 3: HTML Template (analytics_dashboard.mustache)
- Added Zone 0: Executive Summary banner with health grade badge, 2-3 sentence briefing, going_well/needs_attention lists

### Phase 4: JS Module (analytics_dashboard.js)
- Added `renderExecutiveSummary()` for new Zone 0
- Added `loadAllCourses()` mode for multi-course overview
- Updated `renderStudentDossiers()` to use `ai_narrative` as primary summary
- Updated `renderCoursePulse()` with new labels ("Avg Performance", "Students at Risk", "Active This Week") + tooltip explanations from `metric_explanations`
- Exported `loadData` and `loadAllCourses` publicly

### Phase 5: CSS (umat-dashboard.css)
- Added ~200 lines of styles for executive summary zone, health grade badge, going_well/needs_attention chips, all-courses grid, pulse source tag, responsive layout

### Phase 6: Integration (overlay_helper.php)
- Switched template rendering from `struggle_dashboard` to `analytics_dashboard`
- Switched AMD module loading from `struggle_dashboard` to `analytics_dashboard`
- Updated all 5 inline JS references from `window.struggleDashboard` to `window.analyticsDashboard`
- Simplified `loadInsights()` fallback to delegate to AMD module
- Updated pane title from "Struggle Dashboard" to "Insights Dashboard"

## Files Modified
- `ai_service/api/v1/routes/analytics.py` — prompts + schemas
- `moodle/public/local/umat_ai/classes/external/get_struggle_insights.php` — PHP enrichment
- `moodle/public/local/umat_ai/templates/analytics_dashboard.mustache` — template redesign
- `moodle/public/local/umat_ai/amd/src/analytics_dashboard.js` — JS module
- `moodle/public/local/umat_ai/styles/umat-dashboard.css` — CSS styles
- `moodle/public/local/umat_ai/classes/overlay_helper.php` — integration

## Next Steps
1. Rebuild minified JS (analytics_dashboard.min.js) via `npx grunt` or manual
2. Run full test suite
3. Clean up dead code: struggle_dashboard.mustache, struggle_dashboard.js (src & build), old get_struggle_dashboard_data endpoint
