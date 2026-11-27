<?php

namespace App\Http\Controllers;

use App\Exceptions\VersionConflictException;
use App\Models\File;
use App\Models\FilePin;
use App\Models\FileVersion;
use App\Models\Obj;
use App\Models\Team;
use App\Services\FileVersions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

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

        // EA-2: only a live folder opens as the workspace. A file's node or a folder in the trash (or inside one) is
        // not a place to file things into.
        $object = Obj::with('ancestorsAndSelf.objectable')
            ->where('uuid', $uuid)
            ->where('objectable_type', 'folder')
            ->whereNull('trashed_at')
            ->firstOrFail();

        abort_if($object->ancestors()->whereNotNull('trashed_at')->exists(), 404);

        return view('files', [
            'object' => $object,
            'ancestors' => $object->ancestorsAndSelf()->breadthFirst()->get(),
        ]);
    }

    public function download(File $file)
    {
        $this->authorize('download', $file);
        // EA-2: content missing from disk is "gone", like an old version's, never a server error.
        abort_unless(Storage::disk('local')->exists($file->path), 410, 'This file\'s content is no longer on disk.');

        return Storage::disk('local')->download($file->path, $file->name);
    }

    /**
     * The document rendered in the browser (R-27): the blob streamed with an inline
     * disposition, same policy as the download, and ONLY for formats the browser renders
     * passively (PDF, raster images, plain text). Active content (HTML, SVG, anything
     * scriptable) is never served inline — it stays a download, by design.
     */
    public function inline(File $file)
    {
        $this->authorize('download', $file);

        $inlineable = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'text/plain', 'text/csv'];

        abort_unless(in_array($file->mime, $inlineable, true), 404);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        $headers = [
            'Content-Type' => $file->mime,
            // RFC 6266: the real name in filename*, a plain-ASCII fallback in filename (EA-1).
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $file->name, $this->asciiName($file->name)),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ];

        // Images and text open in a sandbox with nothing allowed to run. PDFs are left out: browsers refuse to show
        // their own PDF viewer inside a sandboxed document.
        if ($file->mime !== 'application/pdf') {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'";
        }

        return response()->file(Storage::disk('local')->path($file->path), $headers);
    }

    /** A plain-ASCII stand-in for a document name, for clients that cannot read filename*. */
    private function asciiName(string $name): string
    {
        $ascii = trim(str_replace(['"', '\\', '/', '%'], '_', (string) preg_replace('/[^\x20-\x7E]/', '_', Str::ascii($name))));

        return $ascii !== '' ? $ascii : 'document';
    }

    /**
     * Replace a document's content with a new upload (R-28 Part F): the old content stays as
     * its version, the file moves forward, the snapshot regenerates. Same membership rule as
     * every other change in this workspace; the trail records exactly who did it.
     */
    public function replace(Request $request, File $file, FileVersions $versions)
    {
        $this->authorize('download', $file);
        abort_if($file->isTrashed(), 409, 'This document is in the trash; restore it first.');

        $request->validate([
            'upload' => ['required', 'file', 'max:102400',
                'mimes:pdf,csv,txt,text,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp,svg,zip,tar,gz,mp4,mp3,mov,avi'],
            'base_version' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $version = $versions->replaceFromUpload($file, $request->file('upload'), $request->user()->id,
                $request->filled('base_version') ? (int) $request->input('base_version') : null);
        } catch (VersionConflictException $e) {
            return redirect()->route('files.open', $file->uuid)
                ->with('conflict', 'While you had this page open, the document moved on to version '.$e->currentVersion.'. Nothing was saved — review the latest version below, then replace again if your content should still win.');
        }

        return redirect()->route('files.open', $file->uuid)
            ->with('status', 'New content saved as version '.$version->version.'. Every earlier version stays in the history below.');
    }

    /** One historical version's content, under the same policy as the live download. */
    public function downloadVersion(File $file, FileVersion $version)
    {
        $this->authorize('download', $file);
        abort_unless($version->file_id === $file->id, 404);
        abort_unless(Storage::disk('local')->exists($version->path), 410);

        return Storage::disk('local')->download($version->path, 'v'.$version->version.' — '.$version->name);
    }

    /** Bring an old version forward as the newest one; nothing in between is erased. */
    public function restoreVersion(Request $request, File $file, FileVersion $version, FileVersions $versions)
    {
        $this->authorize('download', $file);
        abort_if($file->isTrashed(), 409, 'This document is in the trash; restore it first.');

        $new = $versions->restore($file, $version, $request->user()->id);

        return redirect()->route('files.open', $file->uuid)
            ->with('status', 'Version '.$version->version.' is back as version '.$new->version.'. The history in between is untouched.');
    }

    /** Pin or unpin this file for the signed-in person (R-27). A pin is a bookmark, not access. */
    public function togglePin(Request $request, File $file)
    {
        $this->authorize('download', $file);

        $pin = FilePin::where('user_id', $request->user()->id)->where('file_id', $file->id)->first();
        $pin ? $pin->delete() : FilePin::create(['user_id' => $request->user()->id, 'file_id' => $file->id]);

        return back()->with('status', $pin ? $file->name.' is no longer pinned.' : $file->name.' is pinned.');
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
