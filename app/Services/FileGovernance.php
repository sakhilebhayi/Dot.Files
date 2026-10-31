<?php

namespace App\Services;

use App\Mail\SuspiciousActivityAlert;
use App\Models\File;
use App\Models\FileFlag;
use App\Models\FileVersion;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Document-governance monitoring (R-28 §29), scoped to the rule family the owner enabled:
 * hide-the-evidence patterns. Every flag is review-only — plain evidence (who, what, when, how
 * many) for a human to judge; nothing is auto-labelled fraud and nothing is blocked. Human
 * actions only: platform traffic has its own audit trail and is out of scope by owner choice.
 *
 * Dot.Files runs no scheduler on its host, so detection is opportunistic: each rule is
 * evaluated right after the action that could complete its pattern, after the response.
 */
class FileGovernance
{
    /** After a person saves new content: many rewrites of one file in a short window. */
    public function afterReplace(int $fileId, int $userId): void
    {
        $file = File::withoutGlobalScope('team')->find($fileId);

        if ($file === null) {
            return;
        }

        $window = max(1, (int) config('governance.version_churn_minutes'));
        $threshold = max(2, (int) config('governance.version_churn_count'));

        $versions = FileVersion::where('file_id', $file->id)
            ->where('created_by', $userId)
            ->whereIn('action', ['replaced', 'restored'])
            ->where('created_at', '>=', now()->subMinutes($window))
            ->orderBy('version')
            ->get();

        if ($versions->count() < $threshold) {
            return;
        }

        $this->flag($file, 'version-churn', $userId, [
            'count' => $versions->count(),
            'window_minutes' => $window,
            'versions' => $versions->pluck('version')->all(),
            'first_at' => $versions->first()->created_at->toIso8601String(),
            'last_at' => $versions->last()->created_at->toIso8601String(),
        ]);
    }

    /** After a person trashes a file: was its content just replaced? */
    public function afterTrash(int $fileId, int $userId): void
    {
        $file = File::withoutGlobalScope('team')->find($fileId);

        if ($file === null) {
            return;
        }

        $window = max(1, (int) config('governance.replace_trash_minutes'));

        $recent = FileVersion::with('author')
            ->where('file_id', $file->id)
            ->whereIn('action', ['replaced', 'restored'])
            ->whereNotNull('created_by')
            ->where('created_at', '>=', now()->subMinutes($window))
            ->orderByDesc('version')
            ->first();

        if ($recent === null) {
            return;
        }

        $this->flag($file, 'replace-then-trash', $userId, [
            'window_minutes' => $window,
            'replaced_version' => (int) $recent->version,
            'replaced_by' => $recent->author?->name,
            'replaced_at' => $recent->created_at->toIso8601String(),
            'trashed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Raise the flag once: while the same question about the same file and person is still
     * open, the pattern repeating adds nothing but noise.
     */
    private function flag(File $file, string $rule, int $actorId, array $evidence): void
    {
        $exists = FileFlag::open()
            ->where('file_id', $file->id)
            ->where('rule', $rule)
            ->where('actor_id', $actorId)
            ->exists();

        if ($exists) {
            return;
        }

        $flag = FileFlag::create([
            'team_id' => $file->team_id,
            'file_id' => $file->id,
            'rule' => $rule,
            'actor_id' => $actorId,
            'severity' => 'review',
            'evidence' => $evidence,
            'status' => 'open',
        ]);

        $this->alert($flag, $file);
    }

    /** Email the company's owner and administrators. A mail failure never breaks the flag. */
    private function alert(FileFlag $flag, File $file): void
    {
        if (! config('governance.alert_email')) {
            return;
        }

        $team = Team::find($file->team_id);

        if ($team === null || $team->personal_team) {
            return;
        }

        $recipients = $team->users
            ->filter(fn (User $u) => $u->hasTeamRole($team, 'admin'))
            ->push($team->owner)
            ->filter()
            ->unique('id')
            ->pluck('email')
            ->filter()
            ->all();

        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send(new SuspiciousActivityAlert($flag, $file, $team));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
