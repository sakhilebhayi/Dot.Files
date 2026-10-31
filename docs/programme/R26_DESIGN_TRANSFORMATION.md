# R-26 — Dot.Files premium design transformation (the Archive Room)

Brief: `docs/programme/requirements/dot-files-design-transformation.md` (verbatim).
Design contracts: `PRODUCT.md` and `DESIGN.md` at the repo root.

## What changed

**Identity.** Dot.Files now has its own visual world, "the Archive Room": warm paper ground,
kraft folder surfaces, oxide red as the working accent (Files' container colour in the
ecosystem's Yard language), Archivo for everything read, Big Shoulders Stencil Display strictly
for references, tallies and the FILES plate. Light and dark rooms, tokens on `:root` in
`resources/css/app.css` (`fx-` component classes). Montserrat and the Font Awesome CDN are gone;
icons are hand-authored inline SVG (`resources/views/components/fx/icon.blade.php`).

**Workspace.** `/files` is a business workspace: a room head with the company's identity and
real tallies (documents, folders, bytes on file — live queries, nothing invented), shelf (grid)
and register (list) views with a per-session toggle, document snapshot cards, breadcrumb path,
inline rename, kraft upload tray (FilePond kept), empty states with a next action. All existing
behaviour — search, new folder, rename, delete + operator-gated bulk deletion, selection — is
preserved on the same Livewire component.

**Document snapshots** (`FilePreviewService`, `files:previews`). Rendered server-side from the
real blob after upload (`afterResponse`): raster images via GD, PDF first pages via Imagick
where the host has it. Capability-detected and honest — unsupported formats and missing
capabilities keep the type glyph (`preview_status: none`); SVG is never rasterised (active
content). Snapshots are flat JPEGs on the private disk, streamed only through policy-checked
routes (`files.preview` for the company, `shared.preview` re-checked against the live share).
No public thumbnail URLs exist.

**Owner-approved sharing** (`/approvals`). Members may now ASK to share a file
(`files.share-request`); owners/administrators decide on the approvals desk. The gate is
server-side: `FileShare::active()` requires `status=active`, and every open/download already
runs through it, so a pending or declined request grants nothing whatever any UI shows.
Direct owner shares are unchanged (status `active` immediately; a matching pending request is
absorbed as the approval). All decisions land in the existing `file_share_events` audit trail
(`requested` / `approved` / `declined`).

**Other surfaces** restyled in the same system: document page (`/o/{uuid}`) with the big
snapshot, share page (manage panel for owners, request form for members), shared-with-me,
recipient page, request-access, operator deletion queue, navigation (team switcher kept).
`/dashboard` now redirects to `/files`.

## Verification

- 132 tests green (10 new: `FilePreviewTest`, `ShareApprovalTest`), Pint clean,
  `impeccable detect --json` → no findings.
- Browser-checked on a seeded two-business sqlite DB: tenant isolation (each business sees only
  its own records), request → approve → recipient access end to end, snapshots served only to
  authorised eyes, dark + light, phone + desktop widths.

## After deploying

1. Run `php artisan migrate --force` (deploy does) — two new migrations, both additive.
2. Run `php artisan files:previews` once to backfill snapshots for existing files. It prints
   the host's real capability (GD / Imagick). If the cPanel PHP lacks Imagick+Ghostscript,
   PDFs honestly keep their glyph; re-run with `--retry` if the host gains the extension.
3. Nothing else: no new env keys, no queue requirements (everything is `afterResponse`).
