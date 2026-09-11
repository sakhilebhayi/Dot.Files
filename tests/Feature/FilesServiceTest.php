<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Obj;
use App\Models\Team;
use App\Models\User;
use App\Services\FilesService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\UsesTheSharedDocumentsTable;
use Tests\TestCase;

/**
 * The seam both products write the shared tree through — the Dot.Files half.
 *
 * The load-bearing rule under test is the one the Dot.Doc side found and
 * fixed: every method takes its team from the object it was handed, never
 * from the session's currentTeam. Several tests below deliberately act as a
 * user whose currentTeam is the WRONG team, because that is the only way to
 * tell a service that resolves team correctly from one that happens to work
 * while the session agrees with it.
 */
class FilesServiceTest extends TestCase
{
    use RefreshDatabase, UsesTheSharedDocumentsTable;

    private FilesService $files;

    protected function setUp(): void
    {
        parent::setUp();

        // A real local disk under storage/framework/testing, not a null
        // driver: FileController::view() proves containment with realpath(),
        // so the bytes have to exist somewhere on disk.
        Storage::fake('files');

        $this->files = new FilesService;
    }

    private function root(Team $team): Obj
    {
        return Obj::withoutGlobalScope('team')
            ->where('team_id', $team->id)
            ->whereNull('parent_id')
            ->firstOrFail();
    }

    public function test_root_returns_the_team_root_the_team_was_created_with(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $this->assertTrue($this->files->root($team)->is($this->root($team)));
    }

    public function test_root_is_idempotent_and_creates_one_when_a_team_has_none(): void
    {
        $team = Team::factory()->create();
        Obj::withoutGlobalScope('team')->where('team_id', $team->id)->delete();

        $first = $this->files->root($team);
        $second = $this->files->root($team);

        $this->assertTrue($first->is($second));
        $this->assertSame('folder', $first->kind());
        $this->assertNull($first->parent_id);
        $this->assertSame(1, Obj::withoutGlobalScope('team')
            ->where('team_id', $team->id)
            ->whereNull('parent_id')
            ->count());
    }

    public function test_a_second_root_for_one_team_is_refused_by_the_database(): void
    {
        $team = Team::factory()->create();
        $folder = Folder::create(['name' => 'Impostor', 'team_id' => $team->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        $obj = new Obj(['parent_id' => null]);
        $obj->team_id = $team->id;
        $obj->objectable()->associate($folder);
        $obj->save();
    }

    public function test_create_folder_uses_the_parents_team_not_the_session_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $guest = User::factory()->withPersonalTeam()->create();
        $owner->currentTeam->users()->attach($guest, ['role' => 'editor']);

        // The session says one team; the parent node says another. The node wins.
        $this->actingAs($guest);
        $this->assertTrue($guest->currentTeam->isNot($owner->currentTeam));

        $folder = $this->files->createFolder($this->root($owner->currentTeam), 'Contracts', $guest);

        $this->assertSame($owner->currentTeam->id, $folder->team_id);
        $this->assertSame($owner->currentTeam->id, $folder->objectable->team_id);
    }

    public function test_children_lists_the_parents_team_rows_even_when_the_session_is_elsewhere(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $guest = User::factory()->withPersonalTeam()->create();
        $owner->currentTeam->users()->attach($guest, ['role' => 'editor']);

        $root = $this->root($owner->currentTeam);
        $this->files->createFolder($root, 'Contracts', $owner);

        $this->actingAs($guest);

        $this->assertSame(['Contracts'], $this->files->children($root)->map->name()->all());
    }

    public function test_children_orders_folders_then_documents_then_files_by_name(): void
    {
        $this->createSharedDocumentsTable();

        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $root = $this->root($team);

        $this->files->createFolder($root, 'zulu folder', $owner);
        $this->files->createFolder($root, 'Alpha folder', $owner);
        $this->fileDocumentInTree($team, $root, 'Some document');
        $this->files->createFile($root, UploadedFile::fake()->create('notes.txt', 1), $owner);

        $this->assertSame(
            ['Alpha folder', 'zulu folder', 'Some document', 'notes.txt'],
            $this->files->children($root)->map->name()->all(),
        );
    }

    public function test_children_skips_a_node_whose_objectable_has_gone(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = $this->root($owner->currentTeam);

        $folder = $this->files->createFolder($root, 'Contracts', $owner);
        Folder::withoutGlobalScope('team')->whereKey($folder->objectable_id)->delete();

        $this->assertCount(0, $this->files->children($root->fresh()));
    }

    public function test_create_folder_is_refused_for_someone_outside_the_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $this->expectException(AuthorizationException::class);

        $this->files->createFolder($this->root($owner->currentTeam), 'Contracts', $outsider);
    }

    public function test_a_repeated_folder_name_is_suffixed_rather_than_duplicated(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = $this->root($owner->currentTeam);

        $this->assertSame('Reports', $this->files->createFolder($root, 'Reports', $owner)->name());
        $this->assertSame('Reports (2)', $this->files->createFolder($root, 'Reports', $owner)->name());
        // The suffix is appended to the name as TYPED; only the comparison
        // against siblings is case-insensitive.
        $this->assertSame('reports (3)', $this->files->createFolder($root, 'reports', $owner)->name());
    }

    public function test_a_name_carrying_a_path_separator_is_refused(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $this->expectException(ValidationException::class);

        $this->files->createFolder($this->root($owner->currentTeam), 'etc/passwd', $owner);
    }

    public function test_safe_name_scrubs_what_clean_name_would_have_refused(): void
    {
        $this->assertSame('etc passwd', $this->files->safeName('etc/passwd'));
        $this->assertSame('a b', $this->files->safeName("a\x00\x1F\\b"));
        $this->assertSame('Folder', $this->files->safeName('///'));
        $this->assertSame('Upload', $this->files->safeName('', 'Upload'));
        $this->assertSame(255, mb_strlen($this->files->safeName(str_repeat('x', 400))));
    }

    public function test_a_suffixed_name_still_fits_the_column(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = $this->root($owner->currentTeam);
        $long = str_repeat('x', 255);

        $this->assertSame(255, mb_strlen($this->files->createFolder($root, $long, $owner)->name()));

        $second = $this->files->createFolder($root, $long, $owner)->name();

        $this->assertSame(255, mb_strlen($second));
        $this->assertStringEndsWith(' (2)', $second);
    }

    public function test_rename_dedupes_against_siblings_and_takes_its_team_from_the_node(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $guest = User::factory()->withPersonalTeam()->create();
        $owner->currentTeam->users()->attach($guest, ['role' => 'editor']);

        $root = $this->root($owner->currentTeam);
        $this->files->createFolder($root, 'Reports', $owner);
        $notes = $this->files->createFolder($root, 'Notes', $owner);

        $this->actingAs($guest);

        $this->assertSame('Reports (2)', $this->files->renameObject($notes, 'Reports', $guest)->name());
    }

    public function test_a_document_is_renamed_in_dot_doc_not_here(): void
    {
        $this->createSharedDocumentsTable();

        $owner = User::factory()->withPersonalTeam()->create();
        $root = $this->root($owner->currentTeam);
        $document = $this->fileDocumentInTree($owner->currentTeam, $root, 'Quarterly Report');

        $this->expectException(ValidationException::class);

        try {
            $this->files->renameObject($document->node, 'Renamed', $owner);
        } finally {
            $this->assertDatabaseHas('documents', ['id' => $document->id, 'title' => 'Quarterly Report']);
        }
    }

    public function test_move_files_a_node_under_another_folder(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = $this->root($owner->currentTeam);

        $archive = $this->files->createFolder($root, 'Archive', $owner);
        $notes = $this->files->createFolder($root, 'Notes', $owner);

        $this->assertSame($archive->id, $this->files->moveObject($notes, $archive, $owner)->parent_id);
    }

    public function test_the_root_cannot_be_moved(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = $this->root($owner->currentTeam);
        $archive = $this->files->createFolder($root, 'Archive', $owner);

        $this->expectException(ValidationException::class);

        $this->files->moveObject($root, $archive, $owner);
    }

    public function test_a_folder_cannot_be_filed_inside_itself(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = $this->root($owner->currentTeam);

        $outer = $this->files->createFolder($root, 'Outer', $owner);
        $inner = $this->files->createFolder($outer, 'Inner', $owner);

        $this->expectException(ValidationException::class);

        $this->files->moveObject($outer, $inner, $owner);
    }

    public function test_a_node_cannot_be_moved_into_another_teams_folder(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        $other->currentTeam->users()->attach($owner, ['role' => 'editor']);

        $notes = $this->files->createFolder($this->root($owner->currentTeam), 'Notes', $owner);

        $this->expectException(ValidationException::class);

        $this->files->moveObject($notes, $this->root($other->currentTeam), $owner);
    }

    public function test_deleting_a_file_takes_its_blob_with_it(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = $this->root($owner->currentTeam);

        $node = $this->files->createFile($root, UploadedFile::fake()->create('notes.txt', 1), $owner);
        $path = $node->objectable->path;

        $this->assertTrue(Storage::disk('files')->exists($path));

        $this->files->deleteObject($node, $owner);

        $this->assertFalse(Storage::disk('files')->exists($path));
        $this->assertNull(Obj::withoutGlobalScope('team')->find($node->id));
        $this->assertNull(File::withoutGlobalScope('team')->find($node->objectable_id));
    }

    public function test_deleting_a_document_node_leaves_the_document_to_dot_doc(): void
    {
        $this->createSharedDocumentsTable();

        $owner = User::factory()->withPersonalTeam()->create();
        $root = $this->root($owner->currentTeam);
        $document = $this->fileDocumentInTree($owner->currentTeam, $root, 'Quarterly Report');
        $node = $document->node;

        $this->files->deleteObject($node, $owner);

        $this->assertNull(Obj::withoutGlobalScope('team')->find($node->id));
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'title' => 'Quarterly Report']);
    }

    public function test_a_folder_with_anything_in_it_refuses_to_be_deleted(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = $this->root($owner->currentTeam);

        $outer = $this->files->createFolder($root, 'Outer', $owner);
        $inner = $this->files->createFolder($outer, 'Inner', $owner);

        try {
            $this->files->deleteObject($outer, $owner);
            $this->fail('A non-empty folder should not be deletable.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Empty the folder first', $e->getMessage());
        }

        $this->assertNotNull(Obj::withoutGlobalScope('team')->find($inner->id));
    }

    public function test_the_root_cannot_be_deleted(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $this->expectException(ValidationException::class);

        $this->files->deleteObject($this->root($owner->currentTeam), $owner);
    }

    public function test_delete_is_refused_for_someone_outside_the_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $notes = $this->files->createFolder($this->root($owner->currentTeam), 'Notes', $owner);

        $this->expectException(AuthorizationException::class);

        $this->files->deleteObject($notes, $outsider);
    }

    public function test_an_upload_is_stored_under_a_composed_path_and_keeps_its_type_and_owner(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = $this->root($owner->currentTeam);

        $node = $this->files->createFile(
            $root,
            UploadedFile::fake()->image('holiday snap.png'),
            $owner,
        );

        $file = $node->objectable;

        // The client name survives as the LABEL and decides nothing about
        // where the bytes land - only the extension reaches the path, and
        // the rest of it is randomly generated here.
        $this->assertSame('holiday snap.png', $file->name);
        $this->assertSame($owner->id, $file->owner_id);
        $this->assertSame($owner->currentTeam->id, $file->team_id);
        $this->assertSame('image/png', $file->mime_type);
        $this->assertMatchesRegularExpression(
            '#^files/'.$owner->currentTeam->id.'/[A-Za-z0-9]{40}\.png$#',
            $file->path,
        );
    }
}
