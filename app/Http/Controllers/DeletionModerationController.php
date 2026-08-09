<?php

namespace App\Http\Controllers;

use App\Actions\Jetstream\DeleteTeam;
use App\Models\BulkDeletionRequest;
use App\Models\Obj;
use App\Models\TeamDeletionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeletionModerationController extends Controller
{
    public function index(): View
    {
        return view('operator.deletion-requests.index', [
            'pendingTeamRequests' => TeamDeletionRequest::where('status', 'pending')->with(['team', 'requester'])->latest()->get(),
            'pendingBulkRequests' => BulkDeletionRequest::where('status', 'pending')->with(['team', 'requester'])->latest()->get(),
        ]);
    }

    public function approveTeam(TeamDeletionRequest $teamDeletionRequest, DeleteTeam $deleter): RedirectResponse
    {
        if ($teamDeletionRequest->status !== 'pending') {
            return back()->withErrors(['status' => 'Only a pending request can be approved.']);
        }

        $deleter->delete($teamDeletionRequest->team);

        $teamDeletionRequest->update([
            'status' => 'approved',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Team deleted.');
    }

    public function rejectTeam(TeamDeletionRequest $teamDeletionRequest, Request $request): RedirectResponse
    {
        if ($teamDeletionRequest->status !== 'pending') {
            return back()->withErrors(['status' => 'Only a pending request can be rejected.']);
        }

        $validated = $request->validate(['reason' => 'required|string']);

        $teamDeletionRequest->update([
            'status' => 'rejected',
            'rejected_reason' => $validated['reason'],
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Team deletion request rejected.');
    }

    public function approveBulk(BulkDeletionRequest $bulkDeletionRequest): RedirectResponse
    {
        if ($bulkDeletionRequest->status !== 'pending') {
            return back()->withErrors(['status' => 'Only a pending request can be approved.']);
        }

        // Re-resolve against real rows rather than trusting object_ids
        // blindly, and re-scope to this request's own team_id even though
        // Obj's HasTeamScope global scope would already enforce that for
        // an authenticated request -- belt-and-suspenders, matching this
        // session's established double-check style.
        //
        // withoutGlobalScope('team') is required: the operator reviewing
        // this request has their own currentTeam, which HasTeamScope's
        // global scope would otherwise filter every query by -- silently
        // returning zero rows for any request that isn't the operator's
        // own team.
        Obj::withoutGlobalScope('team')
            ->whereIn('id', $bulkDeletionRequest->object_ids)
            ->where('team_id', $bulkDeletionRequest->team_id)
            ->get()
            ->each->delete();

        $bulkDeletionRequest->update([
            'status' => 'approved',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Selected items deleted.');
    }

    public function rejectBulk(BulkDeletionRequest $bulkDeletionRequest, Request $request): RedirectResponse
    {
        if ($bulkDeletionRequest->status !== 'pending') {
            return back()->withErrors(['status' => 'Only a pending request can be rejected.']);
        }

        $validated = $request->validate(['reason' => 'required|string']);

        $bulkDeletionRequest->update([
            'status' => 'rejected',
            'rejected_reason' => $validated['reason'],
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Bulk deletion request rejected.');
    }
}
