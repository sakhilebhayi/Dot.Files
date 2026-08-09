# Destructive-Action Approval Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Team deletion and bulk file/folder deletion no longer execute immediately — both create a pending request that a platform operator must approve (or reject with a reason) before anything is actually deleted.

**Architecture:** A new `is_platform_operator` flag gates a new operator review screen. Team deletion is intercepted by overriding Jetstream's `teams.delete-team-form` Livewire component (a supported Jetstream customization) so the existing button now creates a `TeamDeletionRequest` instead of calling `DeletesTeams::delete()` directly. Bulk file deletion is a new multi-select capability added to `FileBrowser` that creates a `BulkDeletionRequest` instead of deleting. Both request types are approved/rejected from one controller; approval is the only place either real deletion now happens.

**Tech Stack:** Laravel (this repo's existing conventions), Livewire 2 (the vendor component this repo extends), PHPUnit, `Livewire::test()`.

## Global Constraints

- `is_platform_operator` is a `boolean` on `users`, default `false`, **excluded from `User::$fillable`** — mass-assignment must never be able to set it. No route or command grants it; an operator is created by hand.
- Only one `pending` `TeamDeletionRequest` may exist per team at a time — a second click while one is pending must not create a duplicate.
- The overridden `DeleteTeamForm::deleteTeam()` must **never** call `DeletesTeams::delete()` and must **never** set `$this->team = null` or redirect — those are the vendor's "it's actually gone now" side effects, and the team is not gone.
- Existing single-object deletion (`FileBrowser::deleteObject()`) is untouched — stays instant.
- Rejecting either request type requires a non-empty `reason` — a Laravel validation failure, never silently accepted.
- `BulkDeletionRequest` approval re-resolves `object_ids` against real rows at approval time (re-scoped to the request's own `team_id`) — never trusts the ID list blindly, and silently skips an object that no longer exists rather than erroring.
- Every `git add` lists files explicitly, never `git add -A`/`git add .` — this repo has pre-existing unrelated uncommitted changes (`resources/views/vendor/jetstream/components/application-mark.blade.php`, `resources/views/navigation-dropdown.blade.php`, a deleted `public/img/logo.png`, `public/images/mark-light.png`/`mark.png`, `.phpunit.cache/test-results`, `storage/objects.index`) stashed, not part of this work. `git status --short` before and after every task.
- Full test suite (`php artisan test --compact`) must stay green after every task; `vendor/bin/pint --dirty --format agent` after every task that touches PHP files, if Pint is installed (confirm with `ls vendor/bin/pint` first — this platform's own doc records it was only added 2026-08-07; if missing, skip the Pint step and note it in the commit message instead of failing the task).

---

### Task 1: `is_platform_operator` flag

**Files:**
- Create: `database/migrations/2026_08_09_000001_add_is_platform_operator_to_users_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Unit/UserOperatorFlagTest.php`

**Interfaces:**
- Produces: `users.is_platform_operator` column (boolean, default `false`), readable as `$user->is_platform_operator`. Not fillable.

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_08_09_000001_add_is_platform_operator_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Deliberately NOT named "is_admin" -- a generic admin flag
            // name is an obvious mass-assignment target to probe for.
            // Scoped naming plus exclusion from $fillable (see User model)
            // means no request payload can ever set this, regardless of
            // name. Matches the identical column already proven in
            // ChartSense/Dot.Ehail/Dot.Emall's approval-gate work.
            $table->boolean('is_platform_operator')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_operator');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: migration runs with no errors.

- [ ] **Step 3: Add the boolean cast to `User`**

In `app/Models/User.php`, add `'is_platform_operator' => 'boolean',` to the existing `protected $casts = [...]` array (do not touch `$fillable` — the column must stay absent from it).

- [ ] **Step 4: Write the test**

Create `tests/Unit/UserOperatorFlagTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserOperatorFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_platform_operator_defaults_to_false(): void
    {
        // ->fresh() is required: a DB-level column default is applied
        // server-side and never populates the in-memory model returned by
        // create() (Eloquent's insert only refreshes the auto-incrementing
        // key), so asserting directly against the just-created instance
        // would read an unset PHP attribute (null), not the real column
        // value.
        $user = User::factory()->create()->fresh();

        $this->assertFalse($user->is_platform_operator);
    }

    public function test_is_platform_operator_is_not_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'operator-probe@example.com',
            'password' => bcrypt('password'),
            'is_platform_operator' => true,
        ]);

        $this->assertFalse($user->fresh()->is_platform_operator);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Unit/UserOperatorFlagTest.php`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_09_000001_add_is_platform_operator_to_users_table.php \
  app/Models/User.php tests/Unit/UserOperatorFlagTest.php
git commit -m "feat: is_platform_operator flag on User

Mirrors the identical column already proven in ChartSense/Dot.Ehail/
Dot.Emall's approval-gate work. Granted only by hand -- no route or
command in this codebase sets it."
```

---

### Task 2: `TeamDeletionRequest` + `BulkDeletionRequest` models

**Files:**
- Create: `database/migrations/2026_08_09_000002_create_team_deletion_requests_table.php`
- Create: `database/migrations/2026_08_09_000003_create_bulk_deletion_requests_table.php`
- Create: `app/Models/TeamDeletionRequest.php`
- Create: `app/Models/BulkDeletionRequest.php`
- Test: `tests/Unit/DeletionRequestModelsTest.php`

**Interfaces:**
- Produces: `TeamDeletionRequest::team()`/`requester()`/`reviewer()` (all `BelongsTo`), `TeamDeletionRequest::$fillable` = `['team_id', 'requested_by', 'status', 'rejected_reason', 'reviewed_by', 'reviewed_at']`. `BulkDeletionRequest` identical shape plus `object_ids` (array cast), same relation names.

- [ ] **Step 1: Write the migrations**

Create `database/migrations/2026_08_09_000002_create_team_deletion_requests_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->text('rejected_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_deletion_requests');
    }
};
```

Create `database/migrations/2026_08_09_000003_create_bulk_deletion_requests_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->json('object_ids');
            $table->string('status')->default('pending');
            $table->text('rejected_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_deletion_requests');
    }
};
```

- [ ] **Step 2: Run the migrations**

Run: `php artisan migrate`
Expected: both migrations run with no errors.

- [ ] **Step 3: Write the failing test**

Create `tests/Unit/DeletionRequestModelsTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\BulkDeletionRequest;
use App\Models\TeamDeletionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeletionRequestModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_deletion_request_relations(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $reviewer = User::factory()->create();

        $request = TeamDeletionRequest::create([
            'team_id' => $owner->currentTeam->id,
            'requested_by' => $owner->id,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $this->assertSame('pending', $request->status);
        $this->assertTrue($request->team->is($owner->currentTeam));
        $this->assertTrue($request->requester->is($owner));
        $this->assertTrue($request->reviewer->is($reviewer));
    }

    public function test_bulk_deletion_request_stores_object_ids_as_an_array(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $request = BulkDeletionRequest::create([
            'team_id' => $owner->currentTeam->id,
            'requested_by' => $owner->id,
            'object_ids' => [1, 2, 3],
        ]);

        $fresh = $request->fresh();
        $this->assertSame([1, 2, 3], $fresh->object_ids);
        $this->assertTrue($fresh->team->is($owner->currentTeam));
        $this->assertTrue($fresh->requester->is($owner));
    }
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `php artisan test tests/Unit/DeletionRequestModelsTest.php`
Expected: FAIL — `Class "App\Models\TeamDeletionRequest" not found`.

- [ ] **Step 5: Write `app/Models/TeamDeletionRequest.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamDeletionRequest extends Model
{
    protected $fillable = [
        'team_id', 'requested_by', 'status', 'rejected_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
```

- [ ] **Step 6: Write `app/Models/BulkDeletionRequest.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkDeletionRequest extends Model
{
    protected $fillable = [
        'team_id', 'requested_by', 'object_ids', 'status', 'rejected_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'object_ids' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Unit/DeletionRequestModelsTest.php`
Expected: PASS (2 tests)

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_09_000002_create_team_deletion_requests_table.php \
  database/migrations/2026_08_09_000003_create_bulk_deletion_requests_table.php \
  app/Models/TeamDeletionRequest.php app/Models/BulkDeletionRequest.php \
  tests/Unit/DeletionRequestModelsTest.php
git commit -m "feat: TeamDeletionRequest + BulkDeletionRequest models

Both pending/approved/rejected, reviewer + timestamp tracking. Neither
performs any deletion itself -- pure request records consumed by
Task 3/4's gates and Task 5's approval controller."
```

---

### Task 3: Team deletion gate

**Files:**
- Create: `app/Http/Livewire/DeleteTeamForm.php`
- Modify: `app/Providers/JetstreamServiceProvider.php`
- Modify: `resources/views/teams/delete-team-form.blade.php`
- Test: `tests/Feature/TeamDeletionRequestTest.php`

**Interfaces:**
- Consumes: `TeamDeletionRequest` (Task 2).
- Produces: `App\Http\Livewire\DeleteTeamForm` registered under the `teams.delete-team-form` Livewire alias, overriding the vendor component.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TeamDeletionRequestTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Livewire\DeleteTeamForm;
use App\Models\TeamDeletionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeamDeletionRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_clicking_delete_team_creates_a_pending_request_and_does_not_delete_the_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        Livewire::actingAs($owner)
            ->test(DeleteTeamForm::class, ['team' => $team])
            ->call('deleteTeam');

        $this->assertNotNull($team->fresh());
        $this->assertSame(1, TeamDeletionRequest::where('team_id', $team->id)->count());
        $request = TeamDeletionRequest::where('team_id', $team->id)->first();
        $this->assertSame('pending', $request->status);
        $this->assertSame($owner->id, $request->requested_by);
    }

    public function test_a_second_click_while_one_is_pending_does_not_create_a_duplicate(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        $component = Livewire::actingAs($owner)->test(DeleteTeamForm::class, ['team' => $team]);
        $component->call('deleteTeam');
        $component->call('deleteTeam');

        $this->assertSame(1, TeamDeletionRequest::where('team_id', $team->id)->count());
    }

    public function test_a_non_owner_cannot_request_team_deletion(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $stranger = User::factory()->create();

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        Livewire::actingAs($stranger)
            ->test(DeleteTeamForm::class, ['team' => $team])
            ->call('deleteTeam');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/TeamDeletionRequestTest.php`
Expected: FAIL — `Class "App\Http\Livewire\DeleteTeamForm" not found`.

- [ ] **Step 3: Write `app/Http/Livewire/DeleteTeamForm.php`**

```php
<?php

namespace App\Http\Livewire;

use App\Models\TeamDeletionRequest;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Http\Livewire\DeleteTeamForm as JetstreamDeleteTeamForm;

/**
 * Overrides Jetstream's own team-deletion component (registered under the
 * teams.delete-team-form Livewire alias -- see the re-registration in
 * app/Providers/JetstreamServiceProvider.php::boot(), which runs after
 * Jetstream's own package registration and so wins). Team deletion is no
 * longer instant: clicking "Delete Team" creates a pending
 * TeamDeletionRequest instead of calling DeletesTeams::delete() directly.
 * A platform operator must approve it (see
 * app/Http/Controllers/DeletionModerationController.php) before the team
 * is actually removed.
 */
class DeleteTeamForm extends JetstreamDeleteTeamForm
{
    /**
     * True once this session has requested deletion (or found one already
     * pending) -- the view reads this to show a "pending approval" message
     * instead of the normal delete confirmation.
     */
    public bool $requestedDeletion = false;

    public function deleteTeam(): void
    {
        Gate::authorize('delete', $this->team);

        $existing = TeamDeletionRequest::where('team_id', $this->team->id)
            ->where('status', 'pending')
            ->first();

        if (! $existing) {
            TeamDeletionRequest::create([
                'team_id' => $this->team->id,
                'requested_by' => auth()->id(),
            ]);
        }

        $this->confirmingTeamDeletion = false;
        $this->requestedDeletion = true;
    }
}
```

Note: this override deliberately does **not** call `DeletesTeams::delete()`, does **not** set `$this->team = null`, and does **not** redirect — those are the vendor's "deletion succeeded" side effects (see the parent class at `vendor/laravel/jetstream/src/Http/Livewire/DeleteTeamForm.php` for comparison), and shipping any of them here would misrepresent a team that is still fully intact as gone.

- [ ] **Step 4: Register the override**

In `app/Providers/JetstreamServiceProvider.php`, add the import `use Illuminate\Support\Facades\Livewire;` (note: Jetstream ships Livewire 2, whose facade is `Livewire\Livewire`, not `Illuminate\Support\Facades\Livewire` — use `use Livewire\Livewire;`), and add one line at the end of the existing `boot()` method:

```php
public function boot()
{
    $this->configurePermissions();

    Jetstream::createTeamsUsing(CreateTeam::class);
    Jetstream::updateTeamNamesUsing(UpdateTeamName::class);
    Jetstream::addTeamMembersUsing(AddTeamMember::class);
    Jetstream::deleteTeamsUsing(DeleteTeam::class);
    Jetstream::deleteUsersUsing(DeleteUser::class);

    Livewire::component('teams.delete-team-form', \App\Http\Livewire\DeleteTeamForm::class);
}
```

`Jetstream::deleteTeamsUsing(DeleteTeam::class)` (the `App\Actions\Jetstream\DeleteTeam` action, unchanged by this plan) stays exactly as-is — it still means "delete now," it's just no longer reachable from the end-user button after this task. It becomes reachable again in Task 5, from the operator's approve action.

- [ ] **Step 5: Update the view**

In `resources/views/teams/delete-team-form.blade.php`, wrap the existing button/modal content in a check for `$requestedDeletion`:

```blade
<x-jet-action-section>
    <x-slot name="title">
        {{ __('Delete Team') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Permanently delete this team.') }}
    </x-slot>

    <x-slot name="content">
        @if ($requestedDeletion)
            <div class="max-w-xl text-sm text-amber-600 font-bold">
                {{ __('Deletion requested. A platform operator must approve it before this team and its data are removed.') }}
            </div>
        @else
            <div class="max-w-xl text-sm text-gray-600">
                {{ __('Once a team is deleted, all of its resources and data will be permanently deleted. Before deleting this team, please download any data or information regarding this team that you wish to retain.') }}
            </div>

            <div class="mt-5">
                <x-jet-danger-button wire:click="$toggle('confirmingTeamDeletion')" wire:loading.attr="disabled">
                    {{ __('Delete Team') }}
                </x-jet-danger-button>
            </div>

            <!-- Delete Team Confirmation Modal -->
            <x-jet-confirmation-modal wire:model="confirmingTeamDeletion">
                <x-slot name="title">
                    {{ __('Delete Team') }}
                </x-slot>

                <x-slot name="content">
                    {{ __('Are you sure you want to request deletion of this team? A platform operator will need to approve the request before anything is actually deleted.') }}
                </x-slot>

                <x-slot name="footer">
                    <x-jet-secondary-button wire:click="$toggle('confirmingTeamDeletion')" wire:loading.attr="disabled">
                        {{ __('Nevermind') }}
                    </x-jet-secondary-button>

                    <x-jet-danger-button class="ml-2" wire:click="deleteTeam" wire:loading.attr="disabled">
                        {{ __('Request Deletion') }}
                    </x-jet-danger-button>
                </x-slot>
            </x-jet-confirmation-modal>
        @endif
    </x-slot>
</x-jet-action-section>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/TeamDeletionRequestTest.php`
Expected: PASS (3 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Livewire/DeleteTeamForm.php app/Providers/JetstreamServiceProvider.php \
  resources/views/teams/delete-team-form.blade.php tests/Feature/TeamDeletionRequestTest.php
git commit -m "feat: team deletion now requires operator approval

Overrides Jetstream's teams.delete-team-form component (a supported
customization pattern -- app providers boot after package providers,
so this registration wins) so the existing button creates a
TeamDeletionRequest instead of calling DeletesTeams::delete()
directly. The team stays fully intact until approved (Task 5)."
```

---

### Task 4: Bulk file/folder deletion gate

**Files:**
- Modify: `app/Http/Livewire/FileBrowser.php`
- Modify: `resources/views/livewire/file-browser.blade.php`
- Test: `tests/Feature/BulkDeletionRequestTest.php`

**Interfaces:**
- Consumes: `BulkDeletionRequest` (Task 2), `FileBrowser::resolveCurrentTeam()` (existing private helper).
- Produces: `FileBrowser::$selectedObjects` (array), `FileBrowser::requestBulkDeletion(): void`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BulkDeletionRequestTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Livewire\FileBrowser;
use App\Models\BulkDeletionRequest;
use App\Models\File;
use App\Models\Obj;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BulkDeletionRequestTest extends TestCase
{
    use RefreshDatabase;

    private function makeFileObject($team, string $name = 'widget.txt'): Obj
    {
        $object = $team->objects()->make(['parent_id' => null]);
        $object->objectable()->associate($team->files()->create([
            'name' => $name,
            'size' => 10,
            'path' => 'files/'.uniqid().'.txt',
        ]));
        $object->save();

        return $object;
    }

    public function test_selecting_objects_and_requesting_deletion_creates_a_request_without_deleting(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $one = $this->makeFileObject($team, 'one.txt');
        $two = $this->makeFileObject($team, 'two.txt');
        $root = Obj::whereNull('parent_id')->first();

        Livewire::actingAs($owner)
            ->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect()])
            ->set('selectedObjects', [$one->id, $two->id])
            ->call('requestBulkDeletion');

        $this->assertNotNull(Obj::find($one->id));
        $this->assertNotNull(Obj::find($two->id));

        $request = BulkDeletionRequest::where('team_id', $team->id)->first();
        $this->assertNotNull($request);
        $this->assertSame('pending', $request->status);
        $this->assertEqualsCanonicalizing([$one->id, $two->id], $request->object_ids);
    }

    public function test_requesting_with_an_empty_selection_creates_no_request(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->first();

        Livewire::actingAs($owner)
            ->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect()])
            ->set('selectedObjects', [])
            ->call('requestBulkDeletion');

        $this->assertSame(0, BulkDeletionRequest::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/BulkDeletionRequestTest.php`
Expected: FAIL — `requestBulkDeletion` method does not exist.

- [ ] **Step 3: Add `$selectedObjects` and `requestBulkDeletion()` to `FileBrowser`**

In `app/Http/Livewire/FileBrowser.php`, add `use App\Models\BulkDeletionRequest;` to the imports, and add the following properties/method (placed near the existing `confirmingObjectDeletion`/`deleteObject()` pair, as a sibling capability):

```php
    public array $selectedObjects = [];

    public bool $bulkDeletionRequested = false;

    public function requestBulkDeletion(): void
    {
        if (empty($this->selectedObjects)) {
            return;
        }

        $team = $this->resolveCurrentTeam();

        if (! $team) {
            abort(403, 'No active team selected.');
        }

        BulkDeletionRequest::create([
            'team_id' => $team->id,
            'requested_by' => auth()->id(),
            'object_ids' => array_values($this->selectedObjects),
        ]);

        $this->selectedObjects = [];
        $this->bulkDeletionRequested = true;
    }
```

Note: `$this->selectedObjects` only ever contains IDs the current page rendered checkboxes for, and those checkboxes are rendered from `$this->results`/`$this->object->children`, both of which resolve through `Obj`'s `HasTeamScope` global scope (see `app/Models/Concerns/HasTeamScope.php`) — so a cross-team object ID can never enter `$selectedObjects` in the first place. No additional scoping check is needed here; Task 5's `approveBulk()` re-validates against `team_id` again anyway as a second layer (belt-and-suspenders, not because this layer is insufficient).

- [ ] **Step 4: Add checkboxes and a "Delete Selected" button to the view**

In `resources/views/livewire/file-browser.blade.php`, add a new column to the results table. First, add a header cell to the `<thead>` row (before the existing "Name" column):

```blade
<thead class="bg-gray-100">
    <tr>
        <th class="p-2 w-8"></th>
        <th class="text-left py-2 px-3">Name</th>
        <th class="text-left py-2 px-3 w-2/12">Size</th>
        <th class="text-left py-2 px-3 w-2/12">Created</th>
        <th class="p-2 w-2/12"></th>
    </tr>
</thead>
```

Then add a checkbox cell as the first `<td>` inside the `@foreach ($this->results as $child)` loop's `<tr>` (immediately before the existing `<td class="py-2 px-3 flex items-center">` cell):

```blade
<td class="py-2 px-3">
    <input type="checkbox" wire:model="selectedObjects" value="{{ $child->id }}">
</td>
```

Add a "Delete Selected" button next to the existing "New folder"/"upload files" buttons:

```blade
<button class="bg-red-600 text-white font-bold px-6 h-12 rounded-lg mr-2"
    wire:click="requestBulkDeletion"
    @if (empty($selectedObjects)) disabled @endif>
    Delete Selected ({{ count($selectedObjects) }})
</button>
```

And a confirmation banner near the top of the component (after the search/actions row, matching the existing inline-message convention):

```blade
@if ($bulkDeletionRequested)
    <div class="mb-4 p-3 bg-amber-100 text-amber-800 font-bold rounded-lg">
        Deletion requested for the selected items. A platform operator must approve it before anything is removed.
    </div>
@endif
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/BulkDeletionRequestTest.php`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Livewire/FileBrowser.php resources/views/livewire/file-browser.blade.php \
  tests/Feature/BulkDeletionRequestTest.php
git commit -m "feat: bulk file/folder deletion, gated behind operator approval

New multi-select capability (didn't exist before). Selecting objects
and confirming creates a BulkDeletionRequest instead of deleting.
Existing single-object deleteObject() is untouched -- stays instant."
```

---

### Task 5: Operator review screen

**Files:**
- Create: `app/Http/Middleware/EnsurePlatformOperator.php`
- Create: `app/Http/Controllers/DeletionModerationController.php`
- Create: `resources/views/operator/deletion-requests/index.blade.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/DeletionModerationTest.php`

**Interfaces:**
- Consumes: `TeamDeletionRequest`/`BulkDeletionRequest` (Task 2), `is_platform_operator` (Task 1), `App\Actions\Jetstream\DeleteTeam` (existing, unmodified), `Obj` (existing model).
- Produces: routes `operator.deletion-requests.index`, `operator.deletion-requests.team.approve`, `operator.deletion-requests.team.reject`, `operator.deletion-requests.bulk.approve`, `operator.deletion-requests.bulk.reject`; middleware alias `operator`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/DeletionModerationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BulkDeletionRequest;
use App\Models\Obj;
use App\Models\Team;
use App\Models\TeamDeletionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeletionModerationTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();

        return $operator;
    }

    private function makeFileObject(Team $team, string $name = 'widget.txt'): Obj
    {
        $object = $team->objects()->make(['parent_id' => null]);
        $object->objectable()->associate($team->files()->create([
            'name' => $name,
            'size' => 10,
            'path' => 'files/'.uniqid().'.txt',
        ]));
        $object->save();

        return $object;
    }

    public function test_operator_can_view_pending_requests_of_both_types(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $teamRequest = TeamDeletionRequest::create([
            'team_id' => $owner->currentTeam->id,
            'requested_by' => $owner->id,
        ]);
        $bulkRequest = BulkDeletionRequest::create([
            'team_id' => $owner->currentTeam->id,
            'requested_by' => $owner->id,
            'object_ids' => [999],
        ]);

        $this->actingAs($this->operator())
            ->get(route('operator.deletion-requests.index'))
            ->assertOk()
            ->assertSee($owner->currentTeam->name);
    }

    public function test_approving_a_team_deletion_request_actually_deletes_the_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $request = TeamDeletionRequest::create([
            'team_id' => $team->id,
            'requested_by' => $owner->id,
        ]);
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('operator.deletion-requests.team.approve', $request))
            ->assertRedirect();

        $this->assertNull(Team::find($team->id));
        $fresh = $request->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($operator->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
    }

    public function test_rejecting_a_team_deletion_request_without_a_reason_is_blocked(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $request = TeamDeletionRequest::create([
            'team_id' => $owner->currentTeam->id,
            'requested_by' => $owner->id,
        ]);

        $this->actingAs($this->operator())
            ->post(route('operator.deletion-requests.team.reject', $request), [])
            ->assertSessionHasErrors('reason');

        $this->assertNotNull(Team::find($owner->currentTeam->id));
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_rejecting_a_team_deletion_request_with_a_reason_marks_it_rejected(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $request = TeamDeletionRequest::create([
            'team_id' => $team->id,
            'requested_by' => $owner->id,
        ]);

        $this->actingAs($this->operator())
            ->post(route('operator.deletion-requests.team.reject', $request), ['reason' => 'Not warranted.'])
            ->assertRedirect();

        $fresh = $request->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Not warranted.', $fresh->rejected_reason);
        $this->assertNotNull(Team::find($team->id));
    }

    public function test_approving_a_bulk_deletion_request_deletes_exactly_the_listed_objects(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $toDelete = $this->makeFileObject($team, 'delete-me.txt');
        $toKeep = $this->makeFileObject($team, 'keep-me.txt');
        $request = BulkDeletionRequest::create([
            'team_id' => $team->id,
            'requested_by' => $owner->id,
            'object_ids' => [$toDelete->id],
        ]);
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('operator.deletion-requests.bulk.approve', $request))
            ->assertRedirect();

        $this->assertNull(Obj::find($toDelete->id));
        $this->assertNotNull(Obj::find($toKeep->id));
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_approving_a_bulk_deletion_request_skips_an_object_already_deleted(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $alreadyGone = $this->makeFileObject($team, 'already-gone.txt');
        $stillThere = $this->makeFileObject($team, 'still-there.txt');
        $request = BulkDeletionRequest::create([
            'team_id' => $team->id,
            'requested_by' => $owner->id,
            'object_ids' => [$alreadyGone->id, $stillThere->id],
        ]);
        $alreadyGone->delete();

        $this->actingAs($this->operator())
            ->post(route('operator.deletion-requests.bulk.approve', $request))
            ->assertRedirect();

        $this->assertNull(Obj::find($stillThere->id));
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_non_operator_is_refused_on_index_and_every_action(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $teamRequest = TeamDeletionRequest::create([
            'team_id' => $owner->currentTeam->id,
            'requested_by' => $owner->id,
        ]);
        $nonOperator = User::factory()->create();

        $this->actingAs($nonOperator)
            ->get(route('operator.deletion-requests.index'))
            ->assertForbidden();

        $this->actingAs($nonOperator)
            ->post(route('operator.deletion-requests.team.approve', $teamRequest))
            ->assertForbidden();

        $this->assertSame('pending', $teamRequest->fresh()->status);
    }

    public function test_approving_an_already_decided_team_request_is_refused(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $request = TeamDeletionRequest::create([
            'team_id' => $team->id,
            'requested_by' => $owner->id,
        ]);
        $request->update(['status' => 'rejected', 'rejected_reason' => 'Already handled.']);

        $this->actingAs($this->operator())
            ->post(route('operator.deletion-requests.team.approve', $request))
            ->assertSessionHasErrors();

        $this->assertNotNull(Team::find($team->id));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/DeletionModerationTest.php`
Expected: FAIL — `operator.deletion-requests.*` routes don't exist yet.

- [ ] **Step 3: Write `app/Http/Middleware/EnsurePlatformOperator.php`**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_platform_operator, 403);

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the middleware alias**

In `bootstrap/app.php`, add `'operator' => \App\Http\Middleware\EnsurePlatformOperator::class,` to the existing `$middleware->alias([...])` array (which currently only contains `'verified' => EnsureEmailIsVerified::class`).

- [ ] **Step 5: Write `app/Http/Controllers/DeletionModerationController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Actions\Jetstream\DeleteTeam;
use App\Models\BulkDeletionRequest;
use App\Models\Obj;
use App\Models\TeamDeletionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeletionModerationController extends Controller
{
    public function index(): View
    {
        return view('operator.deletion-requests.index', [
            'pendingTeamRequests' => TeamDeletionRequest::where('status', 'pending')->with(['team', 'requester'])->latest()->get(),
            'pendingBulkRequests' => BulkDeletionRequest::where('status', 'pending')->with(['team', 'requester'])->latest()->get(),
        ]);
    }

    public function approveTeam(TeamDeletionRequest $teamDeletionRequest, DeleteTeam $deleter): RedirectResponse
    {
        if ($teamDeletionRequest->status !== 'pending') {
            return back()->withErrors(['status' => 'Only a pending request can be approved.']);
        }

        $deleter->delete($teamDeletionRequest->team);

        $teamDeletionRequest->update([
            'status' => 'approved',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Team deleted.');
    }

    public function rejectTeam(TeamDeletionRequest $teamDeletionRequest, Request $request): RedirectResponse
    {
        if ($teamDeletionRequest->status !== 'pending') {
            return back()->withErrors(['status' => 'Only a pending request can be rejected.']);
        }

        $validated = $request->validate(['reason' => 'required|string']);

        $teamDeletionRequest->update([
            'status' => 'rejected',
            'rejected_reason' => $validated['reason'],
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Team deletion request rejected.');
    }

    public function approveBulk(BulkDeletionRequest $bulkDeletionRequest): RedirectResponse
    {
        if ($bulkDeletionRequest->status !== 'pending') {
            return back()->withErrors(['status' => 'Only a pending request can be approved.']);
        }

        // Re-resolve against real rows rather than trusting object_ids
        // blindly, and re-scope to this request's own team_id even though
        // Obj's HasTeamScope global scope would already enforce that for
        // an authenticated request -- belt-and-suspenders, matching this
        // session's established double-check style.
        Obj::whereIn('id', $bulkDeletionRequest->object_ids)
            ->where('team_id', $bulkDeletionRequest->team_id)
            ->get()
            ->each->delete();

        $bulkDeletionRequest->update([
            'status' => 'approved',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Selected items deleted.');
    }

    public function rejectBulk(BulkDeletionRequest $bulkDeletionRequest, Request $request): RedirectResponse
    {
        if ($bulkDeletionRequest->status !== 'pending') {
            return back()->withErrors(['status' => 'Only a pending request can be rejected.']);
        }

        $validated = $request->validate(['reason' => 'required|string']);

        $bulkDeletionRequest->update([
            'status' => 'rejected',
            'rejected_reason' => $validated['reason'],
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Bulk deletion request rejected.');
    }
}
```

Note: `Obj::whereIn(...)` here runs as the authenticated operator, whose own `currentTeam` may not match `$bulkDeletionRequest->team_id` (an operator reviewing any team's request) -- `HasTeamScope`'s global scope would filter to the *operator's own* current team, not the request's team, silently returning zero rows for any request that isn't the operator's own team. This must use `Obj::withoutGlobalScope('team')->whereIn(...)` instead of a bare `Obj::whereIn(...)` to avoid that trap. Correct the query to:

```php
        Obj::withoutGlobalScope('team')
            ->whereIn('id', $bulkDeletionRequest->object_ids)
            ->where('team_id', $bulkDeletionRequest->team_id)
            ->get()
            ->each->delete();
```

Use this corrected version in the actual file, not the first version shown above — the first version is left in place only to make the mistake explicit for whoever reviews this task; do not write the buggy version to disk.

- [ ] **Step 6: Write the review-screen view**

Create `resources/views/operator/deletion-requests/index.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Deletion Requests
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-4 bg-green-100 text-green-800 rounded-lg">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white p-6 rounded-lg shadow">
                <h3 class="font-bold text-lg mb-4">Pending Team Deletions ({{ $pendingTeamRequests->count() }})</h3>
                @forelse ($pendingTeamRequests as $request)
                    <div class="border-b py-3">
                        <p class="font-bold">{{ $request->team->name }}</p>
                        <p class="text-sm text-gray-500 mb-2">Requested by {{ $request->requester->name }}</p>
                        <div class="flex items-center gap-2">
                            <form method="POST" action="{{ route('operator.deletion-requests.team.approve', $request) }}">
                                @csrf
                                <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-bold">Approve</button>
                            </form>
                            <form method="POST" action="{{ route('operator.deletion-requests.team.reject', $request) }}" class="flex items-center gap-2">
                                @csrf
                                <input type="text" name="reason" placeholder="Reason for rejecting" required class="border rounded-lg px-2 py-1 text-sm">
                                <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold">Reject</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="text-gray-500 text-sm">No pending team deletions.</p>
                @endforelse
            </div>

            <div class="bg-white p-6 rounded-lg shadow">
                <h3 class="font-bold text-lg mb-4">Pending Bulk File Deletions ({{ $pendingBulkRequests->count() }})</h3>
                @forelse ($pendingBulkRequests as $request)
                    <div class="border-b py-3">
                        <p class="font-bold">{{ $request->team->name }} &mdash; {{ count($request->object_ids) }} item(s)</p>
                        <p class="text-sm text-gray-500 mb-2">Requested by {{ $request->requester->name }}</p>
                        <div class="flex items-center gap-2">
                            <form method="POST" action="{{ route('operator.deletion-requests.bulk.approve', $request) }}">
                                @csrf
                                <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-bold">Approve</button>
                            </form>
                            <form method="POST" action="{{ route('operator.deletion-requests.bulk.reject', $request) }}" class="flex items-center gap-2">
                                @csrf
                                <input type="text" name="reason" placeholder="Reason for rejecting" required class="border rounded-lg px-2 py-1 text-sm">
                                <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold">Reject</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="text-gray-500 text-sm">No pending bulk deletions.</p>
                @endforelse
            </div>

        </div>
    </div>
</x-app-layout>
```

- [ ] **Step 7: Add routes**

In `routes/web.php`, add `use App\Http\Controllers\DeletionModerationController;` to the imports, and add a new middleware-nested group after the existing `files`/`files.download` group:

```php
Route::middleware(['auth', 'verified', 'operator'])->prefix('operator')->name('operator.')->group(function () {
    Route::get('/deletion-requests', [DeletionModerationController::class, 'index'])->name('deletion-requests.index');
    Route::post('/deletion-requests/team/{teamDeletionRequest}/approve', [DeletionModerationController::class, 'approveTeam'])->name('deletion-requests.team.approve');
    Route::post('/deletion-requests/team/{teamDeletionRequest}/reject', [DeletionModerationController::class, 'rejectTeam'])->name('deletion-requests.team.reject');
    Route::post('/deletion-requests/bulk/{bulkDeletionRequest}/approve', [DeletionModerationController::class, 'approveBulk'])->name('deletion-requests.bulk.approve');
    Route::post('/deletion-requests/bulk/{bulkDeletionRequest}/reject', [DeletionModerationController::class, 'rejectBulk'])->name('deletion-requests.bulk.reject');
});
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test tests/Feature/DeletionModerationTest.php`
Expected: PASS (8 tests)

- [ ] **Step 9: Commit**

```bash
git add app/Http/Middleware/EnsurePlatformOperator.php app/Http/Controllers/DeletionModerationController.php \
  resources/views/operator/deletion-requests/index.blade.php bootstrap/app.php routes/web.php \
  tests/Feature/DeletionModerationTest.php
git commit -m "feat: operator review screen -- approve/reject deletion requests

is_platform_operator-gated. Rejecting requires a non-empty reason.
approveTeam() is the only path that now calls the real, unmodified
DeleteTeam action. approveBulk() re-resolves object_ids against real
rows, re-scoped to the request's own team_id via
withoutGlobalScope('team') -- the operator's own currentTeam would
otherwise silently filter out every other team's request."
```

---

### Task 6: Full regression + manual verification

**Files:** none new — verification only.

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test --compact`
Expected: 0 failures across the whole suite — confirms Tasks 1-5 didn't break `FilesTeamScopeTest`, `NoCurrentTeamTest`, or `EcosystemErrorPagesTest`.

- [ ] **Step 2: Manual end-to-end verification — team deletion**

```bash
php artisan tinker --execute '
$owner = \App\Models\User::factory()->withPersonalTeam()->create(["name" => "Manual Test Owner"]);
$operator = \App\Models\User::factory()->create(["name" => "Manual Test Operator"]);
$operator->forceFill(["is_platform_operator" => true])->save();
$team = $owner->currentTeam;
echo "owner_id={$owner->id} operator_id={$operator->id} team_id={$team->id}\n";
'
```

Then, as the owner, request deletion via the real Livewire component:

```bash
php artisan tinker --execute '
auth()->loginUsingId(<owner_id>);
$team = \App\Models\Team::find(<team_id>);
$component = new \App\Http\Livewire\DeleteTeamForm();
$component->mount($team);
$component->deleteTeam();
echo "team still exists after request: ".(\App\Models\Team::find(<team_id>) ? "yes" : "no")."\n";
echo "pending request count: ".\App\Models\TeamDeletionRequest::where("team_id", <team_id>)->where("status", "pending")->count()."\n";
'
```

Expected: `team still exists after request: yes`, `pending request count: 1`.

Then, as the operator, approve it:

```bash
php artisan tinker --execute '
$request = \App\Models\TeamDeletionRequest::where("team_id", <team_id>)->first();
auth()->loginUsingId(<operator_id>);
app(\App\Http\Controllers\DeletionModerationController::class)->approveTeam($request, new \App\Actions\Jetstream\DeleteTeam());
echo "team exists after approval: ".(\App\Models\Team::find(<team_id>) ? "yes" : "no")."\n";
'
```

Expected: `team exists after approval: no`. Confirms the real end-to-end lifecycle, not just in-memory test doubles.

- [ ] **Step 3: Manual end-to-end verification — bulk file deletion**

```bash
php artisan tinker --execute '
$owner = \App\Models\User::factory()->withPersonalTeam()->create(["name" => "Manual Test Owner 2"]);
$operator = \App\Models\User::factory()->create(["name" => "Manual Test Operator 2"]);
$operator->forceFill(["is_platform_operator" => true])->save();
$team = $owner->currentTeam;
auth()->loginUsingId($owner->id);
$object = $team->objects()->make(["parent_id" => null]);
$object->objectable()->associate($team->files()->create(["name" => "manual-test.txt", "size" => 10, "path" => "files/manual-test.txt"]));
$object->save();
echo "owner_id={$owner->id} operator_id={$operator->id} object_id={$object->id}\n";
'
```

Then request and approve bulk deletion:

```bash
php artisan tinker --execute '
auth()->loginUsingId(<owner_id>);
$request = new \App\Models\BulkDeletionRequest();
\App\Models\BulkDeletionRequest::create(["team_id" => auth()->user()->currentTeam->id, "requested_by" => auth()->id(), "object_ids" => [<object_id>]]);
$bulkRequest = \App\Models\BulkDeletionRequest::latest()->first();
echo "object still exists after request: ".(\App\Models\Obj::withoutGlobalScope("team")->find(<object_id>) ? "yes" : "no")."\n";
auth()->loginUsingId(<operator_id>);
app(\App\Http\Controllers\DeletionModerationController::class)->approveBulk($bulkRequest);
echo "object exists after approval: ".(\App\Models\Obj::withoutGlobalScope("team")->find(<object_id>) ? "yes" : "no")."\n";
'
```

Expected: `object still exists after request: yes`, `object exists after approval: no`.

- [ ] **Step 4: Clean up manual verification fixtures**

```bash
php artisan tinker --execute '
\App\Models\User::where("name", "like", "Manual Test%")->each(function ($u) {
    $u->currentTeam?->delete();
    $u->delete();
});
echo "cleaned up manual verification fixtures\n";
'
```

- [ ] **Step 5: Report completion**

No commit for this task — it's verification only. If Step 1 finds any failures, stop and fix them (return to the relevant earlier task) before considering this plan complete.

## Self-Review Notes

- **Spec coverage:** Task 1 covers spec §1. Task 2 covers spec §2-§3. Task 3 covers spec §4. Task 4 covers spec §5. Task 5 covers spec §6. Task 6 covers the spec's implicit "this all actually works together" requirement.
- **Placeholder scan:** none — every step has literal file content, including full controller/view/policy/migration contents.
- **Type consistency:** `TeamDeletionRequest`/`BulkDeletionRequest` field names, `DeleteTeamForm::$requestedDeletion`/`deleteTeam()`, `FileBrowser::$selectedObjects`/`requestBulkDeletion()`, `DeletionModerationController::index/approveTeam/rejectTeam/approveBulk/rejectBulk` signatures are used identically across every task that references them.
- **The `HasTeamScope` trap, addressed explicitly:** Task 5 Step 5 walks through the buggy version and the corrected `withoutGlobalScope('team')` version side by side, on purpose — this is the single most likely way to ship an operator review screen that silently does nothing for every team but the operator's own, and it directly parallels the `HasUserScope` trap this session already hit once in Dot.Ehail's `FleetController`.
- **The "no misleading redirect" constraint, addressed at its single point of enforcement:** Task 3 Step 3's `DeleteTeamForm::deleteTeam()` is the only place this matters, and the plan states explicitly what NOT to do (call the deleter, null `$this->team`, redirect) with a pointer to the vendor file for comparison.
- **Uncommitted pre-existing changes:** this repo had pre-existing uncommitted changes (`application-mark.blade.php`, `navigation-dropdown.blade.php`, a deleted logo, mark images, `.phpunit.cache/test-results`, `storage/objects.index`) stashed before this plan was written, per the spec's own context section; every `git add` in this plan lists files explicitly.
