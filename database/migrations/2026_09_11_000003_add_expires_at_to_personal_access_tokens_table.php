<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The column the handoff's five-minute window is actually kept in.
 *
 * `personal_access_tokens` is shared across the ecosystem, and this repo's
 * guarded copy (2019_12_14_000001) predates Sanctum's `expires_at`. Nothing
 * noticed while Dot.Files only ever REDEEMED tokens - the receiving check in
 * EcosystemAuthController reads `expires_at` and a missing attribute is
 * simply null, so an InfoDot-minted token from the shared `infodot` database
 * (where the column exists) validated fine. Minting one from here needs the
 * column to be real: `createToken($name, $abilities, $expiresAt)` writes it.
 *
 * Guarded with `Schema::hasColumn`, like everything else in this set: on the
 * shared database another product's migration added it long ago.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens') || Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('personal_access_tokens') || ! Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
