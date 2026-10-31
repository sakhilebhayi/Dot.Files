<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\FileShare;
use App\Services\FileShareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The sharing approvals desk (R-26): every question of "who outside the company can see our
 * files?" answered in one screen. Owners and administrators see the requests waiting on them
 * and everything currently shared; members see what they asked for and how it was decided.
 * The decision itself lives in FileShareService — this controller only presents and relays.
 */
class ShareApprovalController extends Controller
{
    public function index(Request $request, FileShareService $service)
    {
        $user = $request->user();
        $team = $user->currentTeam;

        if (! $team) {
            return redirect()->route('teams.create');
        }

        // File carries the team scope, so these ids are the current company's files only.
        $fileIds = File::pluck('id');

        $canDecide = ! $team->personal_team && ($user->ownsTeam($team) || $user->hasTeamRole($team, 'admin'));

        $base = FileShare::with(['file' => fn ($q) => $q->withoutGlobalScopes(), 'requester'])
            ->whereIn('file_id', $fileIds);

        $pending = (clone $base)->pending()
            ->when(! $canDecide, fn ($q) => $q->where('requested_by', $user->id))
            ->orderBy('created_at')->get();

        $active = (clone $base)->active()
            ->when(! $canDecide, fn ($q) => $q->where('requested_by', $user->id))
            ->orderBy('expires_at')->get();

        $decided = (clone $base)
            ->where(fn ($q) => $q->whereIn('status', ['declined'])->orWhereNotNull('revoked_at')->orWhere('expires_at', '<=', now()))
            ->when(! $canDecide, fn ($q) => $q->where('requested_by', $user->id))
            ->orderByDesc('updated_at')->limit(20)->get();

        // Shared by me: what this person put outside the company, whatever its state.
        $mine = (clone $base)
            ->where(fn ($q) => $q->where('created_by', $user->id)->orWhere('requested_by', $user->id))
            ->orderByDesc('created_at')->limit(15)->get();

        // Platform connections (R-28 §32): which Dot platforms actually hold or touch this
        // company's files — real counts from the audit trail, and which callers are even
        // configured. No platform is shown as "connected" unless it has really acted.
        $platformActivity = DB::table('platform_file_events')
            ->where('team_id', $team->id)
            ->selectRaw("platform, count(*) as events, sum(case when event = 'stored' then 1 else 0 end) as stored, max(created_at) as last_at")
            ->groupBy('platform')->orderByDesc('events')->get()
            ->map(function ($row) {
                $row->name = (string) (config("ecosystem.platforms.{$row->platform}.name") ?: ucfirst($row->platform));

                return $row;
            });

        return view('files.approvals', [
            'team' => $team,
            'canDecide' => $canDecide,
            'pending' => $pending,
            'active' => $active,
            'decided' => $decided,
            'mine' => $mine,
            'platformActivity' => $platformActivity,
        ]);
    }

    /** A member submits a share request for one of the company's files. */
    public function store(Request $request, File $file, FileShareService $service)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'days' => ['required', 'integer', 'in:7,30,60,90'],
        ]);

        $share = $service->requestShare($request->user(), $file, $data['email'], (int) $data['days']);

        return redirect()->route('approvals')->with('status',
            'Asked to share '.$file->name.' with '.$share->email.'. An owner or administrator decides; nothing is visible to them until then.');
    }

    public function approve(Request $request, FileShare $share, FileShareService $service)
    {
        $service->approve($request->user(), $share);

        return redirect()->route('approvals')->with('status', 'Approved — '.$share->email.' can now open '.$share->file?->name.'.');
    }

    public function decline(Request $request, FileShare $share, FileShareService $service)
    {
        $service->decline($request->user(), $share, (string) $request->input('note', ''));

        return redirect()->route('approvals')->with('status', 'Declined — nothing was shared with '.$share->email.'.');
    }
}
