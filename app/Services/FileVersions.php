<?php

namespace App\Services;

use App\Exceptions\VersionConflictException;
use App\Models\File;
use App\Models\FileVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The one writer of file history (R-28 Part F). Rules it enforces:
 *
 * - Append-only: versions are only ever created, each owning its own blob; nothing edits or
 *   removes a version while its file exists.
 * - The file row always points at the LATEST blob; replacing content stores a new blob and a
 *   new version row, leaving every earlier blob untouched.
 * - Restoring an old version COPIES its blob forward as a new version (action `restored`),
 *   so the intervening history survives — exactly what the brief demands.
 * - Every version carries who, when, from which platform, what kind of change, and the
 *   content's sha-256, so a dispute can be settled from the record.
 */
class FileVersions
{
    /** The baseline row for a file that predates version history, created lazily and once. */
    public function ensureBaseline(File $file): void
    {
        if (FileVersion::where('file_id', $file->id)->exists()) {
            return;
        }

        FileVersion::create([
            'file_id' => $file->id,
            'version' => (int) $file->current_version ?: 1,
            'name' => $file->name,
            'size' => (int) $file->size,
            'mime' => $file->mime,
            'path' => $file->path,
            'checksum' => $this->checksum($file->path),
            'created_by' => $file->uploaded_by,
            'origin_platform' => $file->origin_platform,
            'action' => 'uploaded',
            'created_at' => $file->created_at ?? now(),
        ]);

        if ($file->checksum === null) {
            $file->forceFill(['checksum' => $this->checksum($file->path)])->saveQuietly();
        }
    }

    /**
     * New content for an existing file: a new blob, a new version, the file row moved forward.
     *
     * $expectedBase, when given, is the version the caller read before editing: if the trail
     * has moved past it, the save is refused with a VersionConflictException before any blob
     * or row is written (R-28 §31 — detect conflicts, never silently overwrite).
     */
    public function replace(File $file, string $binary, ?string $mime, ?int $userId, ?string $platform, string $action = 'replaced', ?string $newName = null, ?int $expectedBase = null): FileVersion
    {
        $this->ensureBaseline($file);

        return DB::transaction(function () use ($file, $binary, $mime, $userId, $platform, $action, $newName, $expectedBase) {
            // The trail itself is the authority on numbering — never an in-memory attribute.
            $next = ((int) FileVersion::where('file_id', $file->id)->max('version')) + 1;

            if ($expectedBase !== null && $expectedBase !== $next - 1) {
                throw new VersionConflictException($next - 1);
            }

            Storage::disk('local')->put($path = 'files/'.Str::random(40), $binary);

            $version = FileVersion::create([
                'file_id' => $file->id,
                'version' => $next,
                'name' => $newName ?? $file->name,
                'size' => strlen($binary),
                'mime' => $mime ?? $file->mime,
                'path' => $path,
                'checksum' => hash('sha256', $binary),
                'created_by' => $userId,
                'origin_platform' => $platform,
                'action' => $action,
                'created_at' => now(),
            ]);

            $file->forceFill([
                'name' => $newName ?? $file->name,
                'size' => strlen($binary),
                'mime' => $mime ?? $file->mime,
                'path' => $path,
                'checksum' => $version->checksum,
                'current_version' => $next,
                'preview_status' => 'pending',
            ])->save();

            // The snapshot follows the content: regenerate after the response so the preview
            // always shows the version people will actually open (R-28 §10).
            $fileId = $file->id;
            dispatch(function () use ($fileId) {
                $fresh = File::withoutGlobalScope('team')->find($fileId);

                if ($fresh !== null && $fresh->preview_status === 'pending') {
                    app(FilePreviewService::class)->generate($fresh);
                }
            })->afterResponse();

            return $version;
        });
    }

    /** From an UploadedFile (the document page's "Replace content" flow). */
    public function replaceFromUpload(File $file, UploadedFile $upload, ?int $userId, ?int $expectedBase = null): FileVersion
    {
        return $this->replace($file, (string) $upload->get(), $upload->getMimeType(), $userId, null, 'replaced', basename($upload->getClientOriginalName()), $expectedBase);
    }

    /**
     * Bring an old version's content forward as the newest version. The old row and every row
     * in between stay exactly as they were.
     */
    public function restore(File $file, FileVersion $old, ?int $userId): FileVersion
    {
        abort_unless($old->file_id === $file->id, 404);
        abort_unless(Storage::disk('local')->exists($old->path), 410, 'That version\'s content is no longer on disk.');

        $this->ensureBaseline($file);

        $binary = Storage::disk('local')->get($old->path);

        $version = $this->replace($file, $binary, $old->mime, $userId, null, 'restored', $old->name);
        $version->forceFill(['restored_from_version' => $old->version])->saveQuietly();

        return $version;
    }

    /** The full trail, newest first, for the document page. */
    public function trail(File $file)
    {
        $this->ensureBaseline($file);

        return FileVersion::with('author')->where('file_id', $file->id)->orderByDesc('version')->get();
    }

    private function checksum(string $path): ?string
    {
        $full = Storage::disk('local')->path($path);

        return is_file($full) ? hash_file('sha256', $full) : null;
    }
}
