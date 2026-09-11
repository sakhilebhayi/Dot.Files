<?php

namespace Tests\Feature;

use App\Http\Livewire\FileBrowser;
use App\Models\Folder;
use App\Models\Obj;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\UsesTheSharedDocumentsTable;
use Tests\TestCase;

/**
 * The browser's two write paths now go through App\Services\FilesService
 * rather than building rows inline.
 *
 * What that buys, and what these tests hold it to: names are deduplicated and
 * sanitised on the way in, an upload records its type and its owner, and the
 * team comes from the node being written to rather than from the session.
 */
class FileBrowserUsesTheServiceTest extends TestCase
{
    use RefreshDatabase, UsesTheSharedDocumentsTable;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('files');
    }

    public function test_creating_a_folder_twice_from_the_browser_suffixes_the_second(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->firstOrFail();

        Livewire::actingAs($owner)
            ->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect()])
            ->set('newFolderState.name', 'Reports')
            ->call('createFolder')
            ->set('newFolderState.name', 'Reports')
            ->call('createFolder');

        $this->assertEqualsCanonicalizing(
            [$owner->currentTeam->name, 'Reports', 'Reports (2)'],
            Folder::pluck('name')->all(),
        );
    }

    public function test_a_folder_name_carrying_a_path_separator_is_refused_with_a_message(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->firstOrFail();

        Livewire::actingAs($owner)
            ->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect()])
            ->set('newFolderState.name', 'etc/passwd')
            ->call('createFolder')
            ->assertHasErrors('name');

        $this->assertSame(1, Folder::count());
    }

    public function test_renaming_from_the_browser_deduplicates_against_siblings(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->firstOrFail();

        $component = Livewire::actingAs($owner)
            ->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect()])
            ->set('newFolderState.name', 'Reports')
            ->call('createFolder')
            ->set('newFolderState.name', 'Notes')
            ->call('createFolder');

        $notes = Obj::whereHas('objectable', fn ($query) => $query->where('name', 'Notes'))->firstOrFail();

        $component
            ->set('renamingObject', $notes->id)
            ->set('renamingObjectState.name', 'Reports')
            ->call('renameObject');

        $this->assertSame('Reports (2)', $notes->objectable->fresh()->name);
    }

    public function test_a_folder_is_created_under_the_open_node_not_the_session_team_root(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->firstOrFail();

        Livewire::actingAs($owner)
            ->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect()])
            ->set('newFolderState.name', 'Outer')
            ->call('createFolder');

        $outer = Obj::whereHas('objectable', fn ($query) => $query->where('name', 'Outer'))->firstOrFail();

        Livewire::actingAs($owner)
            ->test(FileBrowser::class, ['object' => $outer, 'ancestors' => collect()])
            ->set('newFolderState.name', 'Inner')
            ->call('createFolder');

        $inner = Obj::whereHas('objectable', fn ($query) => $query->where('name', 'Inner'))->firstOrFail();

        $this->assertSame($outer->id, $inner->parent_id);
        $this->assertSame($owner->currentTeam->id, $inner->team_id);
    }

    public function test_a_document_row_offers_no_rename_because_its_title_lives_in_dot_doc(): void
    {
        $this->createSharedDocumentsTable();

        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->firstOrFail();
        $this->fileDocumentInTree($owner->currentTeam, $root, 'Quarterly Report');

        Livewire::actingAs($owner)
            ->test(FileBrowser::class, ['object' => $root->fresh(), 'ancestors' => collect()])
            ->assertSee('Quarterly Report')
            ->assertDontSee('Rename');
    }
}
