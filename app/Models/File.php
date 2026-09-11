<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property int $size
 * @property string $path
 * @property string|null $mime_type
 * @property int|null $owner_id
 * @property int $team_id
 * @property-read Team|null $team
 */
class File extends Model
{
    use HasFactory, HasTeamScope;

    protected $fillable = ['name', 'size', 'path', 'mime_type', 'owner_id', 'team_id'];

    public function sizeForHumans()
    {
        $bytes = $this->size;

        $units = ['b', 'kb', 'mb', 'gb', 'tb', 'pd'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).$units[$i];
    }

    public static function booted()
    {
        static::creating(function ($model) {
            $model->uuid = Str::uuid();
        });

        static::deleting(function ($model) {
            // The `files` disk, not `local`: the blob store is shared with
            // Dot.Doc and its root is configurable (FILES_ROOT). It defaults
            // to storage/app, which is where `local` put every existing row's
            // bytes, so nothing already stored moves.
            Storage::disk('files')->delete($model->path);
        });
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
