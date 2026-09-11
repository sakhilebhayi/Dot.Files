<?php

namespace App\Services;

use App\Models\Concerns\HasTeamScope;
use App\Models\Document;
use App\Models\File;
use App\Models\Folder;
use App\Models\Obj;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The one seam Dot.Files writes the shared tree through.
 *
 * This is the Dot.Files half of the pair described in
 * `docs/superpowers/specs/2026-09-08-dot-doc-files-integration-design.md`
 * (Dot.docs repo, decision F3). The Dot.Doc half is `App\Files\FilesService`
 * over there. The two are not the same PHP - the models, namespaces and
 * storage conventions differ - but they are the same VERB SET with the same
 * semantics, because they write the same three tables.
 *
 * The rule that makes it worth having: EVERY method takes its team from the
 * object it was handed (`$parent->team_id`, or the Team argument on root())
 * and never from `Auth::user()->currentTeam`. This application's
 * {@see HasTeamScope} reads currentTeam and falls OPEN
 * when a user has none, which is how a team-less session could ever see
 * another team's rows. Nothing in here consults the session, and every query
 * drops that global scope and states its own `team_id` instead - so the
 * fail-open cannot be reached through this seam, whichever way the scope is
 * eventually fixed.
 *
 * The actor is a separate argument from the team on purpose: it is who to
 * authorise, and every mutating method checks the actor's membership of the
 * OBJECT'S team before touching anything.
 */
class FilesService
{
    /** Names may not carry path separators or control characters. */
    private const BAD_NAME = '/[\/\\\\]|[\x00-\x1F\x7F]/';

    /** The width of `folders.name` and `files.name`. */
    private const MAX_NAME_LENGTH = 255;

    /**
     * The team's one root node, created on first ask.
     *
     * Idempotent, so it is safe on every page load. Check-then-create is a
     * race that no amount of care in PHP closes - two tabs opening a new
     * workspace can both find no root before either commits, and two roots
     * for one team is not recoverable afterwards. `objects_team_root_unique`
     * (migration 2026_09_11_000001) decides the race; the loser catches the
     * violation here and takes the winner's row. The create runs in its own
     * transaction so a nested call rolls back to a SAVEPOINT rather than
     * poisoning a caller's transaction on postgres.
     */
    public function root(Team $team): Obj
    {
        $root = $this->existingRoot($team->id);

        if ($root !== null) {
            return $this->withObjectable($root);
        }

        try {
            return DB::transaction(function () use ($team) {
                $folder = Folder::create(['name' => $team->name, 'team_id' => $team->id]);

                return $this->node($folder, null, $team->id);
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->existingRoot($team->id) ?? throw $e;
        }
    }

    /** The team's root node as the database has it, or null. */
    private function existingRoot(int $teamId): ?Obj
    {
        return $this->objects($teamId)
            ->whereNull('parent_id')
            ->where('objectable_type', 'folder')
            ->orderBy('id')
            ->first();
    }

    /**
     * What is filed directly inside this node: folders, then documents, then
     * files, each run sorted by name.
     *
     * Sorting happens in PHP because the names live in three different tables
     * behind one polymorphic column. A node whose objectable has gone (a
     * document deleted in Dot.Doc, a row from a half-finished import) is left
     * out rather than rendered as a blank line.
     *
     * @return Collection<int, Obj>
     */
    public function children(Obj $parent): Collection
    {
        $order = ['folder' => 0, 'document' => 1, 'file' => 2];

        $children = $this->objects($parent->team_id)
            ->where('parent_id', $parent->id)
            ->with(['objectable' => function ($morphTo) {
                /** @var MorphTo $morphTo */
                $morphTo->constrain([
                    Folder::class => fn (Builder $query) => $query->withoutGlobalScope('team'),
                    File::class => fn (Builder $query) => $query->withoutGlobalScope('team'),
                ]);
            }])
            ->get()
            ->reject(fn (Obj $obj) => $obj->objectable === null)
            ->sortBy([
                fn (Obj $a, Obj $b) => ($order[$a->kind()] ?? 3) <=> ($order[$b->kind()] ?? 3),
                fn (Obj $a, Obj $b) => strcasecmp($a->name(), $b->name()),
            ])
            ->values();

        return new Collection($children->all());
    }

    public function createFolder(Obj $parent, string $name, User $actor): Obj
    {
        $this->authorise($actor, $parent);
        $this->requireFolder($parent);

        $name = $this->uniqueName($parent, $this->cleanName($name));

        return DB::transaction(function () use ($parent, $name) {
            $folder = Folder::create(['name' => $name, 'team_id' => $parent->team_id]);

            return $this->node($folder, $parent, $parent->team_id);
        });
    }

    /**
     * Write an upload to the shared blob store and file it in the tree.
     *
     * The stored path is composed HERE from the team id and a random name,
     * never from the uploaded file name, so nothing a person can type decides
     * where the bytes land; only the extension survives, sanitised. The
     * `files/` prefix is kept because every row already in the table uses it
     * and the disk root is the same directory `local` always was.
     */
    public function createFile(Obj $parent, UploadedFile $upload, User $actor): Obj
    {
        $this->authorise($actor, $parent);
        $this->requireFolder($parent);

        $name = $this->uniqueName($parent, $this->safeName(
            basename($upload->getClientOriginalName()),
            'Upload',
        ));

        $path = 'files/'.$parent->team_id.'/'.Str::random(40).$this->extension($name);

        Storage::disk('files')->putFileAs(dirname($path), $upload, basename($path));

        return DB::transaction(function () use ($parent, $name, $path, $upload, $actor) {
            $file = File::create([
                'name' => $name,
                'size' => $upload->getSize(),
                'path' => $path,
                'mime_type' => $upload->getMimeType(),
                'owner_id' => $actor->id,
                'team_id' => $parent->team_id,
            ]);

            return $this->node($file, $parent, $parent->team_id);
        });
    }

    public function moveObject(Obj $obj, Obj $parent, User $actor): Obj
    {
        $this->authorise($actor, $obj);
        $this->authorise($actor, $parent);

        if ($obj->parent_id === null) {
            throw ValidationException::withMessages(['object' => 'The team root cannot be moved.']);
        }

        if (! $parent->isFolder()) {
            throw ValidationException::withMessages(['object' => 'Things can only be filed inside a folder.']);
        }

        if ($obj->team_id !== $parent->team_id) {
            throw ValidationException::withMessages(['object' => 'That folder belongs to a different team.']);
        }

        if ($parent->id === $obj->id || in_array($parent->id, $this->descendantIds($obj), true)) {
            throw ValidationException::withMessages(['object' => 'A folder cannot be filed inside itself.']);
        }

        $obj->update(['parent_id' => $parent->id]);

        return $obj->refresh();
    }

    public function renameObject(Obj $obj, string $name, User $actor): Obj
    {
        $this->authorise($actor, $obj);

        $objectable = $this->objectableOf($obj);

        if ($objectable === null) {
            throw ValidationException::withMessages(['name' => 'There is nothing here to rename.']);
        }

        if ($objectable instanceof Document) {
            // A document's title belongs to Dot.Doc, and App\Models\Document
            // refuses the write - renaming it here would be editing another
            // application's record through a file browser.
            throw ValidationException::withMessages([
                'name' => 'Rename this document in Dot.Doc — its title lives there.',
            ]);
        }

        $name = $this->cleanName($name);

        $parent = $this->parentOf($obj);

        if ($parent !== null) {
            $name = $this->uniqueName($parent, $name, $obj);
        }

        $objectable->update(['name' => $name]);

        return $this->withObjectable($obj->refresh());
    }

    /**
     * Delete semantics differ by what the node points at, and the difference
     * is the whole point of routing deletes through here:
     *
     * - a DOCUMENT loses only its tree row. Dot.Files does not own document
     *   content and never writes `documents`, so the document itself survives
     *   in Dot.Doc and has to be deleted there; the browser says so.
     * - a FOLDER with anything in it is REFUSED - cascading would take real
     *   content down with a piece of filing.
     * - a FILE takes its blob with it, since nothing else references it.
     */
    public function deleteObject(Obj $obj, User $actor): void
    {
        $this->authorise($actor, $obj);

        if ($obj->parent_id === null) {
            throw ValidationException::withMessages(['object' => 'The team root cannot be deleted.']);
        }

        if ($obj->isFolder() && $this->objects($obj->team_id)->where('parent_id', $obj->id)->exists()) {
            throw ValidationException::withMessages([
                'object' => 'Empty the folder first — deleting it would take everything inside it too.',
            ]);
        }

        $objectable = $this->objectableOf($obj);

        DB::transaction(function () use ($obj, $objectable) {
            if ($objectable instanceof File) {
                Storage::disk('files')->delete($objectable->path);
                $objectable->delete();
            } elseif ($objectable instanceof Folder) {
                $objectable->delete();
            }

            // A Document objectable is deliberately untouched. Obj's own
            // deleting hook makes the same distinction, so removing the node
            // here cannot reach across into Dot.Doc's table either.
            $obj->delete();
        });
    }

    /**
     * "Report", then "Report (2)", then "Report (3)".
     *
     * Neither product enforces sibling uniqueness in the database, so two
     * folders called Reports in one parent are legal and merely unusable for
     * a person. This is where a name is made unique BEFORE it is written, and
     * it is the same rule the Dot.Doc side applies (`App\Files\UniqueName`).
     */
    public function uniqueName(Obj $parent, string $name, ?Obj $ignore = null): string
    {
        $taken = [];

        foreach ($this->children($parent) as $sibling) {
            if ($ignore !== null && $sibling->id === $ignore->id) {
                continue;
            }

            $taken[mb_strtolower($sibling->name())] = true;
        }

        if (! isset($taken[mb_strtolower($name)])) {
            return $name;
        }

        for ($n = 2; $n < 1000; $n++) {
            $candidate = $this->withSuffix($name, ' ('.$n.')');

            if (! isset($taken[mb_strtolower($candidate)])) {
                return $candidate;
            }
        }

        return $this->withSuffix($name, ' ('.uniqid().')');
    }

    /**
     * The same name rule as every interactive path, for a name nobody is
     * standing there to correct - an upload's client file name, an imported
     * row.
     *
     * Interactive callers get cleanName()'s ValidationException so the person
     * can fix what they typed; nobody is there to ask on this path, so the
     * offending characters become spaces and a name left with nothing in it
     * becomes the fallback. What must NOT happen is a name carrying a path
     * separator or a control character reaching the shared tree, where the
     * invariant everything else relies on is that it cannot.
     */
    public function safeName(string $name, string $fallback = 'Folder'): string
    {
        $name = (string) preg_replace(self::BAD_NAME, ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $name = mb_substr($name, 0, self::MAX_NAME_LENGTH);

        try {
            return $this->cleanName($name);
        } catch (ValidationException) {
            return $fallback;
        }
    }

    /** @throws ValidationException */
    private function cleanName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Give it a name.']);
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw ValidationException::withMessages(['name' => 'That name is too long (255 characters at most).']);
        }

        if (preg_match(self::BAD_NAME, $name) === 1) {
            throw ValidationException::withMessages(['name' => 'A name cannot contain slashes or control characters.']);
        }

        return $name;
    }

    /**
     * `$name (2)`, shortened to fit the column.
     *
     * The BASE is trimmed, never the counter: the counter is the part that
     * makes the name unique, and handing postgres a 259-character string
     * because somebody named a folder right at the boundary is a raw
     * QueryException.
     */
    private function withSuffix(string $name, string $suffix): string
    {
        $room = self::MAX_NAME_LENGTH - mb_strlen($suffix);

        if ($room <= 0) {
            return mb_substr($suffix, 0, self::MAX_NAME_LENGTH);
        }

        return mb_strlen($name) <= $room
            ? $name.$suffix
            : rtrim(mb_substr($name, 0, $room)).$suffix;
    }

    /** The extension of a name, sanitised to letters and digits, or ''. */
    private function extension(string $name): string
    {
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';

        return $ext === '' ? '' : '.'.substr($ext, 0, 10);
    }

    /**
     * What a node points at, read WITHOUT the session-reading team scope.
     *
     * `File` and `Folder` carry {@see HasTeamScope}
     * too, so `$obj->objectable` resolves against the session's currentTeam
     * rather than the node's team - and comes back null whenever the two
     * disagree, which is exactly the case this service exists to handle
     * correctly. Every read of an objectable in here goes through this.
     */
    private function objectableOf(Obj $obj): ?Model
    {
        $class = Relation::getMorphedModel($obj->objectable_type) ?? $obj->objectable_type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->withoutGlobalScope('team')->find($obj->objectable_id);
    }

    /** The node with its objectable attached, scope-free. */
    private function withObjectable(Obj $obj): Obj
    {
        return $obj->setRelation('objectable', $this->objectableOf($obj));
    }

    /**
     * A tree query pinned to one team, with the session-reading global scope
     * removed.
     *
     * Both halves matter. Dropping `HasTeamScope` stops a caller's currentTeam
     * from hiding rows of the team it was actually asked about (and stops the
     * fail-open when there is no currentTeam at all); the explicit `where` is
     * what replaces it. No query in this class may omit either.
     */
    /** @return Builder<Obj> */
    private function objects(int $teamId): Builder
    {
        return Obj::withoutGlobalScope('team')->where('objects.team_id', $teamId);
    }

    /** Put a folder/file row into the tree under `$parent`. */
    private function node(mixed $objectable, ?Obj $parent, int $teamId): Obj
    {
        $obj = new Obj(['parent_id' => $parent?->id]);
        $obj->team_id = $teamId;
        $obj->objectable()->associate($objectable);
        $obj->save();

        return $obj;
    }

    /** The node's parent, read without the session-reading scope. */
    private function parentOf(Obj $obj): ?Obj
    {
        return $obj->parent_id === null
            ? null
            : $this->objects($obj->team_id)->find($obj->parent_id);
    }

    /**
     * Every id below this node.
     *
     * Uses the adjacency-list package this repo already depends on
     * (`descendants()`), with the team scope dropped and restated the same
     * way every other read in here does it.
     */
    private function descendantIds(Obj $obj): array
    {
        return $obj->descendants()
            ->withoutGlobalScope('team')
            ->where('team_id', $obj->team_id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The actor must belong to the team the OBJECT says it is in - never the
     * team the session happens to have selected.
     *
     * @throws AuthorizationException
     */
    private function authorise(User $actor, Obj $obj): void
    {
        $team = Team::find($obj->team_id);

        if ($team === null || ! $actor->belongsToTeam($team)) {
            throw new AuthorizationException('You do not have access to this team’s files.');
        }
    }

    private function requireFolder(Obj $parent): void
    {
        if (! $parent->isFolder()) {
            throw ValidationException::withMessages([
                'object' => 'Things can only be filed inside a folder.',
            ]);
        }
    }
}
