<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Obj;
use App\Models\Team;
use App\Services\FileShareService;
use App\Services\FileVersions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The destination of an InfoDot deep link: /o/{file uuid}. InfoDot already checked that
 * the person belongs to the company, and this checks again on its own: the file's company
 * decides, not the session's current team. A member of the company lands on the file with
 * that company made current; anyone else gets a request-access page that says nothing
 * about the file.
 */
class FileOpenController extends Controller
{
    public function __invoke(Request $request, string $uuid, FileShareService $shares)
    {
        abort_unless(Str::isUuid($uuid), 404);

        $file = File::withoutGlobalScope('team')->where('uuid', $uuid)->first();

        abort_if($file === null, 404);

        $user = $request->user();

        if (! $user->allTeams()->contains('id', $file->team_id)) {
            // Outside the company: the only way in is a live share made out to this person's
            // verified email — and a trashed file is off the record for outsiders (R-27).
            $share = $file->isTrashed() ? null : $shares->activeShareFor($user, $file);

            if ($share === null) {
                $shares->denied($file, $user);

                return response()->view('files.request-access', [], 403);
            }

            $shares->opened($user, $share);

            return view('files.shared', ['file' => $file, 'share' => $share]);
        }

        if ($user->current_team_id !== $file->team_id) {
            $user->switchTeam(Team::findOrFail($file->team_id));
        }

        $obj = Obj::where('objectable_type', 'file')->where('objectable_id', $file->id)->first();
        $parent = $obj?->parent;

        // A short, harmless excerpt for plain-text documents: read server-side, escaped by
        // Blade, never executed. Everything else renders inline or keeps its glyph.
        $excerpt = null;

        if (in_array($file->mime, ['text/plain', 'text/csv'], true) && Storage::disk('local')->exists($file->path)) {
            $excerpt = (string) file_get_contents(Storage::disk('local')->path($file->path), false, null, 0, 2000);
        }

        return view('files.open', [
            'file' => $file,
            'folderName' => $parent?->objectable?->name,
            'folderUuid' => $parent?->uuid,
            'trashed' => (bool) $obj?->isTrashed(),
            'pinned' => $file->isPinnedBy($user),
            'uploader' => $file->uploader,
            'excerpt' => $excerpt,
            // The append-only content trail (R-28): who changed what, when, from where.
            'versions' => app(FileVersions::class)->trail($file),
            // The sharing trail is owner/admin reading: it names outside addresses.
            'events' => $shares->canShare($user, $file)
                ? DB::table('file_share_events')->where('file_uuid', (string) $file->uuid)->orderByDesc('id')->limit(6)->get()
                : collect(),
        ]);
    }
}
