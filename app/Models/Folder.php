<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Folder extends Model
{
    use HasFactory, HasTeamScope;

    protected $fillable = ['name'];

    public static function booted()
    {
        static::creating(function ($model)
        {
            $model->uuid = Str::uuid();
        });
    }
}
