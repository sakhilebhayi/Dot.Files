<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Applies to Obj, File, and Folder -- Dot.Files' three team-owned tables
 * (each carries its own `team_id` foreign key, per the `objects`, `files`,
 * and `folders` migrations). Not applied to Team, User, or Membership,
 * which are Jetstream's own auth/team-membership models, not team-owned
 * domain data.
 *
 * Before this trait, team isolation depended on every caller remembering
 * to opt in via `App\Models\Traits\RelatesToTeams::scopeForCurrentTeam()`
 * (`Obj::forCurrentTeam()`), and File/Folder had no scoping at all --
 * `FileController::download(File $file)` relied entirely on implicit
 * route-model binding finding the row first, then a `FilePolicy::download`
 * check. This trait makes the restriction the default for every query
 * against these models, so a forgotten explicit scope call no longer
 * means a cross-team leak.
 */
trait HasTeamScope
{
    protected static function bootHasTeamScope(): void
    {
        static::addGlobalScope('team', function (Builder $builder): void {
            if (! Auth::check()) {
                return; // jobs, console and the like have no person behind them
            }

            $team = Auth::user()->currentTeam;

            // A signed-in person with no active team sees nothing. Failing open here
            // would show every team's rows to anyone without a current team.
            if (! $team) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where($builder->getModel()->getTable().'.team_id', $team->id);
        });
    }
}
