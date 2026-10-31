<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-25 Phase 3: the platform file API.
 *
 * - `files.origin_platform`: which Dot platform filed the document (null = a person did, through
 *   the workspace). This is also the API's authorisation boundary: a platform may fetch, list
 *   and trash only rows carrying its own key.
 * - `platform_file_events`: the audit trail of every platform store/fetch/trash, per company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('origin_platform', 32)->nullable()->index()->after('uploaded_by');
        });

        Schema::create('platform_file_events', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 32)->index();
            $table->foreignId('team_id')->index();
            $table->string('file_uuid', 36)->index();
            $table->string('event', 16);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('origin_platform');
        });

        Schema::dropIfExists('platform_file_events');
    }
};
