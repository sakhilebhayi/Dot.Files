<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EA-2: in-app search reads each record's own company only. Every object keeps its searchable text (its folder path and
 * name, lower case) next to it, so a search is one query scoped by team instead of a lookup in an index shared by every
 * company. Additive; the backfill computes the text for existing records from the tree itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('objects', 'search_text')) {
            Schema::table('objects', function (Blueprint $table) {
                $table->text('search_text')->nullable();
            });
        }

        $names = [
            'file' => DB::table('files')->pluck('name', 'id'),
            'folder' => DB::table('folders')->pluck('name', 'id'),
        ];

        $objects = DB::table('objects')->select(['id', 'parent_id', 'objectable_type', 'objectable_id'])->get()->keyBy('id');
        $texts = [];

        $textOf = function (int $id, array $seen = []) use (&$textOf, &$texts, $objects, $names): string {
            if (isset($texts[$id])) {
                return $texts[$id];
            }

            $object = $objects[$id] ?? null;

            if ($object === null || isset($seen[$id])) {
                return '';
            }

            $name = mb_strtolower((string) ($names[$object->objectable_type][$object->objectable_id] ?? ''));
            $parent = $object->parent_id !== null ? $textOf((int) $object->parent_id, $seen + [$id => true]) : '';

            return $texts[$id] = ltrim($parent.' / '.$name, ' /');
        };

        foreach ($objects->keys() as $id) {
            DB::table('objects')->where('id', $id)->update(['search_text' => $textOf((int) $id)]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('objects', 'search_text')) {
            Schema::table('objects', function (Blueprint $table) {
                $table->dropColumn('search_text');
            });
        }
    }
};
