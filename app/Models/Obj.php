<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
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
