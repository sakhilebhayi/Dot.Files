<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EA-0: stored addresses become trimmed and lower case, matching how the app now writes them, so the existing unique
 * index on users.email also holds case-insensitively. Data-only and safe to re-run. Two accounts whose addresses differ
 * only by case are never merged or renamed here: both stay as they are and are logged (ids only) for a person to review.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('users')->select(['id', 'email'])->orderBy('id')->get();

        $groups = $rows->groupBy(fn (object $row): string => mb_strtolower(trim((string) $row->email)));

        foreach ($groups as $normalised => $members) {
            if ($members->count() > 1) {
                Log::warning('users.email case collision left for review', ['user_ids' => $members->pluck('id')->all()]);

                continue;
            }

            $row = $members->first();

            if ($row->email !== $normalised) {
                DB::table('users')->where('id', $row->id)->update(['email' => $normalised]);
            }
        }
    }

    public function down(): void
    {
        // Original casing is not kept; lower-case addresses remain valid, so there is nothing to undo.
    }
};
