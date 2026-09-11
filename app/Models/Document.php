<?php

namespace App\Models;

use App\Services\FilesService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A Dot.Doc document, as far as Dot.Files is allowed to know about one.
 *
 * `documents` belongs to Dot.Doc. This application never creates, edits or
 * deletes a row in it - it reads four columns to put a name and a lamp in
 * the tree, and to build the "Open in Dot.Doc" handoff link. Everything a
 * document actually IS (its content, its versions, its comments, its
 * sharing) stays on the other side of the handoff.
 *
 * Two things make that more than a convention:
 *
 * 1. A global scope pins every query to {@see self::SAFE_COLUMNS}. Both
 *    products read the SAME table on the shared `infodot` database, so
 *    `content` and `content_json` are one careless `with('objectable')` away
 *    from being loaded into a file browser that has no business holding
 *    them. Selecting them is not possible from here, including through the
 *    `objectable` morph.
 * 2. Writes throw. A `document` node's delete is a tree operation
 *    ({@see FilesService::deleteObject()} removes the `objects`
 *    row and leaves the document alone); if some future caller reaches for
 *    the document itself, it finds a closed door rather than silently
 *    destroying a colleague's work in another application.
 *
 * There is deliberately no migration for `documents` in this repo. On a
 * standalone Dot.Files database the table does not exist and no `document`
 * node can exist either, so nothing here is ever reached.
 *
 * @property int $id
 * @property string $uuid
 * @property string $title
 * @property int|null $team_id
 * @property int|null $owner_id
 */
class Document extends Model
{
    /**
     * The only columns Dot.Files has any reason to read.
     *
     * @var list<string>
     */
    public const SAFE_COLUMNS = ['id', 'uuid', 'title', 'team_id', 'owner_id'];

    protected $table = 'documents';

    /** Read-only: nothing in this application may mass-assign a document. */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::addGlobalScope('safe-columns', function (Builder $builder) {
            $builder->select(array_map(
                fn (string $column): string => 'documents.'.$column,
                self::SAFE_COLUMNS,
            ));
        });

        $refuse = function (): never {
            throw new LogicException(
                'documents belongs to Dot.Doc. Dot.Files reads it and never writes it.'
            );
        };

        static::saving($refuse);
        static::deleting($refuse);
    }

    /**
     * The tree node this document is filed at, if it has been filed.
     */
    public function node()
    {
        return $this->morphOne(Obj::class, 'objectable');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
