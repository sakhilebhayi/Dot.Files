<?php

namespace App\Console\Commands;

use App\Models\Obj;
use Illuminate\Console\Command;

/**
 * Empties every company's trash of records older than the holding window (R-27). Runs
 * daily from the scheduler; deletion is the same permanent path the deleting hooks
 * already guard (blob unlinked, shares revoked, InfoDot pointer removed).
 */
class PurgeTrash extends Command
{
    protected $signature = 'files:purge-trash {--days=}';

    protected $description = 'Permanently remove trashed records older than the holding window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: Obj::TRASH_DAYS);

        $stale = Obj::withoutGlobalScope('team')
            ->whereNotNull('trashed_at')
            ->where('trashed_at', '<', now()->subDays($days))
            ->get();

        foreach ($stale as $obj) {
            $obj->delete();
        }

        $this->info("Purged {$stale->count()} record(s) trashed more than {$days} days ago.");

        return self::SUCCESS;
    }
}
