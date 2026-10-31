<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-26: owner-approved sharing. Members of a company may ask for a file to be shared; only an
 * owner or administrator turns the request into access. `status` is the server-side gate:
 * every access check already runs through FileShare::active(), which now also requires
 * status=active, so a pending or declined request grants nothing no matter what the UI shows.
 * Existing rows (created directly by owners/admins) default to active — behaviour unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_shares', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->after('email');
            $table->foreignId('requested_by')->nullable()->after('created_by');
            $table->foreignId('decided_by')->nullable()->after('requested_by');
            $table->timestamp('decided_at')->nullable()->after('decided_by');
        });
    }

    public function down(): void
    {
        Schema::table('file_shares', function (Blueprint $table) {
            $table->dropColumn(['status', 'requested_by', 'decided_by', 'decided_at']);
        });
    }
};
