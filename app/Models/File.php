<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use App\Services\FilePreviewService;
use App\Services\FileShareService;
use App\Services\InfoDot\ResourceRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class File extends Model
{
    use HasFactory, HasTeamScope;

    protected $fillable = ['name', 'size', 'path', 'team_id', 'mime', 'uploaded_by'];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /** The tree row this file hangs off (carries its place and its trash state). */
    public function obj()
    {
        return $this->morphOne(Obj::class, 'objectable');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isTrashed(): bool
    {
        return $this->obj()->withoutGlobalScope('team')->whereNotNull('trashed_at')->exists();
    }

    /** The Dot platform that filed this document, by display name — null when a person did. */
    public function originLabel(): ?string
    {
        if ($this->origin_platform === null) {
            return null;
        }

        return (string) (config("ecosystem.platforms.{$this->origin_platform}.name") ?: ucfirst($this->origin_platform));
    }

    public function isPinnedBy(User $user): bool
    {
        return FilePin::where('user_id', $user->id)->where('file_id', $this->id)->exists();
    }

    /**
     * The document's kind, for the type glyph and the meta line (R-26). From the stored mime
     * first, the extension second — display only, never used for an access decision.
     */
    public function kind(): string
    {
        $mime = (string) $this->mime;
        $ext = strtolower(pathinfo((string) $this->name, PATHINFO_EXTENSION));

        return match (true) {
            $mime === 'application/pdf' || $ext === 'pdf' => 'pdf',
            str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true) => 'image',
            in_array($ext, ['csv', 'xls', 'xlsx', 'ods'], true) => 'sheet',
            in_array($ext, ['zip', 'tar', 'gz', '7z', 'rar'], true) => 'archive',
            str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'mov', 'avi', 'webm'], true) => 'media',
            str_starts_with($mime, 'audio/') || in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'], true) => 'audio',
            in_array($ext, ['doc', 'docx', 'txt', 'text', 'rtf', 'md', 'ppt', 'pptx'], true) => 'doc',
            default => 'file',
        };
    }

    public function kindLabel(): string
    {
        return match ($this->kind()) {
            'pdf' => 'PDF',
            'image' => 'Image',
            'sheet' => 'Spreadsheet',
            'archive' => 'Archive',
            'media' => 'Video',
            'audio' => 'Audio',
            'doc' => 'Document',
            default => 'File',
        };
    }

    public function hasPreview(): bool
    {
        return $this->preview_status === 'ready' && $this->preview_path !== null;
    }

    public function sizeForHumans()
    {
        return static::bytesForHumans((int) $this->size);
    }

    public static function bytesForHumans(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    public static function booted()
    {
        static::creating(function ($model) {
            $model->uuid = Str::uuid();
        });

        // A new file in a linked company gets a pointer in InfoDot. After the response, so an
        // upload never waits for (or fails because of) InfoDot.
        static::created(function ($model) {
            $uuid = (string) $model->uuid;
            $teamId = $model->team_id;

            dispatch(fn () => app(ResourceRegistry::class)->registerPointer($uuid, $teamId))->afterResponse();

            // The document snapshot renders after the response too: an upload never waits on
            // image work, and a host that can't render simply leaves the type glyph (R-26).
            $fileId = $model->id;
            dispatch(function () use ($fileId) {
                $file = File::withoutGlobalScope('team')->find($fileId);

                if ($file !== null && $file->preview_status === 'pending') {
                    app(FilePreviewService::class)->generate($file);
                }
            })->afterResponse();
        });

        static::deleting(function ($model) {
            Storage::disk('local')->delete($model->path);
            app(FilePreviewService::class)->delete($model);

            // Anyone it was shared with loses access now, and the audit trail says why.
            app(FileShareService::class)->revokeMany(FileShare::where('file_id', $model->id)->active()->get(), 'file deleted');

            if ($model->infodot_resource_uuid !== null) {
                $uuid = (string) $model->uuid;
                dispatch(fn () => app(ResourceRegistry::class)->remove($uuid))->afterResponse();
            }
        });
    }
}
