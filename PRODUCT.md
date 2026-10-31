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
A sibling of InfoDot's "Yard": in the Yard's container language Files is the OXIDE-RED door. Its
own identity is **the Archive Room** — the company's organised records room: warm paper ground,
kraft-folder tones, oxide-red as the working accent, documents that look like documents. It must
not resemble the dark control-room platforms (Memory, Central), the stock Jetstream look it
replaces, or a generic SaaS drive clone.

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
Name: Dot.Files. Type: Archivo (read), Big Shoulders Stencil Display strictly for references and
big numerals (Yard kinship). Accent: oxide red. Light-first with an equal-quality dark room.
Ecosystem logos in `resources/img` where provided; no invented marks.

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
