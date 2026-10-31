<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-27: workspace features on real data.
 *
 * - `file_pins`: a person pins the documents they keep reaching for; pins are per person and
 *   per file, and grant nothing — they only order what that person could already see.
 * - `objects.trashed_at/trashed_by`: the trash. A single-click delete now parks the record in
 *   the trash for 30 days instead of destroying it; restore puts it back exactly where it was.
 *   The operator-approved bulk gate is unchanged and still deletes for real.
 * - `files.uploaded_by`: who filed the document (R-25 Phase 2 register item), shown on the
 *   document page. Nullable — files filed before this column keep an honest blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->foreignId('file_id')->index();
            $table->timestamps();
            $table->unique(['user_id', 'file_id']);
        });

        Schema::table('objects', function (Blueprint $table) {
            $table->timestamp('trashed_at')->nullable()->index()->after('team_id');
            $table->foreignId('trashed_by')->nullable()->after('trashed_at');
        });

        Schema::table('files', function (Blueprint $table) {
            $table->foreignId('uploaded_by')->nullable()->after('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_pins');

        Schema::table('objects', function (Blueprint $table) {
            $table->dropColumn(['trashed_at', 'trashed_by']);
        });

        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('uploaded_by');
        });
    }
};
