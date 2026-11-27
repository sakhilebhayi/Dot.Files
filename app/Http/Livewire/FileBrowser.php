<?php

namespace App\Http\Livewire;

use App\Models\BulkDeletionRequest;
use App\Models\File;
use App\Models\FileFlag;
use App\Models\FilePin;
use App\Models\FileShare;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\Obj;
use App\Models\Team;
use App\Models\User;
use App\Services\FileShareService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

class FileBrowser extends Component
{
    use WithFileUploads;

    public $query;

    public $upload;

    public $object;

    public $ancestors;

    public $creatingNewFolder = false;

    public $newFolderState = [
        'name' => '',
    ];

    /**
     * FileController::index() already redirects to teams.create when the
     * page loads with no current team, but that guard doesn't cover this
     * component's own wire:click/wire:submit-triggered action methods
     * (createFolder, updatedUpload), which can still be invoked over the
     * Livewire wire protocol against a component that was mounted while a
     * team was current but whose team assignment changed since (e.g. the
     * user was removed from their last team mid-session). Mirrors the
     * null-currentTeam guard pattern used across the ecosystem.
     */
    private function resolveCurrentTeam(): ?Team
    {
        return auth()->user()?->currentTeam;
    }

    public function createFolder()
    {
        $team = $this->resolveCurrentTeam();

        if (! $team) {
            abort(403, 'No active team selected.');
        }

        $this->validate([
            'newFolderState.name' => 'required|max:255',
        ]);

        $parent = $this->liveFolder($team);

        // EA-2: the folder row and its place in the tree are written together or not at all. Only the name is taken
        // from the browser-held state.
        DB::transaction(function () use ($team, $parent) {
            $object = $team->objects()->make(['parent_id' => $parent->id]);
            $object->objectable()->associate($team->folders()->create(['name' => (string) $this->newFolderState['name']]));
            $object->save();
        });

        $this->creatingNewFolder = false;

        $this->newFolderState = ['name' => ''];

        $this->object = $this->object->fresh();
    }

    /**
     * The folder being filed into, re-read from the database (EA-2): it must be this company's, a folder, and neither
     * in the trash nor inside a folder that is. A colleague may have trashed it since the page loaded.
     */
    private function liveFolder(Team $team): Obj
    {
        $folder = Obj::where('id', $this->object->id)
            ->where('team_id', $team->id)
            ->where('objectable_type', 'folder')
            ->whereNull('trashed_at')
            ->first();

        if ($folder === null || $folder->ancestors()->whereNotNull('trashed_at')->exists()) {
            throw ValidationException::withMessages(['upload' => __('This folder is no longer available. It may have been moved to the trash.')]);
        }

        return $folder;
    }

    public function getCurrentTeamProperty()
    {
        return $this->resolveCurrentTeam();
    }

    public $renamingObject;

    public $showingObjectloadForm = false;

    public $confirmingObjectDeletion;

    /** Shelf (grid) or register (list) — a per-person preference, kept for the session. */
    public string $view = 'grid';

    public function mount(): void
    {
        // The register is the working view (R-51); the shelf stays one click away and is remembered.
        $this->view = session('files.view', 'list') === 'grid' ? 'grid' : 'list';

        $sort = session('files.sort', 'name');
        $this->sort = in_array($sort, self::SORTS, true) ? $sort : 'name';
        $this->dir = session('files.dir', 'asc') === 'desc' ? 'desc' : 'asc';

        // The top bar's ecosystem search submits here (GET ?search=…): land with the
        // workspace already filtered to what was typed.
        if (($q = trim((string) request('search'))) !== '') {
            $this->query = $q;
        }
    }

    public function setView(string $view): void
    {
        $this->view = $view === 'list' ? 'list' : 'grid';
        session(['files.view' => $this->view]);
    }

    /**
     * The room head's tallies — real queries against this company's records, nothing invented
     * (R-26). The models carry the team scope, so each count is the current company's own.
     */
    public function getStatsProperty(): array
    {
        return [
            'documents' => Obj::where('objectable_type', 'file')->whereNull('trashed_at')->count(),
            'folders' => Obj::where('objectable_type', 'folder')->whereNull('trashed_at')->whereNotNull('parent_id')->count(),
            'bytes' => (int) File::sum('size'), // disk truth: the trash still occupies it
            // The ledger's one data figure: how many documents are visible outside the company.
            'shared' => FileShare::active()->whereIn('file_id', File::pluck('id'))->distinct('file_id')->count('file_id'),
        ];
    }

    /**
     * First run (R-50): this company has never filed anything — no folder, no document, nothing
     * in the trash. Only then does the workspace open on the fuller arrival instead of the band.
     * Obj carries the team scope, so this reads the current company's own records only.
     */
    public function getIsFirstRunProperty(): bool
    {
        // EA-2: "New folder" on the first-run screen opens the ordinary workspace, where the folder form lives.
        return empty($this->query)
            && ! $this->creatingNewFolder
            && $this->object->parent_id === null
            && ! Obj::whereNotNull('parent_id')->exists();
    }

    /** Pending share requests waiting on an owner/admin of this company (R-26). */
    public function getPendingSharesProperty(): int
    {
        $team = $this->resolveCurrentTeam();
        $user = auth()->user();

        if (! $team || $team->personal_team || ! ($user->ownsTeam($team) || $user->hasTeamRole($team, 'admin'))) {
            return 0;
        }

        return FileShare::pending()->whereIn('file_id', File::pluck('id'))->count();
    }

    /** Shelf filter: all, or one document kind (folders show only on 'all'). */
    public string $kind = 'all';

    public const KINDS = ['all', 'pdf', 'doc', 'sheet', 'image', 'media', 'archive'];

    public function updatedKind($value): void
    {
        if (! in_array($value, self::KINDS, true)) {
            $this->kind = 'all';
        }
    }

    public function getResultsProperty()
    {
        if (! empty($this->query)) {
            // EA-2: one query over this company's own records (path and name), never an index shared by every company.
            $results = Obj::matching((string) $this->query)
                ->where('team_id', $this->resolveCurrentTeam()?->id ?? 0)
                ->whereNull('trashed_at')
                ->whereNotNull('parent_id')
                ->orderBy('id')
                ->limit(500)
                ->with('objectable')
                ->get();
        } else {
            $results = $this->object->children()->whereNull('trashed_at')->with('objectable')->get();
        }

        if ($this->kind !== 'all') {
            $results = $results->filter(fn ($o) => $o->objectable_type === 'file' && $o->objectable->kind() === $this->kind)->values();
        }

        return $this->sorted($results);
    }

    // ---- The register's sort (R-51): kept for the session like the view; folders always lead. ----

    public const SORTS = ['name', 'size', 'filed'];

    public string $sort = 'name';

    public string $dir = 'asc';

    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTS, true)) {
            return;
        }

        $this->dir = $this->sort === $column && $this->dir === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
        session(['files.sort' => $this->sort, 'files.dir' => $this->dir]);
    }

    private function sorted($results)
    {
        $key = match ($this->sort) {
            'size' => fn ($o) => $o->objectable_type === 'file' ? (int) $o->objectable->size : -1,
            'filed' => fn ($o) => $o->created_at?->getTimestamp() ?? 0,
            default => fn ($o) => mb_strtolower((string) $o->objectable?->name),
        };

        return $results
            ->sortBy($key, SORT_NATURAL | SORT_FLAG_CASE, $this->dir === 'desc')
            ->sortBy(fn ($o) => $o->objectable_type === 'folder' ? 0 : 1, SORT_REGULAR, false)
            ->values();
    }

    /**
     * The documents this person pinned to the front (R-27). A pin orders, it never grants:
     * the File query carries the team scope, so a pin to a file the current company does not
     * own simply resolves to nothing.
     */
    public function getPinsProperty()
    {
        $ids = FilePin::where('user_id', auth()->id())->latest()->limit(8)->pluck('file_id');

        return File::whereIn('id', $ids)->get()
            ->filter(fn ($f) => ! $f->isTrashed())
            ->sortBy(fn ($f) => $ids->search($f->id))->values();
    }

    public function togglePin(int $fileId): void
    {
        $file = File::find($fileId); // team-scoped: another company's id resolves to null

        if (! $file) {
            return;
        }

        $pin = FilePin::where('user_id', auth()->id())->where('file_id', $file->id)->first();

        $pin ? $pin->delete() : FilePin::create(['user_id' => auth()->id(), 'file_id' => $file->id]);
    }

    public function deleteObject()
    {
        $obj = Obj::find($this->confirmingObjectDeletion);
        $this->confirmingObjectDeletion = null;

        if (! $obj) {
            return;
        }

        // The id came from the browser. Whatever the global scope did, the object
        // must belong to the company the person is acting for.
        abort_unless($obj->team_id === $this->resolveCurrentTeam()?->id, 403);

        // The team's ROOT is never deletable here (that is the team-deletion flow), and a folder
        // that still has children goes through the operator-approved bulk gate instead of
        // vanishing on one click (R-25). A file or an empty folder goes to the TRASH (R-27):
        // held for 30 days, restorable from /trash, access through shares blocked meanwhile.
        abort_if($obj->parent_id === null, 403, 'The root folder cannot be deleted.');

        if ($obj->objectable_type === 'folder' && $obj->children()->whereNull('trashed_at')->exists()) {
            BulkDeletionRequest::create([
                'team_id' => $obj->team_id,
                'requested_by' => auth()->id(),
                'object_ids' => [$obj->id],
                'object_names' => [$obj->objectable->name ?? ''],
            ]);
            $this->bulkDeletionRequested = true;

            return;
        }

        $obj->trash(auth()->id());
        $this->object = $this->object->fresh();
    }

    public array $selectedObjects = [];

    public bool $bulkDeletionRequested = false;

    public function requestBulkDeletion(): void
    {
        if (empty($this->selectedObjects)) {
            return;
        }

        $team = $this->resolveCurrentTeam();

        if (! $team) {
            abort(403, 'No active team selected.');
        }

        // The selection is client-controlled: keep only numeric ids that really belong to this
        // team, and snapshot the names so the operator approves something legible, not bare ids.
        $objects = Obj::with('objectable')
            ->whereIn('id', array_filter(array_values($this->selectedObjects), 'is_numeric'))
            ->where('team_id', $team->id)->get();

        if ($objects->isEmpty()) {
            $this->selectedObjects = [];

            return;
        }

        BulkDeletionRequest::create([
            'team_id' => $team->id,
            'requested_by' => auth()->id(),
            'object_ids' => $objects->pluck('id')->all(),
            'object_names' => $objects->map(fn ($o) => (string) ($o->objectable->name ?? ''))->all(),
        ]);

        $this->selectedObjects = [];
        $this->bulkDeletionRequested = true;
    }

    // ---- Sharing a selection (R-28 §23): one recipient, one preset, many records at once. ----

    public bool $sharingSelection = false;

    public string $shareEmail = '';

    public int $shareDays = FileShare::DEFAULT_DAYS;

    public string $sharePermission = 'download';

    public ?string $shareNotice = null;

    public function shareSelected(FileShareService $service): void
    {
        $this->validate([
            'shareEmail' => ['required', 'email', 'max:255'],
            'shareDays' => ['required', 'integer', 'in:7,30,60,90'],
            'sharePermission' => ['required', 'in:view,download'],
        ]);

        $team = $this->resolveCurrentTeam();

        if (! $team) {
            abort(403, 'No active team selected.');
        }

        // The selection is client-controlled: keep only this team's untrashed FILES. Folders
        // never share (a share is one file, never a container), so they fall out here.
        $files = Obj::with('objectable')
            ->whereIn('id', array_filter(array_values($this->selectedObjects), 'is_numeric'))
            ->where('team_id', $team->id)
            ->whereNull('trashed_at')
            ->where('objectable_type', 'file')
            ->get()
            ->map(fn (Obj $o) => $o->objectable)
            ->filter()
            ->values();

        if ($files->isEmpty()) {
            $this->addError('shareEmail', __('Pick at least one record — folders cannot be shared.'));

            return;
        }

        $user = auth()->user();
        $shared = 0;
        $requested = 0;
        $failures = [];

        // Each file is its own share with the full single-share rules; one file's refusal
        // (say, its share limit) never blocks the rest of the batch.
        foreach ($files as $file) {
            try {
                if ($service->canShare($user, $file)) {
                    $service->share($user, $file, $this->shareEmail, $this->shareDays, $this->sharePermission);
                    $shared++;
                } else {
                    $service->requestShare($user, $file, $this->shareEmail, $this->shareDays, $this->sharePermission);
                    $requested++;
                }
            } catch (ValidationException $e) {
                $failures[] = $file->name.' — '.collect($e->errors())->flatten()->first();
            }
        }

        if ($shared === 0 && $requested === 0) {
            $this->addError('shareEmail', $failures[0] ?? __('Nothing could be shared.'));

            return;
        }

        $email = strtolower(trim($this->shareEmail));
        $label = strtolower($this->sharePermission === 'view' ? __('View only') : __('View and download'));
        $notice = $shared > 0
            ? trans_choice(':count record shared|:count records shared', $shared, ['count' => $shared]).' '.__('with').' '.$email.' — '.$label.', '.trans_choice(':count day|:count days', $this->shareDays, ['count' => $this->shareDays]).'.'
            : trans_choice(':count share requested|:count shares requested', $requested, ['count' => $requested]).' '.__('for').' '.$email.' — '.__('an owner or administrator decides; they see nothing until then.');

        if ($failures !== []) {
            $notice .= ' '.__('Not shared:').' '.implode(' · ', $failures);
        }

        $this->shareNotice = $notice;
        $this->selectedObjects = [];
        $this->sharingSelection = false;
        $this->shareEmail = '';
    }

    public function updatedUpload($upload)
    {
        $team = $this->resolveCurrentTeam();

        if (! $team) {
            abort(403, 'No active team selected.');
        }

        $this->validate([
            'upload' => [
                'required',
                'file',
                'max:102400',
                'mimes:pdf,csv,txt,text,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp,svg,zip,tar,gz,mp4,mp3,mov,avi',
            ],
        ]);

        $safeName = basename($upload->getClientOriginalName());
        $parent = $this->liveFolder($team);
        $path = $upload->store('files', ['disk' => 'local']);

        // EA-2: the file row and its place in the tree are written together; if that fails, the stored content goes.
        try {
            DB::transaction(function () use ($team, $parent, $upload, $safeName, $path) {
                $object = $team->objects()->make(['parent_id' => $parent->id]);
                $object->objectable()->associate(
                    $team->files()->create([
                        'name' => $safeName,
                        'size' => $upload->getSize(),
                        'mime' => $upload->getMimeType(),
                        'uploaded_by' => auth()->id(),
                        'path' => $path,
                    ])
                );
                $object->save();
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }

        $this->object = $this->object->fresh();
    }

    public $renamingObjectState = ['name' => null];

    public function renameObject()
    {
        $this->validate([
            'renamingObjectState.name' => 'required|max:255',
        ]);

        $obj = Obj::find($this->renamingObject);

        if (! $obj) {
            $this->renamingObject = null;

            return;
        }

        // $renamingObjectState is a public Livewire property, so the CLIENT controls its keys. A
        // rename may change exactly one thing: the display name. Passing the whole array through
        // update() would let a crafted payload rewrite fillable columns such as files.path or
        // files.team_id — moving the row to another tenant or pointing it at another blob (R-25).
        $obj->objectable->update(['name' => $this->renamingObjectState['name']]);

        $this->object = $this->object->fresh();

        $this->renamingObject = null;
    }

    public function updatingRenamingObject($id)
    {
        if ($id === null) {
            $this->renamingObjectState = [
                'name' => '',
            ];
        }

        if ($object = Obj::find($id)) {
            $this->renamingObjectState = [
                'name' => $object->objectable->name,
            ];
        }
    }

    // ---- The dashboard (R-51): a selected record, the room, the headline, the foot. ----
    //
    // Tenant isolation is the hard requirement here. FileVersion and FileShare carry NO team scope, and
    // FileFlag only a team_id column, so every query below starts from team-scoped file ids
    // (`File::query()->select('id')`, which fails closed for a person with no current team) and every
    // FileFlag query filters on team_id. Nothing here ever asks a global question of those tables.

    /** The register row (an Obj id) whose record fills the panel. Client-controlled, so never trusted: see getFocusedProperty(). */
    public ?int $focusId = null;

    public ?string $focusNotice = null;

    public function focus(int $id): void
    {
        $this->focusNotice = null;

        // Resolved through this company's own, untrashed records: another company's id, a folder and a
        // record that vanished since the page loaded all leave the panel on the room.
        $obj = Obj::where('objectable_type', 'file')->whereNull('trashed_at')->find($id);

        if (! $obj || ! $this->results->contains('id', $obj->id)) {
            $this->focusId = null;
            $this->focusNotice = __('That record is no longer here.');

            return;
        }

        $this->focusId = $obj->id;
    }

    public function clearFocus(): void
    {
        $this->focusId = null;
        $this->focusNotice = null;
    }

    /** True when this person decides share requests and reads governance flags (owner or administrator of a company). */
    private function decides(): bool
    {
        $team = $this->resolveCurrentTeam();
        $user = auth()->user();

        return $team && ! $team->personal_team && ($user->ownsTeam($team) || $user->hasTeamRole($team, 'admin'));
    }

    private function inCompany(): bool
    {
        $team = $this->resolveCurrentTeam();

        return $team && ! $team->personal_team;
    }

    /** Active, not-ended shares as this person may see them: a decider sees all of the company's, a member only their own requests. */
    private function visibleShares()
    {
        $q = FileShare::whereIn('file_id', File::query()->select('id'));

        return $this->decides() ? $q : $q->where('requested_by', auth()->id());
    }

    /** The panel's record: a file in the rows currently listed, with its facts, or null. */
    public function getFocusedProperty(): ?array
    {
        $team = $this->resolveCurrentTeam();

        if (! $team || $this->focusId === null) {
            return null;
        }

        $obj = $this->results->firstWhere('id', $this->focusId);

        if (! $obj || $obj->objectable_type !== 'file' || ! $obj->objectable) {
            return null;
        }

        $file = $obj->objectable; // team-scoped morph: another company's file would not have loaded
        $decides = $this->decides();

        return [
            'obj' => $obj,
            'file' => $file,
            'uploader' => $file->uploader?->name,
            'folder' => $obj->parent_id ? Obj::with('objectable')->find($obj->parent_id)?->objectable?->name : null,
            'versions' => FileVersion::where('file_id', $file->id)->with('author')->orderByDesc('version')->limit(3)->get(),
            'outside' => $this->inCompany() ? ($this->outsideMap[$file->id] ?? ['active' => 0, 'pending' => 0, 'soonest' => null]) : null,
            'flagged' => $decides && FileFlag::open()->where('team_id', $team->id)->where('file_id', $file->id)->exists(),
            'pinned' => FilePin::where('user_id', auth()->id())->where('file_id', $file->id)->exists(),
        ];
    }

    /**
     * For the rows listed: how many outside shares each file has and when the soonest ends. One grouped
     * query over file ids that came from the team-scoped results, so another company's shares never join.
     *
     * @return array<int, array{active: int, pending: int, soonest: ?Carbon}>
     */
    public function getOutsideMapProperty(): array
    {
        if (! $this->inCompany()) {
            return [];
        }

        $ids = $this->results->where('objectable_type', 'file')->pluck('objectable_id')->all();

        if ($ids === []) {
            return [];
        }

        $map = [];
        // Only ids that are really this company's files (belt and braces over the results above).
        $own = File::query()->whereIn('id', $ids)->pluck('id')->all();

        FileShare::whereIn('file_id', $own)->active()
            ->selectRaw('file_id, count(*) as n, min(expires_at) as soonest')->groupBy('file_id')->get()
            ->each(function ($r) use (&$map) {
                $map[(int) $r->file_id] = ['active' => (int) $r->n, 'pending' => 0, 'soonest' => Carbon::parse($r->soonest)];
            });

        // A pending request is shown to those who decide, and to the person who asked.
        $pending = FileShare::whereIn('file_id', $own)->pending();
        $pending = $this->decides() ? $pending : $pending->where('requested_by', auth()->id());
        $pending->selectRaw('file_id, count(*) as n')->groupBy('file_id')->get()
            ->each(function ($r) use (&$map) {
                $map[(int) $r->file_id] ??= ['active' => 0, 'pending' => 0, 'soonest' => null];
                $map[(int) $r->file_id]['pending'] = (int) $r->n;
            });

        return $map;
    }

    /** Records inside each folder row listed (untrashed), one grouped query over team-scoped Obj rows. */
    public function getFolderCountsProperty(): array
    {
        $ids = $this->results->where('objectable_type', 'folder')->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        return Obj::whereIn('parent_id', $ids)->whereNull('trashed_at')
            ->selectRaw('parent_id, count(*) as n')->groupBy('parent_id')->pluck('n', 'parent_id')
            ->map(fn ($n) => (int) $n)->all();
    }

    /** Names of whoever filed the listed documents (ids come from this company's own files). */
    public function getUploaderNamesProperty(): array
    {
        $ids = $this->results->where('objectable_type', 'file')->map(fn ($o) => $o->objectable?->uploaded_by)->filter()->unique()->all();

        return $ids === [] ? [] : User::whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    public function getTrashedCountProperty(): int
    {
        return Obj::whereNotNull('trashed_at')->count();
    }

    /** Open governance flags for this company; deciders only (members never see conduct questions). */
    public function getOpenFlagCountProperty(): int
    {
        $team = $this->resolveCurrentTeam();

        if (! $team || ! $this->decides()) {
            return 0;
        }

        return FileFlag::open()->where('team_id', $team->id)->count();
    }

    /** Own pending requests, for a member; 0 for a decider (they have pendingShares) and for a personal workspace. */
    public function getMyPendingCountProperty(): int
    {
        if (! $this->inCompany() || $this->decides()) {
            return 0;
        }

        return FileShare::whereIn('file_id', File::query()->select('id'))->pending()->where('requested_by', auth()->id())->count();
    }

    /** The headline sentence: the first true state wins, every number real (design section 2). */
    public function getHeadlineProperty(): string
    {
        $team = $this->resolveCurrentTeam();
        $company = $team?->name ?? '';
        $docs = $this->stats['documents'];

        if ($this->decides()) {
            $requests = $this->pendingShares;
            $flags = $this->openFlagCount;

            if ($requests + $flags > 0) {
                $parts = [];

                if ($requests > 0) {
                    $parts[] = trans_choice(':count share request|:count share requests', $requests, ['count' => $requests]);
                }

                if ($flags > 0) {
                    $parts[] = trans_choice(':count flagged activity|:count flagged activities', $flags, ['count' => $flags]);
                }

                $verb = count($parts) === 1 && $requests + $flags === 1 ? __('waits on you.') : __('wait on you.');

                return implode(' '.__('and').' ', $parts).' '.$verb;
            }
        } elseif ($this->myPendingCount > 0) {
            return trans_choice(':count of your share requests waits for approval.|:count of your share requests wait for approval.', $this->myPendingCount, ['count' => $this->myPendingCount]);
        }

        if ($this->inCompany()) {
            $ending = $this->visibleShares()->active()->where('expires_at', '<=', now()->addDays(self::HEADLINE_DAYS))->orderBy('expires_at')->get(['expires_at']);

            if ($ending->isNotEmpty()) {
                $days = max(1, (int) ceil(now()->diffInHours($ending->first()->expires_at) / 24));

                return $ending->count() === 1
                    ? __('1 outside share ends in :days.', ['days' => trans_choice(':count day|:count days', $days, ['count' => $days])])
                    : __(':count outside shares end within :days days, the first in :first.', ['count' => $ending->count(), 'days' => self::HEADLINE_DAYS, 'first' => trans_choice(':count day|:count days', $days, ['count' => $days])]);
            }
        }

        if ($this->isFirstRun || $docs === 0 && ! $this->hasRecords()) {
            return __('Nothing is filed for :company yet.', ['company' => $company]);
        }

        return trans_choice(':count document is filed for :company.|:count documents are filed for :company.', $docs, ['count' => number_format($docs), 'company' => $company]);
    }

    private function hasRecords(): bool
    {
        return Obj::whereNotNull('parent_id')->exists();
    }

    /** The headline counts a share as "ending" inside this window; the room's Ending soon list uses the longer one. */
    public const HEADLINE_DAYS = 7;

    public const ENDING_DAYS = 14;

    /**
     * The room: what needs a person, what is ending, what changed lately, what this person pinned.
     * Sections with nothing to say are simply empty collections; the view omits or states them.
     */
    public function getRoomProperty(): array
    {
        $company = $this->inCompany();
        $decides = $this->decides();
        $team = $this->resolveCurrentTeam();

        $pending = collect();
        $flags = collect();
        $ending = collect();

        if ($company) {
            $pendingQ = FileShare::whereIn('file_id', File::query()->select('id'))->pending();
            $pending = ($decides ? $pendingQ : $pendingQ->where('requested_by', auth()->id()))
                ->with(['file' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('created_at')->get();

            $ending = $this->visibleShares()->active()->where('expires_at', '<=', now()->addDays(self::ENDING_DAYS))
                ->with(['file' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('expires_at')->limit(3)->get();

            if ($decides) {
                $flags = FileFlag::open()->where('team_id', $team->id)->with(['file' => fn ($q) => $q->withoutGlobalScopes()])
                    ->orderByDesc('created_at')->get();
            }
        }

        // Recently changed: newest versions of this company's untrashed files, with the author. Never FileVersion::latest().
        $recent = FileVersion::whereIn('file_id', File::query()->select('id'))
            ->whereIn('file_id', Obj::where('objectable_type', 'file')->whereNull('trashed_at')->select('objectable_id'))
            ->with(['author', 'file' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get();

        return [
            'pending' => $pending,
            'flags' => $flags,
            'ending' => $ending,
            'recent' => $recent,
            'pins' => $this->pins,
            'showEnding' => $company,
        ];
    }

    /** The spines drawn in the arrival: the newest documents (team-scoped), with their size and whether they are shared outside. */
    public function getSpinesProperty()
    {
        $files = File::query()->whereIn('id', Obj::where('objectable_type', 'file')->whereNull('trashed_at')->select('objectable_id'))
            ->orderByDesc('created_at')->limit(24)->get(['id', 'size']);

        $shared = $this->inCompany()
            ? FileShare::whereIn('file_id', $files->pluck('id'))->active()->distinct()->pluck('file_id')->all()
            : [];

        return $files->map(fn ($f) => ['size' => (int) $f->size, 'shared' => in_array($f->id, $shared, true)])->values();
    }

    public function render()
    {
        return view('livewire.file-browser');
    }
}
