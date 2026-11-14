<?php

namespace App\Actions\Ecosystem;

use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\RedeemedCompanies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Jetstream\Jetstream;

/**
 * Applies the companies InfoDot says a person belongs to, on every sign-in.
 *
 * A company here is a team linked to an InfoDot team by uuid. Rules:
 * - Only people InfoDot lists as owner or admin of a company create its team here;
 *   an ordinary member is attached once it exists, so a member can never become
 *   the local owner of someone else's company.
 * - Membership and role follow InfoDot (owner and admin become admin, editor stays
 *   editor, anything else the least powerful role this app has).
 * - Companies InfoDot no longer lists for the person are detached, so removing
 *   someone in InfoDot removes their access here at their next sign-in.
 * - The company InfoDot says is active becomes the person's current team.
 * - Personal teams are never linked or removed.
 * - R-50: a company InfoDot marks `entitled: false` for Dot.Files is left exactly as it is here: not
 *   created, not renamed, no membership or role change, never made the current team. A company with no
 *   `entitled` key (an InfoDot that does not send it yet) is handled as before.
 */
class SyncTeamsFromInfoDot
{
    /**
     * @param  array<string, mixed>  $identity  the redeem response
     */
    public function __invoke(User $user, array $identity): void
    {
        $listed = collect($identity['teams'] ?? [])
            ->filter(fn ($t) => is_array($t) && filled($t['uuid'] ?? null) && ! ($t['personal'] ?? false))
            ->keyBy('uuid');

        $admitted = $listed->filter(fn (array $t) => RedeemedCompanies::admits($t));

        DB::transaction(function () use ($user, $listed, $admitted, $identity) {
            foreach ($admitted as $uuid => $info) {
                $this->apply($user, (string) $uuid, $info);
                $this->forgetCachedTeams($user);
            }

            $this->revoke($user, $listed->keys()->all());
            $this->forgetCachedTeams($user);

            // Still listed (entitled or not) is not revoked: a company that lost the platform keeps its people.
            $active = $identity['current_team_uuid'] ?? null;
            $team = $active && $admitted->has($active) ? Team::where('infodot_team_uuid', $active)->first() : null;

            if ($team && $user->belongsToTeam($team)) {
                $user->switchTeam($team);

                return;
            }

            $this->leaveRefusedCompany($user, $listed->diffKeys($admitted)->keys()->all(), $admitted->keys()->all());
        });
    }

    /**
     * A person whose current team here is a company InfoDot has just refused does not land in it: they are
     * moved to an entitled company they belong to, else to their personal workspace.
     *
     * @param  list<string>  $refused
     * @param  list<string>  $admitted
     */
    private function leaveRefusedCompany(User $user, array $refused, array $admitted): void
    {
        $current = $user->currentTeam;

        if (! $current instanceof Team || ! in_array($current->infodot_team_uuid, $refused, true)) {
            return;
        }

        $next = $user->allTeams()->first(fn ($team) => in_array($team->infodot_team_uuid, $admitted, true)) ?? $user->personalTeam();

        if ($next) {
            $user->switchTeam($next);
        }
    }

    /** Membership checks read relations cached on the model; reload after changing them. */
    private function forgetCachedTeams(User $user): void
    {
        $user->unsetRelation('teams')->unsetRelation('ownedTeams')->unsetRelation('currentTeam');
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function apply(User $user, string $uuid, array $info): void
    {
        $role = (string) ($info['role'] ?? '');
        $team = Team::where('infodot_team_uuid', $uuid)->first();

        if (! $team) {
            if (! in_array($role, ['owner', 'admin'], true)) {
                return;
            }

            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => (string) ($info['name'] ?? 'Company'),
                'personal_team' => false,
                'infodot_team_uuid' => $uuid,
            ]);
        } elseif (filled($info['name'] ?? null) && $team->name !== $info['name']) {
            $team->forceFill(['name' => $info['name']])->save();
        }

        if ($team->user_id === $user->id) {
            return; // the local owner needs no membership row
        }

        $localRole = $this->localRole($role);

        if ($user->belongsToTeam($team)) {
            $team->users()->updateExistingPivot($user->id, ['role' => $localRole]);
        } else {
            $team->users()->attach($user->id, ['role' => $localRole]);
        }
    }

    /**
     * @param  list<string>  $keep
     */
    private function revoke(User $user, array $keep): void
    {
        $stale = $user->allTeams()->filter(fn ($team) => filled($team->infodot_team_uuid) && ! in_array($team->infodot_team_uuid, $keep, true));

        foreach ($stale as $team) {
            if ($team->user_id === $user->id) {
                // Cannot detach a team's owner; surface it instead of leaving it silent.
                Log::warning('InfoDot no longer lists a company this person owns locally.', ['team_id' => $team->id, 'user_id' => $user->id]);

                continue;
            }

            $team->users()->detach($user->id);

            if ($user->current_team_id === $team->id) {
                $user->forceFill(['current_team_id' => null])->save();
            }
        }
    }

    private function localRole(string $infodotRole): string
    {
        $wanted = in_array($infodotRole, ['owner', 'admin'], true) ? 'admin' : ($infodotRole === 'editor' ? 'editor' : 'viewer');

        return Jetstream::findRole($wanted) ? $wanted : 'editor';
    }
}
