<?php

namespace App\Services;

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Models\User;

/**
 * One-click, no-re-login links into another Dot platform.
 *
 * Every product in the ecosystem shares the `personal_access_tokens` table
 * and redeems a short-lived `ecosystem:read` token at its own
 * `/auth/ecosystem` (see {@see EcosystemAuthController}
 * and InfoDot's original EcosystemTokenController, the pattern this follows).
 * Dot.Files has only ever been on the RECEIVING end of that; this is the
 * outbound half, so "Open in Dot.Doc" and "New document" land a person on
 * the exact page, signed in, in one click.
 *
 * A token is minted per click, never per rendered row: it is single-use and
 * expires in `ecosystem.handoff_ttl` minutes (five by default), so a page of
 * twenty documents rendering twenty live tokens would be twenty credentials
 * in one HTML response for nineteen clicks that never happen.
 */
class EcosystemHandoffService
{
    /**
     * A fresh single-use handoff token for this person.
     *
     * `ecosystem:read` is the only ability granted, which is what the
     * receiving `/auth/ecosystem` checks for before it will log anybody in.
     */
    public function tokenFor(User $user): string
    {
        return $user->createToken(
            'ecosystem-handoff',
            ['ecosystem:read'],
            now()->addMinutes((int) config('ecosystem.handoff_ttl', 5)),
        )->plainTextToken;
    }

    /**
     * A signed-in link to a path on another platform in the registry.
     *
     * `$redirect` is where the person lands ON THAT PLATFORM, so it is held
     * to the same rule the receiving end holds it to - a path inside the
     * application and nothing else. A target this refuses becomes the
     * platform's own default landing page rather than an error: a bad deep
     * link should still get somebody where they were going, roughly.
     */
    public function urlFor(User $user, string $platform, string $redirect): string
    {
        $base = rtrim((string) config('ecosystem.platforms.'.$platform.'.url'), '/');

        $query = ['token' => $this->tokenFor($user)];

        if ($path = self::safePath($redirect)) {
            $query['redirect'] = $path;
        }

        return $base.'/auth/ecosystem?'.http_build_query($query);
    }

    /** The Dot.Doc URL for opening one document straight in the editor. */
    public function documentUrl(User $user, string $documentUuid): string
    {
        return $this->urlFor($user, 'docs', '/documents/'.rawurlencode($documentUuid).'/edit');
    }

    /**
     * Where a handoff may land: a path INSIDE the receiving application, or
     * nowhere.
     *
     * A query parameter that becomes a redirect is an open redirect unless it
     * is pinned to one origin. A value is accepted only when it starts with a
     * single `/`, carries no backslash (browsers normalise `\` to `/`, so
     * `/\evil.test` is a protocol-relative URL in disguise), has no control
     * characters, and has no `:` before the first `/` after the leading one,
     * which is what keeps a scheme out.
     *
     * This is the one implementation of that rule in this repo: the inbound
     * controller calls it too, so the link Dot.Files mints and the link
     * Dot.Files accepts can never drift apart. It is a straight port of the
     * Dot.Doc side's `EcosystemAuthController::safeRedirect()`.
     */
    public static function safePath(mixed $target): ?string
    {
        if (! is_string($target) || $target === '') {
            return null;
        }

        if (! str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return null;
        }

        if (str_contains($target, '\\') || preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
            return null;
        }

        $rest = substr($target, 1);
        $firstSlash = strpos($rest, '/');
        $head = $firstSlash === false ? $rest : substr($rest, 0, $firstSlash);

        if (str_contains($head, ':')) {
            return null;
        }

        return $target;
    }
}
