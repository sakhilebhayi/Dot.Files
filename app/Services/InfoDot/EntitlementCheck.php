<?php

namespace App\Services\InfoDot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks InfoDot whether a company still holds Dot.Files (R-50).
 *
 * One read per company, kept for 60 seconds, so a switch made at InfoDot (a platform put in maintenance)
 * takes effect within about a minute (R-61 availability contract, as Dot.Tasks). The answer is one of three
 * things: held or not held (with InfoDot's status for the wording, and whether InfoDot has the platform
 * switched on at all: `available`, `message`, `features`), or unknown. Every clear answer is also kept for
 * six hours. Unknown covers every failure (timeout, refused secret, unknown company, a body we cannot read,
 * our own row missing, no secret configured): the last clear answer under six hours old is used, so a
 * company last told "unavailable" or "not held" stays that way through an InfoDot outage; with none ever
 * stored the caller lets the person carry on. An outage or misconfiguration at InfoDot never locks out a
 * company that was last told it could work. Only the company's uuid is sent; nothing about a person.
 */
class EntitlementCheck
{
    public const KNOWN_FOR = 60;

    /** How long the last clear answer is used when InfoDot cannot be reached. */
    public const LAST_KNOWN_FOR = 21600;

    public const UNKNOWN_FOR = 60;

    public function enabled(): bool
    {
        return (bool) config('ecosystem.entitlement_check', true);
    }

    /**
     * @return array{entitled: bool, status: string, available: bool, message: ?string, features: array<string, array{state: string, message: ?string}>}|null null when InfoDot gave no clear answer and none is remembered
     */
    public function for(string $teamUuid): ?array
    {
        $key = 'infodot:entitlement:'.$this->platform().':'.$teamUuid;

        try {
            $cached = Cache::get($key);
        } catch (Throwable) {
            $cached = null;
        }

        // An entry written before the availability contract has no `available` and is a miss, never read as "available".
        if (is_array($cached) && array_key_exists('answer', $cached) && ($cached['answer'] === null || (is_array($cached['answer']) && array_key_exists('available', $cached['answer'])))) {
            return $cached['answer'];
        }

        $answer = $this->ask($teamUuid);

        try {
            if ($answer !== null) {
                Cache::put($key.':last', ['answer' => $answer, 'at' => time()], self::LAST_KNOWN_FOR);
            } else {
                $last = Cache::get($key.':last');
                $answer = is_array($last) && is_array($last['answer'] ?? null) && array_key_exists('available', $last['answer']) && time() - (int) ($last['at'] ?? 0) < self::LAST_KNOWN_FOR ? $last['answer'] : null;
            }

            Cache::put($key, ['answer' => $answer], $answer === null ? self::UNKNOWN_FOR : self::KNOWN_FOR);
        } catch (Throwable) {
            // No cache, no memory of the answer: the next request asks again.
        }

        return $answer;
    }

    /**
     * @return array{entitled: bool, status: string, available: bool, message: ?string, features: array<string, array{state: string, message: ?string}>}|null
     */
    private function ask(string $teamUuid): ?array
    {
        $url = $this->url($teamUuid);

        if (blank(config('ecosystem.infodot.secret')) || $url === null) {
            return $this->unknown('not configured');
        }

        try {
            $response = Http::withToken((string) config('ecosystem.infodot.secret'))
                ->withHeaders(['X-Dot-Platform' => $this->platform()])
                ->acceptJson()
                ->timeout($this->timeout())
                ->get($url, ['platform' => $this->platform()]);
        } catch (Throwable $e) {
            return $this->unknown('unreachable', ['error' => class_basename($e)]);
        }

        if (! $response->successful()) {
            return $this->unknown('refused', ['status' => $response->status()]);
        }

        $platforms = $response->json('platforms');

        if (! is_array($platforms)) {
            return $this->unknown('unreadable answer');
        }

        foreach ($platforms as $row) {
            if (is_array($row) && ($row['key'] ?? null) === $this->platform()) {
                if (! is_bool($row['entitled'] ?? null)) {
                    return $this->unknown('unreadable answer');
                }

                return [
                    'entitled' => $row['entitled'],
                    'status' => is_string($row['status'] ?? null) ? $row['status'] : '',
                    // R-61: an InfoDot from before contract 2 gives no `available`, which is not a switch-off.
                    'available' => ($row['available'] ?? true) !== false,
                    'message' => is_string($row['message'] ?? null) && $row['message'] !== '' ? mb_substr($row['message'], 0, 400) : null,
                    'features' => self::features($row['features'] ?? null),
                ];
            }
        }

        return $this->unknown('own platform not listed');
    }

    /**
     * The feature switches in the caller's own row (R-61), kept as key => state and message. Anything malformed is
     * ignored, and a feature that is not listed is available.
     *
     * @return array<string, array{state: string, message: ?string}>
     */
    private static function features(mixed $raw): array
    {
        $out = [];

        foreach (is_array($raw) ? $raw : [] as $key => $row) {
            if (is_string($key) && is_array($row) && is_string($row['state'] ?? null)) {
                $out[$key] = ['state' => $row['state'], 'message' => is_string($row['message'] ?? null) && $row['message'] !== '' ? mb_substr($row['message'], 0, 400) : null];
            }
        }

        return $out;
    }

    /** Asked inside a page load, so it waits less than the sign-in call does, unless FILES_ENTITLEMENT_TIMEOUT sets it. */
    private function timeout(): int
    {
        $set = config('ecosystem.entitlement_timeout');

        return is_numeric($set) && (int) $set > 0 ? (int) $set : min(3, max(1, (int) config('ecosystem.infodot.timeout', 5)));
    }

    /**
     * Logged where it is produced, and the result is cached, so this is written once per cache window.
     *
     * @param  array<string, mixed>  $context
     */
    private function unknown(string $why, array $context = []): null
    {
        Log::warning('InfoDot entitlement check gave no answer; access continues unless a last clear answer is remembered.', ['reason' => $why] + $context);

        return null;
    }

    private function platform(): string
    {
        return (string) config('ecosystem.infodot.platform', 'files');
    }

    private function url(string $teamUuid): ?string
    {
        $configured = config('ecosystem.entitlement_url');

        if (is_string($configured) && $configured !== '') {
            return str_replace('{uuid}', rawurlencode($teamUuid), $configured);
        }

        $base = (string) config('ecosystem.infodot.companies_url', '');

        if ($base === '') {
            $redeem = (string) config('ecosystem.infodot.redeem_url', '');

            if (! str_ends_with($redeem, '/redeem')) {
                return null;
            }

            $base = substr($redeem, 0, -strlen('/redeem')).'/companies';
        }

        return rtrim($base, '/').'/'.rawurlencode($teamUuid).'/platforms';
    }
}
