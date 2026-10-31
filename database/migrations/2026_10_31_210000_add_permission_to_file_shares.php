<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// R-28 §24: what a share lets the recipient do. Existing shares were always
// view-and-download, so that stays the default and nothing changes for them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_shares', function (Blueprint $table) {
            $table->string('permission', 16)->default('download');
        });
    }

    public function down(): void
    {
        Schema::table('file_shares', function (Blueprint $table) {
            $table->dropColumn('permission');
        });
    }
};
