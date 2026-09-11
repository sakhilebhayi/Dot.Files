<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPathToFilesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // A `files` created by Dot.Doc already carries `path` (it is in that
        // repo's 2026_09_08_000001 migration), so this catch-up only runs on
        // the table Dot.Files created itself in 2020.
        if (Schema::hasColumn('files', 'path')) {
            return;
        }

        Schema::table('files', function (Blueprint $table) {
            $table->string('path');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('path');
        });
    }
}
