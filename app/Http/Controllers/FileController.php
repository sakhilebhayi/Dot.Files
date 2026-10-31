<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Obj;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    /**
     * Users bridged in via EcosystemAuthController's cross-platform Sanctum
     * token login are not guaranteed to have ever gone through this app's
     * own team-creation flow, so `EnsureCurrentTeam` may find no personal
     * team to fall back to and leave current_team_id null. Without this
     * guard, Obj's HasTeamScope global scope simply skips its team filter
     * when currentTeam is null (see app/Models/Concerns/HasTeamScope.php),
     * so the root-object lookup below would silently resolve to whichever
     * team's root object happens to be found first -- a cross-tenant leak,
     * not just a crash risk.
     */
    private function resolveCurrentTeam(): ?Team
    {
        return Auth::user()?->currentTeam;
    }

    public function index(Request $request)
    {
        if (! $this->resolveCurrentTeam()) {
            return redirect()->route('teams.create');
        }

        $uuid = $request->query('uuid')
            ?? Obj::whereNull('parent_id')->value('uuid');

        $object = Obj::with('children.objectable', 'ancestorsAndSelf.objectable')
            ->where('uuid', $uuid)
            ->firstOrFail();

        return view('files', [
            'object' => $object,
            'ancestors' => $object->ancestorsAndSelf()->breadthFirst()->get(),
        ]);
    }

    public function download(File $file)
    {
        $this->authorize('download', $file);

        return Storage::disk('local')->download($file->path, $file->name);
    }

    /**
     * The document snapshot (R-26). Same policy as the download — the file's own company
     * decides — and streamed from the private disk, so no snapshot ever has a public URL.
     */
    public function preview(File $file)
    {
        $this->authorize('download', $file);

        abort_unless($file->hasPreview() && Storage::disk('local')->exists($file->preview_path), 404);

        return response()->file(Storage::disk('local')->path($file->preview_path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
