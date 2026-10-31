<?php

namespace App\Http\Controllers;

use App\Models\FileShare;
use App\Services\FileShareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Files other companies have shared with the signed-in person, and the download of each. Every
 * request re-checks the share against the person's verified email, so a revoked or expired
 * share stops working at once and a link passed to someone else opens nothing.
 */
class SharedFileController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $shares = $user->email_verified_at === null
            ? collect()
            : FileShare::with('file')->where('email', strtolower((string) $user->email))->active()->orderBy('expires_at')->get()->filter(fn (FileShare $s) => $s->file !== null);

        return view('files.shared-with-me', ['shares' => $shares]);
    }

    public function download(Request $request, FileShare $share, FileShareService $service)
    {
        $user = $request->user();
        $file = $share->file;

        // A trashed file is off the record for everyone outside the company (R-27).
        abort_unless($file !== null && ! $file->isTrashed() && $service->activeShareFor($user, $file)?->is($share), 404);

        $service->opened($user, $share, 'downloaded');

        return Storage::disk('local')->download($file->path, $file->name);
    }

    /**
     * The snapshot for a file shared with this person — re-checked against the live share the
     * same way the download is, so a revoked or expired share shows nothing (R-26).
     */
    public function preview(Request $request, FileShare $share, FileShareService $service)
    {
        $file = $share->file;

        abort_unless($file !== null && ! $file->isTrashed() && $service->activeShareFor($request->user(), $file)?->is($share), 404);
        abort_unless($file->hasPreview() && Storage::disk('local')->exists($file->preview_path), 404);

        return response()->file(Storage::disk('local')->path($file->preview_path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
