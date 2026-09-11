<?php

namespace Tests\Concerns;

use App\Models\Document;
use App\Models\Obj;
use App\Models\Team;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Stands in for Dot.Doc's `documents` migration.
 *
 * `documents` belongs to Dot.Doc and this repo deliberately ships no
 * migration for it (see App\Models\Document): in production both products
 * share the `infodot` database and Dot.Doc's own migrations create it. A
 * standalone Dot.Files test database has no such table, so the tests that
 * exercise the `document` node kind create the columns they read plus a
 * `content` column they must never read - which is how the read-only
 * column guard is proved rather than asserted.
 *
 * Postgres has transactional DDL, so RefreshDatabase rolls the table back
 * with the rest of the test.
 */
trait UsesTheSharedDocumentsTable
{
    protected function createSharedDocumentsTable(): void
    {
        if (Schema::hasTable('documents')) {
            return;
        }

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->foreignId('team_id')->nullable();
            $table->foreignId('owner_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Insert a document the way Dot.Doc would, then file it in the tree.
     *
     * Written with the query builder, not the model: App\Models\Document
     * refuses every write, which is the point of it.
     */
    protected function fileDocumentInTree(Team $team, Obj $parent, string $title, string $content = 'secret body'): Document
    {
        $uuid = (string) Str::uuid();

        $id = DB::table('documents')->insertGetId([
            'uuid' => $uuid,
            'title' => $title,
            'content' => $content,
            'team_id' => $team->id,
            'owner_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $obj = new Obj(['parent_id' => $parent->id]);
        $obj->team_id = $team->id;
        $obj->objectable_type = 'document';
        $obj->objectable_id = $id;
        $obj->save();

        return Document::findOrFail($id);
    }
}
