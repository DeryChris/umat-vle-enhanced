# UMaT VLE Enhanced — UI/UX Architecture

**Last Updated**: 2026-06-11  
**Audience**: New developers, designers, and anyone wanting to understand how the plugin's user interface works  
**Key Concept**: Everything is **overlay-based** — panels appear on top of the page, not as separate pages

---

## Table of Contents

1. [What is an Overlay-Based UI?](#1-what-is-an-overlay-based-ui)
2. [The Core Components](#2-the-core-components)
3. [How Components Are Loaded](#3-how-components-are-loaded)
4. [The Three Viewing Modes](#4-the-three-viewing-modes)
5. [Component Deep Dives](#5-component-deep-dives)
6. [Data Flow Diagrams](#6-data-flow-diagrams)
7. [CSS Architecture & Design System](#7-css-architecture--design-system)
8. [AMD Module Architecture](#8-amd-module-architecture)
9. [Inter-Module Communication](#9-inter-module-communication)
10. [Why This Approach Was Chosen](#10-why-this-approach-was-chosen)

---

## 0. UI in the Context of System Architecture

The UI is the **Presentation Layer** of the 5-layer system architecture defined in the thesis (Chapter 3):

```
┌──────────────────────────────────────────────────────────┐
│  Presentation Layer  (HTML/CSS/JS — this document)        │
│  Moodle UI + Overlay panels + FAB + Chat + Viewer         │
├──────────────────────────────────────────────────────────┤
│  Application Layer  (Moodle PHP)                          │
│  Auth, courses, sessions, request distribution             │
├──────────────────────────────────────────────────────────┤
│  Integration Layer  (RESTful APIs)                        │
│  Moodle ↔ AI Service ↔ BigBlueButton                      │
├──────────────────────────────────────────────────────────┤
│  Processing AI Layer  (Python/FastAPI)                    │
│  Whisper ASR, Gemini LLM, ChromaDB RAG                    │
├──────────────────────────────────────────────────────────┤
│  Data Layer  (PostgreSQL + ChromaDB)                      │
│  Users, courses, sessions, transcripts, embeddings         │
└──────────────────────────────────────────────────────────┘
```

The UI communicates **only** with the Application Layer (Moodle PHP) via AJAX calls to Moodle web services. Moodle then proxies requests to the AI Service or BigBlueButton. This maintains separation of concerns and security boundaries.

Key design decisions from the thesis:
- **Extensions to existing Moodle UI** — rather than a new interface, we extend Moodle through plugins, themes, and templates
- **All interactions in one place** — GenAI panels, summaries, and dashboards live within Moodle course pages
- **RESTful communication** — frontend ↔ Moodle ↔ AI Service, all via APIs
- **No page refreshes** — AJAX calls render results immediately in overlays

---

## 1. What is an Overlay-Based UI?

### The Problem

Moodle is a page-based system. Every action (viewing a course, reading a forum, checking grades) requires loading a **new page**. This is slow and interrupts the user's flow. A student watching a lecture video shouldn't have to leave the page to ask a question.

### The Solution: Overlays

Instead of navigating to new pages, our plugin displays **panels that float on top** of the current page:

```
┌──────────────────────────────────────────────────────┐
│  Normal Moodle Course Page                           │
│                                                       │
│  ┌──────────────────────────────────────┐             │
│  │  Course Content                      │             │
│  │                                       │             │
│  │                                       │     ┌──────┐│
│  │                                       │     │      ││
│  │                                       │     │  FAB  ││
│  │                                       │     │ (btn) ││
│  │                                       │     └──────┘│
│  └──────────────────────────────────────┘             │
│                                                       │
└──────────────────────────────────────────────────────┘

              │  User clicks FAB button
              ▼

┌──────────────────────────────────────────────────────┐
│  Moodle Page (still visible underneath, now dimmed)   │
│  ┌ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ┐  │
│  │     Semi-transparent backdrop (blur)             │  │
│  │                                                   │  │
│  │     ┌────────────────────────────────────┐        │  │
│  │     │  AI Assistant Panel (slides in)     │        │  │
│  │     │  ────────────────────────────       │        │  │
│  │     │  Chat  │ Notes │ Resources          │        │  │
│  │     │  ────────────────────────────       │        │  │
│  │     │                                      │        │  │
│  │     │  [Chat messages here]                │        │  │
│  │     │                                      │        │  │
│  │     │  ┌──────────────────────────┐        │        │  │
│  │     │  │ Type your question...    │[send]  │        │  │
│  │     │  └──────────────────────────┘        │        │  │
│  │     └────────────────────────────────────┘        │  │
│  └ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ┘  │
└──────────────────────────────────────────────────────┘
```

This means:
- The **Moodle page stays open** underneath (dimmed)
- The **overlay panel slides in** from the right
- The user can **close it and return** to exactly where they were
- No page reloads, no navigation away

### How This Compare to Traditional Web UIs

| Approach | What Happens | User Experience |
|----------|-------------|-----------------|
| **Traditional** (Moodle default) | Click → new page loads → read → back button → click next thing | Slow, disorienting |
| **Overlay-based** (our plugin) | Click → panel appears on top → interact → close → still on same page | Fast, stays in context |
| **Modal** (common pattern) | Click → dialog box appears → must dismiss before doing anything else | Blocking, interrupts flow |
| **Sidebar** (like Gmail chat) | Click → sidebar slides out → can still interact with main page | Non-blocking, persistent |

Our approach is a **hybrid of modal and sidebar** — it's a semi-modal overlay (backdrop prevents interacting with the page behind) but slides in smoothly from the right like a sidebar.

---

## 2. The Core Components

Our plugin has **6 main UI components** that work together:

```
                    ┌──────────────────────────────────────┐
                    │         FAB (Floating Action Button)  │
                    │         The green circle button       │
                    │         "Ask UMaT AI Assistant"       │
                    └──────────────┬───────────────────────┘
                                   │ click
                                   ▼
          ┌────────────────────────────────────────────────┐
          │           AI Workspace Overlay                  │
          │  ┌────────────────────────────────────────────┐ │
          │  │  Panel slides in from right                │ │
          │  │  ┌──────────┬──────────┬──────────────┐    │ │
          │  │  │ Chat Tab │Notes Tab │Resources Tab │    │ │
          │  │  └──────────┴──────────┴──────────────┘    │ │
          │  │  ┌──────────────────────────────────┐      │ │
          │  │  │ Messages (chat bubbles)          │      │ │
          │  │  │ Quick Action buttons             │      │ │
          │  │  │ Text input + send button         │      │ │
          │  │  └──────────────────────────────────┘      │ │
          │  └────────────────────────────────────────────┘ │
          └─────────────────────┬──────────────────────────┘
                                │ click "Expand"
                                ▼
          ┌────────────────────────────────────────────────┐
          │         Expanded Workspace (Full Page)          │
          │  ┌─────────────────────┬──────────────────────┐ │
          │  │  Left Panel         │  Right Panel (AI)    │ │
          │  │  ┌───────────────┐  │  ┌────────────────┐ │ │
          │  │  │ Video Player  │  │  │ Chat/Notes/etc │ │ │
          │  │  ├───────────────┤  │  ├────────────────┤ │ │
          │  │  │ Transcript    │  │  │ Input area     │ │ │
          │  │  └───────────────┘  │  └────────────────┘ │ │
          │  └─────────────────────┴──────────────────────┘ │
          └────────────────────────────────────────────────┘
```

### The 6 Components at a Glance

| # | Component | What It Does | Where It Lives |
|---|-----------|-------------|----------------|
| 1 | **FAB Button** | Green circle button, sits in bottom-right corner of every course page | `amd/src/ai_fab_injector.js` or `templates/ai_fab.mustache` |
| 2 | **Chat Panel** | The Q&A interface — type questions, get AI answers with source citations | `amd/src/ai_chat_panel.js`, `templates/ai_chat_panel.mustache` |
| 3 | **Workspace Overlay** | The full AI panel with Chat/Notes/Resources tabs, quick actions | `templates/ai_fab.mustache`, `amd/src/ai_fab.js` |
| 4 | **Expanded Workspace** | Full-page view with video player + transcript + AI sidebar | `templates/ai_workspace.mustache`, `amd/src/ai_workspace.js` |
| 5 | **Material Viewer** | Overlay for viewing course files (PDF, DOCX, images, video) | `amd/src/material_viewer.js`, `amd/src/umatshared.js` |
| 6 | **Attachment Drawer** | File picker that slides up from the chat input area | Built into `umat-overlay.css` and `umatshared.js` |

---

## 3. How Components Are Loaded

### The Loading Chain

```
1. User visits a Moodle course page
         │
2. Moodle detects our plugin is installed
         │
3. PHP code (lib.php or hook in before_footer.php) runs
         │
4. PHP checks: does this user have permission to use AI?
         │
5. PHP renders our Mustache template(s) 
         │
6. PHP injects JavaScript AMD module(s) into the page
         │
7. Browser loads the AMD modules (RequireJS)
         │
8. AMD modules create the DOM elements (FAB, overlay, panel)
         │
9. AMD modules set up event listeners (click, type, send)
         │
10. User interacts with the UI → AMD sends AJAX calls to Moodle web services
         │
11. Moodle web services call the Python AI Service
         │
12. Response comes back → AMD module updates the UI
```

### Entry Point: `lib.php`

The `lib.php` file is Moodle's standard hook for local plugins. When Moodle renders a course page, it automatically loads functions from it.

```php
// lib.php (simplified flow)
function local_umat_ai_before_footer() {
    // 1. Check if user has capability
    if (!has_capability('local/umat_ai:chatwithai', $context)) {
        return; // Don't load anything
    }
    
    // 2. Get course info
    $courseId = $PAGE->course->id;
    $courseName = $PAGE->course->fullname;
    
    // 3. Render the AI FAB template
    echo $OUTPUT->render_from_template('local_umat_ai/ai_fab', [
        'courseid' => $courseId,
        'coursename' => $courseName,
    ]);
    
    // OR: Inject JS that creates the FAB dynamically
    $PAGE->requires->js_amd_inline("
        require(['local_umat_ai/ai_fab_injector'], function(Fab) {
            Fab.init($courseId, '$courseName');
        });
    ");
}
```

### Two Ways to Render UI

We use **two approaches** depending on the situation:

**Approach A: Mustache Template Rendering** (for `ai_fab.mustache`)
```
PHP → Mustache template → HTML + CSS + inline JS → Browser
```
- Used when we want clean, maintainable HTML
- CSS is embedded in the template `<style>` block
- JavaScript event handlers are inline `<script>` blocks
- Good for complex UI panels

**Approach B: AMD Module DOM Creation** (for `ai_fab_injector.js`)
```
PHP → AMD module → JavaScript creates DOM elements → Browser
```
- Used when we need dynamic, programmatic UI
- All HTML is constructed in JavaScript
- More flexible but harder to maintain
- Good for simple elements like the FAB button

### Why Both Approaches Exist

The plugin grew organically. Earlier versions used AMD module creation (Approach B). Later, we moved to Mustache templates (Approach A) for better separation of concerns. Both still work because:

- **Approach A** templates are loaded via `ai_fab.mustache`
- **Approach B** modules like `ai_fab_injector.js` provide a lightweight fallback
- **`ai_chat_panel.mustache`** has its own inline CSS and JS (self-contained component)

---

## 4. The Three Viewing Modes

Our plugin provides **three levels** of AI interaction, each progressively more immersive:

### Mode 1: Compact FAB + Side Panel

```
┌────────────────────────────────────────┐
│  Course Page                            │
│                                         │
│                                ┌──────┐ │
│                                │  🧠  │ │  FAB button (always visible)
│                                └──────┘ │
└────────────────────────────────────────┘
                      │ click
                      ▼
┌────────────────────────────────────────┐
│  ┌──────────────────────────────┐      │
│  │  AI Assistant          [X]   │      │  Small panel
│  │  ──────────────────────────  │      │  400px wide
│  │  Chat │ Notes │ Resources    │      │  Slides from right
│  │  ──────────────────────────  │      │
│  │                              │      │
│  │  [Hello! Ask me anything]    │      │
│  │                              │      │
│  │  ┌──────────────────────┐   │      │
│  │  │ Type question... [>] │   │      │
│  │  └──────────────────────┘   │      │
│  └──────────────────────────────┘      │
└────────────────────────────────────────┘
```

**When to use**: Quick Q&A while browsing course materials  
**How to open**: Click the FAB button (bottom-right green circle)  
**How to close**: Click X, click backdrop, or press Escape  
**File**: `templates/ai_fab.mustache` (Approach A) or `amd/src/ai_fab_injector.js` (Approach B)

### Mode 2: Expanded Workspace (Full Page)

```
┌────────────────────────────────────────┐
│  ┌──────────────────────────────┐      │
│  │  AI Hub                 [X]  │      │  Full page overlay
│  │  ──────────────────────────  │      │  600px or 100% wide
│  │  Chat │ Notes │ Resources    │      │  With quick actions
│  │  ──────────────────────────  │      │
│  │                              │      │
│  │  [Summarize] [Explain]       │      │  Quick action buttons
│  │  [Quiz]      [Review]        │      │
│  │                              │      │
│  │  [Chat messages here]        │      │
│  │                              │      │
│  │  ┌──────────────────────┐   │      │
│  │  │ Ask a question... [>]│   │      │
│  │  └──────────────────────┘   │      │
│  └──────────────────────────────┘      │
└────────────────────────────────────────┘
```

**When to use**: Deep work — studying, taking notes, reviewing materials  
**How to open**: Click the "Expand" button (↗) in the compact panel  
**File**: Same `templates/ai_fab.mustache` but with `.expanded` class

### Mode 3: Workspace with Video + Transcript

```
┌──────────────────────────────────────────────────────┐
│  ┌──────────────────────┬───────────────────────────┐│
│  │  ← Back to AI Hub    │  AI Learning Assistant    ││
│  │  ────────────────────│───────────────────────────││
│  │                      │  Chat │ Notes │ Resources ││
│  │  ┌────────────────┐  │  ───────────────────────── ││
│  │  │  Video Player  │  │                           ││
│  │  │  [▶▶] [=====]  │  │  [Explain concept]        ││
│  │  │  12:45 / 45:00  │  │  [Tell me more]          ││
│  │  └────────────────┘  │                           ││
│  │                      │  [AI messages here]       ││
│  │  ┌────────────────┐  │                           ││
│  │  │ Transcript     │  │  ┌─────────────────────┐  ││
│  │  │ [12:15] text   │  │  │ Ask about video [>] │  ││
│  │  │ [12:45] text ←─│──│──│ highlighted         │  ││
│  │  │ [13:10] text   │  │  └─────────────────────┘  ││
│  │  └────────────────┘  │                           ││
│  └──────────────────────┴───────────────────────────┘│
└──────────────────────────────────────────────────────┘
```

**When to use**: Watching a lecture recording with synchronized transcript  
**How to open**: Click a session in AI Hub → workspace page loads  
**File**: `templates/ai_workspace.mustache` + `amd/src/ai_workspace.js`

---

## 5. Component Deep Dives

### 5.1 The FAB Button

**What it looks like**: A 56px × 56px green circle with a robot icon (🧠/smart_toy)

**Where it sits**: Fixed position, bottom-right corner (bottom: 28px, right: 28px)

**Key behaviors**:
- Pulsing animation to draw attention
- Scales up (1.1×) on hover
- Shows a tooltip "Ask UMaT AI Assistant" on hover
- Has a notification badge (red circle with number) for pending items

**How it's created**:

```
In ai_fab_injector.js:
1. Create <button> element
2. Set styles (position: fixed, bottom: 80px, right: 24px)
3. Set inner HTML (robot icon SVG)
4. Append to document.body
5. Add click event listener → show workspace overlay

In ai_fab.mustache:
1. Same button rendered in HTML template
2. CSS classes for styling (`.umat-global-fab`)
3. Inline JS for event handling
```

**Z-index strategy**:
```
FAB button:    z-index: 9999
Overlay:       z-index: 10000
Expanded:      z-index: 10001
Lightbox:      z-index: 99998 (highest, rare use)
```

### 5.2 The Overlay Panel System

The overlay is the **container** that holds all AI content. It has a consistent structure:

```
.umat-workspace-overlay (or .umat-cp-ov)     ← Fixed position, covers entire screen
├── .umat-workspace-panel (or .umat-cp)       ← Slides from right, flex column
│   ├── .umat-workspace-header                 ← Green gradient header
│   │   ├── Avatar (robot icon in circle)
│   │   ├── Title + course name
│   │   └── Close button (X)
│   ├── .umat-workspace-tabs                  ← Tab bar
│   │   ├── Chat tab (active by default)
│   │   ├── Notes tab
│   │   └── Resources tab
│   ├── .umat-workspace-content (#tab-chat)    ← Tab content (shown/hidden)
│   │   ├── Quick action buttons
│   │   ├── Chat messages container
│   │   └── Input area
│   ├── .umat-workspace-content (#tab-notes)   ← Notes tab (hidden initially)
│   └── .umat-workspace-content (#tab-resources) ← Resources tab (hidden initially)
└── Close on backdrop click
```

**How the slide-in animation works**:
```css
/* The panel starts off-screen to the right */
.umat-workspace-panel {
    transform: translateX(100%);       /* Hidden off-screen */
    transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

/* When the overlay gets the 'show' class, panel slides in */
.umat-workspace-overlay.show .umat-workspace-panel {
    transform: translateX(0);          /* Slides into view */
}
```

The `cubic-bezier(0.4, 0, 0.2, 1)` timing function creates a **material design-style ease-out** effect — starts fast, slows down at the end.

### 5.3 The Tab System

The tabs work by **showing/hiding content panels**. Only one tab is active at a time:

```
User clicks "Notes" tab
         │
         ▼
1. Remove 'active' class from all tabs
2. Add 'active' class to clicked tab
3. Hide all content panels (display: none)
4. Show the matching content panel (display: block/flex)

Tab content matching:
  data-tab="chat"      →  #tab-chat       (display: flex)
  data-tab="notes"     →  #tab-notes      (display: block)
  data-tab="resources" →  #tab-resources  (display: block)
```

### 5.4 The Chat System

```
User types question and clicks Send
         │
         ▼
1. Create user bubble (green, right-aligned)
2. Append to messages container
3. Clear input field
4. Create typing indicator (animated dots, left-aligned)
5. Scroll messages to bottom
         │
         ▼
6. AJAX call: local_umat_ai_ask_question
   ├── courseid: 123
   └── question: "What is RAM?"
         │
         ▼
7. Response received:
   ├── Success → Remove typing indicator
   │           → Create AI bubble (white, left-aligned, green border)
   │           → Add source tags if provided
   │           → Append to messages
   └── Error   → Remove typing indicator
               → Show error message in AI bubble
         │
         ▼
8. Scroll messages to bottom
9. Update questions remaining count
```

**Message bubble styling**:

```
User message (right-aligned):
┌──────────────────────────────────────────┐
│                                          │
│   What is the difference between         │
│   RAM and ROM?                           │
│                                          │
│   └────────────────── (student bubble)   │
└──────────────────────────────────────────┘

AI message (left-aligned):
┌──────────────────────────────────────────┐
│  🧠                                      │
│  ┌──────────────────────────────────┐    │
│  │ Based on your course materials, │    │
│  │ RAM is volatile memory that...   │    │
│  └──────────────────────────────────┘    │
│  [lecture3_transcript] [book.pdf]        │
└──────────────────────────────────────────┘
```

### 5.5 The Video + Transcript Workspace

This is the most complex component. It has **synchronized video and transcript**:

```
How Transcript Highlighting Works:

1. Video plays
2. Every 250ms, video 'timeupdate' event fires
3. JavaScript reads video.currentTime (e.g., 765.4 seconds)
4. It loops through all transcript segments
5. For each segment, it checks:
     if (currentTime >= segment.start && currentTime <= segment.end)
       → Add 'active' class to this segment
       → Scroll segment into view
     else
       → Remove 'active' class
6. Active segment is highlighted with green left border
```

**How clicking a timestamp works**:

```
User clicks [12:45] timestamp in transcript
         │
         ▼
1. Read the segment's data-start attribute (e.g., 765)
2. Set video.currentTime = 765
3. Call video.play()
4. Segment highlights automatically (from timeupdate event)
```

**How the search function works**:

```
User types "anisotropy" in transcript search box
         │
         ▼
1. On every keystroke (input event)
2. Get search text, convert to lowercase
3. Loop through all transcript segments
4. For each segment:
     If segment text contains search term
       → Show segment (display: '')
     Else
       → Hide segment (display: 'none')
5. Result: only matching segments visible
```

### 5.6 The Attachment Drawer

The attachment drawer is a **slide-up panel** that appears above the chat input:

```
Normal state:
┌──────────────────────────────┐
│  Type your question...  [>]  │  Input area
│  📎 Attach materials         │  Footer with attachment button
└──────────────────────────────┘

When attachment button clicked (or drawer opens):
┌──────────────────────────────┐
│  Select Materials      [X]   │  Drawer header
│  ┌──────────────────────┐    │
│  │ Search materials...  │    │  Search box
│  └──────────────────────┘    │
│                              │
│  ☐ 📄 lecture_notes.pdf     │  File list (scrollable)
│  ☐ 🎬 session_recording.mp4 │
│  ☐ 📊 data_analysis.xlsx    │
│                              │
│  0 selected    [Attach (0)]  │  Drawer footer
├──────────────────────────────┤
│  Type your question...  [>]  │  Input area (still visible)
│  📎 Attach materials         │
└──────────────────────────────┘
```

The drawer uses CSS positioning to slide up from the input area:

```css
.umat-attach-drawer {
    position: absolute;
    top: 100%;                    /* Starts below the input area */
    left: 0;
    right: 0;
    max-height: 360px;
    transform: translateY(0);
    transition: transform 0.3s ease;
}

.umat-attach-drawer.open {
    transform: translateY(-100%); /* Slides up to cover input area */
}
```

### 5.7 The Expanded Workspace (Full Page Video + AI)

When the user clicks "Expand" in the compact panel, or opens a session from the AI Hub, they see a **full-page workspace** with two columns:

```
┌──────────────────────────────────────────────────────────────┐
│  ← Back to AI Hub                        EL 452 - Mining    │
│  ────────────────────────────────────────────────────────────│
│                                                               │
│  ┌─────────────────────────────┬──────────────────────────┐  │
│  │                             │  AI Learning Assistant    │  │
│  │   ┌───────────────────┐     │  ──────────────────────  │  │
│  │   │ 🎬 Video Player   │     │  Chat │ Notes │ Resources │  │
│  │   │   ▶ 12:45 / 45:00 │     │  ──────────────────────  │  │
│  │   │   [=====●=======]  │     │                          │  │
│  │   └───────────────────┘     │  [Explain this concept]   │  │
│  │                             │  [Tell me more]           │  │
│  │   ┌───────────────────┐     │                          │  │
│  │   │ Transcript  [🔍]  │     │  ┌──────────────────┐    │  │
│  │   │                     │     │  │ AI: The Hoek-   │    │  │
│  │   │ [12:15] Mechanical  │     │  │ Brown criterion │    │  │
│  │   │ [12:45] anisotropy  │ ←  │  │ is used for...  │    │  │
│  │   │ [13:10] Hoek-Brown  │     │  └──────────────────┘    │  │
│  │   │                     │     │                          │  │
│  │   └───────────────────┘     │  ┌──────────────────┐    │  │
│  │                             │  │ │Ask about...│[>] │    │  │
│  │   Left: 60% of screen       │  └──────────────────┘    │  │
│  │                             │  📎 Attach     🎤 Voice  │  │
│  └─────────────────────────────┴──────────────────────────┘  │
│                                                               │
│         Left column (flex: 1)      Right column (420px fixed) │
└──────────────────────────────────────────────────────────────┘
```

This layout is achieved with CSS flexbox:

```css
.umat-ws {
    display: flex;
    height: calc(100vh - 60px);  /* Full height minus Moodle header */
}

.umat-ws-left {
    flex: 1;                     /* Takes remaining space */
    display: flex;
    flex-direction: column;
}

.umat-ws-right {
    width: 420px;                /* Fixed width sidebar */
    display: flex;
    flex-direction: column;
    flex-shrink: 0;              /* Won't shrink */
}
```

---

## 6. Data Flow Diagrams

### 6.1 Full Interaction Flow: Student Asks a Question

```
┌──────────┐    ┌──────────────┐    ┌───────────┐    ┌────────────┐    ┌───────────┐
│  Student  │    │  AMD Module   │    │  Moodle   │    │ AI Service  │    │  Gemini   │
│  (browser)│    │  (ai_fab.js) │    │  Web      │    │  (FastAPI)  │    │  LLM      │
│           │    │              │    │  Service  │    │             │    │           │
├──────────┤    ├──────────────┤    ├───────────┤    ├────────────┤    ├───────────┤
│           │    │              │    │           │    │             │    │           │
│ 1. Click  │───>│              │    │           │    │             │    │           │
│    FAB    │    │              │    │           │    │             │    │           │
│           │    │              │    │           │    │             │    │           │
│           │    │ 2. Show      │    │           │    │             │    │           │
│           │    │    overlay   │    │           │    │             │    │           │
│           │    │              │    │           │    │             │    │           │
│ 3. Type   │───>│              │    │           │    │             │    │           │
│    Q      │    │              │    │           │    │             │    │           │
│           │    │              │    │           │    │             │    │           │
│           │    │ 4. Show      │    │           │    │             │    │           │
│           │    │    typing    │    │           │    │             │    │           │
│           │    │    indicator │    │           │    │             │    │           │
│           │    │              │    │           │    │             │    │           │
│           │    │ 5. AJAX call │───>│           │    │             │    │           │
│           │    │              │    │           │    │             │    │           │
│           │    │              │    │ 6. Auth   │───>│             │    │           │
│           │    │              │    │    check  │    │             │    │           │
│           │    │              │    │           │    │             │    │           │
│           │    │              │    │ 7. Rate   │    │             │    │           │
│           │    │              │    │    limit  │    │             │    │           │
│           │    │              │    │    check  │    │             │    │           │
│           │    │              │    │           │    │             │    │           │
│           │    │              │    │           │    │ 8. Embed Q  │───>│           │
│           │    │              │    │           │    │ 9. Search   │    │           │
│           │    │              │    │           │    │    ChromaDB │    │           │
│           │    │              │    │           │    │             │    │           │
│           │    │              │    │           │    │ 10. RAG     │───>│           │
│           │    │              │    │           │    │     prompt  │    │           │
│           │    │              │    │           │    │             │    │           │
│           │    │              │    │           │    │ 11. Answer  │<───│           │
│           │    │              │    │           │    │             │    │           │
│           │    │              │    │ 12. Return│<───│             │    │           │
│           │    │              │    │    answer │    │             │    │           │
│           │    │              │    │           │    │             │    │           │
│           │    │ 13. Remove   │<───│           │    │             │    │           │
│           │    │     typing   │    │           │    │             │    │           │
│           │    │              │    │           │    │             │    │           │
│ 14. See   │<───│ 14. Show AI  │    │           │    │             │    │           │
│    answer │    │     bubble   │    │           │    │             │    │           │
└──────────┘    └──────────────┘    └───────────┘    └────────────┘    └───────────┘
```

### 6.2 Component Loading Flow

```
Moodle renders course page
         │
         ▼
lib.php: before_footer() hook fires
         │
         ├── Check capability: local/umat_ai:chatwithai
         │
         ├── If YES → load AI FAB:
         │       │
         │       ├── Method A (Mustache template):
         │       │   $OUTPUT->render_from_template('local_umat_ai/ai_fab', data)
         │       │       │
         │       │       ▼
         │       │   Renders: <button id="umat-global-fab"> + <div class="overlay"> + <style> + <script>
         │       │
         │       └── Method B (AMD module):
         │           $PAGE->requires->js_amd_inline("require([...])")
         │               │
         │               ▼
         │           ai_fab_injector.js:
         │               createFab() → append to body
         │
         ├── Check: is this a course page with sessions?
         │       │
         │       └── If YES → load AI Workspace:
         │               render ai_workspace.mustache
         │               init ai_workspace.js (video, transcript, chat)
         │
         └── Check: are there course materials?
                 │
                 └── If YES → load material_viewer.js
                         init with file list
```

### 6.3 Tab Switching Flow

```
User clicks tab
    │
    ▼
Tab click event handler
    │
    ├── Remove 'active' from ALL tabs
    ├── Add 'active' to clicked tab
    │
    ├── Get tab name from data-tab attribute
    │   (e.g., "chat", "notes", "resources")
    │
    ├── Loop through ALL content panels (umat-workspace-content)
    │   │
    │   ├── If panel.id === 'tab-' + tabName → display: flex/block
    │   └── Else → display: none
    │
    └── (Optionally) Load data for the new tab
        │
        ├── If "notes" → AJAX call to get session notes
        └── If "resources" → AJAX call to get course materials
```

---

## 7. CSS Architecture & Design System

### 7.1 Design Tokens (CSS Custom Properties)

We define all colors, sizes, and radii as CSS custom properties in `:root`:

```css
:root {
    /* Primary brand colors */
    --u-p:         #006b2f;    /* UMaT Green (main) */
    --u-pb:        #00873d;    /* UMaT Green (lighter) */
    
    /* Surfaces */
    --u-sf:        #f5fbf0;    /* Surface (soft green tint) */
    --u-sfl:       #eff6eb;    /* Surface light */
    --u-sflo:      #ffffff;    /* Surface card/panel */
    
    /* Text */
    --u-ons:       #171d17;    /* On surface (primary text) */
    --u-onsv:      #3e4a3e;    /* On surface variant */
    --u-ol:        #6e7a6d;    /* Outline/text muted */
    --u-olv:       #bdcaba;    /* Outline variant (borders) */
    
    /* Semantic */
    --u-sec:       #3d6844;    /* Success text */
    --u-secc:      #beefc1;    /* Success background */
    --u-ter:       #a5304d;    /* Error/danger */
    --u-warn:      #f59e0b;    /* Warning */
    --u-ok:        #4ade80;    /* Online/active */
    
    /* Spacing/radii */
    --u-r6:        6px;
    --u-r8:        8px;
    --u-r12:       12px;
    --u-r16:       16px;
    --u-r20:       20px;
    --u-rp:        9999px;     /* Fully rounded (pill) */
    
    /* Shadows */
    --u-shadow:    0 12px 40px rgba(0,0,0,.16);
    --u-fshadow:   0 6px 22px rgba(0,107,47,.44);
}
```

### 7.2 Why These Colors?

The color scheme is based on UMaT's brand colors:

| Color | Usage | Why |
|-------|-------|-----|
| `#006b2f` Green | Primary buttons, active tabs, links | UMaT brand color, conveys growth/learning |
| `#f5fbf0` Light green | Panel backgrounds | Soft on eyes, eco-friendly feel |
| `#ffffff` White | Cards, chat bubbles | Clean, readable |
| `#a5304d` Red | Errors, badges | High contrast warning |
| `#4ade80` Green dot | Online status indicator | Familiar "online" signal |

### 7.3 CSS File Organization

| File | What It Contains | Size |
|------|-----------------|------|
| `styles/umat-overlay.css` | All overlay styles — FAB, panels, tabs, chat, input, video player, transcript, materials, analytics, review | ~730 lines |
| `styles/umat-responsive.css` | Mobile/responsive overrides | Smaller |
| Embedded in Mustache templates | Component-specific styles (self-contained) | Varies |

### 7.4 Layer System (z-index)

| Layer | z-index | Elements |
|-------|---------|----------|
| Base content | auto | Normal page content |
| FAB button | 9990 | Floating action button |
| Compact panel overlay | 9995 | Backdrop + slide-in panel |
| Expanded workspace | 10000 | Full AI workspace overlay |
| Expanded workspace (max) | 10001 | Video workspace |
| Full overlay (rare) | 99998 | Lightbox/full-screen viewer |

### 7.5 Positioning Strategies

We use three positioning approaches for different components:

**1. Fixed positioning** — FAB button and overlay backdrops
```css
.umat-fab {
    position: fixed;      /* Stays in place when scrolling */
    bottom: 28px;
    right: 28px;
    z-index: 9990;
}
```

**2. Absolute positioning** — Attachment drawer (relative to input area)
```css
.umat-input-area {
    position: relative;       /* Containing block */
}
.umat-attach-drawer {
    position: absolute;       /* Positioned relative to input area */
    top: 100%;
    left: 0;
    right: 0;
}
```

**3. CSS transform for animations** — Panel slide-in/out
```css
.umat-cp {
    transform: translateX(100%);        /* Hidden off-screen */
    transition: transform 0.36s ease;   /* Animated slide */
}
.umat-cp-ov.open .umat-cp {
    transform: translateX(0);           /* Visible on-screen */
}
```

### 7.6 Responsive Behavior

At viewport widths below 768px (tablet/mobile):

- **Compact panel**: Full width (95vw)
- **Workspace**: Switches from side-by-side to stacked layout
- **FAB**: Stays in same position but slightly smaller
- **Text sizes**: Remain the same for readability

```css
@media (max-width: 768px) {
    .ai-overlay {
        align-items: center;       /* Center instead of bottom-right */
        justify-content: center;
    }
    .ai-panel {
        height: 90vh;              /* More height on mobile */
    }
    .umat-cp, .umat-cp-lec {
        width: 100vw;              /* Full width on mobile */
        max-width: 100vw;
    }
}

@media (max-width: 900px) {
    .umat-ws {
        flex-direction: column;    /* Stack video + AI vertically */
    }
    .umat-ws-right {
        width: 100%;               /* AI sidebar takes full width */
    }
}
```

---

## 8. AMD Module Architecture

### 8.1 Module Map

All JavaScript modules follow Moodle's AMD (Asynchronous Module Definition) pattern using RequireJS.

```
define(['dependency1', 'dependency2'], function(Dependency1, Dependency2) {
    'use strict';
    
    function init(options) {
        // Your code here
    }
    
    return { init: init };  // Must return init function
});
```

### 8.2 Module Dependency Graph

```
ai_fab_injector.js
  └─ depends on: core/ajax
  └─ imports: none

ai_fab.js
  └─ depends on: core/ajax
  └─ creates: DOM elements directly (no template)
  └─ functions: createFab, showFloatingPanel, createWorkspace, sendQuestion

ai_chat_panel.js
  └─ depends on: core/ajax, core/notification, core/str, core/templates
  └─ uses: Mustache templates (ai_chat_panel.mustache)
  └─ features: FAB, overlay, messages, typing indicator, quick actions

ai_workspace.js
  └─ depends on: core/ajax, core/notification
  └─ handles: Video player, transcript sync, tabs, chat, summary generation

umatshared.js
  └─ depends on: core/ajax
  └─ shared utilities: overlay helper, material viewer, analysis handler
  └─ loaded by: before_footer.php (always loaded for course pages)

material_viewer.js
  └─ depends on: core/ajax
  └─ handles: Opening files in overlay, PDF viewer, analysis buttons

approval.js
  └─ depends on: core/ajax
  └─ handles: Approve/reject AI content in approval page
```

### 8.3 Module Loading Order

```
1. require('core/ajax') loaded (Moodle core)
2. require('core/notification') loaded (Moodle core)
3. require('local_umat_ai/umatshared') loaded (always on course pages)
4. require('local_umat_ai/ai_fab') OR template inline JS loaded
5. require('local_umat_ai/ai_workspace') loaded (if session page)
6. require('local_umat_ai/material_viewer') loaded (if materials exist)
```

### 8.4 How AMD Modules Are Loaded from PHP

In `lib.php` or `before_footer.php`:

```php
// Method 1: Inline AMD (for simple initialization)
$PAGE->requires->js_amd_inline("
    require(['local_umat_ai/ai_fab_injector'], function(Fab) {
        Fab.init($courseId, '$courseName');
    });
");

// Method 2: Using js_call_amd (for standard init)
$PAGE->requires->js_call_amd('local_umat_ai/ai_fab', 'init', [$courseId, $courseName]);

// Method 3: Via Mustache template inline script (self-contained)
// The template has <script> block that runs immediately when rendered
// This is what ai_fab.mustache does
```

---

## 9. Inter-Module Communication

Since these are AMD modules (not a framework like React), modules communicate through:

### 9.1 Direct DOM Events

```javascript
// Module A (ai_fab.js) dispatches a custom event
var event = new CustomEvent('umat:question-sent', {
    detail: { question: 'What is RAM?', courseId: 123 }
});
document.dispatchEvent(event);

// Module B (ai_chat_panel.js) listens for it
document.addEventListener('umat:question-sent', function(e) {
    console.log('Question sent:', e.detail.question);
});
```

### 9.2 Moodle AJAX Calls (Shared Web Services)

All modules call the same Moodle web services:

| Web Service | Called By | Purpose |
|-------------|-----------|---------|
| `local_umat_ai_ask_question` | ai_fab.js, ai_chat_panel.js, ai_workspace.js | Send Q&A |
| `local_umat_ai_get_session_outputs` | ai_workspace.js | Get notes/summary |
| `local_umat_ai_list_materials` | umatshared.js, material_viewer.js | List course files |
| `local_umat_ai_get_analysis_status` | umatshared.js, material_viewer.js | Check analysis status |

### 9.3 Shared State via DOM Data Attributes

```html
<!-- Modules read/write shared state via data attributes on common elements -->
<div id="umat-ai-state" 
     data-courseid="123"
     data-has-capability="true"
     data-questions-remaining="10">
</div>
```

```javascript
// Any module can read this state
var courseId = document.getElementById('umat-ai-state').dataset.courseid;
```

---

## 10. Why This Approach Was Chosen

### Why Overlays Instead of Separate Pages?

| Reason | Explanation |
|--------|-------------|
| **Stay in context** | Students can ask questions without leaving their course page or video |
| **Faster interaction** | No page reloads — panel slides in instantly |
| **Less server load** | Moodle doesn't need to render a full page for every interaction |
| **Modern UX** | Similar to how ChatGPT, Google Bard, and Copilot work — persistent side panels |
| **Mobile friendly** | Overlay takes full screen on mobile, feels like a native app |

### Why Mustache Templates + Inline JS?

| Approach | Pros | Cons | When to Use |
|----------|------|------|-------------|
| **Mustache template** | Clean HTML, designer-friendly, Moodle-standard | Harder to do dynamic DOM manipulation | Complex UI panels (chat, workspace) |
| **AMD module + DOM creation** | Full programmatic control, dynamic data | HTML mixed in JS, harder to maintain | Simple elements (FAB button) |
| **Combined (current)** | Best of both worlds | Two code paths to maintain | Transition period |

### Why Our Own Overlay Instead of Bootstrap/Moodle Modal?

Moodle has built-in modals, but we chose a custom approach because:

1. **Custom animations**: Material Design slide-in transitions
2. **Flexible sizing**: Panel can collapse (400px) or expand (100% width)
3. **Multiple panels**: Can have chat + video + transcript simultaneously
4. **Non-blocking**: Backdrop click to close, but doesn't block page underneath
5. **Brand consistency**: Full control over UMaT green styling

### Why CSS Custom Properties?

1. **Easy theming**: Change one value, updates everywhere
2. **Consistency**: All components use the same color tokens
3. **Maintainability**: Add new component → use existing tokens
4. **Performance**: No preprocessor needed (vanilla CSS)
5. **Future dark mode**: Can swap token values with `[data-theme="dark"]`

---

## Appendix: Component Quick Reference

| Component | Files | Key CSS Classes | Key JS Functions |
|-----------|-------|----------------|-----------------|
| FAB Button | `ai_fab_injector.js` | `.umat-fab`, `.umat-fab-pulse` | `createFab()`, `init()` |
| Compact Panel | `templates/ai_fab.mustache` | `.umat-cp-ov`, `.umat-cp` | `showFloatingPanel()`, `hideFloatingPanel()` |
| Chat System | `ai_fab.js`, `ai_chat_panel.js` | `.chat-message`, `.chat-bubble` | `sendQuestion()`, `appendMessage()` |
| Tab System | Built into templates | `.umat-cp-tab`, `.umat-cp-pane` | Tab click → show/hide panels |
| Video Player | `ai_workspace.js` | `.umat-video-wrap` | `initVideo()`, `highlightTranscriptSegment()` |
| Transcript | `ai_workspace.js` | `.umat-ts-segment`, `.umat-ts-time` | `initTranscript()`, click-to-seek |
| Attachment Drawer | `umatshared.js` | `.umat-attach-drawer` | Toggle `.open` class |
| Material Viewer | `material_viewer.js` | `.umat-pdf-viewer-wrap` | `openPDF()`, `viewFile()` |
| Expanded Workspace | `templates/ai_workspace.mustache` | `.umat-ws`, `.umat-ws-left`, `.umat-ws-right` | `initVideo()`, `initChat()`, `initTabs()` |

---

## Appendix: Common Patterns

### Pattern 1: Creating a Bubble Message

```javascript
function appendMessage(text, isUser) {
    var container = document.getElementById('chat-messages');
    var msg = document.createElement('div');
    msg.className = 'chat-message ' + (isUser ? 'user' : 'ai');
    msg.innerHTML = '<div class="chat-bubble"><p>' + escapeHtml(text) + '</p></div>';
    container.appendChild(msg);
    container.scrollTop = container.scrollHeight;
}
```

### Pattern 2: Showing/Hiding Tab Content

```javascript
function switchTab(tabName) {
    // 1. Update tab buttons
    document.querySelectorAll('.tab-btn').forEach(function(t) {
        t.classList.remove('active');
    });
    document.querySelector('[data-tab="' + tabName + '"]').classList.add('active');
    
    // 2. Update content panels
    document.querySelectorAll('.tab-content').forEach(function(p) {
        p.classList.remove('active');
    });
    document.getElementById('tab-' + tabName).classList.add('active');
}
```

### Pattern 3: AJAX Call to Moodle Web Service

```javascript
function callMoodle(method, args) {
    require(['core/ajax'], function(Ajax) {
        Ajax.call([{
            methodname: method,
            args: args
        }])[0].done(function(response) {
            // Handle success
        }).fail(function(error) {
            // Handle failure
        });
    });
}
```

### Pattern 4: Typing Indicator

```javascript
function showTyping() {
    // Create animated dots indicator
    var typing = document.createElement('div');
    typing.className = 'chat-message ai';
    typing.innerHTML = '<div class="umat-typing"><span></span><span></span><span></span></div>';
    document.getElementById('chat-messages').appendChild(typing);
}

function hideTyping() {
    var el = document.getElementById('umat-typing-indicator');
    if (el) el.remove();
}
```

### Pattern 5: Creating the FAB

```javascript
function createFab() {
    var fab = document.getElementById('umat-fab-btn');
    if (fab) return;  // Only create once
    
    var btn = document.createElement('button');
    btn.id = 'umat-fab-btn';
    btn.className = 'umat-fab umat-fab-pulse';
    btn.innerHTML = '<span class="material-symbols-outlined">smart_toy</span>';
    btn.onclick = openOverlay;
    document.body.appendChild(btn);
}
```
