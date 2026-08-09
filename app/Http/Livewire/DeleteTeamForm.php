<?php

namespace App\Http\Livewire;

use App\Models\TeamDeletionRequest;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Actions\ValidateTeamDeletion;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Http\Livewire\DeleteTeamForm as JetstreamDeleteTeamForm;

/**
 * Overrides Jetstream's own team-deletion component (registered under the
 * teams.delete-team-form Livewire alias -- see the re-registration in
 * app/Providers/JetstreamServiceProvider.php::boot(), which runs after
 * Jetstream's own package registration and so wins). Team deletion is no
 * longer instant: clicking "Delete Team" creates a pending
 * TeamDeletionRequest instead of calling DeletesTeams::delete() directly.
 * A platform operator must approve it (see
 * app/Http/Controllers/DeletionModerationController.php) before the team
 * is actually removed.
 */
class DeleteTeamForm extends JetstreamDeleteTeamForm
{
    /**
     * True once this session has requested deletion (or found one already
     * pending) -- the view reads this to show a "pending approval" message
     * instead of the normal delete confirmation.
     */
    public bool $requestedDeletion = false;

    /**
     * $validator/$deleter are kept only to stay signature-compatible with
     * the parent method PHP requires when overriding a concrete method
     * (Jetstream's own deleteTeam() requires both, with no defaults) --
     * this override never uses either. Deliberately does not call
     * DeletesTeams::delete() and does not null $this->team or redirect;
     * those are the vendor's "deletion succeeded" side effects, and the
     * team is not gone.
     */
    public function deleteTeam(?ValidateTeamDeletion $validator = null, ?DeletesTeams $deleter = null): void
    {
        Gate::authorize('delete', $this->team);

        $existing = TeamDeletionRequest::where('team_id', $this->team->id)
            ->where('status', 'pending')
            ->first();

        if (! $existing) {
            TeamDeletionRequest::create([
                'team_id' => $this->team->id,
                'requested_by' => auth()->id(),
            ]);
        }

        $this->confirmingTeamDeletion = false;
        $this->requestedDeletion = true;
    }
}
