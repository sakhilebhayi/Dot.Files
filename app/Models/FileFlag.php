<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of unusual activity, flagged for review (R-28 §29). The evidence says exactly what
 * was seen — who, what, when, how many — and a company owner or administrator closes the flag
 * as reviewed or dismissed. Nothing here accuses anyone and nothing is blocked automatically.
 */
class FileFlag extends Model
{
    protected $guarded = [];

    protected $casts = [
        'evidence' => 'array',
        'decided_at' => 'datetime',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class)->withoutGlobalScopes();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function ruleLabel(): string
    {
        return match ($this->rule) {
            'version-churn' => __('Rapid content changes'),
            'replace-then-trash' => __('Replaced, then trashed'),
            default => $this->rule,
        };
    }
}
