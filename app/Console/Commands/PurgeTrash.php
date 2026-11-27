<?php

namespace App\Console\Commands;

use App\Models\Obj;
use App\Services\TrashPurge;
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

        $purge = app(TrashPurge::class);
        $total = 0;

        do {
            $total += $removed = $purge->overdue($days);
        } while ($removed === TrashPurge::BATCH);

        $this->info("Purged {$total} record(s) trashed more than {$days} days ago.");

        return self::SUCCESS;
    }
}
