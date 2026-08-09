# Dot.Files: Destructive-Action Approval Gate

## Context

Dot.Files' autonomy classification audit (`Dot.Brain/platforms/dot-files.md`, 2026-08-08) found no real Level 2 candidate: no queue worker (`QUEUE_CONNECTION=sync`), no scheduled commands, no notification classes, no events, and no proposal/approval data model anywhere in the codebase. Its own gap summary is explicit that a real Level 2 flow "would require adding a staged/pending-approval state (e.g. to team deletion or bulk file deletion) plus the Context→Evidence→Risk→Recommendation→Proposed Action presentation" — framing both existing team deletion and (not-yet-built) bulk file deletion as end-user self-service, out of scope for operator escalation as audited.

This spec explicitly builds that gate anyway, on both surfaces the audit named: team deletion (`app/Actions/Jetstream/DeleteTeam.php`, currently a real, owner-triggered, irreversible, synchronous action reachable via Jetstream's `teams.delete-team-form` Livewire component) and bulk file/folder deletion (which does not exist today — `App\Http\Livewire\FileBrowser::deleteObject()` only supports one object at a time; this spec adds multi-select alongside the gate, since there is nothing to gate without it).

Dot.Files has no operator/admin concept at all. This spec introduces `is_platform_operator`, matching the exact precedent already proven in ChartSense/Dot.Ehail/Dot.Emall's DKP and marketplace-moderation work, rather than treating a team owner's self-review as independent approval.

## Goal

A team owner clicking "Delete Team" no longer deletes the team immediately — it creates a pending deletion request, and the team stays fully intact and functional until a platform operator approves it. Similarly, a new multi-select "Delete Selected" action in the file browser creates a pending bulk-deletion request instead of deleting immediately. An operator reviews both request types from one screen and approves or rejects (rejecting requires a reason). Existing single-object deletion (`FileBrowser::deleteObject()`) is untouched — it stays instant, matching its current low-stakes, one-item-at-a-time shape.

## Changes

### 1. `is_platform_operator` flag

New migration on `users`: `boolean('is_platform_operator')->default(false)`, excluded from `User::$fillable`, `boolean` cast added — identical to ChartSense's `2026_08_08_172255_add_is_platform_operator_to_users_table.php` and this session's Dot.Emall equivalent. No route or command in this codebase ever grants it; an operator is created by hand.

### 2. `team_deletion_requests`

New table + `TeamDeletionRequest` model: `id`, `team_id` (FK `teams.id`, `cascadeOnDelete`), `requested_by` (FK `users.id`, `cascadeOnDelete`), `status` (string, default `'pending'` — `pending`/`approved`/`rejected`), `rejected_reason` (nullable text), `reviewed_by` (nullable FK `users.id`, `nullOnDelete`), `reviewed_at` (nullable timestamp), timestamps. `TeamDeletionRequest::team(): BelongsTo`, `requester(): BelongsTo` (User via `requested_by`), `reviewer(): BelongsTo` (User via `reviewed_by`). Only one `pending` request may exist per team at a time — enforced at the application level (checked before creating a new one), not a DB constraint, matching this session's established `DriverApplicationController`/`SellerProductController` precedent for "refuse a duplicate in-flight request" checks.

### 3. `bulk_deletion_requests`

New table + `BulkDeletionRequest` model: `id`, `team_id` (FK `teams.id`, `cascadeOnDelete`), `requested_by` (FK `users.id`, `cascadeOnDelete`), `object_ids` (JSON array of `objects.id` values captured at request time), `status`/`rejected_reason`/`reviewed_by`/`reviewed_at` (same shape as §2), timestamps. `BulkDeletionRequest::team()`, `requester()`, `reviewer()` relations, matching §2. Multiple pending bulk-deletion requests for the same team are allowed (they name disjoint or even overlapping object sets; approval re-resolves against real rows, so a stale/overlapping request degrades to "delete whatever of this set still exists," not a conflict) — unlike team deletion, there's no single meaningful "the" pending request to collapse into.

### 4. Team deletion gate (`App\Http\Livewire\DeleteTeamForm`)

New class, `app/Http/Livewire/DeleteTeamForm.php`, extending `Laravel\Jetstream\Http\Livewire\DeleteTeamForm` (the vendor class Jetstream ships, currently registered under the `teams.delete-team-form` Livewire alias in `vendor/laravel/jetstream/src/JetstreamServiceProvider.php`). Overrides `deleteTeam()`:

```php
public function deleteTeam(): void
{
    Gate::authorize('delete', $this->team);

    $existing = TeamDeletionRequest::where('team_id', $this->team->id)
        ->where('status', 'pending')
        ->first();

    if ($existing) {
        $this->confirmingTeamDeletion = false;
        $this->requestedDeletion = true;

        return;
    }

    TeamDeletionRequest::create([
        'team_id' => $this->team->id,
        'requested_by' => auth()->id(),
    ]);

    $this->confirmingTeamDeletion = false;
    $this->requestedDeletion = true;
}
```

`Gate::authorize('delete', $this->team)` reuses the existing `TeamPolicy::delete` check the vendor component itself doesn't call directly (it currently relies on `ValidateTeamDeletion`, an internal Jetstream action) — explicit here since this override no longer routes through that class. `$requestedDeletion` is a new public property (default `false`) the view reads. **Critically, this override never calls `DeletesTeams::delete()` and never sets `$this->team = null` or redirects** — the vendor `deleteTeam()`'s post-delete side effects (nulling the team, redirecting away) are exactly the misleading behavior this spec must avoid, since the team is not actually gone.

Re-registered in `app/Providers/JetstreamServiceProvider.php`'s existing `boot()` method (already present in this app, currently only calling `configurePermissions()`):

```php
public function boot(): void
{
    $this->configurePermissions();

    Livewire::component('teams.delete-team-form', \App\Http\Livewire\DeleteTeamForm::class);
}
```

App service providers boot after package providers, so this registration wins — Jetstream's own `teams.delete-team-form` registration runs first, this one overwrites it. This is a documented, supported Jetstream customization pattern, not a hack.

`resources/views/teams/delete-team-form.blade.php` (already published/app-owned) gains a conditional block: when `$requestedDeletion` is true, show "Deletion requested — awaiting operator approval" in place of the delete button, instead of the button/modal.

### 5. Bulk file/folder deletion (`FileBrowser`)

`app/Http/Livewire/FileBrowser.php` gains:

- `public array $selectedObjects = []` — checked object IDs.
- `requestBulkDeletion(): void` — validates `$selectedObjects` is non-empty, creates a `BulkDeletionRequest` with `object_ids: $this->selectedObjects` scoped to the current team (resolved via `resolveCurrentTeam()`, the existing private helper), clears `$selectedObjects`, sets a flash-equivalent Livewire property (`$bulkDeletionRequested = true`, mirroring `$requestedDeletion`'s shape in §4) for the view to show a confirmation message.

`resources/views/livewire/file-browser.blade.php` gains a checkbox per row (bound to `wire:model="selectedObjects"`, value the object's ID) and a "Delete Selected" button (disabled when `$selectedObjects` is empty) that calls `requestBulkDeletion`. The existing single-object `confirmingObjectDeletion`/`deleteObject()` flow is completely untouched, including its own delete button per row — multi-select is additive, not a replacement.

### 6. Review screen (`DeletionModerationController`)

New controller, `app/Http/Controllers/DeletionModerationController.php`, and new middleware `app/Http/Middleware/EnsurePlatformOperator.php` (same shape as Dot.Ehail/Dot.Emall's — `abort_unless($request->user()?->is_platform_operator, 403)`), registered as an `operator` middleware alias in `bootstrap/app.php`:

- `index(): View` — Route `GET /operator/deletion-requests`, name `operator.deletion-requests.index`. Lists pending `TeamDeletionRequest`s and `BulkDeletionRequest`s together (each with `->with(['team', 'requester'])`), plus a recently-reviewed section for both types.
- `approveTeam(TeamDeletionRequest $request)` / `rejectTeam(TeamDeletionRequest $request, Request $httpRequest)` — Routes `POST /operator/deletion-requests/team/{request}/approve` / `/reject`. Refuses (flash error) unless `status === 'pending'`. `approveTeam` is the only code path in this spec that calls `app(DeletesTeams::class)->delete($teamDeletionRequest->team)` — the real, unmodified `App\Actions\Jetstream\DeleteTeam` action — then marks the request `approved`/`reviewed_by`/`reviewed_at`. `rejectTeam` validates `reason` as `required|string`, sets `rejected`/`rejected_reason`/`reviewed_by`/`reviewed_at`, and does not touch the team.
- `approveBulk(BulkDeletionRequest $request)` / `rejectBulk(BulkDeletionRequest $request, Request $httpRequest)` — Routes `POST /operator/deletion-requests/bulk/{request}/approve` / `/reject`. Same pending-only guard and reject-requires-reason rule as team requests. `approveBulk` re-resolves `Obj::whereIn('id', $bulkDeletionRequest->object_ids)->where('team_id', $bulkDeletionRequest->team_id)->get()` (not `$request->object_ids` trusted blindly, and re-scoped to the request's own team even though `Obj`'s `HasTeamScope` global scope would already enforce this for an authenticated request — belt-and-suspenders, matching this session's established double-check style) and calls `->each->delete()` on whatever still exists, then marks the request `approved`.

Neither `TeamDeletionRequest` nor `BulkDeletionRequest`/`Obj` carries a user-scoping trait keyed to the *reviewing* operator (only `Obj` carries the existing team scope, which the operator's own request doesn't need bypassed — operators aren't scoped to a team), so there is no Dot.Ehail-style `withoutGlobalScope` trap here; implicit route-model binding on `{request}` is safe to use directly.

## Testing

New test files, matching this app's existing `tests/Feature/*` convention (`RefreshDatabase`, hand-built fixtures via `::create()` — no factories exist for `Team`/`Obj`/`File` beyond `UserFactory::withPersonalTeam()`, and none are added):

- `tests/Feature/TeamDeletionRequestTest.php` — the team owner clicking delete creates a pending `TeamDeletionRequest` and the team is **not** deleted (`Team::find($team->id)` still resolves, `$team->objects()->count()` unchanged); a second click while one is already pending does not create a duplicate (`TeamDeletionRequest::count()` stays 1); a non-owner cannot trigger it (existing `TeamPolicy::delete` boundary, unchanged — regression only). Uses `Livewire::test(\App\Http\Livewire\DeleteTeamForm::class, ['team' => $team])->call('deleteTeam')`.
- `tests/Feature/BulkDeletionRequestTest.php` — selecting objects and calling `requestBulkDeletion` creates a `BulkDeletionRequest` with the right `object_ids` and does **not** delete any of them; requesting with an empty selection is a no-op (no request created); a user from a different team cannot include another team's object IDs (the ID is silently dropped — `resolveCurrentTeam()`'s existing scoping already prevents cross-team `objects` reads from ever entering `$selectedObjects` in the first place, so this test proves the boundary via the existing team-scoped query, not new code).
- `tests/Feature/DeletionModerationTest.php` — an operator can view pending requests of both types; approving a `TeamDeletionRequest` actually deletes the team this time (`Team::find($team->id)` now null) and marks `reviewed_by`/`reviewed_at`; approving a `BulkDeletionRequest` deletes exactly the listed objects and leaves others untouched; approving a `BulkDeletionRequest` where one listed object was already deleted between request and approval succeeds without error and deletes only what still exists; rejecting either type without a reason is blocked; rejecting with a reason marks `rejected`/`rejected_reason` and leaves the team/objects untouched; a non-operator is refused (403) on the index and every approve/reject route; approving/rejecting an already-decided request of either type is refused.

## Explicitly out of scope

- Notifying the requester (team owner, or the file-selecting user) of the operator's decision — only the operator sees the outcome on the review screen, matching this session's identical exclusion in Dot.Ehail (driver applicant) and Dot.Emall (seller).
- Withdrawing/cancelling a pending request before it's reviewed — only submit, approve, reject exist.
- Editing an in-flight `BulkDeletionRequest`'s object list after submission — a new request must be made instead.
- Gating single-object deletion (`FileBrowser::deleteObject()`) — stays instant, as today; this spec only gates the new bulk path.
- A self-serve path to becoming a platform operator — granted by hand only, matching ChartSense/Ehail/Emall's identical precedent.
- Building the CVE-patching approval pipeline the audit's gap summary also named as a hypothetical candidate — not pursued; this spec commits to the destructive-action gate only.
- Registering this change in Dot.Brain's `platforms/dot-files.md` or `platforms/autonomy-signals.json` — a separate, future re-audit pass, not part of building the feature.
