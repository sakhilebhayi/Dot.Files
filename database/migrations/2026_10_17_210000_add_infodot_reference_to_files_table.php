<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * InfoDot gives every registered file a stable reference (OWNER-F-000012) and a resource
 * uuid. Both are kept here so the file can show its reference and so a delete can tell
 * InfoDot which pointer to drop. Nothing else about the file ever leaves this app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            if (! Schema::hasColumn('files', 'infodot_reference')) {
                $table->string('infodot_reference', 32)->nullable();
            }
            if (! Schema::hasColumn('files', 'infodot_resource_uuid')) {
                $table->uuid('infodot_resource_uuid')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['infodot_reference', 'infodot_resource_uuid']);
        });
    }
};
