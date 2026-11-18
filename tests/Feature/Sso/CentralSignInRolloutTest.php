<?php

namespace Tests\Feature\Sso;

use App\Mail\SsoClaimCodeMail;
use App\Models\Team;
use App\Models\User;
use App\Sso\FilesProvisioner;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** R-54 rollout: Dot.Files behind DOTSSO_ENABLED. Off, nothing changes; on, every entry goes to InfoDot. */
class CentralSignInRolloutTest extends TestCase
{
    use RefreshDatabase;

    private FakeIdp $idp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idp = (new FakeIdp)->install();
        config([
            'dotsso.provisioner' => FilesProvisioner::class,
            'dotsso.enabled' => false,
            'ecosystem.sso_only' => false,
        ]);
        Mail::fake();
    }

    private function on(): void
    {
        config(['dotsso.enabled' => true]);
    }

    private function signInVia(array $claims = [], array $session = [])
    {
        $response = $this->withSession($session)->get('/sso/login')->assertRedirect();
        $authorize = FakeIdp::authorizeParams($response->headers->get('Location'));
        $code = $this->idp->approve($authorize, $claims);

        return $this->get('/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]));
    }

    public function test_the_users_table_has_a_nullable_unique_infodot_user_uuid(): void
    {
        $a = User::factory()->create();
        $this->assertNull($a->infodot_user_uuid);
        User::factory()->create();

        $a->forceFill(['infodot_user_uuid' => 'x'])->save();
        $this->expectException(QueryException::class);
        User::factory()->create()->forceFill(['infodot_user_uuid' => 'x'])->save();
    }

    public function test_with_the_flag_off_nothing_changes(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in to Dot.Files');
        $this->get('/sso/login')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('/sso/login', false);
    }

    public function test_with_the_flag_on_the_local_pages_go_to_infodot_sign_in(): void
    {
        $this->on();

        $this->get('/login')->assertRedirect(route('dotsso.login'));
        $this->get('/register')->assertRedirect(route('dotsso.login', ['screen' => 'register']));
        $this->get('/forgot-password')->assertRedirect(route('dotsso.login'));
        $this->get('/reset-password/abc')->assertRedirect(route('dotsso.login'));
        $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'])->assertRedirect(route('dotsso.login'));
    }

    public function test_the_signed_out_pages_link_straight_to_the_central_sign_in(): void
    {
        $this->on();

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString(route('dotsso.login'), $html);
        $this->assertStringContainsString(route('dotsso.login', ['screen' => 'register']), html_entity_decode($html));
        $this->assertStringNotContainsString('href="'.route('login').'"', $html);
        $this->assertStringNotContainsString('href="'.route('register').'"', $html);
    }

    public function test_the_break_glass_switch_brings_the_local_login_back(): void
    {
        $this->on();
        config(['dotsso.allow_local_login' => true]);

        $this->get('/login')->assertOk()->assertSee('Sign in to Dot.Files');
    }

    /** The gap R-54 closes: under the older lock only the GET pages redirected; the POST handlers stayed wired. */
    public function test_the_password_reset_and_two_factor_posts_are_refused_under_the_old_lock(): void
    {
        config(['ecosystem.sso_only' => true]);

        $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'])->assertNotFound();
        $this->post('/forgot-password', ['email' => 'a@b.test'])->assertNotFound();
        $this->post('/reset-password', ['token' => 't', 'email' => 'a@b.test', 'password' => 'x', 'password_confirmation' => 'x'])->assertNotFound();
        $this->post('/two-factor-challenge', ['code' => '123456'])->assertNotFound();
    }

    public function test_the_posts_are_refused_when_the_central_sign_in_is_on_and_reopen_with_break_glass(): void
    {
        $this->on();
        // The shared package answers first: the credential post is bounced to the central sign-in, never processed.
        $this->post('/forgot-password', ['email' => 'a@b.test'])->assertRedirect(route('dotsso.login'));
        $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'])->assertRedirect(route('dotsso.login'));

        config(['dotsso.allow_local_login' => true]);
        $this->post('/forgot-password', ['email' => 'a@b.test'])->assertStatus(302);
    }

    public function test_the_posts_work_while_both_locks_are_off(): void
    {
        $this->post('/forgot-password', ['email' => 'a@b.test'])->assertSessionHasErrors('email');
    }

    public function test_a_signed_out_object_link_survives_the_round_trip(): void
    {
        $this->on();
        $uuid = '3f2b8a52-7c1e-4f6a-9d0e-0a1b2c3d4e5f';
        $this->get('/o/'.$uuid)->assertRedirect('/login');
        $this->get('/login')->assertRedirect(route('dotsso.login'));

        $this->signInVia()->assertRedirect('/o/'.$uuid);
        $this->assertAuthenticated();
    }

    public function test_a_signed_out_folder_link_survives_the_round_trip(): void
    {
        $this->on();
        $this->get('/files?q=budget')->assertRedirect('/login');

        $this->signInVia()->assertRedirect('/files?q=budget');
    }

    public function test_a_link_outside_the_room_is_not_followed(): void
    {
        $this->on();

        $this->signInVia([], ['url.intended' => 'https://evil.test/files'])->assertRedirect('/files');
        auth()->logout();
        $this->signInVia([], ['url.intended' => url('/sso/logout')])->assertRedirect('/files');
    }

    public function test_a_first_sign_in_creates_the_account_with_the_infodot_id_and_a_personal_team(): void
    {
        $this->on();

        $this->signInVia(['sub' => 'infodot-uuid-9', 'email' => 'new@example.test', 'name' => 'Nia New'])->assertRedirect('/files');

        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('infodot-uuid-9', $user->infodot_user_uuid);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Team::where('user_id', $user->id)->where('personal_team', true)->exists());
        $this->assertAuthenticatedAs($user);
    }

    public function test_provisioning_can_be_switched_off_like_the_old_receiver(): void
    {
        $this->on();
        config(['dotsso.auto_provision' => false]);

        $this->signInVia(['sub' => 'infodot-uuid-9', 'email' => 'nobody@example.test'])->assertForbidden();
        $this->assertGuest();
        $this->assertNull(User::where('email', 'nobody@example.test')->first());
    }

    public function test_an_unverified_local_user_claims_with_a_code_and_keeps_data_and_password(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'ada@example.test', 'email_verified_at' => null, 'password' => Hash::make('old-password-1')]);
        $team = $user->ownedTeams()->first();
        $hash = $user->password;

        $this->signInVia(['sub' => 'infodot-uuid-5'])->assertRedirect(route('dotsso.claim'));
        $this->assertGuest();

        $sent = null;
        Mail::assertSent(SsoClaimCodeMail::class, function ($m) use (&$sent) {
            $sent = $m->code;

            return $m->hasTo('ada@example.test');
        });

        $this->get('/sso/claim')->assertOk()->assertSee('Confirm it is you')->assertDontSee($sent);
        $this->post('/sso/claim', ['code' => $sent])->assertRedirect('/files');

        $user->refresh();
        $this->assertSame('infodot-uuid-5', $user->infodot_user_uuid);
        $this->assertSame($hash, $user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($team->fresh()->is($user->ownedTeams()->first()), 'team untouched');
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_claim_code_signs_nobody_in(): void
    {
        $this->on();
        User::factory()->create(['email' => 'ada@example.test', 'email_verified_at' => null]);
        $this->signInVia(['sub' => 'infodot-uuid-5'])->assertRedirect(route('dotsso.claim'));

        $this->from('/sso/claim')->post('/sso/claim', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_the_claim_page_is_one_form_with_a_labelled_code_field_and_real_buttons(): void
    {
        $this->on();
        User::factory()->create(['email' => 'ada@example.test', 'email_verified_at' => null]);
        $this->signInVia()->assertRedirect(route('dotsso.claim'));

        $html = $this->get('/sso/claim')->assertOk()->getContent();

        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertMatchesRegularExpression('/<label[^>]+for="code"/', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_the_error_page_says_what_happened_and_offers_one_next_step(): void
    {
        $this->on();

        $html = $this->get('/sso/callback?error=access_denied')->getContent();

        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('Back to Dot.Files', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    /** Regression: the session guard comes from the package default; the sanctum routes must still work beside it. */
    public function test_a_sanctum_route_works_and_the_package_default_guard_is_web(): void
    {
        $this->on();
        $this->assertSame('web', config('dotsso.guard'));

        $user = User::factory()->withPersonalTeam()->create();

        $this->getJson('/api/user')->assertUnauthorized();
        Sanctum::actingAs($user);
        $this->getJson('/api/user')->assertOk();
    }

    public function test_the_machine_doors_the_health_check_and_the_fallback_receiver_are_unaffected(): void
    {
        $this->on();

        $this->get('/up')->assertOk();
        $this->postJson('/api/ecosystem/membership', [])->assertStatus(401);
        $this->postJson('/api/platform/files/list', [])->assertStatus(401);
        $this->postJson('/csp-report', [])->assertStatus(204);
        $this->get('/auth/ecosystem')->assertRedirect('/login');
    }
}
