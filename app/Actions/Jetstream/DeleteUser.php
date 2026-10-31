<?php

namespace App\Actions\Jetstream;

use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * The team deleter implementation.
     *
     * @var DeletesTeams
     */
    protected $deletesTeams;

    /**
     * Create a new action instance.
     *
     * @return void
     */
    public function __construct(DeletesTeams $deletesTeams)
    {
        $this->deletesTeams = $deletesTeams;
    }

    /**
     * Delete the given user.
     *
     * @param  mixed  $user
     * @return void
     */
    public function delete($user)
    {
        DB::transaction(function () use ($user) {
            $this->deleteTeams($user);
            $user->deleteProfilePhoto();
            $user->tokens->each->delete();
            $user->delete();
        });
    }

    /**
     * Delete the teams and team associations attached to the user.
     *
     * @param  mixed  $user
     * @return void
     */
    protected function deleteTeams($user)
    {
        // Deleting an account must not silently destroy a COMPANY's files: a company team the
        // person owns may hold the business's records, and team deletion is an operator-approved
        // flow (DeleteTeamForm), never a side effect. Ownership must be transferred, or the team's
        // deletion approved, before the account can go (R-25).
        $blocking = $user->ownedTeams->first(fn ($team) => ! $team->personal_team);

        if ($blocking) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'password' => __('You still own the company ":team". Transfer its ownership or have its deletion approved before deleting your account.', ['team' => $blocking->name]),
            ]);
        }

        $user->teams()->detach();

        $user->ownedTeams->each(function ($team) {
            $this->deletesTeams->delete($team);
        });
    }
}
