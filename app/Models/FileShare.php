<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file, shared with one person outside the company, until it expires or is revoked.
 * Checked on every open; see FileShareService.
 */
class FileShare extends Model
{
    public const MAX_DAYS = 90;

    public const DEFAULT_DAYS = 30;

    public const MAX_ACTIVE_PER_FILE = 25;

    /**
     * The honest preset set (R-28 §24): this workspace can show a document and hand over a copy,
     * nothing else, so those are the only permissions it claims. `view` = open the share page and
     * its snapshot; `download` = view plus an authorised copy of the file.
     */
    public const PERMISSIONS = ['view', 'download'];

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'first_opened_at' => 'datetime',
        'last_opened_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class)->withoutGlobalScopes();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * The one scope every access check runs through. `status` is the owner-approval gate
     * (R-26): a pending or declined request is not access, whatever the UI shows.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending')->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function canDownload(): bool
    {
        return $this->permission !== 'view';
    }

    public function permissionLabel(): string
    {
        return $this->canDownload() ? __('View and download') : __('View only');
    }

    /** The days the sharer chose, recoverable from the stored window. */
    public function requestedDays(): int
    {
        return max(1, min(self::MAX_DAYS, (int) round($this->created_at->floatDiffInDays($this->expires_at))));
    }
}
