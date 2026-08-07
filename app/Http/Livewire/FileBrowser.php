<?php

namespace App\Http\Livewire;

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

    public function getResultsProperty()
    {
        if (! empty($this->query)) {
            return Obj::search($this->query)
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

        $obj->delete();
        $this->object = $this->object->fresh();
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

        $obj->objectable->update($this->renamingObjectState);

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
