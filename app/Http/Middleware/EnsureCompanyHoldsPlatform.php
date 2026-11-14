<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\EntitlementCheck;
use App\Services\InfoDot\EntitlementRefusal;
use App\Services\InfoDot\RedeemedCompanies;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * R-50: a person who is already signed in stops working here once their company no longer holds
 * Dot.Files. InfoDot decides; this asks (cached per company) on the signed-in area only.
 *
 * It sits on the web group, so the machine-to-machine API (routes/api.php: the platform file API, the
 * membership webhook, InfoDot's search) never passes through it. Inside the web group it leaves alone
 * everything a signed-out person can reach, signing in and out, the handoff receiver, switching company,
 * and the files other companies have shared with the person (those belong to the person, by verified
 * email, not to the company that lost access).
 *
 * A personal workspace, or any team with no InfoDot company behind it, is never asked about. It follows
 * what InfoDot said about the personal workspace at sign-in (kept in the session): refused only when that
 * was exactly false, carried on when true or when nothing was remembered. No answer from InfoDot about a
 * company also carries on.
 */
class EnsureCompanyHoldsPlatform
{
    /** Route names this check never touches. A trailing * matches a prefix. */
    private const EXEMPT = [
        'login', 'login.*', 'logout', 'register', 'register.*', 'auth.ecosystem',
        'password.request', 'password.email', 'password.reset', 'password.update',
        'two-factor.login', 'two-factor.login.*', 'verification.*',
        'cookies', 'terms.show', 'policy.show', 'csp-report', 'sanctum.csrf-cookie',
        'current-team.update',
        'shared', 'shared.*',
    ];

    /** Paths with no route name: the public landing page, the health check, the legacy handoff link. */
    private const EXEMPT_PATHS = ['/', 'up', 'auth/ecosystem', 'livewire/livewire.js', 'livewire/livewire.min.js.map'];

    public function __construct(private EntitlementCheck $check) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->check->enabled() || $this->exempt($request)) {
            return $next($request);
        }

        $user = $request->user();
        $team = $user?->currentTeam;

        if (! $user instanceof User || ! $team instanceof Team) {
            return $next($request);
        }

        // A personal workspace, or any team with no InfoDot company behind it: InfoDot is never asked about it.
        // It is refused only when sign-in remembered that InfoDot marked the personal workspace not entitled.
        if ($team->personal_team || blank($team->infodot_team_uuid)) {
            $remembered = $request->hasSession() ? $request->session()->get(RedeemedCompanies::PERSONAL_SESSION_KEY) : null;

            return $remembered === false
                ? EntitlementRefusal::response($request, EntitlementRefusal::NEEDS_COMPANY, $team, $user)
                : $next($request);
        }

        $answer = $this->check->for((string) $team->infodot_team_uuid);

        if ($answer === null || $answer['entitled'] === true) {
            return $next($request);
        }

        return EntitlementRefusal::response($request, $answer['status'], $team, $user);
    }

    private function exempt(Request $request): bool
    {
        if ($request->is(...self::EXEMPT_PATHS)) {
            return true;
        }

        $name = $request->route()?->getName();

        return is_string($name) && $request->routeIs(...self::EXEMPT);
    }
}
