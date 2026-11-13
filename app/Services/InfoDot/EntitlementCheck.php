<?php

namespace App\Services\InfoDot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks InfoDot whether a company still holds Dot.Files (R-50).
 *
 * One read per company, kept for 10 minutes; a failed read is kept for 60 seconds. The answer is one of
 * three things and never more: held, not held (with InfoDot's status for the wording), or unknown. Unknown
 * covers every failure (timeout, refused secret, unknown company, a body we cannot read, our own row
 * missing, no secret configured) and the caller lets the person carry on: an outage or a misconfiguration
 * at InfoDot must never lock a company out. Only the company's uuid is sent; nothing about a person.
 */
class EntitlementCheck
{
    public const KNOWN_FOR = 600;

    public const UNKNOWN_FOR = 60;

    public function enabled(): bool
    {
        return (bool) config('ecosystem.entitlement_check', true);
    }

    /**
     * @return array{entitled: bool, status: string}|null null when InfoDot gave no usable answer
     */
    public function for(string $teamUuid): ?array
    {
        $key = 'infodot:entitlement:'.$this->platform().':'.$teamUuid;
        $cached = Cache::get($key);

        if (is_array($cached) && array_key_exists('answer', $cached)) {
            return $cached['answer'];
        }

        $answer = $this->ask($teamUuid);

        Cache::put($key, ['answer' => $answer], $answer === null ? self::UNKNOWN_FOR : self::KNOWN_FOR);

        return $answer;
    }

    /**
     * @return array{entitled: bool, status: string}|null
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
                // Asked inside a page load, so it waits less than the sign-in call does.
                ->timeout(min(3, max(1, (int) config('ecosystem.infodot.timeout', 5))))
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

                return ['entitled' => $row['entitled'], 'status' => is_string($row['status'] ?? null) ? $row['status'] : ''];
            }
        }

        return $this->unknown('own platform not listed');
    }

    /**
     * Logged where it is produced, and the result is cached, so this is written once per cache window.
     *
     * @param  array<string, mixed>  $context
     */
    private function unknown(string $why, array $context = []): null
    {
        Log::warning('InfoDot entitlement check gave no answer; access continues unchanged.', ['reason' => $why] + $context);

        return null;
    }

    private function platform(): string
    {
        return (string) config('ecosystem.infodot.platform', 'files');
    }

    private function url(string $teamUuid): ?string
    {
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
