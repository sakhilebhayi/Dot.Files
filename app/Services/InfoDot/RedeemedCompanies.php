<?php

namespace App\Services\InfoDot;

/**
 * Reads the entitlement InfoDot puts on each company of a redeem response (R-50).
 *
 * InfoDot marks every company with `entitled` (may this company use Dot.Files?) and `access` (the reason
 * when it may not). Only a company marked exactly `true` is admitted. An InfoDot that has not been
 * upgraded sends no `entitled` key at all: absence is not a refusal, and such a company is treated as it
 * always was.
 */
class RedeemedCompanies
{
    /**
     * @param  array<string, mixed>  $team  one entry of the redeem response's `teams`
     */
    public static function admits(array $team): bool
    {
        return ! array_key_exists('entitled', $team) || $team['entitled'] === true;
    }

    /** Where sign-in keeps what InfoDot said about the person's personal workspace, for the session check. */
    public const PERSONAL_SESSION_KEY = 'ecosystem.personal_entitled';

    /**
     * What InfoDot said about the person's personal workspace for Dot.Files: true, false, or null when it
     * said nothing (no personal workspace listed, or an InfoDot that does not send `entitled` yet).
     *
     * @param  array<string, mixed>  $identity  the redeem response
     */
    public static function personalEntitled(array $identity): ?bool
    {
        foreach ((array) ($identity['teams'] ?? []) as $team) {
            if (is_array($team) && ($team['personal'] ?? false) === true && array_key_exists('entitled', $team) && is_bool($team['entitled'])) {
                return $team['entitled'];
            }
        }

        return null;
    }

    /**
     * The reason nobody is let in, or null when the person may sign in. A person is turned away only when
     * InfoDot did mark their companies and marked none of them (the personal workspace included) entitled.
     *
     * @param  array<string, mixed>  $identity  the redeem response
     */
    public static function refusal(array $identity): ?string
    {
        $teams = array_values(array_filter((array) ($identity['teams'] ?? []), 'is_array'));
        $marked = array_filter($teams, fn (array $team) => array_key_exists('entitled', $team));

        if ($marked === []) {
            return null;
        }

        foreach ($teams as $team) {
            if (self::admits($team)) {
                return null;
            }
        }

        $reasons = array_values(array_unique(array_filter(array_map(fn (array $team) => $team['access'] ?? null, $marked), 'is_string')));

        // One shared reason words the page; mixed reasons fall back to the plain "not switched on".
        return count($reasons) === 1 ? $reasons[0] : 'not_activated';
    }
}
