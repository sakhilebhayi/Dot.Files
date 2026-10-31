<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-26: document snapshots. A preview is a server-rendered still of the document's first
 * page/frame, stored as a private blob and streamed only through the authorised preview
 * route — never a public URL. `preview_status` records what the host could honestly do:
 * pending (not attempted yet), ready (snapshot exists), none (format or host can't).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('mime', 191)->nullable()->after('size');
            $table->string('preview_path')->nullable()->after('mime');
            $table->string('preview_status', 16)->default('pending')->after('preview_path');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['mime', 'preview_path', 'preview_status']);
        });
    }
};
