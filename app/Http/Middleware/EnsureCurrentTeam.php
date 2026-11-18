<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class EnsureCurrentTeam
{
    /**
     * Ensure the authenticated user has a current team set.
     * Users registered before the switchTeam fix may have current_team_id = null.
     * This middleware auto-recovers by switching to their personal team.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // Only this app's own users have teams (the shared sign-in package's test double does not).
        if ($user instanceof User && is_null($user->current_team_id)) {
            $personalTeam = $user->personalTeam();

            if ($personalTeam) {
                $user->switchTeam($personalTeam);
            }
        }

        return $next($request);
    }
}
