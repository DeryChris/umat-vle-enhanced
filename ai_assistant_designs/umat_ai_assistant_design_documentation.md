# UMaT AI Assistant Design System & Documentation

## Project Overview
A modern AI-enhanced learning layer integrated into the UMaT Moodle VLE. This system provides students with real-time lecture assistance and provides lecturers with deep analytics on student performance and learning gaps.

## Visual Identity

### Color Palette
- **Primary (UMaT Green):** `#009846` — Used for brand identity, primary actions, and "AI Ready" states.
- **Secondary (UMaT Gold/Accent):** `#FFCC00` — Used for highlights, alerts, and progress indicators.
- **Surface:** `#F5FBF0` — A soft, tinted green background to differentiate the AI layer from the standard white Moodle UI.
- **Surface Container (Lowest):** `#FFFFFF` — Pure white for cards and high-elevation components.
- **On Surface:** `#1A1C19` — High-contrast text for maximum readability.

### Typography (Inter)
- **Headline Large:** 32px / 40px (Bold) — Dashboard titles.
- **Headline Medium:** 24px / 32px (Bold) — Section headers.
- **Body Large:** 16px / 24px — Primary reading content.
- **Label Medium:** 12px / 16px (Medium) — Captions, tags, and secondary metadata.

### Shape & Elevation
- **Roundness:** `ROUND_EIGHT` (8px) for cards and standard components.
- **Overlays:** `ROUND_24` (24px) for floating bubbles and AI panels to create a friendly, modern "capsule" aesthetic.
- **Elevation:** Low-diffusion shadows for cards; high-diffusion, soft shadows for floating AI overlays to emphasize their "above-the-interface" positioning.

## Core Component Patterns

### 1. Student AI Bubble & Floating Overlay
- **Trigger:** A circular floating action button (FAB) in the bottom-right corner.
- **Panel State:** A floating panel at ~50% viewport height with `backdrop-blur` behind it.
- **Logic:** Preserves course context while allowing chat-based interaction. Supports "Expansion" into the full workspace.

### 2. Expanded AI Workspace
- **Layout:** Two-column split-screen.
- **Left Column:** Media player for lecture recordings with synchronized, searchable transcripts.
- **Right Column:** Tabbed interface for Chat, AI-generated Notes, and Resources.
- **Interaction:** Clicking a transcript timestamp jumps the video to that exact point.

### 3. Lecturer Analytics Dashboard
- **Metric Cards:** High-level KPIs (Active Students, Avg. Session Time, Struggle Index).
- **Heatmaps:** Visual "Struggle Zones" mapped to video timestamps or course modules.
- **Strategic Recommendations:** AI-generated actionable insights (e.g., "Schedule a review for Rock Mechanics Formula").

## User Flows

### Student Journey
1. **Course Interaction:** Student encounters a complex topic in a lecture recording.
2. **AI Assistance:** Student clicks the floating bubble; the AI overlay slides in.
3. **Deep Dive:** Student expands the overlay to the Full Workspace to see detailed notes and timestamped explanations.
4. **General Query:** Student accesses the General AI Hub from the main navigation to review past logs or ask campus-wide questions.

### Lecturer Journey
1. **Performance Monitoring:** Lecturer opens the Analytics Dashboard to see real-time engagement.
2. **Gap Identification:** Lecturer reviews the "Common Questions" section to see what students are asking the AI.
3. **Intervention:** AI recommends a "Participation Alert" for a specific module; Lecturer uses the "Notify Struggling Students" action directly from the UI.

---
*Created with Stitch — AI-Native UI Design System*
