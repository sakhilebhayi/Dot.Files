<?php

namespace App\Policies;

use App\Models\File;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class FilePolicy
{
    use HandlesAuthorization;

    public function download(User $user, File $file)
    {
        // The file's own team decides, not whichever team the session happens to
        // have active: a member of two companies can open either company's files.
        return $file->team_id !== null && $user->allTeams()->contains('id', $file->team_id);
    }
}
