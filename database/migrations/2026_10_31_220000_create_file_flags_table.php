<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// R-28 §29: unusual activity, flagged for human review. A flag is evidence plus a question,
// never a verdict: it opens as `open` and an owner closes it as reviewed or dismissed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->foreignId('file_id')->index();
            $table->string('rule', 40);
            $table->foreignId('actor_id')->nullable();
            $table->string('severity', 16)->default('review');
            $table->json('evidence');
            $table->string('status', 16)->default('open')->index();
            $table->foreignId('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_flags');
    }
};
