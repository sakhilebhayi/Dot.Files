<?php

namespace App\Services;

use App\Models\File;
use App\Models\FileShare;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules of sharing a file with someone outside the company, in one place.
 *
 * - Only a company owner or administrator may share, and only a company's own file.
 * - The recipient is a person with a VERIFIED InfoDot email; they sign in through InfoDot and the
 *   share is matched on that email every time it is used. A forwarded link opens nothing for anyone else.
 * - View and download of that one file. Never the folder, never another file, never the company.
 * - At most 90 days (30 by default), revocable at any time, and revoked when the file is deleted,
 *   the sharer leaves the company or loses admin rights, or the company is deleted.
 * - Everything is written to an audit trail that holds no file names.
 */
class FileShareService
{
    public function canShare(User $user, File $file): bool
    {
        $team = Team::find($file->team_id);

        return $team !== null && ! $team->personal_team && ($user->ownsTeam($team) || $user->hasTeamRole($team, 'admin'));
    }

    /**
     * Any member of the file's company who cannot share directly may ASK for a share (R-26).
     * The request grants nothing until an owner or administrator approves it.
     */
    public function canRequestShare(User $user, File $file): bool
    {
        $team = Team::find($file->team_id);

        return $team !== null && ! $team->personal_team && $user->belongsToTeam($team) && ! $this->canShare($user, $file);
    }

    public function share(User $by, File $file, string $email, int $days, string $permission = 'download'): FileShare
    {
        abort_unless($this->canShare($by, $file), 403);

        $email = $this->normalisedEmail($by, $file, $email);
        $days = max(1, min($days, FileShare::MAX_DAYS));
        $permission = $this->normalisedPermission($permission);

        if (FileShare::where('file_id', $file->id)->active()->count() >= FileShare::MAX_ACTIVE_PER_FILE) {
            throw ValidationException::withMessages(['email' => 'This file is already shared with the most people allowed. Revoke one first.']);
        }

        return DB::transaction(function () use ($by, $file, $email, $days, $permission) {
            // One live share per person and file: sharing again renews it instead of stacking,
            // and the sharer's latest permission choice is the one that holds.
            $share = FileShare::where('file_id', $file->id)->where('email', $email)->active()->first();

            if ($share) {
                $share->update(['expires_at' => now()->addDays($days), 'permission' => $permission]);
                $this->record($file, $share, 'renewed', $by->email, $email);

                return $share;
            }

            // A member's pending request for the same person becomes the share itself: the
            // owner sharing directly IS the approval, on the owner's terms.
            $share = FileShare::where('file_id', $file->id)->where('email', $email)->pending()->first();

            if ($share) {
                $share->update(['status' => 'active', 'decided_by' => $by->id, 'decided_at' => now(), 'expires_at' => now()->addDays($days), 'permission' => $permission]);
                $this->record($file, $share, 'approved', $by->email, $email);

                return $share;
            }

            $share = FileShare::create(['file_id' => $file->id, 'email' => $email, 'created_by' => $by->id, 'expires_at' => now()->addDays($days), 'permission' => $permission]);
            $this->record($file, $share, 'created', $by->email, $email);

            return $share;
        });
    }

    /**
     * A member asks for this file to be shared with someone outside the company (R-26).
     * Creates a PENDING share: no access until approve() — enforced by FileShare::active(),
     * which every open and download runs through, not by anything the interface hides.
     */
    public function requestShare(User $by, File $file, string $email, int $days, string $permission = 'download'): FileShare
    {
        abort_unless($this->canRequestShare($by, $file), 403);

        $email = $this->normalisedEmail($by, $file, $email);
        $days = max(1, min($days, FileShare::MAX_DAYS));
        $permission = $this->normalisedPermission($permission);

        if (FileShare::where('file_id', $file->id)->where('email', $email)->active()->exists()) {
            throw ValidationException::withMessages(['email' => 'That person already has access to this file.']);
        }

        return DB::transaction(function () use ($by, $file, $email, $days, $permission) {
            // One open request per person and file: asking again just updates the terms.
            $share = FileShare::where('file_id', $file->id)->where('email', $email)->pending()->first();

            if ($share) {
                $share->update(['expires_at' => now()->addDays($days), 'permission' => $permission]);

                return $share;
            }

            $share = FileShare::create([
                'file_id' => $file->id,
                'email' => $email,
                'status' => 'pending',
                'created_by' => $by->id,
                'requested_by' => $by->id,
                'expires_at' => now()->addDays($days),
                'permission' => $permission,
            ]);
            $this->record($file, $share, 'requested', $by->email, $email);

            return $share;
        });
    }

    /** An owner or administrator turns a member's request into access. */
    public function approve(User $by, FileShare $share): FileShare
    {
        $file = $share->file;

        abort_unless($file !== null && $this->canShare($by, $file), 403);
        abort_unless($share->status === 'pending' && $share->revoked_at === null, 404);

        if (FileShare::where('file_id', $file->id)->active()->count() >= FileShare::MAX_ACTIVE_PER_FILE) {
            throw ValidationException::withMessages(['share' => 'This file is already shared with the most people allowed. Revoke one first.']);
        }

        // The access window starts at the approval, for the days the requester asked for.
        $share->update([
            'status' => 'active',
            'decided_by' => $by->id,
            'decided_at' => now(),
            'expires_at' => now()->addDays($share->requestedDays()),
        ]);

        $this->record($file, $share, 'approved', $by->email, $share->email);

        return $share;
    }

    /** An owner or administrator turns a member's request down. Nothing was ever accessible. */
    public function decline(User $by, FileShare $share, string $note = ''): FileShare
    {
        $file = $share->file;

        abort_unless($file !== null && $this->canShare($by, $file), 403);
        abort_unless($share->status === 'pending' && $share->revoked_at === null, 404);

        $share->update(['status' => 'declined', 'decided_by' => $by->id, 'decided_at' => now()]);

        $this->record($file, $share, 'declined', $by->email, $share->email, $note ?: null);

        return $share;
    }

    /** A permission is one of the presets the model names — nothing else is stored. */
    private function normalisedPermission(string $permission): string
    {
        if (! in_array($permission, FileShare::PERMISSIONS, true)) {
            throw ValidationException::withMessages(['permission' => 'Pick what they may do: view only, or view and download.']);
        }

        return $permission;
    }

    /** Shared validation for share() and requestShare(): a clean outside address. */
    private function normalisedEmail(User $by, File $file, string $email): string
    {
        $email = strtolower(trim($email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'Enter a valid email address.']);
        }

        if ($email === strtolower((string) $by->email)) {
            throw ValidationException::withMessages(['email' => 'That is your own address.']);
        }

        $team = Team::find($file->team_id);
        $inCompany = User::whereRaw('lower(email) = ?', [$email])->get()->contains(fn (User $u) => $u->belongsToTeam($team));

        if ($inCompany) {
            throw ValidationException::withMessages(['email' => 'That person is already in your company and can open the file.']);
        }

        return $email;
    }

    public function revoke(User $by, FileShare $share, string $note = ''): void
    {
        $file = $share->file;

        abort_unless($file !== null && $this->canShare($by, $file), 403);

        $this->revokeShare($share, $by->email, $note);
    }

    /** Revoke without an acting person (the file was deleted, the sharer left, the company went). */
    public function revokeShare(FileShare $share, ?string $actorEmail, string $note = ''): void
    {
        if ($share->revoked_at !== null) {
            return;
        }

        $share->update(['revoked_at' => now()]);

        if ($file = $share->file) {
            $this->record($file, $share, 'revoked', $actorEmail, $share->email, $note ?: null);
        }
    }

    /** @param  iterable<FileShare>  $shares */
    public function revokeMany(iterable $shares, string $note): void
    {
        foreach ($shares as $share) {
            $this->revokeShare($share, null, $note);
        }
    }

    /** The live share that lets this signed-in person open this file, if any. */
    public function activeShareFor(User $user, File $file): ?FileShare
    {
        if ($user->email_verified_at === null) {
            return null;
        }

        return FileShare::where('file_id', $file->id)->where('email', strtolower((string) $user->email))->active()->first();
    }

    public function opened(User $user, FileShare $share, string $event = 'opened'): void
    {
        $share->forceFill([
            'first_opened_at' => $share->first_opened_at ?? now(),
            'last_opened_at' => now(),
            'open_count' => $share->open_count + 1,
        ])->save();

        if ($file = $share->file) {
            $this->record($file, $share, $event, $user->email, $share->email);
        }
    }

    public function denied(File $file, User $user): void
    {
        $this->record($file, null, 'denied', $user->email, null);
    }

    public function record(File $file, ?FileShare $share, string $event, ?string $actorEmail, ?string $subjectEmail, ?string $note = null): void
    {
        DB::table('file_share_events')->insert([
            'file_uuid' => (string) $file->uuid,
            'share_id' => $share?->id,
            'event' => $event,
            'actor_email' => $actorEmail !== null ? strtolower($actorEmail) : null,
            'subject_email' => $subjectEmail,
            'note' => $note,
            'created_at' => now(),
        ]);
    }
}
