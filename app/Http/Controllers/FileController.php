<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Obj;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FileController extends Controller
{
    /**
     * The only types rendered IN this origin.
     *
     * Everything else is handed over as a download, so a stored file can
     * never run as a document with the viewer's session: SVG above all (a
     * script that renders as a picture), and HTML by the same argument. The
     * upload form refuses both, but `files` is a SHARED table - a row written
     * by Dot.Doc, or by an older Dot.Files that still accepted `.svg`, can
     * name any type at all - so the inline decision is made here against an
     * allow-list rather than taken from the stored string. This list is
     * `FileViewController::INLINE_TYPES` on the Dot.Doc side, verbatim.
     *
     * @var list<string>
     */
    private const INLINE_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'application/pdf',
        'text/plain', 'text/markdown', 'text/csv',
    ];

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

        return Storage::disk('files')->download($file->path, $file->name);
    }

    /**
     * Read one file out of the shared blob store, in the browser.
     *
     * TWO gates, and both are needed. The `signed` middleware on the route
     * proves the link was minted by this application and has not expired (ten
     * minutes - the same window the Dot.Doc side uses), which is what stops a
     * link pasted into a chat from being a permanent public URL. That alone
     * would still let ANY signed-in person open a link meant for someone
     * else, so team membership is checked here as well, off the file's OWN
     * team_id and never the session's current team - which is also why the
     * lookup drops HasTeamScope rather than relying on it to 404.
     *
     * The path is resolved with realpath() containment: `files.path` is
     * composed by FilesService and is not user input, but a row on a SHARED
     * database was not necessarily written by this application, so what it
     * resolves to is proved to be under the disk root before anything is
     * read.
     */
    public function view(Request $request, string $file): BinaryFileResponse
    {
        $record = File::withoutGlobalScope('team')->where('uuid', $file)->firstOrFail();

        abort_unless($record->team && $request->user()?->belongsToTeam($record->team), 403);

        $path = $this->containedPath($record->path);

        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => $record->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => $this->disposition($record->mime_type).'; filename="'.addslashes($record->name).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** `inline` only for a type on the allow-list; `attachment` for anything else. */
    private function disposition(?string $mime): string
    {
        $type = strtolower(trim(explode(';', (string) $mime, 2)[0]));

        return in_array($type, self::INLINE_TYPES, true) ? 'inline' : 'attachment';
    }

    /**
     * The absolute path a stored `files.path` names inside the `files` disk,
     * or null when it names anything else - an absolute path, a path with a
     * `.`/`..` segment, or one that resolves outside the disk root.
     */
    private function containedPath(string $stored): ?string
    {
        if ($stored === '' || str_starts_with($stored, '/') || preg_match('#(^|/)\.\.?(/|$)#', $stored) === 1) {
            return null;
        }

        $root = realpath(Storage::disk('files')->path(''));
        $path = realpath(Storage::disk('files')->path($stored));

        if ($root === false || $path === false || ! is_file($path)) {
            return null;
        }

        $root = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root) ? $path : null;
    }
}
