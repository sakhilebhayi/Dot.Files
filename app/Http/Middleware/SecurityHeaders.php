<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response (fleet pattern, R-22/R-25). The hard headers are
 * ENFORCED; the Content-Security-Policy ships REPORT-ONLY first, so real violations are read from
 * the logs before enforcement — this platform serves confidential business files, and a wrongly
 * blocked asset is cheaper than guessing. A header a route already set is left alone.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ];

        // Origins audited from resources/views (R-52): scripts, styles and fonts are all this site's own now
        // (Roboto and the shared Dot UI from public/dot-ui, FilePond bundled by Vite). Only image hosts remain:
        // Unsplash photography and ui-avatars (Jetstream's default profile photo).
        $csp = "default-src 'self'; "
            ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
            ."style-src 'self' 'unsafe-inline'; "
            ."font-src 'self' data:; "
            ."img-src 'self' data: blob: https://images.unsplash.com https://ui-avatars.com; "
            ."connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; object-src 'none'; "
            ."base-uri 'self'; report-uri /csp-report";

        $headers['Content-Security-Policy-Report-Only'] = $csp;

        $hsts = (int) config('security.hsts_seconds', 15552000);
        if ($request->isSecure() && $hsts > 0) {
            $headers['Strict-Transport-Security'] = 'max-age='.$hsts;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
