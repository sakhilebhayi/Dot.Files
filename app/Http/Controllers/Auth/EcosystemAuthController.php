<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EcosystemHandoffService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class EcosystemAuthController extends Controller
{
    public function handle(Request $request): RedirectResponse
    {
        $accessToken = PersonalAccessToken::findToken($request->query('token'));

        abort_if(
            ! $accessToken
            || ! $accessToken->can('ecosystem:read')
            || ($accessToken->expires_at && $accessToken->expires_at->isPast()),
            403
        );

        /** @var User $user */
        $user = $accessToken->tokenable;
        $accessToken->delete();

        Auth::login($user);

        return redirect()->to($this->safeRedirect($request->query('redirect')) ?? route('files'));
    }

    /**
     * Where a handoff may land: a path INSIDE this application, or nowhere.
     *
     * The other products link a person straight to the thing they were
     * heading for ("open this folder in Dot.Files"), so the target arrives as
     * a query parameter - and a query parameter that becomes a redirect is an
     * open redirect unless it is pinned to this origin. Anything the rule
     * refuses falls back to the file browser rather than erroring: a bad link
     * should still sign the person in.
     *
     * The rule itself lives in {@see EcosystemHandoffService::safePath()},
     * which is also what this application's own outbound links are built
     * through, so what Dot.Files mints and what Dot.Files accepts cannot
     * drift apart. It is a port of the Dot.Doc side's identical check.
     */
    private function safeRedirect(mixed $target): ?string
    {
        return EcosystemHandoffService::safePath($target);
    }
}
