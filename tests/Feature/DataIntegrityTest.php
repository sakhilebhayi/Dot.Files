<?php

namespace Tests\Feature;

use App\Exceptions\VersionConflictException;
use App\Http\Livewire\FileBrowser;
use App\Http\Livewire\FileSearch;
use App\Models\BulkDeletionRequest;
use App\Models\File;
use App\Models\FilePin;
use App\Models\FileShare;
use App\Models\FileVersion;
use App\Models\Obj;
use App\Models\Team;
use App\Models\User;
use App\Services\FileShareService;
use App\Services\FileVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * EA-2: data integrity and the functions that did not complete — search inside one company, renames found at once,
 * nothing filed into the trash, content and rows written and removed together, decisions taken once.
 */
class DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $acme;

    private Obj $root;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection', 'ecosystem.platform_secrets' => ['docs' => 'secret-docs']]);

        $this->owner = User::factory()->withPersonalTeam()->create(['email' => 'owner@acme.test']);
        $this->acme = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Acme', 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);
        $this->owner->switchTeam($this->acme);
        $this->root = $this->rootOf($this->acme);
    }

    private function rootOf(Team $team): Obj
    {
        return Obj::withoutGlobalScopes()->where('team_id', $team->id)->whereNull('parent_id')->firstOrFail();
    }

    private function folder(Team $team, string $name, ?Obj $parent = null): Obj
    {
        $object = $team->objects()->make(['parent_id' => ($parent ?? $this->rootOf($team))->id]);
        $object->objectable()->associate($team->folders()->create(['name' => $name]));
        $object->save();

        return $object;
    }

    private function file(Team $team, string $name, ?Obj $parent = null, string $bytes = 'content'): Obj
    {
        Storage::disk('local')->put($path = 'files/'.Str::random(12), $bytes);
        $object = $team->objects()->make(['parent_id' => ($parent ?? $this->rootOf($team))->id]);
        $object->objectable()->associate($team->files()->create(['name' => $name, 'size' => strlen($bytes), 'path' => $path, 'mime' => 'text/plain']));
        $object->save();

        return $object;
    }

    private function browser(?Obj $at = null)
    {
        $this->actingAs($this->owner);

        return Livewire::test(FileBrowser::class, ['object' => $at ?? $this->root, 'ancestors' => collect()]);
    }

    public function test_new_folder_on_the_first_run_screen_opens_the_form_and_creates_the_folder(): void
    {
        $this->browser()
            ->assertSet('isFirstRun', true)
            ->set('creatingNewFolder', true)
            ->assertSeeHtml('newFolderState.name')
            ->set('newFolderState.name', 'Contracts')
            ->call('createFolder')
            ->assertHasNoErrors();

        $this->assertTrue(Obj::where('objectable_type', 'folder')->where('parent_id', $this->root->id)->exists());
    }

    public function test_search_finds_this_companys_records_however_many_other_companies_match(): void
    {
        $other = User::factory()->withPersonalTeam()->create();
        $globex = Team::forceCreate(['user_id' => $other->id, 'name' => 'Globex', 'personal_team' => false]);
        foreach (range(1, 30) as $i) {
            $this->file($globex, "Quarterly report $i.pdf");
        }
        $this->file($this->acme, 'Quarterly report Acme.pdf');

        $this->actingAs($this->owner);
        $items = Livewire::test(FileSearch::class)->set('query', 'quarterly report')->instance()->items;

        $this->assertSame(['Quarterly report Acme.pdf'], $items->pluck('label')->all());
    }

    public function test_a_rename_is_found_at_once_and_a_folder_rename_carries_to_what_is_inside(): void
    {
        $folder = $this->folder($this->acme, 'Drafts');
        $file = $this->file($this->acme, 'Lease.pdf', $folder);

        $file->objectable->update(['name' => 'Signed lease.pdf']);
        $folder->objectable->update(['name' => 'Legal']);

        $this->assertTrue(Obj::matching('signed')->whereKey($file->id)->exists());
        $this->assertFalse(Obj::matching('lease.pdf')->where('search_text', 'like', '%drafts%')->exists());
        $this->assertTrue(Obj::matching('legal signed')->whereKey($file->id)->exists(), 'the path holds the new folder name');
    }

    public function test_nothing_is_filed_into_a_folder_in_the_trash(): void
    {
        $folder = $this->folder($this->acme, 'Old');
        $component = $this->browser($folder);
        $folder->trash($this->owner->id);

        $component->set('upload', UploadedFile::fake()->create('late.pdf', 10, 'application/pdf'))->assertHasErrors('upload');
        $component->set('newFolderState.name', 'Inside')->call('createFolder')->assertHasErrors('upload');

        $this->assertSame(0, Obj::where('parent_id', $folder->id)->count());
        $this->assertSame([], Storage::disk('local')->files('files'));
    }

    public function test_only_a_live_folder_opens_as_the_workspace(): void
    {
        $this->actingAs($this->owner);
        $file = $this->file($this->acme, 'Memo.txt');
        $folder = $this->folder($this->acme, 'Archive');
        $inner = $this->folder($this->acme, 'Inner', $folder);
        $folder->trash($this->owner->id);

        $this->get(route('files', ['uuid' => (string) $file->uuid]))->assertNotFound();
        $this->get(route('files', ['uuid' => (string) $folder->uuid]))->assertNotFound();
        $this->get(route('files', ['uuid' => (string) $inner->uuid]))->assertNotFound();
        $this->get(route('files'))->assertOk();
    }

    public function test_a_file_inside_a_trashed_folder_counts_as_trashed(): void
    {
        $folder = $this->folder($this->acme, 'Box');
        $file = $this->file($this->acme, 'Inside.txt', $folder);
        $folder->trash($this->owner->id);

        $this->assertTrue($file->objectable->isTrashed());
    }

    public function test_missing_content_answers_gone_not_a_server_error(): void
    {
        $file = $this->file($this->acme, 'Lost.txt');
        Storage::disk('local')->delete($file->objectable->path);

        $this->actingAs($this->owner)->get(route('files.download', $file->objectable))->assertStatus(410);
    }

    public function test_deleting_a_file_removes_its_pins_and_its_content_only_once_the_deletion_commits(): void
    {
        $obj = $this->file($this->acme, 'Pinned.txt');
        $file = $obj->objectable;
        FilePin::create(['user_id' => $this->owner->id, 'file_id' => $file->id]);

        try {
            DB::transaction(function () use ($obj) {
                $obj->delete();

                throw new \RuntimeException('fails part-way');
            });
        } catch (\RuntimeException) {
        }

        $this->assertTrue(Storage::disk('local')->exists($file->path), 'a rolled-back deletion keeps the content');

        Obj::withoutGlobalScopes()->find($obj->id)->delete();

        $this->assertFalse(Storage::disk('local')->exists($file->path));
        $this->assertSame(0, FilePin::where('file_id', $file->id)->count());
    }

    public function test_a_refused_version_leaves_no_content_behind_and_the_baseline_is_written_once(): void
    {
        $file = $this->file($this->acme, 'Plan.txt')->objectable;
        $versions = app(FileVersions::class);
        $versions->ensureBaseline($file);
        $versions->ensureBaseline($file);
        $this->assertSame(1, FileVersion::where('file_id', $file->id)->count());

        $before = count(Storage::disk('local')->files('files'));

        try {
            $versions->replace($file, 'new content', 'text/plain', $this->owner->id, null, expectedBase: 7);
            $this->fail('a stale base must be refused');
        } catch (VersionConflictException) {
        }

        $this->assertCount($before, Storage::disk('local')->files('files'));
    }

    public function test_operator_approval_moves_items_to_the_trash_and_counts_once(): void
    {
        $doomed = $this->file($this->acme, 'Doomed.txt');
        $request = BulkDeletionRequest::create(['team_id' => $this->acme->id, 'requested_by' => $this->owner->id, 'object_ids' => [$doomed->id]]);
        $operator = User::factory()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();

        $this->actingAs($operator)->post(route('operator.deletion-requests.bulk.approve', $request))->assertSessionHasNoErrors();
        $this->actingAs($operator)->post(route('operator.deletion-requests.bulk.approve', $request))->assertSessionHasErrors('status');

        $this->assertNotNull(Obj::withoutGlobalScopes()->find($doomed->id)->trashed_at);
        $this->assertTrue(Storage::disk('local')->exists($doomed->objectable()->withoutGlobalScopes()->first()->path), 'held 30 days, not destroyed');
    }

    public function test_a_live_share_can_be_renewed_when_the_file_is_at_its_share_limit(): void
    {
        $file = $this->file($this->acme, 'Popular.txt')->objectable;
        $service = app(FileShareService::class);
        foreach (range(1, FileShare::MAX_ACTIVE_PER_FILE) as $i) {
            $service->share($this->owner, $file, "person$i@partner.test", 7);
        }

        $renewed = $service->share($this->owner, $file, 'person1@partner.test', 30);

        $this->assertTrue($renewed->expires_at->greaterThan(now()->addDays(29)));
    }

    public function test_a_share_request_is_decided_once(): void
    {
        $member = User::factory()->withPersonalTeam()->create();
        $this->acme->users()->attach($member, ['role' => 'editor']);
        $file = $this->file($this->acme, 'Ask.txt')->objectable;
        $service = app(FileShareService::class);
        $share = $service->requestShare($member, $file, 'guest@partner.test', 7);
        $stale = FileShare::find($share->id);

        $service->approve($this->owner, $share);

        $this->expectException(HttpException::class);
        $stale->status = 'pending'; // the other request still holds the old state
        $service->decline($this->owner, $stale);
    }

    public function test_the_platform_list_skips_trashed_files_in_the_query(): void
    {
        $sign = function (string $path, array $payload) {
            $body = json_encode($payload);
            $at = time();

            return $this->call('POST', $path, [], [], [], [
                'HTTP_X-DOT-PLATFORM' => 'docs',
                'HTTP_X-DOT-TIMESTAMP' => (string) $at,
                'HTTP_X-DOT-SIGNATURE' => 'sha256='.hash_hmac('sha256', $at.'.'.$body, 'secret-docs'),
                'CONTENT_TYPE' => 'application/json',
            ], $body);
        };

        $uuids = [];
        foreach (['A', 'B', 'C'] as $n) {
            $uuids[$n] = $sign('/api/platform/files/store', ['team_uuid' => $this->acme->infodot_team_uuid, 'name' => "$n.pdf", 'content_b64' => base64_encode("%PDF-1.4 $n")])->json('uuid');
        }
        $sign('/api/platform/files/trash', ['team_uuid' => $this->acme->infodot_team_uuid, 'uuid' => $uuids['A']])->assertOk();
        $sign('/api/platform/files/trash', ['team_uuid' => $this->acme->infodot_team_uuid, 'uuid' => $uuids['B']])->assertOk();

        $page = $sign('/api/platform/files/list', ['team_uuid' => $this->acme->infodot_team_uuid])->assertOk()->json();

        $this->assertSame([$uuids['C']], array_column($page['files'], 'uuid'));
        $this->assertSame(File::withoutGlobalScopes()->where('uuid', $uuids['C'])->value('id'), $page['next_after_id']);
    }

    public function test_the_migration_backfills_the_search_text_from_the_tree(): void
    {
        $folder = $this->folder($this->acme, 'Finance');
        $file = $this->file($this->acme, 'Budget.xlsx', $folder);
        DB::table('objects')->update(['search_text' => null]);

        (require database_path('migrations/2026_11_28_000001_add_search_text_to_objects.php'))->up();

        $this->assertSame('acme / finance / budget.xlsx', DB::table('objects')->where('id', $file->id)->value('search_text'));
    }
}
