# R-27 — Dot.Files premium product experience: delivery report

Brief: `docs/programme/requirements/dot-files-premium-experience.md` (verbatim).
Built on the R-26 Archive Room (same branch/PR as R-26; R-25 is the security foundation).

## Identity (brief §1–2)

The Archive Room is Dot.Files' own world — warm paper ground, kraft folder surfaces, oxide-red
working accent, hand-drawn icons, stencil reserved for references and tallies. It shares nothing
with InfoDot's layout, navigation, components or colours; the one deliberate kinship is the
Archivo type family and the reference idiom, which mark ecosystem membership the way a letterhead
does. Contracts: `PRODUCT.md` + `DESIGN.md`. Both themes ship; captures in `.impeccable/review/`.

## What R-27 added on top of R-26

- **InfoDot-only entry (§9).** In production the login page sends visitors to InfoDot
  (`ecosystem.sso_only`, default on in production only); they come back through InfoDot's launch
  POST to `/auth/ecosystem` as before. Registration was already closed. Break-glass:
  `ECOSYSTEM_SSO_ONLY=false`. Local dev and the test suite keep the plain form.
- **Pins (§4).** `file_pins` — personal bookmarks shown as a strip at the root; a pin grants
  nothing (team-scoped reads; forged pins resolve to nothing — tested).
- **Trash and restore (§4).** Single-click deletes park the record in the trash for 30 days
  (`objects.trashed_at/trashed_by`); restore puts it back (re-rooted if its folder went);
  outside access through shares goes dark while trashed; owner/admin may delete for good;
  `files:purge-trash` + an opportunistic purge on the trash page (this host has no scheduler
  cron, by design). The operator-approved bulk gate is unchanged.
- **Inline document rendering (§6).** `/files/{file}/inline` streams the blob inline under the
  download policy for passive formats only (PDF, raster images, plain text/CSV) — the document
  page renders PDFs in the browser's own pager, text as an escaped excerpt. Active content
  (SVG/HTML/office) never renders inline, deliberately.
- **Discovery (§5).** Kind filter (everything/PDFs/documents/spreadsheets/images/media/archives),
  `/` focuses search, drag-and-drop anywhere opens the upload tray (FilePond `dropOnPage`).
- **Document page (§5–6).** Uploader recorded on new files (`files.uploaded_by`) and shown;
  InfoDot origin chip; owner/admin sharing trail (audit events) on the page; pin/unpin action.

## Honest integration audit (§7, "do not assume")

Operational today: InfoDot identity (launch POST + redeem), InfoDot membership webhooks/team
sync, the file pointer registry (uuid + reference per file), deep links (`/o/{uuid}`), and
cross-company person-to-person shares with owner approval. **Not yet built, reported as such:**
the platform file API for other Dot platforms (Dot.docs, Dot.Finance, Dot.HR… — R-25 Phase 3),
destination-platform shares, multi-file share batches, version history (no version model
exists), spreadsheet/office server-side previews (host has no converter), PDF page-thumbnails
beyond page 1 (needs Imagick; the inline viewer gives full paging in the browser instead).
Nothing in the UI pretends otherwise.

## Verification

137 tests green (5 new R-27 tests covering trash lifecycle + tenant isolation + purge, pins
granting nothing, SSO-only entry, inline policy/passive-formats). Pint clean. Browser-verified
live: pin → strip, delete → trash → restore, PDF inline render, both themes, 1440px and 390px.

## Deploy notes (owner)

1. `php artisan migrate --force` (three additive migrations since #112).
2. `php artisan files:previews` once (snapshot backfill; prints real GD/Imagick capability).
3. Confirm `DOT_INFODOT_URL` is set on the host (it is the SSO redirect target); production
   SSO-only entry then needs no new env. To keep the old local login form in production
   temporarily, set `ECOSYSTEM_SSO_ONLY=false`.
