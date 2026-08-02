---
title: Dot.Files — Platform Wiki
version: 0.1.0
status: draft
owners: [Files Platform Lead]
platform-id: dot-files
last-review: 2026-08-02
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
| `Obj` | `objects` | The actual node of the file tree. Polymorphic (`objectable_type`/`objectable_id`) pointer to either a `File` or a `Folder`, plus `parent_id` (self-referential) and `team_id`. This is the model that's team-scoped, searched, and traversed — `File` and `Folder` themselves hold no hierarchy or team-scoping logic of their own. |
| `File` | `files` | A stored file — `name`, `size` (bytes), `path` (storage path on the `local` disk), `uuid`. Deleting a `File` deletes its bytes off disk in a `booted()` hook. |
| `Folder` | `folders` | A folder — just `name` and `uuid`. No content of its own; its children are `Obj` rows with `parent_id` pointing at its owning `Obj`. |
| `Team` (extends Jetstream's `Team`) | `teams` | On `created`, a `Team` automatically gets a root `Obj`/`Folder` pair named after the team — every team starts with exactly one root folder. |
| `User`, `Membership` | Jetstream defaults | Standard Jetstream Teams auth; `Membership` is a thin subclass with no Dot.Files-specific behavior. |

Neither `File` nor `Folder` implements `RelatesToTeams` (the `forCurrentTeam` scope trait) — only `Obj` does, via `App\Models\Traits\RelatesToTeams`. Every by-ID lookup in `FileBrowser` and `FileController` goes through `Obj::forCurrentTeam()`, not through `File::find()`/`Folder::find()` directly, which is what keeps deletion/rename/browse team-scoped (see §6).

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
| 0.1.0 | 2026-08-02 | Files Platform Lead | Initial platform-owned wiki, derived from the actual Laravel codebase (models, migrations, routes, controllers, Livewire components). Verified the `EcosystemAuthController` SSO contract and `DB_DATABASE=infodot` match the ecosystem-wide pattern. Ran a focused IDOR/tenant-scoping security scan — found the codebase's existing `forCurrentTeam()` scoping pattern already closes the common cross-tenant `find($id)` bug class; found and fixed one real migration bug (`contrained` → `constrained` typo in the folders migration that would fatal on first real `php artisan migrate`). Corrected README.md, which described AWS S3 storage, file previews, version history, granular sharing permissions, and Reverb-based realtime collaboration — none of which exist in this codebase; storage is local-disk only and the domain is materially smaller than the README claimed. Wired the real Dot.Files logo (`public/images/logo.png`, copied from the pre-existing `public/img/logo.png`) into `application-logo`, `application-mark`, and `authentication-card-logo` Jetstream vendor components (previously the unswapped default purple placeholder SVG on 3 of 4 auth pages: reset-password, two-factor-challenge, verify-email), generated `apple-touch-icon.png`/`favicon-32x32.png`/`favicon-16x16.png` via `sips`, and fixed a broken `img/apple-icon.png` favicon reference (file never existed) across `layouts/app.blade.php`, `layouts/guest.blade.php`, and `welcome.blade.php`. |

## Open Questions

- Should `File`/`Folder` gain their own `RelatesToTeams` scope directly, or is routing all team-scoping through the `Obj` polymorphic parent (the current pattern) intentional and sufficient going forward? Leaning toward "sufficient" since every current lookup path goes through `Obj` first — but worth an explicit decision before any new code adds a `File::find()`/`Folder::find()` call.
- Is the legacy Bootstrap/now-ui-kit CSS in `layouts/app.blade.php` and `welcome.blade.php` intentional (e.g., an unfinished migration to Tailwind) or dead weight from an early scaffold? Not determined this pass — flagged in §8 rather than removed, since removing CSS from live-looking pages without being able to render them is higher-risk than this bounded pass allows.
