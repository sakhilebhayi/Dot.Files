<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateObjsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Guarded: `objects` is shared with Dot.Doc on the `infodot` database
        // (see 2026_09_11_000001 and the Dot.Doc side's
        // 2026_09_08_000001_create_shared_files_tree_tables.php). Whichever
        // product migrates first creates it; this one then skips rather than
        // failing with "relation already exists". Anything the shape is
        // missing is added by the 2026_09_11_* migrations, never here.
        if (Schema::hasTable('objects')) {
            return;
        }

        Schema::create('objects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->morphs('objectable');
            $table->foreignId('parent_id')->nullable()->constrained('objects');
            $table->foreignId('team_id')->constrained('teams');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('objs');
    }
}
