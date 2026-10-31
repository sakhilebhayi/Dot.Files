# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users
Businesses registered through InfoDot and their people: owners who are accountable for the
company's records, administrators who organise them, and members who upload and use them daily.
They arrive mid-task — to file something, find something, or share something — and they judge the
company (and the ecosystem) by how its records room feels.

## Product Purpose
Dot.Files is each business's secure digital filing room inside the Dot ecosystem: upload,
organise in folders, find, preview, and share business documents — with the owner in control of
who sees what. It receives people through InfoDot sign-in and will hold files for the other Dot
platforms as the integration layer (R-25 Phase 3) ships. Today's real capabilities: folders and
files per company, search, downloads, cross-company shares to named verified people with expiry
and revocation, an operator-approved deletion gate, InfoDot references on files.

## Positioning
A premium, standalone-feeling file workspace that happens to be connected to the Dot ecosystem.
Owner decision (2026-10-31, replacing the Archive Room world): Dot.Files executes the **category
standard at full craft** — the clean minimal file product that sits alongside Linear, Dropbox
and Apple. Distinction comes from exactness (hairlines, spacing rhythm, type discipline, one
accent), never from world material, texture or costume. It must not resemble InfoDot's Yard,
the dark control-room platforms, or the stock Jetstream look.

## Operating Context
Laravel 12 + Livewire 3 + Tailwind 3 (Vite) on shared cPanel hosting; blobs on the local disk
under the app (deny-file guarded, probe-verified); manual deploy workflow; tests on in-memory
sqlite; no queue worker (afterResponse only). Preview rendering is capability-detected: GD always,
PDF first pages only where Imagick/Ghostscript exists on the host — never faked.

## Capabilities and Constraints
Tenant isolation is the hardest requirement: team-scoped global scope (fail-closed), policies on
serve paths, no public blob or thumbnail URLs ever. Owner approval governs sharing: members may
request, owners/admins approve; a pending share grants nothing server-side. Only features that
work appear in the UI. No fabricated counts, activity or previews — every number on screen is a
real query.

## Brand Commitments
Name: Dot.Files. **Owner-chosen direction (2026-10-31, from four live boards; supersedes every
earlier commitment that day): the Achromatic Ledger** — design-taste-frontend app sheet, seed
2063257168. Blue-grey ledger ground (#f2f4f7), flat white surfaces separated by 1px rules with
no shadow at rest, no colour in the chrome: a near-black primary, grey-pill secondary, figures
set in ink with tabular numerals, the single data hue (#1d5fbf) reserved for the main data
element. Geist for everything read; Geist Mono strictly for references and IDs; sentence case,
no tracked capitals. Status pills in the sheet's positive/warning/neutral pairs. Dark theme is
the same ledger at night (inverted white-pill primary, hue lifted). The real logo
(public/images/logo.png) is the mark. The public landing page keeps its own separate system.

## Evidence on Hand
Only the tenant's own data. No customer counts, testimonials or compliance claims exist and none
may be invented.

## Product Principles
- A document looks like a document: snapshot first, filename second, never a bare table of names.
- The reference (CODE-style) is the signature detail, set in stencil, copyable.
- The owner can always answer "who has access?" in one screen.
- Destructive and sharing actions explain themselves in plain language before they happen.
- Motion is one deliberate moment per flow; the room itself stays still.

## Accessibility & Inclusion
Contrast ≥4.5:1 both themes; keyboard operable throughout; visible focus; reduced motion
respected; responsive from 375px; plain language (South African business audience, POPIA-aware).
