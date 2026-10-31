<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-28 Part F: version history. Every meaningful change to a file's content is an APPEND-ONLY
 * row here, each owning its own immutable blob: who, when, from which platform, what kind of
 * change, and the content's sha-256. The file row always points at the latest blob; restoring
 * an old version copies its blob forward as a NEW version, so the intervening history is never
 * erased. No update or delete route exists for these rows — they go only when the file itself
 * is permanently deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->index();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->unsignedBigInteger('size');
            $table->string('mime', 191)->nullable();
            $table->string('path');
            $table->string('checksum', 64)->nullable();
            $table->foreignId('created_by')->nullable();
            $table->string('origin_platform', 32)->nullable();
            $table->string('action', 24); // uploaded | replaced | platform-update | restored
            $table->unsignedInteger('restored_from_version')->nullable();
            $table->timestamp('created_at');
            $table->unique(['file_id', 'version']);
        });

        Schema::table('files', function (Blueprint $table) {
            $table->string('checksum', 64)->nullable()->after('preview_status');
            $table->unsignedInteger('current_version')->default(1)->after('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_versions');

        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['checksum', 'current_version']);
        });
    }
};
