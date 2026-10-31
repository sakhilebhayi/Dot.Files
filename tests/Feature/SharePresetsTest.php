<?php

namespace Tests\Feature;

use App\Http\Livewire\FileBrowser;
use App\Models\File;
use App\Models\FileShare;
use App\Models\Obj;
use App\Models\Team;
use App\Models\User;
use App\Services\FileShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-28 §23–24: permission presets on shares (view only / view and download), enforced where the
 * copy is handed over, and sharing a whole selection from the workspace in one go.
 */
class SharePresetsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $editor;

    private User $recipient;

    private Team $acme;

    private File $file;

    private FileShareService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection']);
        $this->service = app(FileShareService::class);

        $this->owner = User::factory()->withPersonalTeam()->create(['email' => 'owner@acme.test']);
        $this->acme = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Acme', 'personal_team' => false]);
        $this->owner->switchTeam($this->acme);

        $this->editor = User::factory()->withPersonalTeam()->create(['email' => 'editor@acme.test']);
        $this->acme->users()->attach($this->editor, ['role' => 'editor']);
        $this->editor->switchTeam($this->acme);

        $this->recipient = User::factory()->withPersonalTeam()->create(['email' => 'pat@rival.test', 'email_verified_at' => Carbon::now()]);

        $this->file = $this->makeFile('Contract.pdf');
    }

    private function makeFile(string $name): File
    {
        Storage::disk('local')->put($path = 'files/'.Str::random(8), 'contents of '.$name);
        $file = $this->acme->files()->create(['name' => $name, 'size' => 12, 'path' => $path]);

        $obj = $this->acme->objects()->make(['parent_id' => $this->root()->id]);
        $obj->objectable()->associate($file);
        $obj->save();

        return $file;
    }

    private function root(): Obj
    {
        return $this->acme->objects()->withoutGlobalScope('team')->whereNull('parent_id')->firstOrFail();
    }

    public function test_a_view_only_share_shows_the_page_but_never_hands_over_a_copy(): void
    {
        $share = $this->service->share($this->owner, $this->file, 'pat@rival.test', 30, 'view');

        $this->assertSame('view', $share->permission);

        // The recipient opens the share page and is told the terms — but the copy is refused.
        $this->actingAs($this->recipient)->get('/o/'.$this->file->uuid)->assertOk()->assertSee('View only');
        $this->actingAs($this->recipient)->get('/shared/'.$share->id.'/download')->assertForbidden();

        // The sharer widens it: sharing again with the download preset is the renewal.
        $this->service->share($this->owner, $this->file, 'pat@rival.test', 30, 'download');

        $this->assertSame('download', $share->refresh()->permission);
        $this->actingAs($this->recipient)->get('/shared/'.$share->id.'/download')
            ->assertOk()->assertDownload($this->file->name);
    }

    public function test_a_member_request_carries_its_preset_through_the_approval(): void
    {
        $this->actingAs($this->editor)
            ->post('/files/'.$this->file->id.'/share-request', ['email' => 'pat@rival.test', 'days' => 30, 'permission' => 'view'])
            ->assertRedirect(route('approvals'));

        $share = FileShare::where('file_id', $this->file->id)->firstOrFail();
        $this->assertSame('pending', $share->status);
        $this->assertSame('view', $share->permission);

        $this->actingAs($this->owner)->post('/approvals/'.$share->id.'/approve')->assertRedirect(route('approvals'));

        // Approval opens exactly what was asked for: a view, never a copy.
        $this->assertSame('view', $share->refresh()->permission);
        $this->assertTrue($share->isActive());
        $this->actingAs($this->recipient)->get('/shared/'.$share->id.'/download')->assertForbidden();
    }

    public function test_an_owner_shares_a_whole_selection_in_one_go_and_folders_fall_out(): void
    {
        $second = $this->makeFile('Rates.pdf');

        $folderObj = $this->acme->objects()->make(['parent_id' => $this->root()->id]);
        $folderObj->objectable()->associate($this->acme->folders()->create(['name' => 'Board']));
        $folderObj->save();

        Livewire::actingAs($this->owner)->test(FileBrowser::class, ['object' => $this->root(), 'ancestors' => collect()])
            ->set('selectedObjects', [
                (string) $this->file->obj()->withoutGlobalScope('team')->first()->id,
                (string) $second->obj()->withoutGlobalScope('team')->first()->id,
                (string) $folderObj->id,
            ])
            ->set('shareEmail', 'pat@rival.test')
            ->set('shareDays', 30)
            ->set('sharePermission', 'view')
            ->call('shareSelected')
            ->assertHasNoErrors()
            ->assertSet('selectedObjects', [])
            ->assertSet('sharingSelection', false);

        // One active share per record, on the chosen terms; the folder shared nothing.
        $shares = FileShare::active()->get();
        $this->assertCount(2, $shares);
        $this->assertEqualsCanonicalizing([$this->file->id, $second->id], $shares->pluck('file_id')->all());
        $this->assertSame(['view', 'view'], $shares->pluck('permission')->all());
    }

    public function test_a_member_selection_becomes_pending_requests_not_access(): void
    {
        $second = $this->makeFile('Rates.pdf');

        Livewire::actingAs($this->editor)->test(FileBrowser::class, ['object' => $this->root(), 'ancestors' => collect()])
            ->set('selectedObjects', [
                (string) $this->file->obj()->withoutGlobalScope('team')->first()->id,
                (string) $second->obj()->withoutGlobalScope('team')->first()->id,
            ])
            ->set('shareEmail', 'pat@rival.test')
            ->set('shareDays', 7)
            ->set('sharePermission', 'download')
            ->call('shareSelected')
            ->assertHasNoErrors();

        $this->assertSame(2, FileShare::pending()->count());
        $this->assertSame(0, FileShare::active()->count());

        // Pending is not access, on either record.
        $this->actingAs($this->recipient)->get('/o/'.$this->file->uuid)->assertForbidden();
    }
}
