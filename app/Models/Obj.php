<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use App\Services\FileGovernance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships;

class Obj extends Model
{
    use HasFactory, HasRecursiveRelationships, HasTeamScope;

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
    }

    public function isTrashed(): bool
    {
        return $this->trashed_at !== null;
    }

    public static function booted()
    {
        static::creating(function ($model) {
            $model->uuid = Str::uuid();
        });

        // EA-2: the searchable text (folder path and name) is kept on the row, so search stays inside one company.
        static::saving(function (Obj $model) {
            if (! $model->exists || $model->isDirty('parent_id') || $model->search_text === null) {
                $model->search_text = $model->computeSearchText();
            }
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

    /** Folder path and name, lower case: "root / contracts / lease 2027.pdf". */
    public function computeSearchText(): string
    {
        $parent = $this->parent_id !== null ? static::withoutGlobalScopes()->find($this->parent_id) : null;
        $name = mb_strtolower((string) $this->objectable()->withoutGlobalScopes()->first()?->name);

        return ltrim(($parent?->search_text ?? '').' / '.$name, ' /');
    }

    /** After a rename or a move: this record's text and every record below it. */
    public function refreshSearchText(): void
    {
        $this->forceFill(['search_text' => $this->computeSearchText()])->saveQuietly();

        static::withoutGlobalScopes()->where('parent_id', $this->id)->get()->each->refreshSearchText();
    }

    /**
     * Records whose path or name holds every word typed (case-insensitive, wildcards escaped). The team scope still
     * applies, so this never reaches another company.
     */
    public function scopeMatching(Builder $query, string $terms): Builder
    {
        foreach (preg_split('/\s+/', mb_strtolower(trim($terms)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $query->whereRaw("search_text LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%']);
        }

        return $query;
    }

    public function objectable()
    {
        return $this->morphTo();
    }
}
