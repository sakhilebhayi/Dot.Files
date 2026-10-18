<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-company sharing (v1): a company owner or admin shares one file with one person outside the
 * company, by verified email, for a limited time. The share is checked on every open, so revoking
 * or expiring it takes effect at once. `file_share_events` is the audit trail; it keeps no file
 * names and survives the file being deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained('files')->cascadeOnDelete();
            $table->string('email');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamps();

            $table->index(['email', 'revoked_at', 'expires_at']);
            $table->index('file_id');
        });

        Schema::create('file_share_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('file_uuid');
            $table->unsignedBigInteger('share_id')->nullable();
            $table->string('event', 30);
            $table->string('actor_email')->nullable();
            $table->string('subject_email')->nullable();
            $table->string('note', 120)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['file_uuid', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_share_events');
        Schema::dropIfExists('file_shares');
    }
};
