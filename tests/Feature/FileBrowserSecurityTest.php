<?php

namespace Tests\Feature;

use App\Actions\Jetstream\DeleteUser;
use App\Http\Livewire\FileBrowser;
use App\Models\BulkDeletionRequest;
use App\Models\Obj;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-25 Phase 1: the audit's findings stay fixed. A rename can only rename; the root cannot be
 * deleted from the browser; a populated folder goes to the approval gate; bulk requests are
 * server-validated and legible; an account owning a company cannot delete itself away.
 */
class FileBrowserSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $ann;

    private Team $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection']);

        $this->ann = User::factory()->withPersonalTeam()->create();
        $this->acme = Team::forceCreate(['user_id' => $this->ann->id, 'name' => 'Acme', 'personal_team' => false]);
        $this->ann->switchTeam($this->acme);
    }

    private function root(): Obj
    {
        return $this->acme->objects()->withoutGlobalScope('team')->whereNull('parent_id')->first();
    }

    private function makeFile(string $name): Obj
    {
        $path = 'files/'.uniqid().'.txt';
        Storage::disk('local')->put($path, 'contents');
        $file = $this->acme->files()->create(['name' => $name, 'size' => 8, 'path' => $path]);
        $obj = $this->acme->objects()->make(['parent_id' => $this->root()->id]);
        $obj->objectable()->associate($file);
        $obj->save();

        return $obj->fresh();
    }

    private function makeFolder(string $name, ?int $parentId = null): Obj
    {
        $folder = $this->acme->folders()->create(['name' => $name]);
        $obj = $this->acme->objects()->make(['parent_id' => $parentId ?? $this->root()->id]);
        $obj->objectable()->associate($folder);
        $obj->save();

        return $obj->fresh();
    }

    public function test_a_rename_payload_cannot_rewrite_path_or_team(): void
    {
        $obj = $this->makeFile('ledger.txt');
        $file = $obj->objectable;
        $originalPath = $file->path;

        Livewire::actingAs($this->ann)
            ->test(FileBrowser::class, ['object' => $this->root(), 'ancestors' => collect([$this->root()])])
            ->set('renamingObject', $obj->id)
            ->set('renamingObjectState', ['name' => 'renamed.txt', 'path' => 'files/other-tenants-blob.txt', 'team_id' => 999])
            ->call('renameObject');

        $file->refresh();
        $this->assertSame('renamed.txt', $file->name);
        $this->assertSame($originalPath, $file->path, 'the blob pointer never moves on rename');
        $this->assertSame($this->acme->id, $file->team_id, 'the tenant never changes on rename');
    }

    public function test_the_team_root_cannot_be_deleted_from_the_browser(): void
    {
        $this->makeFile('keep.txt');

        Livewire::actingAs($this->ann)
            ->test(FileBrowser::class, ['object' => $this->root(), 'ancestors' => collect([$this->root()])])
            ->set('confirmingObjectDeletion', $this->root()->id)
            ->call('deleteObject')
            ->assertStatus(403);

        $this->assertNotNull(Obj::withoutGlobalScope('team')->find($this->root()->id));
    }

    public function test_a_folder_with_children_goes_to_the_approval_gate_instead_of_vanishing(): void
    {
        $folder = $this->makeFolder('Records');
        $this->acme->files()->create(['name' => 'inside.txt', 'size' => 1, 'path' => 'files/inside.txt']);
        $inner = $this->acme->objects()->make(['parent_id' => $folder->id]);
        $inner->objectable()->associate($this->acme->files()->latest('id')->first());
        $inner->save();

        Livewire::actingAs($this->ann)
            ->test(FileBrowser::class, ['object' => $this->root(), 'ancestors' => collect([$this->root()])])
            ->set('confirmingObjectDeletion', $folder->id)
            ->call('deleteObject');

        $this->assertNotNull(Obj::withoutGlobalScope('team')->find($folder->id), 'the populated folder survives the click');
        $this->assertDatabaseHas('bulk_deletion_requests', ['team_id' => $this->acme->id, 'status' => 'pending']);
        $this->assertSame(['Records'], BulkDeletionRequest::latest('id')->first()->object_names);

        // An empty folder and a single file still leave on one click — into the TRASH (R-27):
        // out of the browse queries at once, restorable for the holding window.
        $empty = $this->makeFolder('Empty');
        Livewire::actingAs($this->ann)
            ->test(FileBrowser::class, ['object' => $this->root(), 'ancestors' => collect([$this->root()])])
            ->set('confirmingObjectDeletion', $empty->id)
            ->call('deleteObject');
        $trashed = Obj::withoutGlobalScope('team')->find($empty->id);
        $this->assertNotNull($trashed->trashed_at, 'one click parks it in the trash');
    }

    public function test_bulk_requests_keep_only_this_teams_numeric_ids_and_snapshot_names(): void
    {
        $mine = $this->makeFile('mine.txt');

        $other = User::factory()->withPersonalTeam()->create();
        $globex = Team::forceCreate(['user_id' => $other->id, 'name' => 'Globex', 'personal_team' => false]);
        $other->switchTeam($globex);
        $theirs = $globex->objects()->withoutGlobalScope('team')->whereNull('parent_id')->first();

        Livewire::actingAs($this->ann)
            ->test(FileBrowser::class, ['object' => $this->root(), 'ancestors' => collect([$this->root()])])
            ->set('selectedObjects', [$mine->id, $theirs->id, 'DROP TABLE objects', '999999'])
            ->call('requestBulkDeletion');

        $request = BulkDeletionRequest::latest('id')->firstOrFail();
        $this->assertSame([$mine->id], $request->object_ids, 'foreign and junk ids are discarded server-side');
        $this->assertSame(['mine.txt'], $request->object_names);
    }

    public function test_an_account_owning_a_company_cannot_be_deleted(): void
    {
        $this->expectException(ValidationException::class);

        app(DeleteUser::class)->delete($this->ann->fresh());
    }

    public function test_an_account_with_only_a_personal_team_can_still_be_deleted(): void
    {
        $solo = User::factory()->withPersonalTeam()->create();

        app(DeleteUser::class)->delete($solo->fresh());

        $this->assertDatabaseMissing('users', ['id' => $solo->id]);
    }
}
