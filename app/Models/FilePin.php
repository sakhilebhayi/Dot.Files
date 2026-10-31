<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person keeps one file at the front of their room (R-27). A pin is a bookmark, never a
 * permission: everything a pin shows still goes through the same team scope and policies.
 */
class FilePin extends Model
{
    protected $guarded = [];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
