<?php

namespace App\Http\Controllers;

use App\Actions\Jetstream\DeleteTeam;
use App\Models\BulkDeletionRequest;
use App\Models\Obj;
use App\Models\TeamDeletionRequest;
use App\Models\User;
use App\Notifications\FilesActivity;
use App\Services\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $teamName = (string) $teamDeletionRequest->team?->name;
        $deleter->delete($teamDeletionRequest->team);

        $teamDeletionRequest->update([
            'status' => 'approved',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $this->tell($teamDeletionRequest->requested_by, 'deletion.approved', 'Your company deletion was approved', ['"'.$teamName.'" and its records have been deleted.'], route('files'), 'Open Dot.Files');

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

        $this->tell($teamDeletionRequest->requested_by, 'deletion.rejected', 'Your company deletion was not approved', ['"'.$teamDeletionRequest->team?->name.'" stays as it is.', 'Reason: '.$validated['reason']], route('files'), 'Open Dot.Files');

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
        //
        // EA-2: approval moves the items to the company's trash, the same 30-day hold as every other deletion, and the
        // purge removes them for good. The decision is recorded only if the request is still pending at that moment,
        // so approving twice (or approving and rejecting at once) cannot both take effect.
        $approved = DB::transaction(function () use ($bulkDeletionRequest) {
            $claimed = BulkDeletionRequest::whereKey($bulkDeletionRequest->id)->where('status', 'pending')->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            if ($claimed !== 1) {
                return false;
            }

            Obj::withoutGlobalScope('team')
                ->whereIn('id', $bulkDeletionRequest->object_ids)
                ->where('team_id', $bulkDeletionRequest->team_id)
                ->whereNotNull('parent_id')
                ->whereNull('trashed_at')
                ->get()
                ->each(fn (Obj $obj) => $obj->trash($bulkDeletionRequest->requested_by));

            return true;
        });

        if (! $approved) {
            return back()->withErrors(['status' => 'Only a pending request can be approved.']);
        }

        $this->tell($bulkDeletionRequest->requested_by, 'deletion.approved', 'Your deletion request was approved', [$this->itemsLine($bulkDeletionRequest).' moved to the trash.', 'They are deleted for good after '.Obj::TRASH_DAYS.' days and can be restored from the trash until then.'], route('trash'), 'Open the trash');

        return back()->with('status', 'Selected items moved to the company\'s trash. They are deleted for good after '.Obj::TRASH_DAYS.' days.');
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

        $this->tell($bulkDeletionRequest->requested_by, 'deletion.rejected', 'Your deletion request was not approved', [$this->itemsLine($bulkDeletionRequest).' stay where they are.', 'Reason: '.$validated['reason']], route('files'), 'Open Dot.Files');

        return back()->with('status', 'Bulk deletion request rejected.');
    }

    /** The person who asked hears the operator's decision (EA-3). */
    private function tell(?int $userId, string $kind, string $title, array $lines, string $url, string $action): void
    {
        app(Notifier::class)->send([$userId !== null ? User::find($userId) : null], new FilesActivity($kind, $title, $lines, $url, $action));
    }

    private function itemsLine(BulkDeletionRequest $request): string
    {
        $names = array_values(array_filter((array) $request->object_names));
        $count = count((array) $request->object_ids);

        return $names !== [] ? 'The '.trans_choice('item|items', $count).' "'.implode('", "', array_slice($names, 0, 3)).'"'.($count > 3 ? ' and '.($count - 3).' more' : '') : $count.' '.trans_choice('item|items', $count);
    }
}
