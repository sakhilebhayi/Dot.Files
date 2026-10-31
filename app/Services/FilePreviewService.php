<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Renders the document snapshot shown on workspace cards and the file page (R-26).
 *
 * Honesty and safety rules, in order:
 * - A snapshot is rendered from the real blob on the server, or it does not exist. Nothing is
 *   ever faked client-side; unsupported formats keep their type glyph (`preview_status: none`).
 * - Capability-detected for the shared host: raster images need GD, PDF first pages need
 *   Imagick with Ghostscript behind it. Whatever is missing degrades to `none`, never errors.
 * - Document content is never executed: SVG and HTML are never rasterised in a browser context,
 *   office formats are not converted. The snapshot is a flat JPEG written to the private disk
 *   and streamed only through the policy-checked preview routes. No public URLs (R-26 brief).
 */
class FilePreviewService
{
    /** Snapshots wider than the largest card would waste disk; first pages read fine at this size. */
    private const MAX_WIDTH = 800;

    /** A source this large is a memory risk on the shared host; the glyph is the honest fallback. */
    private const MAX_SOURCE_BYTES = 25 * 1024 * 1024;

    private const MAX_SOURCE_PIXELS = 8000;

    public function generate(File $file): void
    {
        try {
            $path = Storage::disk('local')->path($file->path);

            if (! is_file($path) || filesize($path) > self::MAX_SOURCE_BYTES) {
                $this->mark($file, 'none');

                return;
            }

            $mime = $file->mime ?: (string) @mime_content_type($path);

            if ($mime !== '' && $file->mime === null) {
                $file->forceFill(['mime' => $mime])->saveQuietly();
            }

            $jpeg = match (true) {
                $this->isRasterImage($mime) && $this->hasGd() => $this->fromImage($path),
                $mime === 'application/pdf' && $this->canRenderPdf() => $this->fromPdf($path),
                default => null,
            };

            if ($jpeg === null) {
                $this->mark($file, 'none');

                return;
            }

            $previewPath = 'previews/'.$file->uuid.'.jpg';
            Storage::disk('local')->put($previewPath, $jpeg);

            $file->forceFill(['preview_path' => $previewPath, 'preview_status' => 'ready'])->saveQuietly();
        } catch (Throwable $e) {
            report($e);
            $this->mark($file, 'none');
        }
    }

    public function delete(File $file): void
    {
        if ($file->preview_path !== null) {
            Storage::disk('local')->delete($file->preview_path);
        }
    }

    public function hasGd(): bool
    {
        return function_exists('imagecreatefromstring');
    }

    public function canRenderPdf(): bool
    {
        if (! class_exists(\Imagick::class)) {
            return false;
        }

        try {
            return \Imagick::queryFormats('PDF') !== [];
        } catch (Throwable) {
            return false;
        }
    }

    private function isRasterImage(string $mime): bool
    {
        // SVG is explicitly out: rasterising it means parsing active content. Glyph instead.
        return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    private function fromImage(string $path): ?string
    {
        $info = @getimagesize($path);

        if ($info === false || $info[0] < 1 || $info[1] < 1
            || $info[0] > self::MAX_SOURCE_PIXELS || $info[1] > self::MAX_SOURCE_PIXELS) {
            return null;
        }

        $source = @imagecreatefromstring((string) file_get_contents($path));

        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $targetWidth = min($width, self::MAX_WIDTH);
        $targetHeight = (int) max(1, round($height * $targetWidth / $width));

        $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
        // Transparent sources land on paper-white, not black.
        $paper = imagecolorallocate($thumb, 251, 250, 247);
        imagefill($thumb, 0, 0, $paper);
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($thumb, null, 72);
        $jpeg = ob_get_clean();

        return $jpeg === false || $jpeg === '' ? null : $jpeg;
    }

    private function fromPdf(string $path): ?string
    {
        $imagick = new \Imagick;
        $imagick->setResolution(110, 110);
        $imagick->readImage($path.'[0]');
        $imagick->setImageBackgroundColor('#fbfaf7');
        $imagick = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(72);

        if ($imagick->getImageWidth() > self::MAX_WIDTH) {
            $imagick->thumbnailImage(self::MAX_WIDTH, 0);
        }

        $jpeg = $imagick->getImageBlob();
        $imagick->clear();

        return $jpeg === '' ? null : $jpeg;
    }

    private function mark(File $file, string $status): void
    {
        $file->forceFill(['preview_status' => $status])->saveQuietly();
    }
}
