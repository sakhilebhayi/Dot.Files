<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Folder extends Model
{
    use HasFactory, HasTeamScope;

    protected $fillable = ['name'];

    public static function booted()
    {
        static::creating(function ($model) {
            $model->uuid = Str::uuid();
        });

        // EA-2: a renamed folder changes the path of everything inside it, so search follows.
        static::updated(function (Folder $model) {
            if ($model->wasChanged('name')) {
                Obj::withoutGlobalScopes()->where('objectable_type', 'folder')->where('objectable_id', $model->id)->first()?->refreshSearchText();
            }
        });
    }
}
