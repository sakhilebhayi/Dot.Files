<?php

namespace App\Services;

use App\Models\Obj;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The 30-day trash window, enforced without cron (EA-3). The owner's cron order runs only email work on the shared host,
 * so the purge rides on the app's own traffic instead: at most once an hour, after a response has been sent, every
 * company's records trashed more than Obj::TRASH_DAYS ago are removed for good, a bounded batch at a time. A company
 * that never opens its trash is purged all the same. files:purge-trash runs the same code where a scheduler is allowed.
 */
class TrashPurge
{
    /** At most this many records per sweep, so no visitor's request is held up by a large backlog. */
    public const BATCH = 200;

    private const SWEEP_KEY = 'files:trash-purge:last-sweep';

    private const SWEEP_EVERY = 3600;

    /** Purge overdue records across all companies; returns how many were removed. */
    public function overdue(int $days = Obj::TRASH_DAYS, int $limit = self::BATCH): int
    {
        $stale = Obj::withoutGlobalScope('team')
            ->whereNotNull('trashed_at')
            ->where('trashed_at', '<', now()->subDays($days))
            ->orderBy('trashed_at')
            ->limit($limit)
            ->get();

        foreach ($stale as $obj) {
            // A folder purged earlier in this batch has already taken its contents with it.
            Obj::withoutGlobalScope('team')->find($obj->id)?->delete();
        }

        return $stale->count();
    }

    /** Called after a response: sweeps if an hour has passed since the last sweep. Never throws. */
    public function sweepIfDue(): void
    {
        try {
            if (! Cache::add(self::SWEEP_KEY, now()->toIso8601String(), self::SWEEP_EVERY)) {
                return;
            }

            $removed = $this->overdue();

            if ($removed > 0) {
                Log::info('Trash purge removed overdue records.', ['count' => $removed]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
