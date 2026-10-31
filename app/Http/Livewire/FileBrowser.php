<?php

namespace App\Http\Livewire;

use App\Models\BulkDeletionRequest;
use App\Models\File;
use App\Models\FileShare;
use App\Models\Folder;
use App\Models\Obj;
use App\Models\Team;
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
            'documents' => File::count(),
            'folders' => max(0, Folder::count() - 1), // the root is the room, not a folder someone made
            'bytes' => (int) File::sum('size'),
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

    public function getResultsProperty()
    {
        if (! empty($this->query)) {
            return Obj::search($this->query)
                ->query(fn ($q) => $q->where('objects.team_id', $this->resolveCurrentTeam()?->id ?? 0))
                ->get()
                ->values()
                ->load('objectable');
        }

        return $this->object->children;
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

        // Deleting an Obj cascades to every descendant and unlinks each blob, so "single delete"
        // is only truly single for a file or an empty folder. The team's ROOT is never deletable
        // here (that is the team-deletion flow), and a folder that still has children goes through
        // the operator-approved bulk gate instead of vanishing on one click (R-25).
        abort_if($obj->parent_id === null, 403, 'The root folder cannot be deleted.');

        if ($obj->objectable_type === 'folder' && $obj->children()->exists()) {
            BulkDeletionRequest::create([
                'team_id' => $obj->team_id,
                'requested_by' => auth()->id(),
                'object_ids' => [$obj->id],
                'object_names' => [$obj->objectable->name ?? ''],
            ]);
            $this->bulkDeletionRequested = true;

            return;
        }

        $obj->delete();
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
