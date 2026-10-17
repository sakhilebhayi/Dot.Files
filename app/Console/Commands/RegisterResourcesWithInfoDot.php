<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\InfoDot\ResourceRegistry;
use Illuminate\Console\Command;

class RegisterResourcesWithInfoDot extends Command
{
    protected $signature = 'files:register-resources {--all : Register again files that already have a reference}';

    protected $description = 'Give every file in a linked company a stable InfoDot reference (pointers only)';

    public function handle(ResourceRegistry $registry): int
    {
        if (! $registry->enabled()) {
            $this->error('InfoDot is not configured here (INFODOT_SERVICE_SECRET and INFODOT_REDEEM_URL).');

            return self::FAILURE;
        }

        $done = $failed = $skipped = 0;

        File::withoutGlobalScope('team')
            ->when(! $this->option('all'), fn ($q) => $q->whereNull('infodot_reference'))
            ->orderBy('id')
            ->each(function (File $file) use ($registry, &$done, &$failed, &$skipped) {
                $team = $file->team()->withoutGlobalScopes()->first();

                if (! $team || $team->personal_team || blank($team->infodot_team_uuid)) {
                    $skipped++;

                    return;
                }

                $registry->register($file) ? $done++ : $failed++;
            });

        $this->info("Registered {$done}, skipped {$skipped} (personal or unlinked teams), failed {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
