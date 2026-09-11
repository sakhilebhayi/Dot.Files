<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EcosystemHandoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The deep-link handoff, both directions.
 *
 * Inbound: another product hands a person to Dot.Files with a `redirect`
 * telling it where to land. That is a query parameter turning into a
 * redirect, which is an open redirect unless it is pinned to this origin -
 * so the rule is tested against the shapes an attacker actually reaches for,
 * not only against the happy path.
 *
 * Outbound: Dot.Files mints the same kind of link into Dot.Doc, through the
 * SAME validation, so what this application sends and what it accepts cannot
 * drift apart.
 */
class EcosystemHandoffTest extends TestCase
{
    use RefreshDatabase;

    private function handoffToken(User $user): string
    {
        return app(EcosystemHandoffService::class)->tokenFor($user);
    }

    public function test_a_valid_relative_redirect_is_honoured(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->get('/auth/ecosystem?'.http_build_query([
            'token' => $this->handoffToken($user),
            'redirect' => '/files?uuid=abc-123',
        ]))->assertRedirect('/files?uuid=abc-123');

        $this->assertAuthenticatedAs($user);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function maliciousRedirects(): array
    {
        return [
            'protocol relative' => ['//evil.test/steal'],
            'backslash protocol relative' => ['/\\evil.test'],
            'absolute url' => ['https://evil.test'],
            'scheme in the first segment' => ['/javascript:alert(1)'],
            'bare scheme' => ['javascript:alert(1)'],
            'no leading slash' => ['files?uuid=abc'],
            'control character' => ["/files\x00/../"],
            'backslash anywhere' => ['/files\\..\\evil'],
            'empty' => [''],
        ];
    }

    #[DataProvider('maliciousRedirects')]
    public function test_a_redirect_that_could_leave_this_origin_is_refused(string $redirect): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        // Refused, not fatal: a bad link still signs the person in and lands
        // them on their files.
        $this->get('/auth/ecosystem?'.http_build_query([
            'token' => $this->handoffToken($user),
            'redirect' => $redirect,
        ]))->assertRedirect(route('files'));

        $this->assertAuthenticatedAs($user);
        $this->assertNull(EcosystemHandoffService::safePath($redirect));
    }

    public function test_a_handoff_token_is_single_use(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $this->handoffToken($user);

        $this->get('/auth/ecosystem?token='.$token)->assertRedirect(route('files'));

        $this->flushSession();

        $this->get('/auth/ecosystem?token='.$token)->assertForbidden();
    }

    public function test_a_token_without_the_ecosystem_ability_is_refused(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        // A scoped token from some other flow. (A wildcard `*` token DOES
        // satisfy the check - Sanctum's `can()` treats it as everything - so
        // the ability gate is about scoped tokens, which is what the handoff
        // mints.)
        $token = $user->createToken('api', ['files:read'], now()->addMinutes(5))->plainTextToken;

        $this->get('/auth/ecosystem?token='.$token)->assertForbidden();
        $this->assertGuest();
    }

    public function test_an_expired_token_is_refused(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('ecosystem-handoff', ['ecosystem:read'], now()->subMinute())->plainTextToken;

        $this->get('/auth/ecosystem?token='.$token)->assertForbidden();
        $this->assertGuest();
    }

    public function test_the_minted_token_expires_within_the_configured_window(): void
    {
        config(['ecosystem.handoff_ttl' => 5]);

        $user = User::factory()->withPersonalTeam()->create();
        $this->handoffToken($user);

        $token = $user->tokens()->firstOrFail();

        $this->assertSame(['ecosystem:read'], $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->between(now()->addMinutes(4), now()->addMinutes(6)));
    }

    public function test_an_outbound_link_carries_a_token_and_an_encoded_path(): void
    {
        config(['ecosystem.platforms.docs.url' => 'https://docs.example.test/']);

        $user = User::factory()->withPersonalTeam()->create();
        $url = app(EcosystemHandoffService::class)->documentUrl($user, 'abc-123');

        $this->assertStringStartsWith('https://docs.example.test/auth/ecosystem?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('/documents/abc-123/edit', $query['redirect']);
        $this->assertNotEmpty($query['token']);
    }

    public function test_an_outbound_link_refuses_to_carry_a_target_off_the_receiving_origin(): void
    {
        config(['ecosystem.platforms.docs.url' => 'https://docs.example.test']);

        $user = User::factory()->withPersonalTeam()->create();
        $url = app(EcosystemHandoffService::class)->urlFor($user, 'docs', '//evil.test/steal');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertArrayNotHasKey('redirect', $query);
        $this->assertNotEmpty($query['token']);
    }

    public function test_safe_path_accepts_the_paths_the_two_products_actually_exchange(): void
    {
        $this->assertSame('/files', EcosystemHandoffService::safePath('/files'));
        $this->assertSame('/files?uuid=abc', EcosystemHandoffService::safePath('/files?uuid=abc'));
        $this->assertSame(
            '/documents/abc/edit',
            EcosystemHandoffService::safePath('/documents/abc/edit'),
        );
        $this->assertSame(
            '/documents?folder=7&parent=abc',
            EcosystemHandoffService::safePath('/documents?folder=7&parent=abc'),
        );
    }
}
