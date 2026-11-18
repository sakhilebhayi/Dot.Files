<?php

namespace App\Http\Middleware;

use App\Support\CentralSignIn;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With InfoDot as the only front door, a password, reset or two-factor post here must not become a second one.
 *
 * "Only front door" means the older ecosystem lock (ECOSYSTEM_SSO_ONLY, on by default in production) or the R-54 central
 * sign-in (DOTSSO_ENABLED, unless the break-glass DOTSSO_ALLOW_LOCAL_LOGIN is set). R-54 closes the gap this platform
 * had: under sso_only only the GET pages redirected, while `POST /login`, the reset POSTs and the two-factor POST stayed
 * wired. They answer 404 now. Break-glass is unchanged: ECOSYSTEM_SSO_ONLY=false (and DOTSSO off) restores them.
 */
class RefuseLocalSignIn
{
    public function handle(Request $request, Closure $next): Response
    {
        $locked = config('ecosystem.sso_only') || CentralSignIn::active();

        abort_if($locked && ! $request->isMethodSafe() && $request->routeIs('login.store', 'password.email', 'password.update', 'two-factor.login.store'), 404);

        return $next($request);
    }
}
