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
| D §22 | Controlled synchronisation | **Partially implemented** | update endpoint gives last-write-wins WITH full version trail (no silent loss — every state kept, checksummed); conflict DETECTION (stale-version refusal) not built |
| E §23–24 | Universal sharing, granular permissions | **Partially implemented** | Person-shares with owner approval, expiry, revocation, audit (#113). Multi-file/folder shares, destination-platform shares, permission presets beyond view+download: not built |
| E §25 | Ownership + approval governance | **Completed and verified** | Server-side status gate, approvals desk, audit trail (ShareApprovalTest) |
| F §26–27 | Version control + premium history UI | **Completed and verified** (this PR) | file_versions append-only (own blob + sha-256 per version, who/when/platform/action), web replace/restore/download-version, platform update, document-page trail; restore preserves intervening history (VersionHistoryTest). **Not implemented**: content-level diff/comparison (no reliable comparison for these formats — stated, not faked) |
| F §28 | Tamper-resistant audit | **Partially implemented** | Version rows and share/platform events are append-only in code (no mutating route exists; versions delete only with the file). DB-level immutability (triggers/WORM) not in place on shared MySQL |
| F §29 | Suspicious-activity monitoring | **Not implemented** | No behavioural alerting; the trails exist to build it on. Needs an owner decision on rules/alert channel |
| G §30–31 | End-to-end workflow, conflict resolution | **Partially implemented** | The workflow works through shares + API update + versions; concurrent-edit conflict flow not built |
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
3. Conflict detection on platform `update` (refuse stale `base_version`, tiny addition).
4. Suspicious-activity rules + alerts (needs owner's policy choices).
5. Multi-select shares and permission presets in the workspace UI.
