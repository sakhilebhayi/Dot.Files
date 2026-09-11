<?php

namespace App\Http\Livewire;

use App\Models\BulkDeletionRequest;
use App\Models\Document;
use App\Models\File;
use App\Models\Obj;
use App\Models\Team;
use App\Services\EcosystemHandoffService;
use App\Services\FilesService;
use Illuminate\Support\Facades\URL;
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
     *
     * Note this is the only place in this component that reads currentTeam,
     * and it decides nothing about WHICH rows are touched - App\Services\
     * FilesService takes its team from `$this->object` on every call, so the
     * fail-open in HasTeamScope cannot widen what a write reaches.
     */
    private function resolveCurrentTeam(): ?Team
    {
        return auth()->user()?->currentTeam;
    }

    public function createFolder(FilesService $files)
    {
        $team = $this->resolveCurrentTeam();

        if (! $team) {
            abort(403, 'No active team selected.');
        }

        $this->validate([
            'newFolderState.name' => 'required|max:255',
        ]);

        $files->createFolder($this->object, $this->newFolderState['name'], auth()->user());

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

    /**
     * Whether the item awaiting confirmation is a Dot.Doc document, which the
     * dialog says out loud - deleting a `document` node unfiles it here and
     * leaves the document itself in Dot.Doc, where it has to be deleted.
     */
    public function getConfirmingIsDocumentProperty(): bool
    {
        if (! $this->confirmingObjectDeletion) {
            return false;
        }

        return Obj::find($this->confirmingObjectDeletion)?->isDocument() ?? false;
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

        BulkDeletionRequest::create([
            'team_id' => $team->id,
            'requested_by' => auth()->id(),
            'object_ids' => array_values($this->selectedObjects),
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
                // No `svg`. An SVG is a script that renders as a picture, and
                // FileController::view() serves stored bytes back in this
                // origin: one carrying a <script> would run with the viewer's
                // session. The Dot.Doc side found the same risk on the shared
                // `files` table and refuses it on upload too
                // (FileUploadController); the view route's allow-list is the
                // second half of the same guard.
                'mimes:pdf,csv,txt,text,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp,zip,tar,gz,mp4,mp3,mov,avi',
            ],
        ]);

        app(FilesService::class)->createFile($this->object, $upload, auth()->user());

        $this->object = $this->object->fresh();
    }

    public $renamingObjectState = ['name' => null];

    public function renameObject(FilesService $files)
    {
        $this->validate([
            'renamingObjectState.name' => 'required|max:255',
        ]);

        $obj = Obj::find($this->renamingObject);

        if (! $obj) {
            $this->renamingObject = null;

            return;
        }

        $files->renameObject($obj, $this->renamingObjectState['name'], auth()->user());

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
                'name' => $object->name(),
            ];
        }
    }

    /**
     * Hand this person over to Dot.Doc, signed in, on the document itself.
     *
     * The token is minted here rather than in the view because it is a live
     * single-use credential: a rendered page of twenty documents would carry
     * twenty of them for nineteen clicks nobody makes.
     */
    public function openInDocs(int $id, EcosystemHandoffService $handoff)
    {
        $obj = Obj::find($id);
        $document = $obj?->isDocument() ? $obj->objectable : null;

        if (! $document instanceof Document || ! $this->belongsToTheNodesTeam($obj)) {
            return;
        }

        return $this->redirect($handoff->documentUrl(auth()->user(), $document->uuid), navigate: false);
    }

    /**
     * Start a new document in Dot.Doc, filed in the folder standing open.
     *
     * `folder` is the `objects` row id Dot.Doc's documents index actually
     * reads today; `parent` carries the same node's uuid for the
     * create-with-parent route that side has yet to add. Both name the same
     * row in the one shared tree, so the link works now and gets better
     * without a change here.
     */
    public function newDocument(EcosystemHandoffService $handoff)
    {
        abort_unless($this->belongsToTheNodesTeam($this->object), 403);

        $redirect = '/documents?'.http_build_query([
            'folder' => $this->object->id,
            'parent' => $this->object->uuid,
        ]);

        return $this->redirect($handoff->urlFor(auth()->user(), 'docs', $redirect), navigate: false);
    }

    /**
     * Whether the person driving this component is in the team the NODE says
     * it belongs to.
     *
     * The handoff hands out a live credential and a deep link, so it gets the
     * same rule as every write: membership is decided by the row, not by
     * whichever team the session has selected - which is the case HasTeamScope
     * fails open on.
     */
    private function belongsToTheNodesTeam(?Obj $node): bool
    {
        $team = $node === null ? null : Team::find($node->team_id);

        return $team !== null && (auth()->user()?->belongsToTeam($team) ?? false);
    }

    /** A 10-minute signed link to read a file inline, or null for anything else. */
    public function viewUrl(Obj $node): ?string
    {
        $file = $node->kind() === 'file' ? $node->objectable : null;

        if (! $file instanceof File) {
            return null;
        }

        return URL::temporarySignedRoute('files.view', now()->addMinutes(10), [
            'file' => $file->uuid,
        ]);
    }

    public function render()
    {
        return view('livewire.file-browser');
    }
}
