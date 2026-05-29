---
name: Sound PSA
description: A calm, dense operations console for small MSPs — one generalist sees billing, assets, tickets, and client environment in one place.
colors:
  navy: "#1a365d"
  navy-light: "#234179"
  navy-deep: "#0f2440"
  slate-chrome: "#0f172a"
  signal-gold: "#fed136"
  signal-gold-hover: "#fdc50c"
  ink: "#374151"
  muted-ink: "#6b7280"
  canvas: "#f8fafc"
  surface: "#ffffff"
  border: "#e5e7eb"
  field-border: "#d1d5db"
  danger: "#dc3545"
  warning: "#ffc107"
  info: "#0dcaf0"
  success: "#198754"
  neutral: "#6c757d"
typography:
  display:
    fontFamily: "Montserrat, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 800
    lineHeight: 1.2
    letterSpacing: "0.5px"
  headline:
    fontFamily: "Montserrat, sans-serif"
    fontSize: "1.75rem"
    fontWeight: 700
    lineHeight: 1.25
    letterSpacing: "normal"
  title:
    fontFamily: "Montserrat, sans-serif"
    fontSize: "0.9rem"
    fontWeight: 600
    lineHeight: 1.3
    letterSpacing: "0.5px"
  body:
    fontFamily: "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "normal"
  label:
    fontFamily: "Inter, sans-serif"
    fontSize: "0.9rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "normal"
rounded:
  sm: "8px"
  md: "12px"
  pill: "50rem"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "24px"
  topbar: "56px"
  sidebar: "220px"
components:
  button-primary:
    backgroundColor: "{colors.navy}"
    textColor: "{colors.surface}"
    rounded: "{rounded.sm}"
  button-primary-hover:
    backgroundColor: "{colors.navy-light}"
    textColor: "{colors.surface}"
  button-accent:
    backgroundColor: "{colors.signal-gold}"
    textColor: "{colors.navy-deep}"
    rounded: "{rounded.sm}"
  button-accent-hover:
    backgroundColor: "{colors.signal-gold-hover}"
    textColor: "{colors.navy-deep}"
  button-outline-primary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.navy}"
    rounded: "{rounded.sm}"
  card:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "{spacing.md}"
  card-header:
    backgroundColor: "{colors.navy}"
    textColor: "{colors.surface}"
    typography: "{typography.title}"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.sm}"
  sidebar-link:
    backgroundColor: "{colors.navy-deep}"
    textColor: "{colors.muted-ink}"
  sidebar-link-active:
    backgroundColor: "{colors.navy-deep}"
    textColor: "{colors.signal-gold}"
---

# Design System: Sound PSA

## 1. Overview

**Creative North Star: "The Operations Desk"**

Sound PSA is the desk a small MSP runs its whole business from. The user is usually one person, an owner or a tech in a shop of a few, who in a single sitting triages a ticket, checks the client's contract and prepay balance, looks at an asset's backup status, and sends an invoice. There is no specialist hiding behind a walled-off module to hand work to. So the interface behaves like a well-organized physical desk: everything relevant is within reach on the surface where the decision happens, laid out so the eye finds the one thing that matters without rummaging. Density is welcome when it serves that reach; clutter never is.

The system reads calm, trustworthy, and professional. It is a system of record for real money and real incidents, so confidence comes from consistency and accuracy, not decoration. Navy carries the structural chrome (sidebar, headers, top bar) and gold is a rare signal, not a finish. The plane is flat: surfaces sit at one level, separated by hairline borders and a quiet tonal step between the off-white canvas and white content, never by drop shadows or hover animation. Type does the heavy lifting, with Montserrat headings stating structure and Inter carrying the dense body and data.

This system explicitly rejects four things. It is **not a cluttered legacy PSA** (ConnectWise, Halo, Autotask): no grey-on-grey cramming where every pixel is a control. It is **not a generic SaaS template**: no Inter-for-everything, no purple-blue gradients, no identical icon-card grids, no eyebrow kicker above every section. It is **not playful or consumer**: no mascots, candy colors, emoji, or bouncy motion on a tool that handles billing and incidents. And it is **not over-designed or flashy**: gradients, glassmorphism, and animation-everywhere are defects here, not polish.

**Key Characteristics:**
- One operator, full picture: cross-domain context surfaced where decisions are made.
- Flat plane: borders and tonal layering convey structure, not shadows.
- Navy structure, gold signal: gold marks the one thing that matters, never decoration.
- Earned density: dense where the expert workflow rewards it, calm everywhere else.
- Crisp and confident components: clean edges, clear states, no showing off.

## 2. Colors

A restrained navy-and-gold identity on a cool off-white canvas, with a standard semantic vocabulary for ticket and billing state.

### Primary
- **Brand Navy** (`#1a365d`): the identity color and structural anchor. Card headers, table headers, primary buttons, headings, and links. Carries authority without going to pure black.
- **Navy Light** (`#234179`): hover and focus state for navy surfaces and buttons; the lift that interaction earns when color, not motion, signals it.
- **Navy Deep** (`#0f2440`): the darkest structural tone. Sidebar background and the base of the login gradient. Recedes so content reads forward.
- **Slate Chrome** (`#0f172a`): near-black slate for the footer and deepest chrome. Cooler and darker than navy, used only at the page edges.

### Secondary
- **Signal Gold** (`#fed136`): the single accent. Reserved for the one thing that matters on a screen: active navigation, the gold rule under a section title, key calls to action, focus and attention. Its rarity is the point.
- **Signal Gold Hover** (`#fdc50c`): the deepened gold for hover on accent controls.

### Neutral
- **Ink** (`#374151`): default body text. Roughly 10:1 on white and on the canvas; the readable workhorse, never lighter for "elegance."
- **Muted Ink** (`#6b7280`): secondary text, captions, inactive nav labels, timestamps. Holds AA at body size; do not push lighter.
- **Canvas** (`#f8fafc`): the page background (a cool off-white). One tonal step below white so content surfaces read as raised without a shadow.
- **Surface** (`#ffffff`): cards, panels, tables, inputs. The content plane.
- **Border** (`#e5e7eb`): the hairline that does the structural work shadows would do elsewhere. Card edges, dividers, table rules.
- **Field Border** (`#d1d5db`): the slightly stronger stroke on form controls, so inputs read as interactive against a card.

### Semantic (ticket, billing, and system state)
Drawn from Bootstrap 5.3 contextual colors so state reads the same everywhere.
- **Danger** (`#dc3545`): P1 priority, missed calls, open alerts.
- **Warning** (`#ffc107`, with dark text): P2 priority, in-progress, attention badges.
- **Info** (`#0dcaf0`, with dark text): P3 priority, pending client / third party.
- **Success** (`#198754`): resolved tickets, paid invoices, healthy state.
- **Neutral** (`#6c757d`): P4 priority, closed, void, inactive.

### Named Rules
**The Signal Gold Rule.** Gold is a signal, not a finish. It marks the single most important element on a screen (the active nav item, the primary action, the thing needing attention) and appears on a small fraction of any view. The moment gold becomes decoration, the signal is gone. Never use it for large fills, gradients, or backgrounds behind content.

**The Navy-and-Gold Defense.** Navy-and-gold is the deliberate brand identity, and it is also a well-worn finance/fintech reflex. The defense is restraint: navy is structural chrome (chrome recedes, content leads) and gold obeys the Signal Gold Rule. No navy gradients drenching content areas, no gold-on-navy "premium" hero treatments. Identity through discipline, not saturation.

## 3. Typography

**Display Font:** Montserrat (geometric sans; weights 600/700/800)
**Body Font:** Inter (humanist sans; weights 400/500/600), with `-apple-system, BlinkMacSystemFont, 'Segoe UI'` fallback

**Character:** A clean contrast pairing on a real axis: Montserrat's geometric, slightly architectural caps give structure to headings, labels, and chrome; Inter's humanist forms keep dense body text and data legible at small sizes. Two families, three weights of contrast, never a third typeface.

### Hierarchy
- **Display / Section Title** (Montserrat 800, 1.5rem, uppercase, letter-spacing 0.5px): the labeled page and panel headers, with a short gold rule beneath. Structure markers, used sparingly.
- **Headline** (Montserrat 700, ~1.75rem down through h-levels, navy): page and major section headings.
- **Title** (Montserrat 600, 0.9rem, uppercase, letter-spacing 0.5px): card headers and detail-tab labels; the chrome voice on navy.
- **Body** (Inter 400/500, 1rem, line-height 1.5, ink): default reading and form text. Cap prose at 65–75ch; data tables may run denser.
- **Label** (Inter 600, 0.9rem, navy): form labels and inline field labels. A second, smaller label exists for sidebar group headers (Montserrat 700, 0.65rem, uppercase, letter-spacing 1.5px) on dark chrome only.

### Named Rules
**The Fixed Scale Rule.** This is product UI, not a landing page. Type uses a fixed rem scale, never `clamp()` fluid sizing. A heading that shrinks inside a sidebar or detail panel reads worse, not better. Consistent DPI, consistent sizes.

**The Uppercase-for-Chrome Rule.** Uppercase belongs to short structural labels only: section titles, card headers, tab labels, sidebar groups, badges (each four words or fewer). Never set body sentences or multi-word content in all caps.

## 4. Elevation

This system is flat by doctrine. Surfaces sit on a single plane and depth is conveyed by hairline borders (`#e5e7eb`) and one tonal step between the cool canvas (`#f8fafc`) and white content (`#ffffff`). There are no resting shadows and no hover lift on content. Shadow is reserved exclusively for elements that genuinely float above the page: modals, dropdowns, popovers, toasts, and the command palette overlay. Everything else earns separation through border and tone.

### Shadow Vocabulary (overlays only)
- **Overlay** (`box-shadow: 0 12px 40px rgba(0,0,0,0.08)` or similar diffuse value): only for true floating layers (modal, dropdown, popover, command palette). Never on cards, list items, or panels in the document flow.

### Named Rules
**The Flat Rule.** Content surfaces are flat at rest and flat on hover. No `translateY` lift, no shadow bloom, no accent-border swap on hover for cards or panels. If an element needs to read as raised, use a border and a tonal step, not elevation. Depth is structure, not animation.

## 5. Components

Components are crisp and confident: clean edges, decisive states, no ornament. Every interactive element carries the full state set (default, hover, focus-visible, active, disabled), and the same control looks the same on every screen.

### Buttons
- **Shape:** lightly rounded (Bootstrap default ~6px); not pill, not square.
- **Primary:** navy fill (`#1a365d`), white text. The default commit action.
- **Accent:** gold fill (`#fed136`), navy-deep text (`#0f2440`), weight 600. The one high-emphasis call to action per view, under the Signal Gold Rule.
- **Outline:** navy text and border on white; fills navy on hover. Secondary actions.
- **Hover / Focus:** navy buttons deepen to navy-light (`#234179`); focus shows a visible ring. State through color, never through motion or shadow.

### Cards / Containers
- **Corner Style:** 12px radius (`{rounded.md}`).
- **Background:** white surface on the cool canvas.
- **Border:** 1px hairline (`#e5e7eb`). This, plus the tonal step, is the only separation.
- **Shadow Strategy:** none. See Elevation; cards are flat at rest and on hover.
- **Header:** navy bar, white uppercase Montserrat title, gold icon. Internal padding on the `md` step (16px).

### Inputs / Fields
- **Style:** white field, 1px field-border (`#d1d5db`), 8px radius. Labels in navy (Inter 600).
- **Focus:** border shifts to navy-light and a soft 3px navy glow (`box-shadow: 0 0 0 3px rgba(26,54,93,0.12)`). Calm, not neon.
- **Checked controls:** navy fill.

### Badges (status and priority)
- **Style:** Bootstrap contextual fills, pill or square, short uppercase labels. Priority and ticket/invoice state map to the semantic palette above (P1 danger, P2 warning, P3 info, P4 neutral; resolved success, closed neutral).
- **Rule:** state is never color-only. Pair the color with the text label or an icon so it survives color-blindness and grayscale.

### Navigation (sidebar)
- **Style:** fixed navy-deep (`#0f2440`) sidebar, 220px, collapsible to 64px. Links in Inter 500 at 75% white.
- **Group labels:** Montserrat 700, 0.65rem, uppercase, wide tracking, at 35% white.
- **Hover:** subtle white wash (`rgba(255,255,255,0.08)`), text to full white.
- **Active:** gold text, a 3px gold left-border marker, and a faint gold-tint background (`rgba(254,209,54,0.12)`). The one place a left-border stripe is correct, because it is a navigation position indicator, not a decorative card accent.

### Tables
- **Header:** navy bar, white uppercase Montserrat (the `thead-brand` treatment), no row shadows.
- **Body:** links in navy, hover to gold-hover. Dense rows are welcome; tables may exceed prose line length.

### Signature: The Section Rule
A short gold underline (60px, 3px, `::after` on `.section-title`) sits beneath labeled section titles. It is the brand's quiet flourish and the legitimate home for decorative gold. Keep it to true section headers, not every heading on the page.

## 6. Do's and Don'ts

### Do:
- **Do** keep surfaces flat. Convey depth with the 1px border (`#e5e7eb`) and the canvas-to-white tonal step, and reserve shadow for true overlays (modal, dropdown, popover, command palette).
- **Do** treat gold as a signal under the Signal Gold Rule: active nav, primary action, attention, focus. A small fraction of any screen.
- **Do** surface cross-domain context (client, contract, prepay, assets, environment) on the screen where the decision is made. The user is one generalist, not a specialist in a silo.
- **Do** keep ink (`#374151`) for body text and hold muted-ink (`#6b7280`) at AA. Bump toward ink if contrast is even close; never lighten text for "elegance."
- **Do** pair the Montserrat (geometric) and Inter (humanist) families on their contrast axis, and use a fixed rem type scale.
- **Do** give every interactive component the full state set (default, hover, focus-visible, active, disabled) and a visible focus ring, to hold WCAG 2.1 AA.
- **Do** convey status with color plus a text or icon label, never color alone.

### Don't:
- **Don't** build a cluttered legacy PSA (ConnectWise, Halo, Autotask): no grey-on-grey cramming where every pixel is a control. Earn density; do not default to it.
- **Don't** ship a generic SaaS template: no Inter-for-everything (the Montserrat pairing is the defense), no purple-blue gradients, no identical icon-card grids, no eyebrow kicker above every section.
- **Don't** go playful or consumer: no mascots, candy colors, emoji, or bouncy/elastic motion on a tool that handles real money and real incidents.
- **Don't** over-design or get flashy: no gradient-drenched content, no decorative glassmorphism, no animation everywhere. Decoration that slows the work is a defect.
- **Don't** lift cards on hover (no `translateY`, no shadow bloom, no accent-border swap). The Flat Rule governs content surfaces.
- **Don't** use gold for large fills, backgrounds, or gradients, and don't drench content areas in navy gradients. Identity through restraint, not saturation.
- **Don't** use a colored left/right border stripe wider than 1px as decoration on cards, callouts, or alerts. The only legitimate left-border is the sidebar's active-position marker.
- **Don't** set body sentences in all caps or introduce a third type family.
