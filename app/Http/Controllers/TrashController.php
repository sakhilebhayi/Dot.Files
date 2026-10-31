<?php

namespace App\Http\Controllers;

use App\Models\Obj;
use Illuminate\Http\Request;

/**
 * The trash (R-27): single-click deletions wait here for 30 days instead of vanishing.
 * Restore puts a record back exactly where it was; "delete for good" is owner/admin only
 * and is the same permanent path the purge runs. Everything is team-scoped — Obj carries
 * the fail-closed team scope, so one company's trash is invisible to every other.
 */
class TrashController extends Controller
{
    public function index(Request $request)
    {
        if (! $request->user()->currentTeam) {
            return redirect()->route('teams.create');
        }

        // This host's deploy deliberately never touches the shared crontab, so the holding
        // window is enforced here too: opening the trash purges this company's overdue records
        // (the team scope keeps it to this company). files:purge-trash does the same fleet-wide
        // wherever a scheduler exists.
        Obj::whereNotNull('trashed_at')->where('trashed_at', '<', now()->subDays(Obj::TRASH_DAYS))
            ->get()->each->delete();

        return view('files.trash', [
            'items' => Obj::whereNotNull('trashed_at')->with('objectable')->orderByDesc('trashed_at')->get(),
            'days' => Obj::TRASH_DAYS,
            'canPurge' => $this->canPurge($request),
        ]);
    }

    public function restore(Request $request, Obj $obj)
    {
        abort_unless($obj->isTrashed(), 404);

        // If the folder it lived in was itself removed meanwhile, it comes back at the front door.
        $parent = $obj->parent_id ? Obj::withoutGlobalScope('team')->find($obj->parent_id) : null;

        if ($parent === null || $parent->isTrashed()) {
            $obj->parent_id = Obj::whereNull('parent_id')->value('id');
        }

        $obj->restore();

        return redirect()->route('trash')->with('status', ($obj->objectable->name ?? 'The record').' is back on the shelf.');
    }

    public function destroy(Request $request, Obj $obj)
    {
        abort_unless($this->canPurge($request), 403);
        abort_unless($obj->isTrashed(), 404);

        $name = $obj->objectable->name ?? 'The record';
        $obj->delete();

        return redirect()->route('trash')->with('status', $name.' is gone for good, and anyone it was shared with has lost access.');
    }

    private function canPurge(Request $request): bool
    {
        $user = $request->user();
        $team = $user->currentTeam;

        return $team !== null && ($user->ownsTeam($team) || $user->hasTeamRole($team, 'admin'));
    }
}
