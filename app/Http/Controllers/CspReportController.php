<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Receives Content-Security-Policy violation reports while the policy is report-only, so the
 * eventual enforced policy is designed from what the pages actually load. It keeps almost
 * nothing: the directive and the blocked HOST (never the full address, which can carry tokens),
 * logged at most once per pair per day. The payload is attacker-controlled: fields may be arrays
 * and hosts arbitrarily long, so values are type-checked and capped before use (fleet hardening).
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $raw = substr((string) $request->getContent(), 0, 8192);
        $json = json_decode($raw, true);
        $reports = is_array($json) ? (isset($json['csp-report']) ? [$json['csp-report']] : (array_is_list($json) ? array_map(fn ($r) => is_array($r) ? ($r['body'] ?? []) : [], $json) : [])) : [];

        foreach (array_slice($reports, 0, 5) as $r) {
            if (! is_array($r)) {
                continue;
            }

            $directive = substr(preg_replace('/[^a-z-]/', '', strtolower($this->firstString($r, ['effective-directive', 'effectiveDirective', 'violated-directive']))), 0, 40);
            $blocked = $this->firstString($r, ['blocked-uri', 'blockedURL']);
            $host = in_array($blocked, ['inline', 'eval', 'self', ''], true) ? ($blocked ?: 'none') : (strtolower((string) parse_url($blocked, PHP_URL_HOST)) ?: 'other');
            $host = substr(preg_replace('/[^a-z0-9.\-]/', '', $host), 0, 100);

            if ($directive !== '' && Cache::add("csp:$directive:$host:".now()->format('Y-m-d'), 1, 90000)) {
                Log::info('csp.violation', ['directive' => $directive, 'blocked_host' => $host]);
            }
        }

        return response()->noContent();
    }

    /** The first of the named keys whose value is a non-empty string; anything else is ignored. */
    private function firstString(array $report, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($report[$key]) && is_string($report[$key]) && $report[$key] !== '') {
                return $report[$key];
            }
        }

        return '';
    }
}
