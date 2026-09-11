<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships;

/**
 * @property int $id
 * @property string $uuid
 * @property string $objectable_type
 * @property int $objectable_id
 * @property int|null $parent_id
 * @property int $team_id
 * @property-read Model|null $objectable
 */
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

        static::deleting(function ($model) {
            // A `document` node is a POINTER into Dot.Doc's table, not the
            // document. Unfiling it must never destroy a colleague's document
            // in the other application - App\Models\Document refuses the
            // write anyway, and this is what keeps the tree from asking.
            if ($model->objectable_type !== 'document') {
                optional($model->objectable)->delete();
            }

            $model->descendants->each->delete();
        });
    }

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'name' => $this->name(),
            'path' => $this->ancestorsAndSelf
                ->map(fn (self $node) => $node->name())
                ->reverse()
                ->join('/'),
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function objectable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * What this node points at: `folder`, `file` or `document`.
     *
     * The literal morph-map alias rather than a class name, because it is the
     * value the shared `objects.objectable_type` column actually carries and
     * the one both products branch on.
     */
    public function kind(): string
    {
        return (string) $this->objectable_type;
    }

    public function isFolder(): bool
    {
        return $this->kind() === 'folder';
    }

    public function isDocument(): bool
    {
        return $this->kind() === 'document';
    }

    /**
     * The node's label, wherever the label happens to live.
     *
     * A folder and a file carry `name`; a Dot.Doc document carries `title`.
     * Every caller that used to reach through to `objectable->name` goes
     * through here instead, so a document row does not blow up a breadcrumb,
     * a rename form or the search index.
     */
    public function name(): string
    {
        $objectable = $this->objectable;

        if ($objectable === null) {
            return '';
        }

        return (string) ($objectable instanceof Document ? $objectable->title : $objectable->getAttribute('name'));
    }
}
