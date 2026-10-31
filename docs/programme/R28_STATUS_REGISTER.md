# R-28 — Master document-platform prompt: evidence-based status register

Brief: `docs/programme/requirements/dot-files-document-platform.md` (verbatim). The owner asked
for everything to be checked before implementing; most of Parts A–D shipped earlier the same day
(PRs #113–#115). This register maps every part to its real state. Statuses: **Completed and
verified** · **Implemented, verification pending** · **Partially implemented** · **Not
implemented** · **Blocked**.

| Part | Requirement | Status | Evidence |
|---|---|---|---|
| A §2 | Distinctive identity, full design system | **Completed and verified** | Achromatic Ledger (#115 live): DESIGN.md + .impeccable/design.json, finish review "ship", both themes/widths |
| A §3 | Search bar matches InfoDot exactly | **Completed and verified** | InfoDot's component ported value-for-value from infodot.css (pill 44px/#f6f4ee, gold-arrow go, Ctrl K, dropdown anatomy, Archivo self-hosted, its own light/dark tokens); FileSearch Livewire on Files data; EcosystemSearchBarTest (tenant scoping, reference jump, trash exclusion, go-button lands filtered) |
| A §4–6 | Whole-app redesign, copy, motion | **Completed and verified** | #113/#115: every surface restyled, copy pass, motion grammar, reduced-motion kill |
| B §7–12 | Real snapshots, pipeline, secure previews, viewer | **Completed and verified** (within host capability) | #113 live: GD images + Imagick PDFs capability-detected, async afterResponse, policy-checked routes, inline PDF pager; previews regenerate on every new version (this PR). **Partially implemented**: Word/Excel/PPT/video content thumbnails (host has no converter/ffmpeg — glyph fallback, honest); hover-zoom previews not built |
| C §13–18 | InfoDot sole gateway; no local auth pages | **Implemented, verification pending (on host)** | SSO-only mode now redirects login AND register, forgot-password, reset-password to InfoDot (FortifyServiceProvider + /register route; tested in WorkspaceR27Test). POST handlers stay for break-glass. **Blocked on owner**: host .env needs ECOSYSTEM_SSO_ONLY=true (host APP_ENV is not "production"), then config:cache |
| D §19–21 | Central storage, platform references | **Partially implemented** | Platform file API live (#114): per-platform secrets, origin-scoped store/fetch/meta/list/trash + **update** (this PR). References = uuid + origin scoping; richer scoped reference objects with expiry not built |
| D §22 | Controlled synchronisation | **Completed and verified** (for the shipped API surface) | update endpoint versions every write; conflict detection shipped in the follow-up PR: optional `base_version` is checked inside the write transaction — stale base → 409 `version_conflict` with `current_version` + checksum, nothing written; same guard on the web Replace form (hidden `base_version`, refusal note). Tested both paths |
| E §23–24 | Universal sharing, granular permissions | **Completed and verified** (for the honest preset set) | Person-shares with owner approval, expiry, revocation, audit (#113) + follow-up PR: permission presets **View only / View and download** stored per share and enforced at the copy hand-over (shared download 403 on view-only), carried through member requests and approvals; **multi-select sharing** from the workspace (one recipient+preset, a share per record, folders fall out, per-file failures reported). Not claimed because Files cannot honour them: comment/edit presets (no editor here — editing is the platform integrations' job); destination-platform shares remain future §20 work |
| E §25 | Ownership + approval governance | **Completed and verified** | Server-side status gate, approvals desk, audit trail (ShareApprovalTest) |
| F §26–27 | Version control + premium history UI | **Completed and verified** (this PR) | file_versions append-only (own blob + sha-256 per version, who/when/platform/action), web replace/restore/download-version, platform update, document-page trail; restore preserves intervening history (VersionHistoryTest). **Not implemented**: content-level diff/comparison (no reliable comparison for these formats — stated, not faked) |
| F §28 | Tamper-resistant audit | **Partially implemented** | Version rows and share/platform events are append-only in code (no mutating route exists; versions delete only with the file). DB-level immutability (triggers/WORM) not in place on shared MySQL |
| F §29 | Suspicious-activity monitoring | **Completed and verified** (owner policy 2026-10-31) | Owner chose: hide-the-evidence family only, review-only flags, governance centre + owner/admin email. Shipped: `file_flags` + `FileGovernance` (version-churn ≥5/60min and replace-then-trash ≤30min, env-tunable via config/governance.php, human actions only, deduped while open), evaluated opportunistically after the triggering action (no scheduler exists on this host), "Activity to review" on /approvals with plain evidence and Mark reviewed / Dismiss (decision recorded, flag never deleted), SuspiciousActivityAlert mail to the company's owner+admins (failures reported, never break the flag). Declined by owner (not built): mass-removal, sharing-burst, platform-volume rules; auto-brakes. **Owner item: MAIL_* env on the Files host — untested there** |
| G §30–31 | End-to-end workflow, conflict resolution | **Completed and verified** (detection + refusal) | Shares + API update + versions carry the workflow; concurrent edits: stale `base_version` refused atomically (API 409 / web refusal note), both states preserved in the trail, caller reconciles and retries. Live co-editing inside one format remains each editor platform's job (stated, not claimed) |
| H §32 | Governance centre | **Completed and verified** (this PR) | /approvals now: Pending, Currently shared, **Shared by me**, **Platform connections** (real audit-trail counts), Settled |
| I §33–35 | Integration infra, isolation | **Completed and verified** for what exists | HMAC per-platform auth, structural tenant+origin scoping, 148 tests incl. cross-tenant/cross-platform 404s; event names use the existing platform_file_events convention |
| K Phase 6 | Dot.docs/Finance/HR/… integrations | **Blocked / Not implemented** | Consumer wiring lives in each platform's repo against PLATFORM_FILE_API.md; Dot.Finance/HR/Analytics/Tasks are not deployed platforms today (ecosystem registry: active=false). Dot.docs is the natural pilot |
| M | Scenarios | 1,4,7,8,9,10,11 **Completed and verified** (automated tests + live rounds); 2,3 **Blocked** on consumer wiring (API side tested); 5 partial (trail yes, alerting no); 6 **Completed and verified** |

## Verification this PR
148 tests green (9 new across VersionHistoryTest, EcosystemSearchBarTest, extended auth-redirect
assertions), Pint clean, detector clean; live browser round: the InfoDot pill in the ledger bar
(dropdown scoped to the company, reference tags), version panel with Replace/Restore/Current,
platform-connections and shared-by-me sections.

## Recommended next priorities
1. Owner host step (unblocks Part C fully): `ECOSYSTEM_SSO_ONLY=true` + `config:cache`.
2. Dot.docs consumer wiring (first real §20 flow; its repo, against the API contract).
3. ~~Conflict detection on platform `update`~~ — shipped (stale `base_version` → 409, web guard).
4. ~~Suspicious-activity rules + alerts~~ — shipped per the owner's policy (hide-the-evidence, review-only, email+desk).
5. ~~Multi-select shares and permission presets~~ — shipped (view-only/download presets enforced server-side; bulk share from the selection bar).
