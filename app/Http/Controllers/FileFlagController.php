<?php

namespace App\Http\Controllers;

use App\Models\FileFlag;
use App\Models\Team;
use Illuminate\Http\Request;

/**
 * Closing a governance flag (R-28 §29): a company owner or administrator looked at the
 * evidence and decided. Both outcomes only close the question — the flag row stays, saying
 * who decided and when, and nothing else changes anywhere.
 */
class FileFlagController extends Controller
{
    public function review(Request $request, FileFlag $flag)
    {
        return $this->close($request, $flag, 'reviewed', __('Noted as reviewed. The flag and its evidence stay on the record.'));
    }

    public function dismiss(Request $request, FileFlag $flag)
    {
        return $this->close($request, $flag, 'dismissed', __('Dismissed. The flag and its evidence stay on the record.'));
    }

    private function close(Request $request, FileFlag $flag, string $status, string $notice)
    {
        $user = $request->user();
        $team = Team::find($flag->team_id);

        abort_unless($team !== null && ! $team->personal_team
            && ($user->ownsTeam($team) || $user->hasTeamRole($team, 'admin')), 403);
        abort_unless($flag->status === 'open', 404);

        $flag->update(['status' => $status, 'decided_by' => $user->id, 'decided_at' => now()]);

        return redirect()->route('approvals')->with('status', $notice);
    }
}
