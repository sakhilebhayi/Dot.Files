<?php

namespace App\Services\InfoDot;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one plain page a person sees when their company does not hold Dot.Files (R-50): at sign-in, when
 * InfoDot marks none of their companies entitled, and during a session, when the current company's access
 * has ended. It carries no file, folder or count: only the company's name (the person's own), the wording
 * for InfoDot's status, the way to Platforms in InfoDot, and the person's other companies to switch to.
 */
class EntitlementRefusal
{
    /** InfoDot's statuses and refusal reasons, grouped by what the person needs to hear. */
    private const KINDS = [
        'suspended' => 'suspended',
        'payment_required' => 'payment',
        'subscription_expired' => 'ended',
        'expired' => 'ended',
        'trial_ended' => 'ended',
        'cancelled' => 'ended',
        'no_personal_access' => 'company',
    ];

    /** The current team is a personal workspace and InfoDot said individuals do not hold Dot.Files. */
    public const NEEDS_COMPANY = 'no_personal_access';

    public static function response(Request $request, ?string $status, ?Team $team = null, ?User $user = null): Response
    {
        $wording = self::wording($status, $status === self::NEEDS_COMPANY ? null : $team?->name);

        if ($request->expectsJson() && ! $request->hasHeader('X-Livewire')) {
            return response()->json(['message' => $wording['title']], 403);
        }

        return response()->view('errors.platform-access', [
            'title' => $wording['title'],
            'message' => $wording['message'],
            'platformsUrl' => self::platformsUrl(),
            // The person's own other workspaces, by name only; switching re-runs the check for that one.
            'otherTeams' => $user && $team ? $user->allTeams()->reject(fn ($other) => $other->id === $team->id)->values() : collect(),
        ], 403);
    }

    /**
     * @return array{title: string, message: string}
     */
    public static function wording(?string $status, ?string $company = null): array
    {
        $for = $company !== null && $company !== '' ? $company : 'your company';
        $kept = 'Nothing has been deleted or changed.';

        return match (self::KINDS[$status] ?? 'off') {
            'suspended' => [
                'title' => "Dot.Files is suspended for {$for}",
                'message' => "{$kept} Platforms in InfoDot shows what is needed before the files can be opened again.",
            ],
            'payment' => [
                'title' => "A payment is needed before {$for} can use Dot.Files",
                'message' => "{$kept} An owner or administrator can settle it from Platforms in InfoDot, and the files open again.",
            ],
            'company' => [
                'title' => 'Dot.Files needs a company that has switched it on',
                'message' => "{$kept} Create or join a company in InfoDot and switch Dot.Files on from Platforms; this workspace opens again when individuals hold it.",
            ],
            'ended' => [
                'title' => "Dot.Files has ended for {$for}",
                'message' => "{$kept} An owner or administrator can renew it from Platforms in InfoDot.",
            ],
            default => [
                'title' => "Dot.Files is not switched on for {$for}",
                'message' => "{$kept} An owner or administrator can switch it on from Platforms in InfoDot.",
            ],
        };
    }

    public static function platformsUrl(): string
    {
        $infodot = rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/');

        return $infodot.'/platforms/'.rawurlencode((string) config('ecosystem.infodot.platform', 'files'));
    }
}
