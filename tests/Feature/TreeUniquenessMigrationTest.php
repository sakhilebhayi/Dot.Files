<?php

namespace Tests\Feature;

use App\Models\Folder;
use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The de-duplication that has to happen BEFORE the tree's unique indexes.
 *
 * `objects` carries live data, and `CREATE UNIQUE INDEX` over duplicates does
 * not warn - it fails, half way through a deployment. The migration therefore
 * repairs first, which is only worth having if the repair is the right one,
 * so each case below seeds the exact duplicate the constraint would trip over
 * and asserts what survived.
 *
 * Each test rolls the indexes back off the table first (postgres has
 * transactional DDL, so RefreshDatabase undoes all of it), seeds, and runs
 * `up()` again.
 */
class TreeUniquenessMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path(
            'migrations/2026_09_11_000001_guard_domain_tables_and_add_uuid_unique.php'
        );

        $this->migration->down();
    }

    /** A tree row inserted the way a pre-migration database already holds it. */
    private function insertObject(array $attributes): int
    {
        return DB::table('objects')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'objectable_type' => 'folder',
            'parent_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function folderId(Team $team, string $name): int
    {
        return Folder::create(['name' => $name, 'team_id' => $team->id])->id;
    }

    private function rootId(Team $team): int
    {
        return (int) DB::table('objects')
            ->where('team_id', $team->id)
            ->whereNull('parent_id')
            ->where('objectable_type', 'folder')
            ->orderBy('id')
            ->value('id');
    }

    public function test_the_indexes_are_there_after_a_normal_migration(): void
    {
        $this->migration->up();

        $this->assertTrue(Schema::hasIndex('objects', 'objects_uuid_unique'));
        $this->assertTrue(Schema::hasIndex('objects', 'objects_objectable_unique'));
        $this->assertTrue(Schema::hasIndex('objects', 'objects_team_root_unique'));
    }

    public function test_up_is_idempotent(): void
    {
        $this->migration->up();
        $this->migration->up();

        $this->assertTrue(Schema::hasIndex('objects', 'objects_objectable_unique'));
    }

    public function test_a_duplicate_uuid_is_re_minted_on_the_newer_row_only(): void
    {
        $team = Team::factory()->create();
        $root = $this->rootId($team);
        $shared = (string) DB::table('objects')->where('id', $root)->value('uuid');

        $newer = $this->insertObject([
            'uuid' => $shared,
            'objectable_id' => $this->folderId($team, 'Contracts'),
            'parent_id' => $root,
            'team_id' => $team->id,
        ]);

        Log::spy();

        $this->migration->up();

        // The older row keeps the address every existing link points at.
        $this->assertSame($shared, DB::table('objects')->where('id', $root)->value('uuid'));
        $this->assertNotSame($shared, DB::table('objects')->where('id', $newer)->value('uuid'));
        $this->assertSame(2, DB::table('objects')->whereIn('id', [$root, $newer])->count());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'Re-minted duplicate object uuid'))
            ->once();
    }

    public function test_two_nodes_pointing_at_one_folder_are_merged_onto_the_older(): void
    {
        $team = Team::factory()->create();
        $root = $this->rootId($team);
        $folder = $this->folderId($team, 'Contracts');

        $survivor = $this->insertObject([
            'objectable_id' => $folder,
            'parent_id' => $root,
            'team_id' => $team->id,
        ]);

        $loser = $this->insertObject([
            'objectable_id' => $folder,
            'parent_id' => $root,
            'team_id' => $team->id,
        ]);

        $orphanToBe = $this->insertObject([
            'objectable_id' => $this->folderId($team, 'Invoices'),
            'parent_id' => $loser,
            'team_id' => $team->id,
        ]);

        Log::spy();

        $this->migration->up();

        $this->assertSame(1, DB::table('objects')->where('id', $survivor)->count());
        $this->assertSame(0, DB::table('objects')->where('id', $loser)->count());

        // Nothing filed under the removed node is orphaned...
        $this->assertSame($survivor, (int) DB::table('objects')->where('id', $orphanToBe)->value('parent_id'));

        // ...and the folder both rows were pointing at is untouched.
        $this->assertSame(1, DB::table('folders')->where('id', $folder)->count());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'Merged duplicate object rows'))
            ->once();
    }

    public function test_a_second_team_root_is_demoted_rather_than_deleted(): void
    {
        $team = Team::factory()->create();
        $root = $this->rootId($team);

        $extraRoot = $this->insertObject([
            'objectable_id' => $this->folderId($team, 'Second root'),
            'parent_id' => null,
            'team_id' => $team->id,
        ]);

        Log::spy();

        $this->migration->up();

        $this->assertSame($root, (int) DB::table('objects')->where('id', $extraRoot)->value('parent_id'));
        $this->assertSame(1, DB::table('objects')
            ->where('team_id', $team->id)
            ->whereNull('parent_id')
            ->where('objectable_type', 'folder')
            ->count());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'Demoted extra team root folders'))
            ->once();
    }

    public function test_a_top_level_file_is_not_mistaken_for_a_second_root(): void
    {
        $team = Team::factory()->create();
        $this->rootId($team);

        $file = DB::table('files')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'loose.txt',
            'size' => 3,
            'path' => 'files/loose.txt',
            'team_id' => $team->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $node = $this->insertObject([
            'objectable_type' => 'file',
            'objectable_id' => $file,
            'parent_id' => null,
            'team_id' => $team->id,
        ]);

        $this->migration->up();

        // The root constraint is folder-only, so a parentless file keeps its
        // place instead of being re-parented under the root.
        $this->assertNull(DB::table('objects')->where('id', $node)->value('parent_id'));
    }

    public function test_all_three_duplicates_at_once_still_leave_a_migratable_table(): void
    {
        $team = Team::factory()->create();
        $root = $this->rootId($team);
        $shared = (string) DB::table('objects')->where('id', $root)->value('uuid');
        $folder = $this->folderId($team, 'Contracts');

        $this->insertObject([
            'uuid' => $shared,
            'objectable_id' => $folder,
            'parent_id' => $root,
            'team_id' => $team->id,
        ]);
        $this->insertObject([
            'objectable_id' => $folder,
            'parent_id' => $root,
            'team_id' => $team->id,
        ]);
        $this->insertObject([
            'objectable_id' => $this->folderId($team, 'Second root'),
            'parent_id' => null,
            'team_id' => $team->id,
        ]);

        $this->migration->up();

        $this->assertTrue(Schema::hasIndex('objects', 'objects_uuid_unique'));
        $this->assertTrue(Schema::hasIndex('objects', 'objects_objectable_unique'));
        $this->assertTrue(Schema::hasIndex('objects', 'objects_team_root_unique'));

        $this->assertSame(
            0,
            DB::table('objects')->select('uuid')->groupBy('uuid')->havingRaw('count(*) > 1')->get()->count(),
        );
    }
}
