<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** R-25: baseline browser protections on every response; CSP report-only first; session-less report intake. */
class SecurityHeadersTest extends TestCase
{
    public function test_every_response_carries_the_enforced_baseline(): void
    {
        $r = $this->get('/login');

        $r->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('camera=()', (string) $r->headers->get('Permissions-Policy'));

        $this->get('/does-not-exist')->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_csp_ships_report_only_with_the_report_endpoint(): void
    {
        $r = $this->get('/login');

        $policy = (string) $r->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString('report-uri /csp-report', $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertFalse($r->headers->has('Content-Security-Policy'), 'enforcement waits for the log review');
    }

    public function test_hsts_is_https_only_and_switchable(): void
    {
        $this->assertFalse($this->get('/login')->headers->has('Strict-Transport-Security'));
        $this->assertSame('max-age=15552000', $this->get('https://localhost/login')->headers->get('Strict-Transport-Security'));

        config(['security.hsts_seconds' => 0]);
        $this->assertFalse($this->get('https://localhost/login')->headers->has('Strict-Transport-Security'));
    }

    public function test_violation_reports_are_accepted_sessionless_and_malformed_input_cannot_error(): void
    {
        Log::spy();

        $good = json_encode(['csp-report' => ['effective-directive' => 'img-src', 'blocked-uri' => 'https://evil.example/x?secret=1']]);
        $r = $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $good);
        $r->assertNoContent();
        $this->assertNull($r->headers->get('Set-Cookie'), 'a report never starts a session');

        Log::shouldHaveReceived('info')->withArgs(fn ($m, $ctx = []) => $m === 'csp.violation' && ($ctx['blocked_host'] ?? null) === 'evil.example')->once();

        // Array-valued fields and kilometre-long hosts are attacker-controlled; neither may error.
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'],
            json_encode(['csp-report' => ['effective-directive' => ['a'], 'blocked-uri' => ['b']]]))->assertNoContent();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'],
            json_encode(['csp-report' => ['effective-directive' => 'img-src', 'blocked-uri' => 'https://'.str_repeat('a', 4000).'.example']]))->assertNoContent();
    }
}
