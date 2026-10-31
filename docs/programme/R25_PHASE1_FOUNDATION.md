# R-25 — Phase 1: architecture audit, security foundation, phase register (2026-10-31)

The owner's brief is verbatim in `requirements/dot-files-central-storage.md`. This report is the
Phase-1 deliverable: what Dot.Files actually is today (audited at HEAD, not from stale checkouts
or stale READMEs), what was fixed immediately, the architecture decisions for the phases ahead,
and the honest phase register.

## What Dot.Files is today (audited 2026-10-31)

Live at files.infodot.co.za with a manual deploy pipeline. Laravel 12 / Jetstream / Livewire.
The foundation is better than its own README says: `team_id` on all three domain tables with a
FAIL-CLOSED global scope (no current team ⇒ zero rows), a download policy honouring any of the
user's teams, closed registration, SSO v2 with origin checks, a mature cross-company share system
(email-bound, 7–90 day expiry, revocation on every lifecycle event, its own audit trail), an
operator-gated team/bulk deletion queue, and 112 passing tests including a genuine tenant-isolation
battery (id walking, uuid probing, search scoping, two-company membership).

## Fixed in this PR (Phase-1 security findings)

| Finding | Severity | Fix |
|---|---|---|
| Rename mass-assignment: the client-controlled Livewire state array was passed whole to `update()` — a crafted payload could rewrite `files.path` (point at another blob) or `files.team_id` (move the row cross-tenant) | High | rename writes exactly `name`; regression test proves `path`/`team_id` cannot move |
| Single delete could remove the team ROOT (wiping the whole tree, cascade unlinks every blob) and any populated folder, bypassing the operator gate built for bulk deletions | High | root is never deletable from the browser (403); a folder with children becomes a pending approval request; files and empty folders stay instant |
| Account deletion destroyed owned company teams' files as a side effect | High | deletion refused while the person owns a company team, with a clear message (transfer or approved team deletion first) |
| Bulk deletion requests stored unvalidated client ids; operators approved blind counts | Medium | ids validated server-side against the team; names snapshotted at request time and shown in the queue |
| Livewire's temporary-upload default capped uploads at 12 MB before the app's 100 MB validator ever ran | Medium | config published; cap aligned at 100 MB |
| No security headers on the live site (missed by the R-22 census) | Medium | fleet package: enforced baseline + report-only CSP + hardened `/csp-report` |
| Tests silently ran against the ambient `.env` database (pgsql!) | Medium | phpunit pinned to in-memory sqlite |
| No CI ran tests at all; deploys ungated | Medium | `ci.yml` added (tests + Pint) |
| Deploy's exposure probe never checked the blob directory or the search index | Low | `storage/app/files/` and `storage/objects.index` added to the probe |

Suite after: **122 passed** (6 new security regressions + 4 header tests).

## Architecture decisions for the phases ahead (grounded in the audit)

1. **Tenant** stays the Jetstream team keyed to InfoDot by `infodot_team_uuid` — no second identity.
2. **The unscoped contexts are the danger**: the global scope only guards authenticated web
   requests. Every job, console command and signed API endpoint must carry an EXPLICIT tenant.
   Rule for all new code: service-layer methods take `Team $team` as the first argument; no
   model query outside a request may rely on the global scope. (The existing signed endpoints
   already do this; it becomes the written contract.)
3. **Blobs**: move new writes to a per-tenant prefix (`files/{team_uuid}/…`) with a lazy migration
   for existing blobs; add `sha256`, `mime`, `size-verified`, `uploaded_by` columns to `files`
   (Phase 2). Keep `local` disk but behind a named `files` disk indirection so object storage is a
   config change later.
4. **References for the ecosystem**: `files.uuid` becomes unique+indexed and immutable (creation
   no longer overwrites supplied uuids) — the cross-platform reference is `file uuid` + InfoDot's
   `infodot_reference`, never numeric ids.
5. **Platform integration (Phase 3)** reuses the proven per-platform signed-HMAC pattern already
   used by InfoDot↔Files and Billing (`X-InfoDot-*` headers, per-platform secrets, 300s window),
   extended with: per-platform SCOPES (`files:write`, `files:read`, `files:move`), idempotency
   keys on writes, and signatures bound to method+path (closing the audit's note). Platforms get
   folders under a `Platform Files` area per tenant; move/copy semantics per the brief §5, with
   cross-tenant transfer requiring the approval queue that already exists.
6. **Activity ledger (Phase 4)** generalises the proven `file_share_events` design: one
   `file_events` table (tenant, actor, event, file/folder uuid, name snapshot, correlation id,
   result), written by a service, append-only; the share events migrate in.
7. **Search**: fix rename→reindex staleness and add pagination before any content indexing;
   content extraction only after per-tenant index partitioning (TNT index is currently one file).
8. **No second storage architecture**: Dot.docs integration happens through the Phase-3 API, not
   shared disks (the audited repo has NO docs coupling today — earlier reports of one came from a
   stale checkout).

## Phase register

| Phase | Scope | Status |
|---|---|---|
| 1 | Audit, security findings fixed, CI, headers, decisions | **THIS PR — IMPLEMENTED, suite green** |
| 2 | Core management gaps: uploader/mime/checksum columns, immutable uuids, per-tenant blob prefix + named disk, move/copy within a tenant, trash (soft delete) + restore, folder download, rename→reindex, pagination | NOT STARTED |
| 3 | Platform integration: registry, scoped HMAC credentials, upload/retrieve/move APIs, events, pilot (Dot.docs first, then Dot.HR or Dot.Finance) | NOT STARTED |
| 4 | Activity ledger + audit UI, admin/quota dashboard, retention/legal hold | NOT STARTED |
| 5 | Design transformation (installed design skills; Dot design language) | NOT STARTED |
| 6 | Hardening: previews (sandboxed), malware scanning decision, performance, blob backups (dot-backup covers DB + uploads ≤200MB/app — verify Files' blob volume fits or extend), production verification | NOT STARTED |

## Risks carried forward

The blob directory sits inside the app folder on a shared docroot layout; the deny-file guard and
the (new) probe paths are the control — verify on the next deploy. Blob backups: confirm
`dot-backup`'s uploads cap covers `storage/app/files` or raise it for this app. No soft delete
yet: operator-approved deletions are irreversible until Phase 2's trash ships.
