# Design: The Archive Room

Source of truth: `resources/css/app.css` (`fx-` component classes + tokens on `:root`) over
Tailwind utilities. This replaces the stock Jetstream look entirely.

## Concept
A company's records room. Warm paper ground, kraft-folder surfaces, oxide-red working accent
(Files' container colour in the Yard), documents presented as documents — a snapshot, a title, a
reference — filed on shelves (grid) or in a register (list). Kin to InfoDot's Yard through type
and references, never through copied components.

## Tokens (`:root`, dark overrides on `[data-theme="dark"]` and `prefers-color-scheme`)
- Ground: paper `#efece6` light / deep umber-black `#17140f` dark. Panels: white `#fbfaf7` /
  `#201c15`. Kraft (folder surfaces): `#d9c9a3` light / `#3a3122` dark.
- Ink: `#231f18` / `#ece7dd`. Muted: tinted from ink, never pure grey.
- Oxide (accent, Files' colour): `#b3402a` light / `#d05a40` dark. Oxide carries actions,
  focus rings, the FILES plate, approval badges.
- Rules: 1px `#cdc6b8` / `#3d382e`. Shadows: soft, offset (0 2px 8px rgba ink 10%), never halos.
- Radius: 10px panels, 6px controls, 3px references.

## Type
- Archivo (400/560/700) for everything read; Google Fonts.
- Big Shoulders Stencil Display ONLY for references (`fx-ref`), big numerals (storage tally),
  and the FILES plate. Never paragraphs, never labels.
- Body 15–16px; workspace density compact but never cramped (min 44px touch targets).

## Components (`fx-`)
- `fx-top`: graphite-umber top bar — oxide FILES plate (stencil), team name, search, account.
- `fx-room`: the workspace frame; `fx-roomhead`: business identity strip — team initial plate,
  name, real storage/figure tallies (stencil numerals), view toggle.
- `fx-shelf` (grid): snapshot cards — document preview or type-glyph front, title, meta line,
  reference strip at the card's foot. Folder cards read as kraft folder tabs.
- `fx-register` (list): rule-separated rows — reference, name, type, size, modified; the ledger
  idiom from the ecosystem.
- `fx-doc`: the preview panel — snapshot, title, metadata, sharing state, actions.
- `fx-approve`: approval centre rows with pending/active/revoked stamps (`fx-stamp`).
- `fx-drop`: the upload zone — a dashed kraft tray; progress is an oxide fill line.
- `fx-empty`: empty states with a drawn glyph and one next action; never a blank panel.
- Buttons `fx-btn` (quiet) / `fx-btn--oxide` (primary); inputs share token focus ring.

## Icons
Hand-authored inline SVG (1.5px stroke, round caps) via `components/fx/icon.blade.php`: folder,
file types (pdf, sheet, doc, image, archive, media, generic), share, clock, shield, download,
grid, list, search, plus, check, x, dots. No icon fonts, no emoji.

## Behaviour & motion
One authored moment per flow: card hover lifts 2px with shadow deepen (120ms ease-out); the
document page settles onto the desk once (`fx-reveal`, 320ms rise); upload progress is a
determinate oxide line; approval state changes stamp in once (reduced-motion: instant). Browser surfaces are
themed: selection (oxide on paper), caret oxide, scrollbars thin kraft, focus ring 2px oxide.

## Page layouts
- Workspace (`/files`): roomhead over the shelf/register, breadcrumbs as a file-path strip,
  upload tray docked to the header, context actions in a row-end menu.
- Open (`/o/…`) and Shared-with-me: a single `fx-doc` panel centred on paper.
- Approvals (`/approvals`): pending queue first (owner/admin), then history with stamps.
- Operator queue keeps its function, restyled to the room.

## Don'ts
No gradients, no icon fonts, no eyebrows/kickers, no same-size icon-card grids as page structure,
no stencil body text, no pure-grey text on paper, no public thumbnail URLs, no decorative counts.
