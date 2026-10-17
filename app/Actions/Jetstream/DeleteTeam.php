<?php

namespace App\Actions\Jetstream;

use Laravel\Jetstream\Contracts\DeletesTeams;

class DeleteTeam implements DeletesTeams
{
    /**
     * Delete the given team.
     *
     * @param  mixed  $team
     * @return void
     */
    public function delete($team)
    {
        // The team is the explicit argument; an operator approving this has another team.
        $team->objects()->withoutGlobalScope('team')->get()->each->delete();
        $team->purge();
    }
}
