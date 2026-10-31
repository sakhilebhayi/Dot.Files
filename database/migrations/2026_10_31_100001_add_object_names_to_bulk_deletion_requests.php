<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-25: the operator approving a bulk deletion must see WHAT would be deleted, not a count of ids.
 * The names are snapshotted at request time (the rows may be gone or renamed by review time).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_deletion_requests', function (Blueprint $table) {
            $table->json('object_names')->nullable()->after('object_ids');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_deletion_requests', function (Blueprint $table) {
            $table->dropColumn('object_names');
        });
    }
};
