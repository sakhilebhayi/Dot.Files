<?php

namespace App\Services\Ecosystem;

use Illuminate\Http\Request;

/**
 * Which Dot platform really sent this server-to-server request — and is it fresh?
 *
 * Same discipline as InfoDot's SignedRequest: HMAC-SHA256 over "{timestamp}.{raw body}" with the
 * named platform's own secret (config ecosystem.platform_secrets), five-minute tolerance. One
 * secret per platform, so no platform can speak as another; a platform without a configured
 * secret simply cannot call. Every endpoint is a POST with a JSON body, so everything that
 * matters is under the signature — nothing authorising ever rides in the URL.
 */
class PlatformRequest
{
    private const TOLERANCE = 300;

    /** The verified calling platform's key, or null. */
    public static function platform(Request $request): ?string
    {
        $key = strtolower(trim((string) $request->header('X-Dot-Platform')));
        $secret = (string) (config('ecosystem.platform_secrets')[$key] ?? '');
        $timestamp = (int) $request->header('X-Dot-Timestamp');
        $signature = (string) $request->header('X-Dot-Signature');

        if ($key === '' || $secret === '' || $timestamp === 0 || abs(time() - $timestamp) > self::TOLERANCE) {
            return null;
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $signature) ? $key : null;
    }
}
