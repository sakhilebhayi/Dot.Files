<?php

namespace App\Http\Livewire;

use App\Models\BulkDeletionRequest;
use App\Models\File;
use App\Models\FilePin;
use App\Models\FileShare;
use App\Models\Folder;
use App\Models\Obj;
use App\Models\Team;
use App\Services\FileShareService;
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

        $object = $team->objects()->make(['parent_id' => $this->object->id]);
        $object->objectable()->associate($team->folders()->create($this->newFolderState));
        $object->save();

        $this->creatingNewFolder = false;

        $this->newFolderState = ['name' => ''];

        $this->object = $this->object->fresh();
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
        $this->view = session('files.view', 'grid') === 'list' ? 'list' : 'grid';

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
            $results = Obj::search($this->query)
                ->query(fn ($q) => $q->where('objects.team_id', $this->resolveCurrentTeam()?->id ?? 0)
                    ->whereNull('objects.trashed_at'))
                ->get()
                ->values()
                ->load('objectable');
        } else {
            $results = $this->object->children()->whereNull('trashed_at')->with('objectable')->get();
        }

        if ($this->kind === 'all') {
            return $results;
        }

        return $results->filter(fn ($o) => $o->objectable_type === 'file' && $o->objectable->kind() === $this->kind)->values();
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

        $object = $team->objects()->make(['parent_id' => $this->object->id]);
        $object->objectable()->associate(
            $team->files()->create([
                'name' => $safeName,
                'size' => $upload->getSize(),
                'mime' => $upload->getMimeType(),
                'uploaded_by' => auth()->id(),
                'path' => $upload->store('files', ['disk' => 'local']),
            ])
        );

        $object->save();
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

    public function render()
    {
        return view('livewire.file-browser');
    }
}
