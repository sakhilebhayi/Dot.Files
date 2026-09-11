<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The three uniqueness guarantees the shared tree needs from the DATABASE.
 *
 * `objects`, `files` and `folders` are no longer Dot.Files' private tables:
 * Dot.Doc reads and writes the same three (see the Dot.Doc side's
 * 2026_09_08_000001_create_shared_files_tree_tables.php and
 * 2026_09_08_000004_add_uniqueness_to_objects_table.php, and the design at
 * docs/superpowers/specs/2026-09-08-dot-doc-files-integration-design.md in
 * that repo). Whichever product migrates first against the shared `infodot`
 * database creates them; this migration only ever ADDS what is missing on
 * the tables as they already stand, so it is a no-op on a database Dot.Doc
 * built and a catch-up on the one Dot.Files built in 2020.
 *
 * 1. `objects.uuid` unique. Every link in this application addresses a node
 *    by uuid (`/files?uuid=…`), and `Obj::booted()` mints one per row in
 *    PHP - which is a check nothing enforces. Two rows sharing a uuid means
 *    a bookmark resolves to whichever row comes back first.
 * 2. `objects_objectable_unique` on `(objectable_type, objectable_id)` -
 *    one live node per thing, whichever product filed it. This is what
 *    makes Dot.Doc's `registerDocument()` safe to call concurrently.
 * 3. `objects_team_root_unique`, a PARTIAL unique index giving a team one
 *    root: unique on `team_id` WHERE `parent_id IS NULL` AND
 *    `objectable_type = 'folder'`. Only roots are unique - a team has any
 *    number of ordinary folders, and a file filed at the top level is not a
 *    root. Partial indexes exist on postgres (this application's database)
 *    and sqlite; the guard lists both, exactly as the Dot.Doc side's
 *    `supportsPartialIndexes()` does, so the two repos agree about which
 *    drivers carry the constraint rather than each carrying a different
 *    subset of it. MySQL has no partial indexes and keeps the PHP check
 *    alone.
 *
 * Each index is preceded by a defensive de-duplication, because this table
 * carries live data and `CREATE UNIQUE INDEX` over duplicates does not warn,
 * it fails - leaving the deployment half-migrated. Nothing here deletes
 * anything a person can see:
 *
 * - a duplicate uuid is RE-MINTED on the higher-id row (the node keeps its
 *   place, only its address changes, and the older link keeps working);
 * - a duplicate objectable link is a genuine data bug - two tree nodes
 *   cannot both legitimately represent one folder or file - so the higher-id
 *   node is removed after its children are re-parented onto the survivor,
 *   and the removal is logged;
 * - a second root folder is re-parented UNDER the surviving root, becoming
 *   an ordinary subfolder rather than disappearing.
 */
return new class extends Migration
{
    private const UUID_INDEX = 'objects_uuid_unique';

    private const OBJECTABLE_INDEX = 'objects_objectable_unique';

    private const ROOT_INDEX = 'objects_team_root_unique';

    public function up(): void
    {
        if (! Schema::hasTable('objects')) {
            return;
        }

        if (! Schema::hasIndex('objects', self::UUID_INDEX)) {
            $this->reMintDuplicateUuids();

            Schema::table('objects', function (Blueprint $table) {
                $table->unique('uuid', self::UUID_INDEX);
            });
        }

        if (! Schema::hasIndex('objects', self::OBJECTABLE_INDEX)) {
            $this->mergeDuplicateObjectableLinks();

            Schema::table('objects', function (Blueprint $table) {
                $table->unique(['objectable_type', 'objectable_id'], self::OBJECTABLE_INDEX);
            });
        }

        if ($this->supportsPartialIndexes()) {
            $this->demoteExtraRoots();

            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS '.self::ROOT_INDEX.' ON objects (team_id)'
                ." WHERE parent_id IS NULL AND objectable_type = 'folder'"
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('objects')) {
            return;
        }

        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS '.self::ROOT_INDEX);
        }

        foreach ([self::OBJECTABLE_INDEX, self::UUID_INDEX] as $index) {
            if (Schema::hasIndex('objects', $index)) {
                Schema::table('objects', function (Blueprint $table) use ($index) {
                    $table->dropUnique($index);
                });
            }
        }
    }

    /**
     * Give every row but the oldest of each duplicated uuid a fresh one.
     *
     * The oldest row keeps the address, so whichever link is out in the world
     * still lands where it always did; the newer rows are re-addressed, which
     * is the least a unique index can be satisfied with.
     */
    private function reMintDuplicateUuids(): void
    {
        $duplicated = DB::table('objects')
            ->select('uuid')
            ->groupBy('uuid')
            ->havingRaw('count(*) > 1')
            ->pluck('uuid');

        foreach ($duplicated as $uuid) {
            $ids = DB::table('objects')->where('uuid', $uuid)->orderBy('id')->pluck('id')->slice(1);

            foreach ($ids as $id) {
                DB::table('objects')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
            }

            Log::warning('Re-minted duplicate object uuid before adding objects_uuid_unique.', [
                'uuid' => $uuid,
                'reminted_object_ids' => $ids->values()->all(),
            ]);
        }
    }

    /**
     * Collapse two tree nodes pointing at one folder/file down to one.
     *
     * Unlike a duplicate uuid this cannot be repaired by renaming something:
     * the pair asserts that one folder is in two places at once, and only one
     * of them is where it actually is. The oldest node wins, anything filed
     * under a loser is re-parented onto the winner so no subtree is orphaned,
     * and the loser row is removed. The objectable itself is never touched -
     * it is the thing both rows were pointing at, and it survives on the
     * winner.
     */
    private function mergeDuplicateObjectableLinks(): void
    {
        $duplicated = DB::table('objects')
            ->select('objectable_type', 'objectable_id')
            ->groupBy('objectable_type', 'objectable_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicated as $pair) {
            $rows = DB::table('objects')
                ->where('objectable_type', $pair->objectable_type)
                ->where('objectable_id', $pair->objectable_id)
                ->orderBy('id')
                ->get(['id']);

            $survivor = $rows->first()->id;
            $losers = $rows->skip(1)->pluck('id')->all();

            DB::table('objects')->whereIn('parent_id', $losers)->update(['parent_id' => $survivor]);
            DB::table('objects')->whereIn('id', $losers)->delete();

            Log::warning('Merged duplicate object rows pointing at one objectable.', [
                'objectable_type' => $pair->objectable_type,
                'objectable_id' => $pair->objectable_id,
                'kept_object_id' => $survivor,
                'removed_object_ids' => $losers,
            ]);
        }
    }

    /**
     * A team with two root folders keeps the oldest; the rest are filed under
     * it as ordinary subfolders.
     *
     * Deleting one is not an option - a second root is normally the result of
     * two first page loads racing, and both halves may have real content by
     * now - and this keeps every node reachable from the one root the
     * application knows how to open.
     */
    private function demoteExtraRoots(): void
    {
        $teams = DB::table('objects')
            ->select('team_id')
            ->whereNull('parent_id')
            ->where('objectable_type', 'folder')
            ->groupBy('team_id')
            ->havingRaw('count(*) > 1')
            ->pluck('team_id');

        foreach ($teams as $teamId) {
            $roots = DB::table('objects')
                ->whereNull('parent_id')
                ->where('objectable_type', 'folder')
                ->where('team_id', $teamId)
                ->orderBy('id')
                ->pluck('id');

            $survivor = $roots->first();
            $extras = $roots->slice(1)->values()->all();

            DB::table('objects')->whereIn('id', $extras)->update(['parent_id' => $survivor]);

            Log::warning('Demoted extra team root folders before adding objects_team_root_unique.', [
                'team_id' => $teamId,
                'kept_object_id' => $survivor,
                'demoted_object_ids' => $extras,
            ]);
        }
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
