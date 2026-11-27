<?php

namespace App\Services\Ecosystem;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A signed server-to-server request is honoured once (EA-1). The signature covers the timestamp and the body, so the
 * same signature means the same request: a second arrival inside the freshness window is a replay and is refused.
 * Callers that retry sign again with a new timestamp, so a genuine retry always carries a new signature.
 */
class ReplayGuard
{
    /** Longer than the ±300 s freshness window on either side, so a signature cannot outlive its memory. */
    private const REMEMBER_FOR = 660;

    public static function firstUse(string $scope, string $signature): bool
    {
        try {
            return Cache::add('signed-request:'.$scope.':'.hash('sha256', $signature), 1, self::REMEMBER_FOR);
        } catch (Throwable $e) {
            // No cache, no memory: the signature and freshness checks still stand on their own.
            Log::warning('Replay guard unavailable; signed request accepted on signature and freshness only.', ['error' => class_basename($e)]);

            return true;
        }
    }
}
