---
title: Dot.Files — Platform Wiki
version: 0.4.0
status: draft
owners: [Files Platform Lead]
platform-id: dot-files
last-review: 2026-08-04
---

# Dot.Files

Purpose: this is Dot.Files's own knowledge home — owned and maintained by the Dot.Files team. It describes what this platform actually is, as implemented, and how it connects to the wider Dot Ecosystem. Dot.Brain never edits this file; it only reads what we choose to publish.

> **Related:** [Dot.Brain's ingested view of this platform](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-files.md) (not yet created — see §7)

---

## 1. What Dot.Files Is

Dot.Files is the team file storage and management platform in the Dot ecosystem. Each team has a recursive folder tree — files and folders are both leaves hung off a single self-referential `objects` table via polymorphic association — and team members upload, browse, search, rename, and delete files and folders, and download individual files. It is a Laravel 12 / Livewire 3 app, not a general document-collaboration suite: there is one Livewire component (`FileBrowser`) driving the entire UI, no previews, no versioning, and no sharing/permission model beyond team membership.

**Status:** this is a working application — real models, migrations, a functioning Livewire file browser, and the ecosystem SSO handoff all exist and are wired together end to end (upload → store on local disk → list via recursive query → download through a Policy check). The domain is deliberately thin: no S3 integration, no previews, no version history, no per-file sharing, and no real-time layer. Treat §8 (Roadmap) as what's ahead and everything else as what's actually in the repo today.

## 2. Architecture

| Layer | Technology | Notes |
|---|---|---|
| Framework | Laravel 12, PHP 8.4 | Jetstream 5 + Fortify for auth/teams |
| UI | Livewire 3 (`FileBrowser`, `NavigationDropdown`), Alpine.js (via Livewire), Tailwind CSS | Single Livewire component drives the whole file browser; some legacy Bootstrap/now-ui-kit CSS assets are still loaded on a couple of pages (`layouts/app.blade.php`, `welcome.blade.php`) alongside Tailwind — not cleaned up |
| Upload widget | FilePond (loaded from a CDN `<script>` tag in `layouts/app.blade.php`) | Drives drag-and-drop + progress UI, streams into Livewire's `updatedUpload()` handler |
| Database | PostgreSQL 16 | Shared instance across the InfoDot ecosystem — `DB_DATABASE=infodot` in `.env.example`, confirmed matching every other platform |
| Auth | Laravel Sanctum + `App\Http\Controllers\Auth\EcosystemAuthController` | SSO handoff from the InfoDot hub (`/auth/ecosystem`) — verified to match the ecosystem-wide contract (see §4) |
| File storage | Local disk only (`Storage::disk('local')`) | `.env.example` defines `AWS_*` keys but no S3/Flysystem-S3 code exists anywhere in `app/` — those keys are currently dead configuration |
| Search | Laravel Scout, `Obj` model (`Searchable` trait), `SCOUT_DRIVER=tntsearch` by default | Indexes name + computed ancestor path; results are filtered in PHP by `team_id` after the search call (see §6) |
| Hierarchy | `staudenmeir/laravel-adjacency-list` (`HasRecursiveRelationships`) on the `Obj` model | Powers `ancestorsAndSelf()`/`descendants` used for breadcrumbs and cascade-delete |
| Realtime | Laravel Reverb | Env vars present in `.env.example` only; nothing in `app/` broadcasts or listens on any Reverb channel |

## 3. Domain Entities (as implemented)

Source: `database/migrations/`, `app/Models/`.

| Model | Table | Purpose |
|---|---|---|
| `Obj` | `objects` | The actual node of the file tree. Polymorphic (`objectable_type`/`objectable_id`) pointer to either a `File` or a `Folder`, plus `parent_id` (self-referential) and `team_id`. Team-scoped, searched, and traversed. |
| `File` | `files` | A stored file — `name`, `size` (bytes), `path` (storage path on the `local` disk), `uuid`, `team_id`. Deleting a `File` deletes its bytes off disk in a `booted()` hook. |
| `Folder` | `folders` | A folder — `name`, `uuid`, `team_id`. No content of its own; its children are `Obj` rows with `parent_id` pointing at its owning `Obj`. |
| `Team` (extends Jetstream's `Team`) | `teams` | On `created`, a `Team` automatically gets a root `Obj`/`Folder` pair named after the team — every team starts with exactly one root folder. |
| `User`, `Membership` | Jetstream defaults | Standard Jetstream Teams auth; `Membership` is a thin subclass with no Dot.Files-specific behavior. |

As of 0.4.0, `Obj`, `File`, and `Folder` all use `App\Models\Concerns\HasTeamScope`, an Eloquent global scope that filters every query on those models to the authenticated user's current team automatically — this superseded the older opt-in `App\Models\Traits\RelatesToTeams::scopeForCurrentTeam()` local scope, which only `Obj` had and which every caller had to remember to invoke explicitly (`Obj::forCurrentTeam()`). `File` and `Folder` previously had no scoping of their own at all; `FileController::download(File $file)`'s implicit route-model binding now benefits from the same automatic scoping (see §6).

## 4. Ecosystem SSO Verification

`app/Http/Controllers/Auth/EcosystemAuthController::handle()` was checked line-by-line against the pattern used across the ecosystem and matches it exactly:

1. `PersonalAccessToken::findToken($request->query('token'))` — looks up the Sanctum token by its plaintext value.
2. `abort_if(... 403)` guards on three conditions: token doesn't exist, token lacks the `ecosystem:read` ability, or `expires_at` is set and in the past.
3. `$accessToken->delete()` — the token is deleted immediately after being read, making it one-time-use.
4. `Auth::login($user)` — logs in the token's owning user.
5. Redirects to `route('files')` — this app's own home route (`/files`, handled by `FileController::index`), not to `/dashboard` or `/`.

No deviation found. `config/database.php` reads `DB_DATABASE` from the environment with no override, and `.env.example` sets `DB_DATABASE=infodot` — matching the shared database every other platform (and InfoDot itself) uses.

## 5. What Exists Today vs. What's Modeled but Unbuilt

**Built:**
- Ecosystem SSO route (`/auth/ecosystem`) and Sanctum-gated `/files` browser
- `FileBrowser` Livewire component: list (with breadcrumbs), search, create folder, rename, delete, upload (FilePond → `WithFileUploads`)
- `FileController::download`, gated by `FilePolicy::download` (team-membership check)
- Full-text search via Scout, filtered to the current team after the search call

**Modeled in config but not built:**
- S3 storage (`.env.example` has `AWS_*` keys; no S3 disk is configured or used — everything writes to `storage/app/files` via the `local` disk)
- Realtime/Reverb (env vars present; no broadcast events, no listeners, no notification bell)
- Any notion of sharing, view/edit-only permissions, or per-file access beyond "is a member of the owning team"
- File previews and version history (referenced in the pre-existing README; not present in any model, migration, or view)

## 6. Security & Tenant-Scoping Review

This pass's focused security/tech-debt scan covered the single most common bug class found across the ecosystem so far: unscoped by-ID lookups (`Model::find($id)`) creating cross-tenant/cross-user IDOR.

**Result: no IDOR found.** Every by-ID lookup that touches user-supplied input in this codebase already goes through the team-scoping pattern:
- `FileBrowser::deleteObject()`, `renameObject()`, `updatingRenamingObject()` — all call `Obj::forCurrentTeam()->find($id)`, never `Obj::find($id)` directly, and no-op (rather than throw or leak) when the scoped lookup returns null.
- `FileController::index()` and `FileController::download()` — `index()` scopes via `Obj::forCurrentTeam()`; `download()` uses Laravel's implicit route-model binding for `File $file` (which does an unscoped `File::findOrFail()`, so file *existence* isn't tenant-hidden) but immediately calls `$this->authorize('download', $file)` before returning any content, and `FilePolicy::download()` correctly checks `$file->team_id === $user->currentTeam->id`. No data or file bytes are returned without that check passing.
- `RelatesToTeams::scopeForCurrentTeam()` defaults to team id `0` when the user has no current team, which — combined with `team_id` being a non-nullable foreign key with no team `id = 0` possible — safely returns zero rows rather than accidentally matching an un-scoped record.

**One real bug found and fixed:** `database/migrations/2020_11_05_192608_create_folders_table.php` called `$table->foreignId('team_id')->contrained('teams')` — a typo (`contrained` instead of `constrained`). `contrained()` is not a method on Laravel's `ColumnDefinition`/foreign-id fluent builder, so this migration would fatal with an undefined-method error the moment it actually runs against a database. Fixed to `->constrained('teams')`. This was not previously caught because migrations have never been executed in this working environment (see the environment note below) — it would have surfaced on first real `php artisan migrate` in a genuine dev/CI environment.

**Not fixed, flagged for a dedicated pass:** the mix of Tailwind and legacy Bootstrap/now-ui-kit CSS plus a CDN-loaded FilePond `<script>` tag in `layouts/app.blade.php` and `welcome.blade.php` is dead weight and a supply-chain surface (unpinned CDN script) worth cleaning up, but it's a design/dependency cleanup, not a security-critical fix, so it's logged in §8 rather than changed inline this pass.

## 7. Connecting to Dot.Brain

Dot.Files is expected to register in Dot.Brain's platform map as `dot-files` (file storage and management). Dot.Brain's ingested view of this platform does not exist yet at [`platforms/dot-files.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-files.md) — that document is created by a separate Dot.Brain-side ingestion process, not by this repository. This wiki is the platform-owned source that process would read from.

Dot.Files currently emits **no domain events** — there are no Laravel event/listener classes and no outbound webhook or message-bus code anywhere in this repository. If/when Knowledge Pack publishing is built, the natural payloads would be:

| Payload type | Would contain |
|---|---|
| `observation` | Aggregated per-team storage usage (file count, total bytes) — never individual file names/content |
| `insight` | Patterns in upload volume/type mix, generalized across teams |
| `outcome` | N/A until Dot.Brain issues any file-storage-related recommendation |
| `incident` | Storage failures, quota exhaustion, failed uploads at scale |

Given that this platform stores actual user file content (not just metadata like Dot.Billing's money-movement records), any future aggregation published outward must never include file names, paths, or content — only counts/sizes/types — and should default to at least as strict an anonymity floor as Dot.Billing's settlement data (n≥50), pending an explicit decision once publishing is actually designed.

## 8. Roadmap / Open Questions

- [ ] S3 (or other object storage) integration — the `.env.example` `AWS_*` keys currently do nothing; either wire them up or remove them to stop implying a capability that doesn't exist
- [ ] File previews (image/PDF at minimum) — the browser currently only offers a raw download link
- [ ] Version history / restore — not modeled at all (no `file_versions` table or equivalent)
- [ ] Per-file sharing / granular permissions (view vs. edit vs. share) — today the only check is "member of the owning team," which is coarse for a file-sharing product
- [ ] Realtime — Reverb is configured but unused; a real trigger (e.g., "a teammate uploaded a file to this folder") would need to exist before adding a notification bell, per the ecosystem's rule against decorative/fake realtime UI
- [ ] Clean up the legacy Bootstrap/now-ui-kit CSS and CDN-loaded FilePond script mixed into `layouts/app.blade.php` and `welcome.blade.php` alongside the Tailwind/Vite pipeline
- [ ] Domain events for upload/delete/share actions (prerequisite for any Knowledge Pack publishing)
- [ ] Decide and document the aggregation floor for any outward-facing usage metrics before publishing begins

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 0.4.0 | 2026-08-04 | Platform-loop pass | Architecture pass matching the Dot.Mines/Dot.Finance/Dot.Notify pattern (see Dot.Finance commit `2f75bdb`, Dot.Notify commit `e671436`): added `App\Models\Concerns\HasTeamScope`, an Eloquent global scope trait applied to `Obj`, `File`, and `Folder` — the three tables that actually carry their own `team_id` foreign key (`objects`, `files`, `folders` migrations) — scoping every query on those models to `Auth::user()->currentTeam->id` automatically. Not applied to `Team`/`User`/`Membership` (Jetstream's own auth/membership models, not team-owned domain data). This **replaces**, rather than layers on top of, the pre-existing opt-in local scope `App\Models\Traits\RelatesToTeams::scopeForCurrentTeam()` (`Obj::forCurrentTeam()`), which only `Obj` used and which File/Folder never had at all — deleted that trait file since nothing calls it anymore. Removed the now-redundant explicit `->forCurrentTeam()` calls from `FileController::index()` and `FileBrowser::deleteObject()`/`renameObject()`/`updatingRenamingObject()`, and removed `FileBrowser::getResultsProperty()`'s manual post-search `->filter(fn ($obj) => $obj->team_id === $teamId)` — confirmed via `vendor/teamtnt/laravel-scout-tntsearch-driver`'s `TNTSearchEngine::getBuilder()` that Scout hydrates search results through `$model->newQuery()`, which applies global scopes the same as any other query, so the manual filter was already redundant with the new trait. `FilePolicy::download()`'s explicit `$file->team_id === $user->currentTeam->id` check was left untouched (defense-in-depth on top of the model scope, same as every other Policy in this rollout). **Real behavior change, found by actually running the suite**: `FileController::download(File $file)` uses implicit route-model binding, which is now scoped too — a cross-team file ID now 404s (via unscoped-model-not-found) before `FilePolicy::download()` even runs, rather than reaching the policy and getting a 403. No existing test exercised this path (this repo had zero coverage of it before this pass), so no assertion needed updating, but it's the same fail-closed improvement Dot.Finance saw. Added `tests/Feature/FilesTeamScopeTest.php::test_scope_alone_blocks_cross_team_access_even_without_an_explicit_where`, proving the scope alone (no Policy, no explicit `where`) blocks cross-team access on `File`. Writing that test surfaced two small pre-existing gaps that had never been exercised because this repo had no team-aware test coverage at all: `database/factories/UserFactory.php` hashed its factory password to a hard-coded `$2y$10$...` literal, which fatals under `phpunit.xml`'s `BCRYPT_ROUNDS=4` (the `hashed` cast's `Hash::verifyConfiguration()` rejects a cost-10 hash when rounds is configured to 4) — fixed by hashing at request time via `Hash::make('password')`, matching Dot.Notify's factory; and neither a `TeamFactory` nor `UserFactory::withPersonalTeam()` existed in this repo at all (added, mirroring the standard Jetstream stub Dot.Notify already carries). Also added `team_id` to `File::$fillable` since nothing in this codebase previously mass-assigned it directly (the app always creates files via the `$team->files()->create([...])` relation, which sets the foreign key outside mass assignment). Full suite: 3 tests, 3 passed, 0 failed (up from 2/2/0 — the one new test; two pre-existing tests carry a harmless `DEPR` flag for the same unrelated `PDO::MYSQL_ATTR_SSL_CA` notice logged in the 0.3.0 entry below). Added `phpstan.neon.dist` (level 5, Larastan extension) as dev tooling — unlike Dot.Finance/Dot.Notify, `vendor/bin/phpstan analyse --memory-limit=1G` **did** run successfully in this sandbox and produced real output: 31 pre-existing findings, almost entirely Larastan's PHPDoc-covariance noise on framework property overrides (`$fillable`/`$casts`/`$hidden`/`$appends` typed `array` instead of `list<string>`/`array<string,string>`) plus a few pre-existing minor issues (an unnecessary self-referential `use App\Models\Obj;` inside `Obj.php`, a couple of dynamic-property accesses on relation results) — none introduced by this pass, none fixed here since cleaning up 31 pre-existing lint findings is out of scope for a tenant-isolation pass; flagged for a dedicated cleanup pass instead. `composer audit` found 6 pre-existing advisories (1 high, 5 medium) against `guzzlehttp/guzzle`, matching every other platform in this rollout; fixed via `composer update guzzlehttp/guzzle guzzlehttp/psr7 guzzlehttp/promises --with-all-dependencies` — `composer audit` clean afterward, full suite re-confirmed green post-update. |
| 0.3.0 | 2026-08-03 | Sakhile Bhayi | First real-execution verification pass: this codebase had never been run against a real PHP/Postgres toolchain before. `composer install` succeeded with default PHP 8.5 (only a harmless stock-Laravel `PDO::MYSQL_ATTR_SSL_CA` deprecation notice from the unused `mysql` connection block in `config/database.php`, which this platform runs on `pgsql` and never touches — left alone as out-of-scope framework boilerplate, not this platform's code). `php artisan migrate` against an isolated `dot_files_verify` Postgres 16 database ran clean on the first try — all 12 migrations, confirming the `contrained`→`constrained` typo fix recorded in the 0.1.0 entry below actually holds under a real migrate run. `php artisan test` passed clean too: 2 tests, 2 passed, 0 failed (one flagged `DEPR` for the same unrelated PDO constant notice, not a real failure). No real bug found by this pass. Applied the Dot.Brain adr/ADR-0013 idempotent guard (`Schema::hasTable`/`hasColumn` checks) to this platform's five present shared Jetstream-core migrations (`create_users_table`, `add_two_factor_columns_to_users_table`, `create_personal_access_tokens_table`, `create_teams_table`, `create_team_user_table` — this platform has no `team_invitations` migration, so there was no sixth file to guard), previously unguarded, so they're safe to run in any order against the shared `infodot` database alongside other Dot platforms. `password_resets` (this platform's older, differently-named table — other platforms use `password_reset_tokens`) and `sessions` were left out of the guard scope per the ADR-0013 six-migration list, though `sessions` shares its physical table name with other platforms and could theoretically collide; flagged here rather than silently expanded, since it's outside what this pass was asked to guard. Re-verified on a fresh database after guarding: identical 2/2/0/0 result, confirming the guard is behavior-neutral. |
| 0.2.0 | 2026-08-03 | Sakhile Bhayi | Marketing-page pass on `resources/views/welcome.blade.php`, following the pattern already piloted on the `mines` repo. Found the page's starting state didn't match that pattern's assumptions: the nav and hero brand marks were already the real `public/images/logo.png` lockup (wired in a prior session per the 0.1.0 entry below), and the hero background was already a real, on-domain local photo (`public/img/header2.jpg` — colorful file folders/binders on office shelving) rather than an abstract CSS gradient, so no logo swap and no gradient-to-photo swap were actually needed. Upgraded the hero background anyway, from the untracked local JPEG to a real, licensed, photographer-credited Unsplash photo (filing-cabinet/document-archive drawer, by Maksym Kaharlytskyi, @qwitka, unsplash.com/photos/file-cabinet-Q9y3LRuuxmg) hotlinked via Unsplash's CDN (`images.unsplash.com`), matching the ecosystem's now-standard hotlink-plus-inline-HTML-comment-credit convention. Verified the CDN URL resolves with `curl -sI` (`HTTP/2 200`) before committing. Darkened the existing `.hero-bg::after` overlay from `rgba(0,0,0,0.52)` to `rgba(0,0,0,0.65)` to preserve adequate text contrast against the new, brighter photo. No other page content, layout, or copy changed. |
| 0.1.0 | 2026-08-02 | Files Platform Lead | Initial platform-owned wiki, derived from the actual Laravel codebase (models, migrations, routes, controllers, Livewire components). Verified the `EcosystemAuthController` SSO contract and `DB_DATABASE=infodot` match the ecosystem-wide pattern. Ran a focused IDOR/tenant-scoping security scan — found the codebase's existing `forCurrentTeam()` scoping pattern already closes the common cross-tenant `find($id)` bug class; found and fixed one real migration bug (`contrained` → `constrained` typo in the folders migration that would fatal on first real `php artisan migrate`). Corrected README.md, which described AWS S3 storage, file previews, version history, granular sharing permissions, and Reverb-based realtime collaboration — none of which exist in this codebase; storage is local-disk only and the domain is materially smaller than the README claimed. Wired the real Dot.Files logo (`public/images/logo.png`, copied from the pre-existing `public/img/logo.png`) into `application-logo`, `application-mark`, and `authentication-card-logo` Jetstream vendor components (previously the unswapped default purple placeholder SVG on 3 of 4 auth pages: reset-password, two-factor-challenge, verify-email), generated `apple-touch-icon.png`/`favicon-32x32.png`/`favicon-16x16.png` via `sips`, and fixed a broken `img/apple-icon.png` favicon reference (file never existed) across `layouts/app.blade.php`, `layouts/guest.blade.php`, and `welcome.blade.php`. |

## Open Questions

- Should `File`/`Folder` gain their own `RelatesToTeams` scope directly, or is routing all team-scoping through the `Obj` polymorphic parent (the current pattern) intentional and sufficient going forward? Leaning toward "sufficient" since every current lookup path goes through `Obj` first — but worth an explicit decision before any new code adds a `File::find()`/`Folder::find()` call.
- Is the legacy Bootstrap/now-ui-kit CSS in `layouts/app.blade.php` and `welcome.blade.php` intentional (e.g., an unfinished migration to Tailwind) or dead weight from an early scaffold? Not determined this pass — flagged in §8 rather than removed, since removing CSS from live-looking pages without being able to render them is higher-risk than this bounded pass allows.
