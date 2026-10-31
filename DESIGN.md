---
name: Dot.Files
description: The Achromatic Ledger — a business's records kept like well-set books, no colour in the chrome.
colors:
  paper: "#f2f4f7"
  panel: "#ffffff"
  panel-soft: "#eef0f3"
  kraft-soft: "#f6f7f9"
  ink: "#0a0a0a"
  ink-soft: "#6b6b6b"
  ink-faint: "#6f6f6f"
  oxide: "#141414"
  oxide-deep: "#000000"
  oxide-wash: "rgba(20, 20, 20, 0.06)"
  oxide-ink: "#ffffff"
  data: "#1d5fbf"
  moss: "#2e7d32"
  moss-wash: "#dcfae5"
  moss-ink: "#04250e"
  amber: "#92400e"
  amber-wash: "#fffbeb"
  danger: "#c4242a"
  line: "#e6e6e6"
  line-strong: "#d4d4d4"
typography:
  headline:
    fontFamily: "Geist, Inter, system-ui, sans-serif"
    fontSize: "18px"
    fontWeight: 700
    lineHeight: 1.25
    letterSpacing: "-0.01em"
  tally:
    fontFamily: "Geist, Inter, system-ui, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 500
    lineHeight: 1.15
    letterSpacing: "-0.01em"
  body:
    fontFamily: "Geist, Inter, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "Geist, Inter, system-ui, sans-serif"
    fontSize: "12px"
    fontWeight: 400
    lineHeight: 1.4
  ref:
    fontFamily: "Geist Mono, ui-monospace, monospace"
    fontSize: "12px"
    fontWeight: 400
    lineHeight: 1.4
rounded:
  ref: "4px"
  control: "6px"
  panel: "8px"
spacing:
  card-gap: "12px"
  section: "16px"
  row-height: "44px"
  control-height: "36px"
  bar-height: "48px"
components:
  button-primary:
    backgroundColor: "{colors.oxide}"
    textColor: "{colors.oxide-ink}"
    rounded: "{rounded.control}"
    height: "36px"
    padding: "0.35rem 1rem"
  button-primary-hover:
    backgroundColor: "{colors.oxide-deep}"
  button-secondary:
    backgroundColor: "{colors.panel-soft}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    height: "36px"
    padding: "0.35rem 1rem"
  button-secondary-hover:
    backgroundColor: "{colors.line}"
  button-danger:
    backgroundColor: "{colors.danger}"
    textColor: "#ffffff"
    rounded: "{rounded.control}"
    height: "36px"
  button-quiet:
    backgroundColor: "transparent"
    textColor: "{colors.ink-soft}"
    rounded: "{rounded.control}"
    height: "32px"
    padding: "0.25rem 0.5rem"
  input:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    height: "36px"
    padding: "0.35rem 0.75rem"
  card:
    backgroundColor: "{colors.panel}"
    rounded: "{rounded.panel}"
  ref-chip:
    backgroundColor: "{colors.panel-soft}"
    textColor: "{colors.ink-soft}"
    typography: "{typography.ref}"
    rounded: "{rounded.ref}"
    padding: "0.1rem 0.4rem"
  stamp-pending:
    backgroundColor: "{colors.amber-wash}"
    textColor: "{colors.amber}"
    rounded: "999px"
    padding: "0.28rem 0.6rem"
  stamp-active:
    backgroundColor: "{colors.moss-wash}"
    textColor: "{colors.moss-ink}"
    rounded: "999px"
    padding: "0.28rem 0.6rem"
  stamp-ended:
    backgroundColor: "{colors.panel-soft}"
    textColor: "{colors.ink-soft}"
    rounded: "999px"
    padding: "0.28rem 0.6rem"
---

# Design System: Dot.Files

## Overview

**Creative North Star: "The Achromatic Ledger"**

A business ledger as working software. The chrome carries no colour at all: ink on a blue-grey
paper ground, one near-black primary action, a grey-pill secondary, figures set with tabular
numerals like entries in well-kept books. The single data hue (#1d5fbf) exists for exactly one
job — marking the screen's main data element — and nothing else. Owner-chosen 2026-10-31 from
four live full-system boards (design-taste-frontend sheet seed 2063257168, kind workspace); it
supersedes the Archive Room, the violet canon, and palette-swap theming alike.

Distinction comes from exactness — hairlines, spacing rhythm, type discipline, one accent —
never from world material, texture or costume (PRODUCT.md Brand Commitments). Surfaces are
flat; separation is a 1px rule or one tone step, never a shadow at rest. Sentence case
everywhere, no tracked capitals. The dark theme is the same ledger at night: the primary
inverts to a white pill with ink text, the data hue lifts to #7ba4ec, the flat discipline holds.

The source of truth is `resources/css/app.css`: tokens on `:root` (dark overrides on
`[data-theme='dark']` and `prefers-color-scheme`) plus the `fx-` component layer. The public
landing page (`resources/views/welcome.blade.php`) deliberately keeps its own separate system
(Fraunces/Work Sans/IBM Plex Mono); it is not part of this ledger and must not inherit from or
leak into it.

**Key Characteristics:**
- No colour anywhere in the chrome; the one accent is near-black.
- One data hue with exactly one consumer.
- Flat surfaces, 1px rules, zero shadow at rest (`--shadow: none` is a token).
- Geist everywhere read; Geist Mono strictly for references and IDs.
- Tabular numerals on everything that counts; sentence case throughout.
- Every number on screen is a real query — nothing fabricated.

## Colors

An achromatic chrome over a blue-grey paper ground, with one reserved data hue and tinted
status pairs confined to pills and notices.

### Primary
- **Ledger Ink** (`--oxide`, #141414): the primary action — a near-black fill with white text
  (`--oxide-ink`). Hover deepens to true black (`--oxide-deep`, #000000). Its 6% wash
  (`--oxide-wash`) marks the active view-toggle, the input focus halo, and the account
  initial chip. Dark theme inverts it: #f2f3f5 pill with #121316 ink text.

### Secondary
- **Ledger Blue** (`--data`, #1d5fbf; dark #7ba4ec): the single data hue. In the shipped build
  it has exactly one consumer — `.fx-tally--data`, applied to the "shared outside" tally only
  when it is non-zero. Never buttons, never navigation, never decoration.

### Tertiary
- **Moss** (`--moss` #2e7d32 on `--moss-wash` #dcfae5, ink #04250e): positive status — the
  "active"/"shared" stamp and good notices.
- **Amber** (`--amber` #92400e on `--amber-wash` #fffbeb): warning status — the "pending"
  stamp and cautionary notices.
- **Danger Red** (`--danger`, #c4242a; dark #ff8a80): destructive actions only — the solid
  danger button, the red-outline "Request deletion", and danger menu items.

### Neutral
- **Paper** (`--paper`, #f2f4f7): the page ground, one blue-grey level off white; also the
  top bar's background. Dark: #121316.
- **Panel** (`--panel`, #ffffff): all flat working surfaces — cards, tables, menus, dialogs.
  Dark: #1a1c20.
- **Panel Soft** (`--panel-soft` / `--kraft`, #eef0f3): the one-step raised tone — card faces,
  secondary buttons, ref chips, ended stamps. `--kraft-soft` (#f6f7f9) tints folder cards and
  register row hover.
- **Ink** (`--ink`, #0a0a0a): all read text, focus outlines, selection, caret and accent-color.
  **Ink Soft** (`--ink-soft`, #6b6b6b): secondary text, labels, quiet controls.
  **Ink Faint** (`--ink-faint`, #6f6f6f): placeholders and the faintest annotations.
- **Rule** (`--line`, #e6e6e6): the 1px hairline that does all separation. **Rule Strong**
  (`--line-strong`, #d4d4d4): input borders, menu borders, card hover, scrollbar thumbs.

### Named Rules
**The One Hue Rule.** The data hue (#1d5fbf / dark #7ba4ec) marks only the screen's main data
element — today, the non-zero "shared outside" tally. It never touches a button, a link, a nav
item, or an icon. A second consumer needs an owner decision, not a precedent.

**The Achromatic Chrome Rule.** Buttons, navigation, inputs, borders and text are ink and grey
only. The only tinted elements besides the data hue are the status stamp/notice pairs
(moss/amber/danger), and they stay inside pills, notes and destructive controls.

## Typography

**Display Font:** Geist (self-hosted variable, `public/fonts/geist-latin.woff2`, weights
400–700; fallback Inter, system-ui)
**Body Font:** Geist (same face — one voice for everything read)
**Label/Mono Font:** Geist Mono (self-hosted, `public/fonts/geist-mono-latin.woff2`, weights
400–500) — strictly for references and IDs via `.fx-ref`

**Character:** One quiet, modern grotesk set like ledger entries: tight headings (-0.01em),
tabular numerals wherever figures appear, sentence case everywhere. The world's voice never
depends on a CDN — both faces are self-hosted and preloaded.

### Hierarchy
- **Headline** (700, 18px, tight leading): page and section titles (`h1`–`h3` take Geist 700
  with -0.01em); dialog titles.
- **Tally** (500, 1.5rem / 1.15, tabular-nums, -0.01em): the ledger figures in the company
  head (`.fx-tally`); drops to 1.15rem under 640px.
- **Body** (400–500, 14px / 1.5): everything read — body copy, buttons, inputs, menus, table
  cells, nav links (500 for controls and links, 700 only for the current page and names).
- **Label** (400–500, 12px): tally labels, table headers (400, grey, no uppercase), stamps,
  card meta (tabular-nums).
- **Reference** (Geist Mono 400, 12px, tabular-nums): InfoDot references and IDs only, set as
  a `.fx-ref` chip on `--panel-soft`.

### Named Rules
**The Sentence Case Rule.** Sentence case everywhere; no tracked capitals, no uppercase labels
anywhere in the product. Even table headers are 12px lowercase-led grey text.

**The Mono-For-References Rule.** Geist Mono appears only inside `.fx-ref` — references and
IDs. Never paragraphs, never labels, never numerals outside a reference.

**The Tabular Figures Rule.** `font-variant-numeric: tabular-nums` on every element that
counts: tallies, card meta, the register, references, selection counts.

## Layout

No sidebar. A 48px top bar sits directly on the page ground (`--paper`, no border, no fill
change): real logo on the column's left edge, plain grey text links (`.fx-top-link`, 14px/500,
current page ink 700), account/company menu at the right; under 640px it collapses to a
toggle-revealed stack. Content lives in a `max-w-7xl` centered column (16/24/32px gutters),
sections stacked on a 16px rhythm (`space-y-4`).

The workspace reads top-down: company head (`.fx-roomhead` — initial plate, name, four tabular
tallies with 12px grey labels, one hairline below), toolbar (search grows, filter select, view
toggle, grey-pill "New folder", near-black "Upload documents"), then the records. Grid view is
the shelf: `repeat(auto-fill, minmax(min(100%, 212px), 1fr))` with 12px gaps. List view is the
register: 44px rule-separated rows, no zebra, row hover is one tone step (`--kraft-soft`).
Controls are 36px tall (quiet variant 32px); touch targets stay ≥36px. Responsive from 375px:
folder cards drop their face below 640px and become 52px rows; register columns shed by
breakpoint (`sm`/`md` hidden classes).

## Elevation & Depth

Flat, absolutely. `--shadow` and `--shadow-lift` are both `none` — the absence of shadow is
itself a token. Depth is conveyed two ways only: a 1px rule (`--line`) or one tone step
(`--panel` → `--panel-soft`/`--kraft-soft`). Even overlays stay flat: dialogs and menus are
bordered panels; the modal scrim is a flat `rgba(16, 17, 20, 0.55)` ink veil. The one glow-like
treatment is the input focus halo, `box-shadow: 0 0 0 3px var(--oxide-wash)` — a ring, not a
lift.

### Named Rules
**The No-Shadow Rule.** Nothing casts a shadow at rest, on hover, or when raised. If a surface
needs separation, give it a 1px `--line` rule or move it one tone step.

## Shapes

Three radii, descending with intimacy: 8px panels/cards/menus/notes/drop zones (`--r-panel`),
6px controls — buttons, inputs, selects, the initial plate (`--r-control`), 4px references,
checkboxes, focus outlines and link pills (`--r-ref`). Status stamps alone are full pills
(999px). Borders are always 1px (`--line` on surfaces, `--line-strong` on inputs and menus);
the upload zone is the single dashed border (1.5px dashed `--line-strong`). Icons are the
hand-authored product set in `components/fx/icon.blade.php`: one stroke weight (1.7), round
caps and joins, 18px default, always `currentColor` — no icon fonts, no glyph packs.

## Components

### Buttons
- **Shape:** softly squared (6px radius), 36px min-height, 14px/500 text, icon-text gap 0.45rem.
- **Primary** (`.fx-btn--oxide`): near-black fill (#141414), white text; hover deepens to
  black. One per view — the main action (Upload documents, Create folder). Dark theme: white
  pill, ink text.
- **Secondary** (`.fx-btn` base): grey pill on `--panel-soft`; hover steps to `--line`.
- **Quiet** (`.fx-btn--quiet`): transparent, grey text, 32px; hover gains ink text on
  `--panel-soft`.
- **Danger** (`.fx-btn--danger`): #c4242a fill, white text, hover darkens via brightness.
  **Danger outline** (`.fx-btn--danger-outline`): red text and currentColor border on white —
  the "Request deletion" voice.
- **Hover / Focus / Press:** background transitions 140ms on `--ease-out`; every button
  presses to scale(0.97) over 160ms; focus is the global 2px ink outline, offset 2px.
  Disabled: opacity 0.45.

### Chips (stamps and references)
- **Stamps** (`.fx-stamp`): full pills, 12px/500, three states — pending (amber on amber
  wash), active (moss ink on moss wash), ended (grey on `--panel-soft`). A freshly changed
  stamp scales in over 160ms (`.fx-stamp--fresh`).
- **Reference chip** (`.fx-ref`): Geist Mono 12px grey on `--panel-soft`, 4px radius,
  tabular, nowrap — the copyable signature detail.

### Cards / Containers
- **Panel** (`.fx-panel`): white, 1px `--line` border, 8px radius, flat.
- **Record card** (`.fx-card`): flat white card — snapshot face first (`.fx-card-face`,
  aspect 5/3.3 on `--panel-soft`, real preview image or the kind icon), then title (14px/500,
  two-line clamp) and tabular 12px meta. Hover/focus-within only strengthens the border to
  `--line-strong`. Folder cards tint to `--kraft-soft` with a shallower 5/2 face.
- **Empty states** (`.fx-empty`): centered icon in `--ink-faint`, one sentence, one next
  action — never a blank panel.

### Inputs / Fields
- **Style** (`.fx-input`, `.fx-select`): white fill, 1px `--line-strong` border, 6px radius,
  36px, 14px text; placeholders in `--ink-faint`.
- **Focus:** border turns ink and a 3px `--oxide-wash` ring appears (140ms).
- **Checkbox** (`.fx-check`): 1rem, 4px radius, 1.5px border; checked state uses the global
  ink `accent-color`.
- **Upload zone** (`.fx-drop`): dashed `--line-strong` on `--panel-soft`; hover/drag-over
  turns border and text ink. FilePond is reskinned to transparent panels with ink item bars.

### Navigation
- **Top bar** (`.fx-top`): 48px on the page ground, borderless. Links are plain grey 14px/500
  text (`.fx-top-link`); hover turns ink, current page is ink 700. Logo is the real mark
  (`public/images/logo.png`). Menus (`.fx-menu`) are flat white panels, `--line-strong`
  border, 8px radius, 0.3rem padding; items hover to `--panel-soft`; danger items in red.
- **Breadcrumbs** (`.fx-crumbs`): 13px grey links with `/` separators in `--ink-faint`;
  current folder ink 700.

### Register (signature list)
`.fx-register`: full-width, collapsed borders, tabular 14px. Headers are 12px/400 grey with a
hairline below; cells 44px with hairline rules, no zebra; row hover is one tone step to
`--kraft-soft`.

### Selection (signature interaction)
A card's checkbox is invisible until hover or focus-within; the moment anything is checked,
`:has(input:checked)` reveals every checkbox on the shelf and a quiet action bar surfaces —
tabular count, quiet Clear, red-outline Request deletion. On touch (`hover: none`) checkboxes
are always visible. Arrival is the staggered shelf rise: each child animates in over 260ms on
`--ease-out` (cubic-bezier(0.23, 1, 0.32, 1)) with 30ms steps, capped at 210ms; single panels
use `.fx-reveal` (280ms). `prefers-reduced-motion` kills every animation, transition and
smooth scroll globally.

## Do's and Don'ts

### Do:
- **Do** keep the chrome achromatic: ink (#0a0a0a), greys, and the near-black primary
  (#141414) are the entire action palette.
- **Do** reserve #1d5fbf (dark #7ba4ec) for the main data element only — today that is the
  non-zero "shared outside" tally via `.fx-tally--data`.
- **Do** separate surfaces with a 1px #e6e6e6 rule or one tone step; `--shadow` stays `none`.
- **Do** set every figure tabular, and every reference or ID in Geist Mono inside `.fx-ref`.
- **Do** build new UI from the `fx-` component layer and the `:root` tokens; both themes come
  free, and Tailwind utilities handle layout only.
- **Do** give every flow exactly one deliberate motion moment (shelf stagger, stamp-in,
  press), and let reduced-motion remove all of it.

### Don't:
- **Don't** put colour on a button, link, nav item or icon — not the data hue, not a brand
  hue. Moss/amber/red exist only inside stamps, notes and destructive controls.
- **Don't** add shadows, gradients, textures or costume anywhere; distinction is exactness,
  not material.
- **Don't** use uppercase or letter-spaced labels; sentence case everywhere, 700 weight only
  for headings and the current/selected item.
- **Don't** load fonts or type from a CDN in the product shell; Geist and Geist Mono are
  self-hosted in `public/fonts`.
- **Don't** import the landing page's system (Fraunces/Work Sans/IBM Plex Mono,
  `welcome.blade.php`) into the product, or push the ledger onto the landing page — they are
  deliberately separate worlds.
- **Don't** fabricate numbers, counts or previews to fill a layout; every figure on screen is
  a real query (PRODUCT.md).
