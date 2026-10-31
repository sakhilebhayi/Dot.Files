<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable state of a file's content (R-28 Part F). Append-only: rows are created by
 * FileVersions and removed only when the file is permanently deleted. There is no route and
 * no code path that edits one — a person cannot rewrite the history of their own changes.
 */
class FileVersion extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class)->withoutGlobalScopes();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'uploaded' => 'Uploaded',
            'replaced' => 'Content replaced',
            'platform-update' => 'Updated by '.(config("ecosystem.platforms.{$this->origin_platform}.name") ?: ucfirst((string) $this->origin_platform)),
            'restored' => 'Restored from version '.$this->restored_from_version,
            default => ucfirst($this->action),
        };
    }
}
