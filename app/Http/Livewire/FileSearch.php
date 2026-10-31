<?php

namespace App\Http\Livewire;

use App\Models\File;
use App\Models\FileShare;
use App\Models\Obj;
use Livewire\Component;

/**
 * The ecosystem search bar (R-28 §3): InfoDot's search component, operating on Dot.Files' own
 * records. The look and behaviour are InfoDot's (the pill, Ctrl K, the command dropdown, arrow
 * keys, reference jumping); everything it answers with is this company's own — the team scope
 * carries every query, a trashed record never surfaces, and shared-with-me matches are the
 * live shares made out to this person's verified email. Nothing of any other business leaves.
 */
class FileSearch extends Component
{
    public string $query = '';

    public int $highlightIndex = 0;

    public function updatedQuery(): void
    {
        $this->highlightIndex = 0;
    }

    /** A reference typed whole (e.g. MKDL-F-000012) jumps straight to that file. */
    public function getReferenceProperty(): ?File
    {
        $q = strtoupper(trim($this->query));

        if (! preg_match('/^[A-Z0-9]{2,8}-[A-Z]-\d{3,}$/', $q)) {
            return null;
        }

        $file = File::where('infodot_reference', $q)->first(); // team-scoped

        return $file !== null && ! $file->isTrashed() ? $file : null;
    }

    /** This company's matching records: files and folders, by name and folder path. */
    public function getItemsProperty()
    {
        $q = trim($this->query);

        if (mb_strlen($q) < 2) {
            return collect();
        }

        return Obj::search($q)
            ->query(fn ($query) => $query->where('objects.team_id', auth()->user()?->currentTeam?->id ?? 0)
                ->whereNull('objects.trashed_at'))
            ->take(8)
            ->get()
            ->values()
            ->load('objectable')
            ->map(fn (Obj $obj) => [
                'kind' => $obj->objectable_type === 'folder' ? 'folder' : $obj->objectable->kind(),
                'label' => (string) $obj->objectable->name,
                'ref' => $obj->objectable_type === 'file' ? $obj->objectable->infodot_reference : null,
                'url' => $obj->objectable_type === 'folder'
                    ? route('files', ['uuid' => $obj->uuid])
                    : route('files.open', $obj->objectable->uuid),
            ]);
    }

    /** Live shares made out to this person whose file name matches. */
    public function getSharedProperty()
    {
        $q = trim($this->query);
        $user = auth()->user();

        if (mb_strlen($q) < 2 || $user?->email_verified_at === null) {
            return collect();
        }

        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)).'%';

        return FileShare::with(['file' => fn ($r) => $r->withoutGlobalScopes()])
            ->where('email', strtolower((string) $user->email))->active()
            ->get()
            ->filter(fn (FileShare $s) => $s->file !== null && str_contains(mb_strtolower($s->file->name), mb_strtolower($q)))
            ->take(3)
            ->map(fn (FileShare $s) => [
                'kind' => $s->file->kind(),
                'label' => (string) $s->file->name,
                'url' => route('files.open', $s->file->uuid),
            ])->values();
    }

    /** The empty state's "new in your company" list — the latest records, really. */
    public function getRecentProperty()
    {
        return Obj::where('objectable_type', 'file')->whereNull('trashed_at')
            ->latest()->limit(4)->with('objectable')->get()
            ->map(fn (Obj $obj) => [
                'ref' => $obj->objectable->infodot_reference,
                'label' => (string) $obj->objectable->name,
                'url' => route('files.open', $obj->objectable->uuid),
            ]);
    }

    /** Everything the arrow keys can walk, in on-screen order. */
    private function flatItems()
    {
        $rows = collect();

        if ($this->reference) {
            $rows->push(['url' => route('files.open', $this->reference->uuid)]);
        }

        return $rows->concat($this->items)->concat($this->shared)->values();
    }

    public function incrementHighlight(): void
    {
        $count = $this->flatItems()->count();
        $this->highlightIndex = $count === 0 ? 0 : min($this->highlightIndex + 1, $count - 1);
    }

    public function decrementHighlight(): void
    {
        $this->highlightIndex = max(0, $this->highlightIndex - 1);
    }

    public function openHighlighted()
    {
        $item = $this->flatItems()->get($this->highlightIndex);

        if ($item !== null) {
            return redirect()->to($item['url']);
        }
    }

    public function render()
    {
        return view('livewire.file-search');
    }
}
