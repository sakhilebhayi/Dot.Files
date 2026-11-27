# Enterprise readiness audit — Phase 1 baseline and remediation plan (Dot.Files)

Brief: `~/Dot/briefs/ENTERPRISE_READINESS_AUDIT.md` (owner, 2026-11-26), run on Dot.Files on 2026-11-27. Structure copied from
InfoDot R-72 (`InfoDot/docs/programme/R72_ENTERPRISE_AUDIT.md`), findings measured afresh.

Audited: `main` at `3d3e7cf` (default branch confirmed with `gh repo view`). Live site: `https://files.infodot.co.za`.
Deploys are manual only (`deploy.yml`, `workflow_dispatch` with `confirm=deploy`), so merging does not deploy.

Method:
- Three read-only code audits in parallel: security and tenancy; functional, performance, code and CI; email, notifications,
  operations and privacy.
- The full PHPUnit suite, PHPStan, Pint, `composer audit` and `npm audit`.
- Live checks from a normal client (headers, TLS, status codes, the SSO sign-in path, DNS).
- A rendered pass of a local copy, signed in as a throwaway test account: desktop and 375 px, light and dark, axe-core
  (WCAG 2.2 A/AA) on 10 pages.
- Every Critical and High finding was re-checked against the code. The Critical one was also reproduced with a throwaway local test.

Nothing in production was changed and no real data was touched. Exploit detail for security findings is kept out of this
public repository, in the owner's private audit folder.

## 1. Measured baseline

| Measure | Value | Source |
| --- | --- | --- |
| Size | 89 PHP classes in `app/`, 9 Livewire components, 13 models, 2 policies, 40 test files | file counts |
| Tests | **445 pass, 2,731 assertions**, 12 s (PHP 8.5, SQLite). CI runs them on PHP 8.4 | `phpunit` |
| Static analysis | PHPStan level 5: **99 errors**; not run in CI | `phpstan analyse` |
| Style | `pint --test`: **fails** (`DeleteUser.php`). The CI step passes anyway, because it only checks changed files | `ci.yml` |
| Dependencies | `composer audit`: **16 advisories in 8 packages** (laravel/framework low; symfony/mime medium; symfony routing, http-foundation, http-kernel, mailer, filesystem and yaml unrated). `npm audit`: **1 high** (follow-redirects) | audits |
| Live headers | HSTS 180 days, nosniff, `X-Frame-Options`, Referrer-Policy, Permissions-Policy. CSP is **report-only**, with `unsafe-inline` and `unsafe-eval`. Session cookie secure, httponly, lax | `curl -I` |
| Live TLS | TLS 1.2 and 1.3 only; Let's Encrypt certificate valid to 2027-01-16 | `openssl` |
| Live sign-in | `/login` (GET, JSON, Bearer, JSON POST) all go to `/sso/login`. dot-sso-client 0.1.1 is live. `/register` and `/forgot-password` go to InfoDot | `curl` |
| Live exposure | `.env`, `.git`, logs → 403; vendor, phpinfo → 404; `POST /up` → 405 with no debug page | `curl` |
| Mail DNS | SPF `~all`; DMARC `p=none` with no reporting address | `dig` |
| Accessibility (axe) | 0 violations on files, trash, shared, approvals, terms, privacy and cookies. **Colour contrast (serious)** on profile (26 nodes) and team settings (16) in dark mode, and on the welcome page (5) | axe-core 4.10 |

## 2. Initial scorecard (brief's weights)

| Area | Weight | Initial | Basis |
| --- | ---: | ---: | --- |
| Security and data protection | 20% | **5.0** | **One Critical (S-01): cross-company access to outside shares.** Below it the base is strong: fail-closed team scope, scoped bindings, PKCE/nonce SSO, path-only local-auth interception, safe uploads and serving, no injection or XSS found. Lost on replayable signed requests (S-02), platform secrets not scoped to entitled companies (S-03), report-only CSP (S-04), PDF rendering hardening (S-05), dependency advisories (S-10) and an outdated privacy policy (D-01). |
| Functional correctness and regression stability | 15% | **6.5** | 445 green tests with good isolation coverage. Lost on: a dead "New folder" button on the first-run screen (F-01, seen in the browser); search crowding across tenants (F-02); rename not re-indexed (F-03); no transactions around blob and row writes (F-04); version races (F-05); writes into trashed parents (F-06); operator approval deleting outright and skipping the trash (F-07). |
| UX/UI quality and usability | 15% | **6.5** (partially verified) | The ledger desk is coherent at desktop and 375 px with no horizontal overflow. But in dark mode the Jetstream profile and team pages are unreadable: titles, field values and Save are near-invisible (U-01). The team page asks for an "existing account" in an SSO world (U-02). No pending-count signal for approvals (U-03). Checked with an empty company only. |
| Accessibility | 10% | **6.5** (partially verified) | Main desk pages are axe-clean, reduced motion and focus rings are in place (#106–#108). Lost on contrast failures (U-01, A-01), no accessibility checks in CI (A-02), and no manual screen-reader pass yet. |
| Email and notification reliability | 10% | **4.0** | Two mailables, carefully built (claim code: hashed, throttled, single use; governance alert: deduplicated, isolated per recipient). But production mail has never been verified (E-01), the sharing and deletion lifecycle sends nothing by mail or in-app (E-02), there is no notification centre (E-03), and DMARC is `p=none` (E-04). |
| Performance and scalability | 10% | **4.5** | Downloads and previews stream. But the uuid lookup columns used by every deep link and API call have no index (P-01). Queries bind every team file id (P-02). Listings are unbounded and re-query on every click (P-03). 100 MB uploads are held in memory (P-04). PDF previews render inside web workers (P-05). **No response time has been measured.** |
| Code quality and architecture | 10% | **6.0** | Clear services (`FileShareService`, `FileVersions`, `FileGovernance`) and SHA-256 drift guards on vendored packages. Lost on PHPStan's 99 errors outside CI (Q-01), a 781-line `FileBrowser` doing seven jobs (Q-02), and duplicated limits (Q-03). |
| Infrastructure and operational reliability | 10% | **5.0** | Self-checking deploy (debug forced off, assets, health and folder probes), `/up` fails on database loss, branded error pages. Lost on: deploy not gated on CI, no rollback and no pre-migration dump (O-01); logs that never rotate and default to debug (O-02); no outside monitoring (O-03); the owner's cron order not adopted (O-04); a log workflow copied from another platform (O-05); no `SECURITY.md` or Dependabot config (O-06). |
| **Weighted total** | 100% | **5.6 / 10** | |

**Gate:** per the brief, no overall score stands while S-01 (Critical) is open. Fixing it is phase EA-0 and comes before everything else.

Accepted owner decisions (recorded, not scored as defects):
- Nightly backups are paused until the ecosystem is complete.
- Every platform runs only essential (email/notification) cron jobs.
- Branch-protection rules are the owner's call.

## 3. Findings register

Severity per the brief. "Owner" means the item needs the owner's access, credentials, DNS or a decision.

### Security and data protection
| ID | Sev | Finding | Evidence |
| --- | --- | --- | --- |
| S-01 | **Critical** | Account identity attributes can be changed in a way that defeats the share-recipient check; files shared with outside addresses can be reached across companies. Reproduced locally | private file; `UpdateUserProfileInformation.php`, `FileShareService::activeShareFor` |
| S-02 | Medium | Signed server-to-server requests (platform file API, InfoDot membership) have no replay protection inside the 5-minute window, and the signature does not cover method or path | `PlatformRequest.php:18`, `SignedRequest.php:16` |
| S-03 | Medium | A platform's API secret is not limited to companies entitled to that platform | `PlatformFileController.php:244` |
| S-04 | Medium | CSP is report-only and allows `unsafe-inline` and `unsafe-eval` | `SecurityHeaders.php:31`, live headers |
| S-05 | Medium | Uploaded PDFs are rasterised by Ghostscript in the web process with no resource limits (host version unknown) | `FilePreviewService.php:134` |
| S-06 | Low | A Livewire panel id is not locked; read-only exposure of another company's sharing trail to a member of both | `FileShares.php:17` |
| S-07 | Low | No throttle on the two-factor challenge (break-glass mode only) | `config/fortify.php:90` |
| S-08 | Low | Trusted proxies are not configured and `SESSION_SECURE_COOKIE` is unset (both correct live today; fragile) | `bootstrap/app.php:34`, `config/session.php:171` |
| S-09 | Low | The legacy `/auth/ecosystem` hand-off accepts weak same-site signals | `EcosystemAuthController.php:56` |
| S-10 | Medium | 16 composer advisories (8 packages) and 1 high npm advisory; CI runs no dependency audit | `composer audit`, `npm audit` |
| S-11 | Low | Inline PDFs are not sandboxed; `Content-Disposition` is not RFC 6266 encoded; `svg` remains in Livewire `preview_mimes` | `FileController.php:66-81`, `config/livewire.php:71` |
| S-12 | Low | `.env.example` ships `APP_DEBUG=true` and `LOG_LEVEL=debug`; default password rule; Sanctum tokens never expire | `.env.example`, `config/sanctum.php:32` |
| D-01 | High | The privacy policy predates sharing, versions, trash and governance monitoring, and says there is no sharing model (POPIA s18 openness) | `resources/markdown/policy.md:31,39` |
| D-02 | Medium | Share events and flags (outside email addresses, behaviour flags) are kept forever and outlive account deletion | share-events and flags migrations |
| D-03 | Medium | The 30-day trash purge runs only when someone opens that company's trash page, so "deleted" data can be kept indefinitely | `TrashController.php:22` |
| D-04 | Low | No self-service data export (POPIA s23 requests are handled by email only) | routes |

### Functional correctness
| ID | Sev | Finding | Evidence |
| --- | --- | --- | --- |
| F-01 | High | First-run "New folder" button does nothing: it sets `creatingNewFolder`, but the form renders only outside the first-run branch | `file-browser.blade.php:43,130`; seen in the browser |
| F-02 | High | Search: one TNT index for all tenants, filtered after the engine's limit, so other companies' hits can crowd out a company's own (needs a crowding test to measure) | `FileSearch.php:51`, `FileBrowser.php:168` |
| F-03 | Medium | Rename does not re-index: new names are not found, old names still match; folder paths go stale | `FileBrowser.php:460`, `Obj.php:84` |
| F-04 | Medium | No transactions around blob and row writes (upload, folder, platform store, replace, delete): orphaned blobs or rows on failure | `FileBrowser.php:423`, `PlatformFileController.php:71`, `File.php:147` |
| F-05 | Medium | Version numbering is check-then-insert; a race gives a 500 and an orphaned blob instead of the promised 409 | `FileVersions.php:28,66` |
| F-06 | Medium | Uploads and folders can be created under a trashed folder (or a file node); such files skip the trash on purge | `FileController.php:40`, `FileBrowser.php:66` |
| F-07 | Medium | Operator approval of a bulk or team deletion deletes outright (skipping the trash), synchronously, with no transaction or status guard | `DeletionModerationController.php:75` |
| F-08 | Medium | Platform `list` cursor ends early on fully trashed pages, with N+1 trash checks | `PlatformFileController.php:200` |
| F-09 | Low | InfoDot ecosystem search returns trashed files | `EcosystemSearchController.php:49` |
| F-10 | Low | Download returns 500 when the blob is missing (other routes answer 410) | `FileController.php:57`, `SharedFileController.php:42` |
| F-11 | Low | Share renewal is refused at the per-file cap; approve and decline race | `FileShareService.php:51,128` |
| F-12 | Low | Pins are not removed with their file | `File.php:147` |

### UX/UI and accessibility
| ID | Sev | Finding | Evidence |
| --- | --- | --- | --- |
| U-01 | High | Dark mode: Jetstream profile and team settings pages are unreadable (page titles, section titles, field values, Save) | screenshots; axe 26 and 16 contrast nodes |
| U-02 | Medium | Team settings offer "Add team member … must be associated with an existing account" though membership comes from InfoDot | `/teams/{id}` |
| U-03 | Low | No pending-count badge for approvals or flags; the shared bell component is unused | `navigation-dropdown.blade.php:14` |
| A-01 | Low | Welcome page: 5 contrast failures | axe |
| A-02 | Medium | No accessibility or browser tests in CI; no manual screen-reader pass recorded | `ci.yml` |

### Email and notifications
| ID | Sev | Finding | Evidence |
| --- | --- | --- | --- |
| E-01 | High | Production mail is unverified: `.env.example` points at Mailtrap with `MAIL_FROM_ADDRESS=null`; R-28 lists host mail as untested. Claim codes, the only way to link an existing account, depend on it | `.env.example:28`, `R28_STATUS_REGISTER.md` (**Owner**: confirm host `MAIL_*`; then a controlled send) |
| E-02 | Medium | Share requested, approved, declined, received, revoked or expired, and deletion request decided: none sends mail or an in-app notice | `FileShareService.php:90-166`, `DeletionModerationController` |
| E-03 | Medium | No in-app notification centre (no table, unread count or preferences) | migrations |
| E-04 | Medium | DMARC `p=none` with no reporting address; SPF softfail | `dig` (**Owner**: DNS) |
| E-05 | Low | Mail is sent synchronously and not retried; a failed governance alert is lost (the flag remains) | `FileGovernance.php:125` |

### Performance and scalability
| ID | Sev | Finding | Evidence |
| --- | --- | --- | --- |
| P-01 | High | `objects.uuid`, `files.uuid` and `folders.uuid` are not indexed; no composite `(team_id, parent_id, trashed_at)` | migrations |
| P-02 | Medium | `whereIn(File::pluck('id'))` binds every file id on every round-trip; fails beyond about 65k files | `FileBrowser.php:124,150`, `ShareApprovalController.php:30` |
| P-03 | Medium | Folder listing, trash and approvals are unbounded, sorted in PHP, and re-run on every checkbox (`wire:model.live`) | `FileBrowser.php:165`, `TrashController.php:30` |
| P-04 | Medium | Replace, restore and platform fetch hold files of up to 100 MB in memory | `FileVersions.php:125,139` |
| P-05 | Medium | Preview rendering, mail and InfoDot calls run after the response inside web workers, with no timeout or retry | `File.php:125`, `FileVersions.php:101` |
| M-01 | High | No response time has been measured at any data size | none |

### Code quality
| ID | Sev | Finding |
| --- | --- | --- |
| Q-01 | Medium | PHPStan level 5 has 99 errors and does not run in CI; the noise hides real defects (`Authenticate::redirectTo` missing return) |
| Q-02 | Medium | `FileBrowser` is 781 lines doing seven jobs; team-role checks are duplicated across five classes |
| Q-03 | Low | Upload whitelist and size limits are copied in three places (100 MB web, 25 MB API, so a web replace can make a platform file unfetchable) |
| Q-04 | Low | Dead files: webpack configs, `server.php`, `phpunit.xml.bak`, `/api/user`, ExampleTests, the unused exception `Handler.php` |
| Q-05 | Low | Tests write the real search index under `storage/`; `phpunit.xml` does not override the Scout driver |

### Operations and CI
| ID | Sev | Finding | Owner |
| --- | --- | --- | --- |
| O-01 | High | Deploy is not gated on CI for the SHA it ships, resets to whatever `origin/main` is, takes no database dump before `migrate`, and has no rollback | partly (backups are paused by owner choice; a pre-migrate dump is still needed) |
| O-02 | Medium | Logs: one `laravel.log`, never rotated, default level debug | |
| O-03 | Medium | No outside uptime or error alerting | **Owner**: monitor choice |
| O-04 | Medium | Owner cron order not adopted here: no `config/dot_cron.php`, no `DOT_CRON_ESSENTIAL_ONLY`, no `dot:cron-status`. No mail depends on cron today | |
| O-05 | Medium | `server-logs.yml` checks another platform's backup files and command | |
| O-06 | Low | No `SECURITY.md` and no Dependabot config; `dependency-review.yml` uses outdated action versions | |
| O-07 | High (test) | The Pint CI step never checks anything on a clean checkout (`--dirty` passes empty) | `ci.yml` |
| O-08 | Medium | CI lacks PHPStan, `composer audit`, an asset-build check, a migration run on the production engine and JS tests; README says "schedules nothing" but `routes/console.php` schedules the purge | |
| O-09 | Low | Production database engine is not recorded in the repo (`.env.example` says pgsql, R-28 says shared MySQL). Answered 2026-11-27: production is **SQLite**, about 336 KB | fix the docs and `.env.example` (EA-7) |

## 4. Verified sound (positive evidence)

- **Tenant isolation.** `HasTeamScope` fails closed (`1 = 0` with no team). Route bindings go through it. Livewire actions
  re-resolve client ids against the team. Unscoped relations (`FileShare`, `FileVersion`, `FileFlag`) are guarded by callers.
  The platform API scopes by team and origin with uniform `not_found`. Covered by `FilesTenantIsolationTest`,
  `FilesTeamScopeTest` and `FileBrowserSecurityTest`.
- **SSO.** PKCE S256, single-use state, nonce compared with `hash_equals`, RS256-only verification with issuer, audience and age
  checks, single-use backchannel `jti`, session regeneration, open-redirect-safe deep links. Local credential posts are
  intercepted by path and also refused by `RefuseLocalSignIn`. Confirmed live.
- **Files.** Content-sniffed mime against an allowlist, random blob names on a private disk, downloads as attachments, an inline
  allowlist with nosniff, SVG and HTML never rendered or rasterised. Shares are re-checked on every use (status, expiry,
  revocation, view-only preset).
- **R-25 fixes hold.** Rename writes only `name`. The root and populated folders cannot be deleted from the browser. Account
  deletion is refused while the person owns a company.
- **Deploy hygiene.** `APP_DEBUG` forced off, built and Dot UI assets probed, `/up` must be a clean 200, app-folder probe.

## 5a. Progress

| Phase | Status | Evidence |
| --- | --- | --- |
| EA-0 | **Fixed, deployed and verified** (2026-11-27, `b0de299`) | #133: 12 tests in `AccountIdentityTest`; deploy run 46061075507 green, migration ran; live `/login` still goes to central sign-in |
| EA-1 | **Fixed, deployed and verified** (2026-11-27, `5944efd`, run 46064694840) | S-02 (writes and the membership webhook honour a signature once), S-04 (CSP enforced, switchable back to report-only), S-05 (PDF render limits and an off switch), S-06, S-07, S-09, S-10 (composer and npm audits clean, both now gate CI), S-11, S-12. `SecurityHardeningTest` plus updated header, inline and hand-off tests; 466 tests pass |
| EA-2 | **Fixed, deployed and verified** (2026-11-27, `e4d5147`, run 46066102400) | F-01..F-12: first-run New folder; search over each company's own records (`objects.search_text`, refreshed on rename and move; the index shared across companies is no longer read); nothing filed into the trash; uploads, folders and platform stores written in one transaction, with content removed if they fail; blobs deleted only after the deletion commits; locked version numbering and a race-safe baseline; operator approval goes to the trash and is decided once; share renewal at the limit; approve and decline decided once; `list` filtered in SQL; ecosystem search excludes the trash; 410 for missing content; pins removed with their file. `DataIntegrityTest` (14); 480 tests pass |
| EA-3 | **This PR** | E-01 (a From address is never empty, since `MAIL_FROM_ADDRESS=null` made every email fail; the deploy reports the mailer and sender in force), E-02/E-03 (share requested, approved or declined and deletion decisions reach the right people in the bell and by email, sent once, after the response, with Markdown-safe text), U-03 (pending badge on Sharing approvals), O-04 (the owner's cron order adopted: `config/dot_cron.php`, `dot:cron-status`), D-03 (hourly trash purge without cron). `CommunicationTest` (8) plus `EssentialCronTest`; 491 tests pass. Owner items left: real `MAIL_*` on the host and one controlled live send; DMARC (E-04) |
| S-03 | **Owner decision** | Limiting a platform's secret to companies holding that platform means asking InfoDot during API calls. That reverses R-50's rule (enforced by a test) that the machine API never calls InfoDot. Left as it is until the owner decides |
| S-08 | Partly | The session cookie is secure by default for an https `APP_URL`. No trusted proxy is configured, because the host serves the app directly (LiteSpeed); revisit if a proxy is ever placed in front |
| S-02 (method and path) | Deferred | Signing method and path changes the signature every calling platform computes, so it needs a coordinated fleet change |

## 5. Remediation plan (one PR per phase, merged on green CI, deployed only on the owner's word)

| Phase | Scope | Findings |
| --- | --- | --- |
| **EA-0** (urgent, small) | Close S-01: lock identity fields to InfoDot for linked or SSO accounts, otherwise re-verify on change; normalise email case with a unique index (after a read-only duplicate check on production) | S-01 |
| **EA-1** Security | Nonce and method/path signing; per-company platform entitlement; lock the panel id; enforce CSP in stages; PDF rendering limits; 2FA throttle; proxies and secure cookie; legacy hand-off origin; inline sandbox and disposition; dependency updates and a `composer audit` gate | S-02..S-12 |
| **EA-2** Data integrity and functions | Transactions and blob cleanup after commit, plus a reconciliation command; version locking; folder-only, untrashed parents; operator approval moves to the trash; `list` cursor; rename re-index; per-team search; 410 on missing blobs; renewals at the cap; pins; the first-run "New folder" | F-01..F-12 |
| **EA-3** Email and notifications | Deploy guard on the mail sender; share and deletion lifecycle mail plus database notifications in the shared bell, with a pending badge; adopt `dot_cron.php` (essential list) and a guaranteed trash purge; one controlled live send | E-01..E-05, U-03, O-04, D-03 |
| **EA-4** Privacy | Rewrite the privacy policy; retention and pruning for share events and flags; pseudonymise on account deletion; personal data export | D-01, D-02, D-04 |
| **EA-5** UX and accessibility (through the design skills, inside the ledger direction) | Dark-mode settings pages, team page under SSO, welcome contrast, axe checks in CI with a keyboard pass | U-01, U-02, A-01, A-02 |
| **EA-6** Performance | Index migration; subqueries; pagination and SQL-side sort; streaming hashes; measured response times at 1k, 10k and 50k files | P-01..P-05, M-01 |
| **EA-7** Code, CI and operations | Real Pint gate; PHPStan baseline in CI; build and migration checks; deploy gated on CI for an exact SHA, pre-migrate dump, rollback workflow; daily logs; fix `server-logs.yml`; `SECURITY.md` and Dependabot; dead files; test search driver; split `FileBrowser` | Q-01..Q-05, O-01, O-02, O-05..O-08 |

### Owner items (exact actions; not claimed as done)
1. Confirm the host's `MAIL_*` settings for files.infodot.co.za (real From on the Dot domain), then allow one controlled test send (E-01).
2. DNS: DMARC `p=quarantine` with a `rua=` address once alignment is confirmed; consider SPF `-all` (E-04).
3. Report the host's Ghostscript version and ImageMagick `policy.xml` (S-05).
4. ~~Confirm the production database engine (O-09)~~: answered, SQLite.
5. Choose an outside uptime monitor that the host's bot protection allows (O-03).
6. Before each phase deploy, take and verify a backup from the InfoDot repo: `gh workflow run backups.yml -f mode=run -f keys=files`, then `-f mode=verify`. InfoDot's `backups.yml` covers Dot.Files under the key `files`, and an account-wide `dot-backup` crontab entry also took a copy at 02:40 UTC on 2026-11-27. So the pre-deploy-copy part of O-01 is covered from outside this repo; the CI gate and rollback are not.
