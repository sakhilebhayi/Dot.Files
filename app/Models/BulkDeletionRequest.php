<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkDeletionRequest extends Model
{
    protected $fillable = [
        'team_id', 'requested_by', 'object_ids', 'status', 'rejected_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'object_ids' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
