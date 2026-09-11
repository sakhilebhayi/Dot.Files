<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two columns `files` is missing against the shared shape.
 *
 * Dot.Doc's copy of this table (its 2026_09_08_000001 migration) carries
 * `mime_type` and `owner_id`; Dot.Files' 2020 original does not. Both are
 * needed on this side too, and for reasons of this application's own:
 *
 * - `mime_type` is what `FileController::view()` decides `Content-Disposition`
 *   from. Without it every preview is a download, and guessing the type from
 *   the stored bytes at read time is how an uploaded SVG ends up rendered as
 *   a document in this origin.
 * - `owner_id` records who put a file there, which the tree has never known -
 *   `files` only ever carried a team. `nullOnDelete` because a file outlives
 *   the account that uploaded it.
 *
 * Both are nullable and both are guarded with `Schema::hasColumn`: on a
 * shared database where Dot.Doc migrated first the columns are already
 * there, and on Dot.Files' own database the existing rows predate them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('files')) {
            return;
        }

        Schema::table('files', function (Blueprint $table) {
            if (! Schema::hasColumn('files', 'mime_type')) {
                $table->string('mime_type')->nullable();
            }

            if (! Schema::hasColumn('files', 'owner_id')) {
                $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('files')) {
            return;
        }

        if (Schema::hasColumn('files', 'owner_id')) {
            Schema::table('files', function (Blueprint $table) {
                $table->dropConstrainedForeignId('owner_id');
            });
        }

        if (Schema::hasColumn('files', 'mime_type')) {
            Schema::table('files', function (Blueprint $table) {
                $table->dropColumn('mime_type');
            });
        }
    }
};
