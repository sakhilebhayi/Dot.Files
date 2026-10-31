<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use App\Services\FileGovernance;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships;

class Obj extends Model
{
    use HasFactory, HasRecursiveRelationships, HasTeamScope, Searchable;

    public $asYouType = true;

    public $table = 'objects';

    protected $fillable = ['parent_id'];

    protected $casts = ['trashed_at' => 'datetime'];

    /** Days a trashed record waits before files:purge-trash removes it for real. */
    public const TRASH_DAYS = 30;

    /**
     * R-27: a single-click delete parks the record here instead of destroying it. The row keeps
     * its place in the tree (parent_id untouched), leaves the browse queries and the search
     * index, and comes back exactly where it was on restore.
     */
    public function trash(?int $byUserId): void
    {
        $this->forceFill(['trashed_at' => now(), 'trashed_by' => $byUserId])->save();
        $this->unsearchable();

        // A person trashing a file right after its content changed is the pattern governance
        // reviews (R-28 §29). Platforms (null user) have their own audit trail.
        if ($byUserId !== null && $this->objectable_type === 'file') {
            $fileId = (int) $this->objectable_id;
            dispatch(fn () => app(FileGovernance::class)->afterTrash($fileId, $byUserId))->afterResponse();
        }
    }

    public function restore(): void
    {
        $this->forceFill(['trashed_at' => null, 'trashed_by' => null])->save();
        $this->searchable();
    }

    public function isTrashed(): bool
    {
        return $this->trashed_at !== null;
    }

    public function shouldBeSearchable(): bool
    {
        return $this->trashed_at === null;
    }

    public static function booted()
    {
        static::creating(function ($model) {
            $model->uuid = Str::uuid();
        });

        // Deleting an object removes what hangs off it. These rows belong to the same
        // team as the object being deleted (whoever authorised that deletion already
        // answered for the team), so they are read without the current-team scope:
        // an operator approving a deletion has a different team, or none.
        static::deleting(function ($model) {
            $type = Relation::getMorphedModel($model->objectable_type) ?? $model->objectable_type;

            if ($type && class_exists($type)) {
                $type::withoutGlobalScope('team')->find($model->objectable_id)?->delete();
            }

            $model->descendants()->withoutGlobalScope('team')->get()->each->delete();
        });
    }

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'name' => $this->objectable->name,
            'path' => $this->ancestorsAndSelf->pluck('objectable.name')->reverse()->join('/'),
        ];
    }

    public function objectable()
    {
        return $this->morphTo();
    }
}
