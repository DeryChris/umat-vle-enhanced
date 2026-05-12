---
name: UMaT Precision Green
colors:
  surface: '#f5fbf0'
  surface-dim: '#d5dcd1'
  surface-bright: '#f5fbf0'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#eff6eb'
  surface-container: '#e9f0e5'
  surface-container-high: '#e3eadf'
  surface-container-highest: '#dee5da'
  on-surface: '#171d17'
  on-surface-variant: '#3e4a3e'
  inverse-surface: '#2b322b'
  inverse-on-surface: '#ecf3e8'
  outline: '#6e7a6d'
  outline-variant: '#bdcaba'
  surface-tint: '#006d30'
  primary: '#006b2f'
  on-primary: '#ffffff'
  primary-container: '#00873d'
  on-primary-container: '#f7fff3'
  inverse-primary: '#64de83'
  secondary: '#3d6844'
  on-secondary: '#ffffff'
  secondary-container: '#beefc1'
  on-secondary-container: '#436e4a'
  tertiary: '#a5304d'
  on-tertiary: '#ffffff'
  tertiary-container: '#c64865'
  on-tertiary-container: '#fffbff'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#81fb9c'
  primary-fixed-dim: '#64de83'
  on-primary-fixed: '#00210a'
  on-primary-fixed-variant: '#005323'
  secondary-fixed: '#beefc1'
  secondary-fixed-dim: '#a3d2a6'
  on-secondary-fixed: '#00210a'
  on-secondary-fixed-variant: '#254f2e'
  tertiary-fixed: '#ffd9dd'
  tertiary-fixed-dim: '#ffb2bd'
  on-tertiary-fixed: '#400013'
  on-tertiary-fixed-variant: '#891839'
  background: '#f5fbf0'
  on-background: '#171d17'
  surface-variant: '#dee5da'
typography:
  display-lg:
    fontFamily: Inter
    fontSize: 36px
    fontWeight: '700'
    lineHeight: 44px
    letterSpacing: -0.02em
  headline-md:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
    letterSpacing: -0.01em
  headline-sm:
    fontFamily: Inter
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
  body-lg:
    fontFamily: Inter
    fontSize: 18px
    fontWeight: '400'
    lineHeight: 28px
  body-md:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-sm:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  label-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.01em
  label-sm:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '500'
    lineHeight: 16px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  base: 4px
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  gutter: 16px
  margin-mobile: 16px
  margin-desktop: 40px
---

## Brand & Style

The design system establishes a digital environment that reflects the University of Mines and Technology’s commitment to engineering excellence, sustainability, and academic rigor. The brand personality is rooted in reliability and technical precision, aimed at providing students with a seamless AI-driven learning companion within the Moodle VLE.

The visual style follows a **Corporate Modern** aesthetic. It prioritizes clarity and efficiency, utilizing a structured information hierarchy that feels institutional yet accessible. The interface avoids unnecessary decorative elements, focusing instead on functional clarity through generous whitespace, subtle depth, and a professional palette that ensures all academic data is easily digestible.

## Colors

This design system utilizes a refreshed UMaT institutional identity as its foundation. The **Primary Green (#009846)** is used for navigational elements, primary actions, and brand headers, conveying stability and growth. The **Sage Secondary (#55815b)** serves as a functional accent for supportive highlights, while the **Rose Tertiary (#D95773)** provides a high-energy contrast for critical notifications or progress alerts.

The background system relies on a **Muted Sage Neutral (#727970)** palette to generate its surfaces, reducing eye strain during long study sessions, while pure white is reserved for cards and interactive surfaces to create a clear "layered" effect. Semantic colors are adjusted for high accessibility (WCAG AA compliant) against light backgrounds, ensuring that student analytics and grade data are interpreted correctly.

## Typography

The design system utilizes **Inter** for all interface levels to maintain a systematic, utilitarian feel. Typography is scaled to favor readability in data-heavy dashboard contexts. 

- **Headlines:** Use a tighter letter-spacing and semi-bold weights to create a strong visual anchor for section headers.
- **Body Text:** Set with generous line heights (1.5x) to facilitate the reading of long-form AI explanations and academic content.
- **Labels:** Small labels are used for metadata, category tags in analytics, and axis labels in charts to differentiate them from interactive content.

## Layout & Spacing

The layout philosophy follows a dual-model approach. For the **AI Assistant Chat**, a fluid model is used to maximize vertical space on mobile and sidebar views. For **Student Analytics Dashboards**, a 12-column fixed grid is employed on desktop to maintain structural integrity of data visualizations.

Spacing is based on a **4px baseline grid**. Standardized gutters of 16px provide enough "breathing room" between complex data widgets. For slide-out overlays, a 24px internal padding (lg) is mandated to ensure that content does not feel cramped against the screen edges.

## Elevation & Depth

Visual hierarchy is achieved through **Tonal Layers** and **Ambient Shadows**. This design system uses three distinct levels of elevation:

1.  **Level 0 (Base):** The light neutral canvas. Used for the main background of the Moodle environment.
2.  **Level 1 (Surface):** Pure white (#FFFFFF) containers with a very soft, diffused shadow (0px 4px 12px rgba(0, 152, 70, 0.05)). This is used for cards and chat bubbles.
3.  **Level 2 (Overlay):** Slide-out panels and modals. These use a stronger shadow depth (0px 10px 25px rgba(0, 0, 0, 0.1)) to visually separate administrative or deep-dive tasks from the main interface.

Borders are used sparingly, primarily as low-contrast outlines to define sections without adding visual noise.

## Shapes

The design system adopts a **Rounded** shape language to soften the institutional feel and make the AI assistant appear more approachable. 

- **Primary Components:** Buttons, input fields, and standard cards use a 0.5rem (8px) radius.
- **Chat Bubbles:** These utilize a larger 1rem (16px) radius, with the tail-end corner sharpened to 4px to indicate the speaker.
- **Status Tags:** Analytics chips and small labels use a "Pill" shape (full rounding) to distinguish them from interactive buttons.

## Components

### Interactive Chat Bubbles
AI responses are housed in Level 1 surface bubbles with left-aligned primary green accents. Student messages are right-aligned using a light green tinted background to differentiate dialogue flow.

### Slide-out Overlays
Used for deep-dive student analytics. These panels slide from the right, occupying 40% of the screen on desktop and 95% on mobile. They feature a fixed header with a clear "Close" action and use Level 2 elevation.

### Data Dashboards
Widgets use a modular card system. Data is presented using Primary Green for bars and lines, with Rose used for "Goal" markers or "High Priority" alerts. Typography in dashboards shifts to `label-sm` for axes to maximize the data-ink ratio.

### Buttons
- **Primary:** Solid #009846 with white text.
- **Secondary:** Outlined #009846 with 8px rounded corners.
- **Ghost:** No background, green text; used for secondary actions like "View Source" or "Dismiss."

### Input Fields
Search and chat inputs feature a 1px low-contrast border that transitions to a 2px #009846 border on focus, accompanied by a subtle green outer glow.