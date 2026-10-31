<?php

namespace App\Http\Livewire;

use App\Models\File;
use App\Models\FileShare;
use App\Services\FileShareService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * The share panel for one file: who it is shared with, until when, who opened it, and the
 * audit trail. Owners and administrators of the file's company only.
 */
class FileShares extends Component
{
    public int $fileId;

    public string $email = '';

    public int $days = FileShare::DEFAULT_DAYS;

    public string $permission = 'download';

    public ?string $notice = null;

    public function mount(File $file): void
    {
        abort_unless(app(FileShareService::class)->canShare(auth()->user(), $file), 403);

        $this->fileId = $file->id;
    }

    private function file(): File
    {
        return File::findOrFail($this->fileId);
    }

    public function share(FileShareService $service): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'days' => ['required', 'integer', 'in:7,30,60,90'],
            'permission' => ['required', 'in:view,download'],
        ]);

        $share = $service->share(auth()->user(), $this->file(), $this->email, $this->days, $this->permission);

        $this->notice = 'Shared with '.$share->email.' until '.$share->expires_at->format('j M Y').' — '.strtolower($share->permissionLabel()).'.';
        $this->email = '';
    }

    public function revoke(int $shareId, FileShareService $service): void
    {
        $share = FileShare::where('file_id', $this->fileId)->findOrFail($shareId);

        $service->revoke(auth()->user(), $share);

        $this->notice = 'Access for '.$share->email.' has been removed.';
    }

    public function render()
    {
        $file = $this->file();
        $link = filled($file->infodot_resource_uuid)
            ? rtrim((string) config('ecosystem.platforms.infodot.url'), '/').'/open/'.$file->infodot_resource_uuid
            : null;

        return view('livewire.file-shares', [
            'file' => $file,
            'shares' => FileShare::where('file_id', $file->id)->active()->orderBy('expires_at')->get(),
            'events' => DB::table('file_share_events')->where('file_uuid', $file->uuid)->orderByDesc('id')->limit(15)->get(),
            'link' => $link,
            'maxDays' => FileShare::MAX_DAYS,
        ]);
    }
}
