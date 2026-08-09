<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * team_deletion_requests.team_id originally cascadeOnDelete()'d --
     * approving a request deletes the team, which then cascade-deleted the
     * very request row recording who approved it and when, destroying the
     * audit trail the whole feature exists to keep. Nullable + nullOnDelete
     * means the request row survives with team_id null, evidence that the
     * team it once pointed to is now gone.
     */
    public function up(): void
    {
        Schema::table('team_deletion_requests', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
        });

        Schema::table('team_deletion_requests', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->change();
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('team_deletion_requests', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
        });

        Schema::table('team_deletion_requests', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable(false)->change();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
        });
    }
};
