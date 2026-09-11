<?php

namespace Tests\Feature;

use App\Http\Livewire\FileBrowser;
use App\Models\Document;
use App\Models\Obj;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\UsesTheSharedDocumentsTable;
use Tests\TestCase;

/**
 * A Dot.Doc document standing in the Dot.Files tree.
 *
 * The point of the `document` morph alias is that one `objects` row means
 * the same thing in both products. These tests hold Dot.Files to its half of
 * that: it resolves the alias, renders the row, links back - and touches
 * nothing in `documents` beyond four columns.
 */
class DocumentNodeTest extends TestCase
{
    use RefreshDatabase, UsesTheSharedDocumentsTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSharedDocumentsTable();
    }

    public function test_the_document_alias_is_registered_in_the_morph_map(): void
    {
        $this->assertSame(Document::class, Relation::getMorphedModel('document'));
    }

    public function test_a_document_node_renders_as_a_row_with_a_way_back_to_dot_doc(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->firstOrFail();
        $this->fileDocumentInTree($owner->currentTeam, $root, 'Quarterly Report');

        Livewire::actingAs($owner)
            ->test(FileBrowser::class, ['object' => $root->fresh(), 'ancestors' => collect()])
            ->assertSee('Quarterly Report')
            ->assertSee('Open in Dot.Doc')
            ->assertDontSee('This folder is empty');
    }

    public function test_opening_a_document_hands_the_person_to_dot_doc_signed_in(): void
    {
        config(['ecosystem.platforms.docs.url' => 'https://docs.example.test']);

        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->firstOrFail();
        $document = $this->fileDocumentInTree($owner->currentTeam, $root, 'Quarterly Report');

        Livewire::actingAs($owner)
            ->test(FileBrowser::class, ['object' => $root->fresh(), 'ancestors' => collect()])
            ->call('openInDocs', $document->node->id)
            ->assertRedirect();

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $owner->id,
            'name' => 'ecosystem-handoff',
        ]);
    }

    public function test_the_new_document_action_carries_the_open_folder_to_dot_doc(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->firstOrFail();

        Livewire::actingAs($owner)
            ->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect()])
            ->call('newDocument')
            ->assertRedirect();
    }

    public function test_a_handoff_is_refused_to_someone_outside_the_nodes_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->firstOrFail();
        $document = $this->fileDocumentInTree($owner->currentTeam, $root, 'Quarterly Report');

        // No current team at all, which is the case HasTeamScope fails OPEN
        // on: Obj::find() will hand this person the row. Membership of the
        // node's own team is what actually decides.
        $stranger = User::factory()->create(['current_team_id' => null]);

        Livewire::actingAs($stranger)
            ->test(FileBrowser::class, ['object' => $root->fresh(), 'ancestors' => collect()])
            ->call('openInDocs', $document->node->id)
            ->assertNoRedirect();

        Livewire::actingAs($stranger)
            ->test(FileBrowser::class, ['object' => $root->fresh(), 'ancestors' => collect()])
            ->call('newDocument')
            ->assertStatus(403);

        $this->assertSame(0, $stranger->tokens()->count());
    }

    public function test_reading_a_document_never_selects_its_body(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->firstOrFail();
        $document = $this->fileDocumentInTree($owner->currentTeam, $root, 'Quarterly Report', 'board pay review');

        $this->assertSame(
            Document::SAFE_COLUMNS,
            array_keys(Document::findOrFail($document->id)->getAttributes()),
        );

        $throughTheTree = Obj::with('objectable')->findOrFail($document->node->id)->objectable;

        $this->assertInstanceOf(Document::class, $throughTheTree);
        $this->assertArrayNotHasKey('content', $throughTheTree->getAttributes());
        $this->assertSame('Quarterly Report', $throughTheTree->title);
    }

    public function test_dot_files_refuses_to_write_a_document(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->firstOrFail();
        $document = $this->fileDocumentInTree($owner->currentTeam, $root, 'Quarterly Report');

        // Mass assignment is refused by an empty $fillable...
        $this->assertThrows(
            fn () => Document::findOrFail($document->id)->update(['title' => 'Rewritten']),
            MassAssignmentException::class,
        );

        // ...and so is every other way in.
        $this->assertThrows(
            fn () => Document::findOrFail($document->id)->forceFill(['title' => 'Rewritten'])->save(),
            LogicException::class,
        );

        $this->assertThrows(
            fn () => Document::findOrFail($document->id)->delete(),
            LogicException::class,
        );

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'title' => 'Quarterly Report']);
    }

    public function test_deleting_a_document_node_through_the_tree_leaves_the_document_alone(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->firstOrFail();
        $document = $this->fileDocumentInTree($owner->currentTeam, $root, 'Quarterly Report');
        $node = $document->node;

        // Obj's own deleting hook, not FilesService: the browser's delete
        // confirmation still goes straight through the model, and it must not
        // reach across into Dot.Doc's table either.
        $node->delete();

        $this->assertNull(Obj::find($node->id));
        $this->assertSame(1, DB::table('documents')->where('id', $document->id)->count());
    }

    public function test_the_delete_dialog_says_the_document_survives_in_dot_doc(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->firstOrFail();
        $document = $this->fileDocumentInTree($owner->currentTeam, $root, 'Quarterly Report');

        Livewire::actingAs($owner)
            ->test(FileBrowser::class, ['object' => $root->fresh(), 'ancestors' => collect()])
            ->set('confirmingObjectDeletion', $document->node->id)
            ->assertSee('The document itself stays in Dot.Doc');
    }
}
