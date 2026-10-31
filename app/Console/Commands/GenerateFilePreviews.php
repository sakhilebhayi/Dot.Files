<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\FilePreviewService;
use Illuminate\Console\Command;

/**
 * Backfills document snapshots for files uploaded before R-26 (or after a host gained
 * Imagick/GD). Safe to re-run: only files still marked `pending` — or everything with
 * --retry, which re-attempts the ones the host previously couldn't render.
 */
class GenerateFilePreviews extends Command
{
    protected $signature = 'files:previews {--retry : also re-attempt files previously marked none} {--chunk=100}';

    protected $description = 'Render missing document snapshots from the stored blobs';

    public function handle(FilePreviewService $previews): int
    {
        $this->line(sprintf(
            'Host capability: raster images %s, PDF first pages %s.',
            $previews->hasGd() ? 'yes (GD)' : 'no',
            $previews->canRenderPdf() ? 'yes (Imagick)' : 'no'
        ));

        $statuses = $this->option('retry') ? ['pending', 'none'] : ['pending'];
        $done = 0;

        File::withoutGlobalScope('team')
            ->whereIn('preview_status', $statuses)
            ->chunkById((int) $this->option('chunk'), function ($files) use ($previews, &$done) {
                foreach ($files as $file) {
                    $previews->generate($file);
                    $done++;
                }
            });

        $ready = File::withoutGlobalScope('team')->where('preview_status', 'ready')->count();
        $this->info("Processed {$done} file(s); {$ready} snapshot(s) ready in total.");

        return self::SUCCESS;
    }
}
