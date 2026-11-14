<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCompanyHoldsPlatform;
use App\Models\File;
use App\Models\Team;
use App\Models\User;
use App\Services\FileShareService;
use App\Services\InfoDot\EntitlementCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-50: Dot.Files honours the company's entitlement, which InfoDot decides.
 *
 * At sign-in only companies InfoDot marks `entitled: true` are admitted and synced. During a session the
 * current company is re-checked against InfoDot's read endpoint (cached), and a company that no longer
 * holds Dot.Files gets a plain 403 page with no data. Every failure to get an answer lets the person carry
 * on. The response bodies below are the shapes InfoDot's own tests assert (PlatformDoorsTest,
 * CompanyPlatformsEndpointTest).
 */
class PlatformEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const COMPANIES = 'https://infodot.test/api/ecosystem/companies/*';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private string $bearer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection']);

        $this->bearer = Str::random(40);

        config([
            'ecosystem.infodot.redeem_url' => self::REDEEM,
            'ecosystem.infodot.companies_url' => null,
            'ecosystem.infodot.platform' => 'files',
            'ecosystem.infodot.secret' => $this->bearer,
            'ecosystem.infodot.timeout' => 2,
            'ecosystem.infodot.provision' => true,
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
            'ecosystem.entitlement_check' => true,
        ]);
    }

    // ---- helpers ---------------------------------------------------------------------------------

    /** One company in a redeem response, as InfoDot sends it. Pass $entitled null for an InfoDot without the key. */
    private function listed(string $uuid, string $name, string $role, ?bool $entitled = true, ?string $access = null, bool $personal = false): array
    {
        $team = ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => $personal, 'role' => $role, 'infodot_role' => $role];

        return $entitled === null ? $team : $team + ['entitled' => $entitled, 'access' => $entitled ? null : ($access ?? 'not_activated')];
    }

    private function signIn(string $email, array $teams, ?string $current = null)
    {
        Http::swap(new Factory);
        Http::fake([self::REDEEM => Http::response([
            'platform' => 'files',
            'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
            'current_team_uuid' => $current,
        ])]);

        auth()->logout();

        return $this->post(route('auth.ecosystem'), ['token' => Str::random(40)], ['Origin' => 'https://infodot.test']);
    }

    /** The read endpoint's body for a company, as InfoDot sends it; $files is this platform's own row (null leaves it out). */
    private function holding(?array $files, string $uuid = self::ACME, bool $personal = false): array
    {
        return [
            'platform' => 'files',
            'company' => ['uuid' => $uuid, 'personal' => $personal],
            'platforms' => array_values(array_filter([
                ['key' => 'finance', 'name' => 'Dot.Finance', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                $files === null ? null : ['key' => 'files', 'name' => 'Dot.Files'] + $files + ['is_core' => true, 'access_mode' => 'everyone'],
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'free_while_pricing_finalised', 'entitled' => false, 'is_core' => false],
            ])),
        ];
    }

    private function infodotSays(mixed $response): void
    {
        Http::swap(new Factory);
        Http::fake([self::COMPANIES => $response]);
    }

    /**
     * The header's platform switcher (shared Dot UI, R-51) reads the same InfoDot endpoint under its own cache key.
     * These tests count and sequence the ENTITLEMENT check's requests, so the switcher's answer is parked as a recent
     * failure: it then asks nothing and every count below stays the entitlement check's own. The switcher has its
     * own tests (DotUiHeaderTest).
     */
    private function parkSwitcher(string $uuid): void
    {
        Cache::put('infodot:platforms:files:'.strtolower($uuid), ['ok' => false, 'personal' => false, 'rows' => []], 3600);
    }

    /** A signed-in owner whose current team is a company linked to InfoDot, holding one file and one folder. */
    private function ownerOf(string $uuid = self::ACME, string $name = 'Acme'): User
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $owner->switchTeam($team);
        $this->parkSwitcher($uuid);

        $this->fileIn($team, 'Budget 2027.txt');

        return $owner->fresh();
    }

    /** A real record in the company's workspace: the blob, the file and its place under the root folder. */
    private function fileIn(Team $team, string $name): File
    {
        Storage::disk('local')->put($path = 'files/'.Str::random(8).'.txt', 'budget numbers');
        $file = $team->files()->create(['name' => $name, 'size' => 14, 'path' => $path, 'mime' => 'text/plain']);

        $place = $team->objects()->make(['parent_id' => $team->objects()->withoutGlobalScope('team')->whereNull('parent_id')->firstOrFail()->id]);
        $place->objectable()->associate($file);
        $place->save();

        return $file;
    }

    private function companyCalls(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), '/api/ecosystem/companies/'))->count();
    }

    // ---- at sign-in (redeem) ---------------------------------------------------------------------

    public function test_a_company_marked_entitled_is_admitted_and_synced(): void
    {
        $this->signIn('thabo@acme.test', [$this->listed(self::ACME, 'Acme', 'owner')], self::ACME)->assertRedirect(route('files'));

        $this->assertAuthenticated();
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $this->assertSame($acme->id, auth()->user()->fresh()->current_team_id);
    }

    public function test_a_company_marked_not_entitled_is_not_created_and_cannot_become_current(): void
    {
        $this->signIn('thabo@acme.test', [
            $this->listed(self::ACME, 'Acme', 'owner'),
            $this->listed(self::GLOBEX, 'Globex', 'owner', false, 'payment_required'),
        ], self::GLOBEX)->assertRedirect(route('files'));

        $this->assertAuthenticated();
        $this->assertNull(Team::where('infodot_team_uuid', self::GLOBEX)->first(), 'the refused company is not created here');

        $user = auth()->user()->fresh();
        $this->assertNotNull(Team::where('infodot_team_uuid', self::ACME)->first());
        $this->assertFalse($user->currentTeam->infodot_team_uuid === self::GLOBEX);
    }

    public function test_a_company_that_lost_access_is_left_exactly_as_it_was_and_the_person_lands_elsewhere(): void
    {
        // First sign-in: both held; Globex is the active company and the person is an editor of it.
        $boss = User::factory()->withPersonalTeam()->create();
        $globex = Team::forceCreate(['user_id' => $boss->id, 'name' => 'Globex', 'personal_team' => false, 'infodot_team_uuid' => self::GLOBEX]);

        $this->signIn('thabo@acme.test', [
            $this->listed(self::ACME, 'Acme', 'owner'),
            $this->listed(self::GLOBEX, 'Globex', 'editor'),
        ], self::GLOBEX);

        $user = User::where('email', 'thabo@acme.test')->firstOrFail();
        $this->assertSame($globex->id, $user->current_team_id);

        // Next sign-in: Globex is suspended, renamed and the role changed at InfoDot. None of it is applied here.
        $this->signIn('thabo@acme.test', [
            $this->listed(self::ACME, 'Acme', 'owner'),
            $this->listed(self::GLOBEX, 'Globex Renamed', 'admin', false, 'suspended'),
        ], self::GLOBEX)->assertRedirect(route('files'));

        $user = $user->fresh();
        $this->assertSame('Globex', $globex->fresh()->name);
        $this->assertTrue($user->belongsToTeam($globex), 'membership is kept: the data and the people are untouched');
        $this->assertSame('editor', $globex->users()->where('users.id', $user->id)->first()->membership->role);
        $this->assertSame(self::ACME, $user->currentTeam->infodot_team_uuid, 'moved to the company that still holds Dot.Files');
    }

    public function test_a_response_without_the_entitled_key_behaves_exactly_as_before(): void
    {
        $this->signIn('thabo@acme.test', [
            $this->listed(self::ACME, 'Acme', 'owner', null),
            $this->listed(self::GLOBEX, 'Globex', 'admin', null),
        ], self::GLOBEX)->assertRedirect(route('files'));

        $this->assertAuthenticated();
        $this->assertNotNull(Team::where('infodot_team_uuid', self::ACME)->first());
        $this->assertSame(self::GLOBEX, auth()->user()->fresh()->currentTeam->infodot_team_uuid);
    }

    public function test_only_exactly_true_admits_a_company(): void
    {
        $odd = ['entitled' => 'yes'] + $this->listed(self::GLOBEX, 'Globex', 'owner', null);

        $this->signIn('thabo@acme.test', [$this->listed(self::ACME, 'Acme', 'owner'), $odd], self::GLOBEX);

        $this->assertNull(Team::where('infodot_team_uuid', self::GLOBEX)->first());
    }

    public function test_when_no_company_is_entitled_the_person_gets_the_plain_page_and_nothing_is_created(): void
    {
        $response = $this->signIn('thabo@acme.test', [
            $this->listed((string) Str::uuid(), "Thabo's Team", 'owner', false, 'not_activated', personal: true),
            $this->listed(self::ACME, 'Acme', 'owner', false, 'not_activated'),
        ], self::ACME);

        $response->assertStatus(403)
            ->assertSee('Dot.Files is not switched on for your company')
            ->assertSee('Open Platforms in InfoDot')
            ->assertSee('https://infodot.test/platforms/files', false)
            ->assertDontSee('Whoops')
            ->assertDontSee('Stack trace');

        $this->assertGuest();
        $this->assertSame(0, User::count());
        $this->assertSame(0, Team::count());
    }

    public function test_the_sign_in_page_is_worded_from_the_reason(): void
    {
        $this->signIn('thabo@acme.test', [$this->listed(self::ACME, 'Acme', 'owner', false, 'suspended')])
            ->assertStatus(403)->assertSee('Dot.Files is suspended for your company');

        $this->signIn('thabo@acme.test', [$this->listed(self::ACME, 'Acme', 'owner', false, 'payment_required')])
            ->assertStatus(403)->assertSee('A payment is needed before your company can use Dot.Files');

        $this->signIn('thabo@acme.test', [$this->listed(self::ACME, 'Acme', 'owner', false, 'expired')])
            ->assertStatus(403)->assertSee('Dot.Files has ended for your company');
    }

    /**
     * Dot.Files is a free core platform: InfoDot's redeem marks the personal workspace entitled
     * (PlatformDoorsTest::test_redeem_for_a_core_platform_entitles_every_company_and_the_personal_workspace),
     * so a person with no company signs in and their personal storage works, with nothing to ask afterwards.
     */
    public function test_a_personal_workspace_on_this_core_platform_signs_in_and_personal_storage_keeps_working(): void
    {
        $this->signIn('thabo@home.test', [
            $this->listed((string) Str::uuid(), "Thabo's Team", 'owner', true, personal: true),
        ])->assertRedirect(route('files'));

        $this->assertAuthenticated();
        $user = auth()->user()->fresh();
        $this->assertTrue($user->currentTeam->personal_team);

        $this->infodotSays(Http::response($this->holding(['status' => 'suspended', 'entitled' => false])));
        $this->fileIn($user->currentTeam, 'My passport scan.txt');

        $this->actingAs($user)->get('/files')->assertOk()->assertSee('My passport scan.txt');
        $this->assertSame(0, $this->companyCalls(), 'a personal workspace has no InfoDot company to ask about');
    }

    // ---- personal workspaces (individuals hold the free core only) -------------------------------

    /** Sign in as a person with a company that holds the platform and a personal workspace marked as given (null: key absent). */
    private function signInWithPersonal(?bool $personalEntitled): User
    {
        $this->signIn('thabo@acme.test', [
            $this->listed((string) Str::uuid(), "Thabo's Team", 'owner', $personalEntitled, 'no_personal_access', personal: true),
            $this->listed(self::ACME, 'Acme', 'owner', $personalEntitled === null ? null : true),
        ], self::ACME)->assertRedirect(route('files'));

        $user = User::where('email', 'thabo@acme.test')->firstOrFail();
        $this->fileIn($user->personalTeam(), 'My passport scan.txt');
        $this->infodotSays(Http::response($this->holding(['status' => 'included', 'entitled' => true])));

        return $user->fresh();
    }

    public function test_personal_workspace_marked_true_keeps_working_and_is_never_asked_about(): void
    {
        $user = $this->signInWithPersonal(true);
        $this->assertTrue(session('ecosystem.personal_entitled'));

        $this->actingAs($user)->withSession(['ecosystem.personal_entitled' => true])->put(route('current-team.update'), ['team_id' => $user->personalTeam()->id]);
        $this->actingAs($user->fresh())->withSession(['ecosystem.personal_entitled' => true])->get('/files')->assertOk()->assertSee('My passport scan.txt');

        $this->assertSame(0, $this->companyCalls());
    }

    public function test_personal_workspace_marked_false_is_refused_but_untouched_and_a_company_is_offered(): void
    {
        $user = $this->signInWithPersonal(false);
        $this->assertFalse(session('ecosystem.personal_entitled'));
        $personal = $user->personalTeam();
        $user->switchTeam($personal);

        $this->actingAs($user->fresh())->withSession(['ecosystem.personal_entitled' => false])->get('/files')
            ->assertStatus(403)
            ->assertSee('Dot.Files needs a company that has switched it on')
            ->assertSee('Open Platforms in InfoDot')
            ->assertSee('https://infodot.test/platforms/files', false)
            ->assertSee('Acme')
            ->assertDontSee('My passport scan.txt');

        $this->actingAs($user->fresh())->withSession(['ecosystem.personal_entitled' => false])->getJson('/files')->assertStatus(403);
        $this->assertSame(0, $this->companyCalls(), 'a team with no InfoDot company is never asked about');
        $this->assertSame(1, File::withoutGlobalScopes()->where('name', 'My passport scan.txt')->count(), 'nothing is deleted or altered');

        // Switching to the entitled company works, and the company is checked as usual.
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $this->actingAs($user->fresh())->withSession(['ecosystem.personal_entitled' => false])->put(route('current-team.update'), ['team_id' => $acme->id])->assertRedirect();
        $this->actingAs($user->fresh())->withSession(['ecosystem.personal_entitled' => false])->get('/files')->assertOk()->assertDontSee('My passport scan.txt');
    }

    public function test_personal_workspace_with_nothing_remembered_carries_on(): void
    {
        $user = $this->signInWithPersonal(null);
        $this->assertNull(session('ecosystem.personal_entitled'));
        $user->switchTeam($user->personalTeam());

        $this->actingAs($user->fresh())->get('/files')->assertOk()->assertSee('My passport scan.txt');
        $this->assertSame(0, $this->companyCalls());
    }

    public function test_a_team_with_no_infodot_company_follows_the_remembered_answer_without_asking_infodot(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $local = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Local only', 'personal_team' => false]);
        $owner->switchTeam($local);

        $this->actingAs($owner->fresh())->get('/files')->assertOk();
        $this->actingAs($owner->fresh())->withSession(['ecosystem.personal_entitled' => true])->get('/files')->assertOk();
        $this->actingAs($owner->fresh())->withSession(['ecosystem.personal_entitled' => false])->get('/files')->assertStatus(403);
        $this->assertSame(0, $this->companyCalls());
    }

    public function test_the_personal_rule_leaves_shared_links_the_platform_api_and_public_pages_alone(): void
    {
        $owner = User::factory()->withPersonalTeam()->create(['email' => 'pat@globex.test', 'email_verified_at' => now()]);
        $session = ['ecosystem.personal_entitled' => false];

        $this->actingAs($owner)->withSession($session)->get('/files')->assertStatus(403);
        $this->actingAs($owner)->withSession($session)->get(route('shared'))->assertOk();
        $this->actingAs($owner)->withSession($session)->get('/cookies')->assertOk();
        $this->actingAs($owner)->withSession($session)->get('/up')->assertOk();
        $this->postJson('/api/platform/files/meta', [])->assertUnauthorized();
        $this->actingAs($owner)->withSession($session)->post('/logout')->assertRedirect();
    }

    // ---- during a session ------------------------------------------------------------------------

    public function test_a_company_that_holds_the_platform_carries_on_and_the_call_is_shaped_as_the_contract_says(): void
    {
        $owner = $this->ownerOf();
        $this->infodotSays(Http::response($this->holding(['status' => 'included', 'entitled' => true])));

        $this->actingAs($owner)->get('/files')->assertOk()->assertSee('Budget 2027.txt');

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://infodot.test/api/ecosystem/companies/'.self::ACME.'/platforms?')
            && str_contains($request->url(), 'platform=files')
            && $request->hasHeader('Authorization', 'Bearer '.$this->bearer)
            && $request->hasHeader('X-Dot-Platform', 'files'));
    }

    public function test_a_company_that_no_longer_holds_it_gets_a_403_page_with_no_data(): void
    {
        $owner = $this->ownerOf();
        $this->fileIn($owner->currentTeam, 'Payroll March.txt');
        $this->infodotSays(Http::response($this->holding(['status' => 'suspended', 'entitled' => false])));

        $this->actingAs($owner)->get('/files')
            ->assertStatus(403)
            ->assertSee('Dot.Files is suspended for Acme')
            ->assertSee('Nothing has been deleted or changed.')
            ->assertSee('Open Platforms in InfoDot')
            ->assertSee('https://infodot.test/platforms/files', false)
            ->assertDontSee('Budget 2027.txt')
            ->assertDontSee('Payroll March.txt')
            // One action: no button leading back into the workspace that was just refused.
            ->assertDontSee(route('dashboard'), false);

        $file = File::withoutGlobalScopes()->where('name', 'Budget 2027.txt')->firstOrFail();

        // Nothing is served or changed either: the download, the trash and the Livewire channel all stop.
        $this->actingAs($owner)->get('/files/'.$file->id)->assertStatus(403);
        $this->actingAs($owner)->get('/trash')->assertStatus(403)->assertDontSee('Budget 2027.txt');
        $this->actingAs($owner)->get('/user/profile')->assertStatus(403);
        $this->actingAs($owner)->post('/livewire/update', [])->assertStatus(403);
        $this->actingAs($owner)->getJson('/files')->assertStatus(403)->assertExactJson(['message' => 'Dot.Files is suspended for Acme']);

        $this->assertSame(2, File::withoutGlobalScopes()->count(), 'the data is untouched');
        $this->assertTrue(Storage::disk('local')->exists($file->path));
    }

    #[DataProvider('statuses')]
    public function test_the_page_is_worded_from_the_status(string $status, string $heading): void
    {
        $owner = $this->ownerOf();
        $this->infodotSays(Http::response($this->holding(['status' => $status, 'entitled' => false])));

        $this->actingAs($owner)->get('/files')->assertStatus(403)->assertSee($heading);
    }

    public static function statuses(): array
    {
        return [
            'not switched on' => ['free_while_pricing_finalised', 'Dot.Files is not switched on for Acme'],
            'pending' => ['activation_pending', 'Dot.Files is not switched on for Acme'],
            'suspended' => ['suspended', 'Dot.Files is suspended for Acme'],
            'payment needed' => ['payment_required', 'A payment is needed before Acme can use Dot.Files'],
            'ended' => ['subscription_expired', 'Dot.Files has ended for Acme'],
        ];
    }

    #[DataProvider('failures')]
    public function test_any_failure_to_get_an_answer_lets_the_person_carry_on(string $mode): void
    {
        $owner = $this->ownerOf();
        Log::spy();

        $asked = 0;

        $this->infodotSays(match ($mode) {
            'timeout' => function () use (&$asked) {
                $asked++;

                throw new ConnectionException('cURL error 28: Operation timed out');
            },
            'bad secret' => Http::response(['error' => 'invalid_request'], 401),
            'platform switched off' => Http::response(['error' => 'invalid_request'], 403),
            'unknown company' => Http::response(['error' => 'not_found'], 404),
            'server error' => Http::response('Server Error', 500),
            'throttled' => Http::response(['message' => 'Too Many Attempts.'], 429),
            'not json' => Http::response('<html>maintenance</html>', 200),
            'no platforms list' => Http::response(['platform' => 'files', 'company' => ['uuid' => self::ACME, 'personal' => false]]),
            'own row missing' => Http::response($this->holding(null)),
            'entitled is not a boolean' => Http::response($this->holding(['status' => 'suspended', 'entitled' => 'false'])),
            'entitled missing' => Http::response($this->holding(['status' => 'suspended'])),
        });

        $this->actingAs($owner)->get('/files')->assertOk()->assertSee('Budget 2027.txt');
        $this->actingAs($owner)->get('/files')->assertOk();

        // A request that never got an answer is not in the client's record, so the timeout is counted where it is thrown.
        $this->assertSame(1, $mode === 'timeout' ? $asked : $this->companyCalls(), 'the failure is cached, not retried on every request');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'entitlement check gave no answer'))->once();
    }

    public static function failures(): array
    {
        return collect(['timeout', 'bad secret', 'platform switched off', 'unknown company', 'server error', 'throttled', 'not json', 'no platforms list', 'own row missing', 'entitled is not a boolean', 'entitled missing'])
            ->mapWithKeys(fn (string $mode) => [$mode => [$mode]])->all();
    }

    public function test_a_missing_secret_lets_the_person_carry_on_without_calling_and_logs_once(): void
    {
        $owner = $this->ownerOf();
        config(['ecosystem.infodot.secret' => null]);
        $this->infodotSays(Http::response($this->holding(['status' => 'suspended', 'entitled' => false])));
        Log::spy();

        $this->actingAs($owner)->get('/files')->assertOk()->assertSee('Budget 2027.txt');
        $this->actingAs($owner)->get('/files')->assertOk();

        $this->assertSame(0, $this->companyCalls());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'entitlement check gave no answer'))->once();
    }

    public function test_the_answer_is_cached_per_company_for_ten_minutes_and_a_failure_for_a_minute(): void
    {
        $owner = $this->ownerOf();
        $this->infodotSays(Http::response($this->holding(['status' => 'included', 'entitled' => true])));

        $this->actingAs($owner)->get('/files')->assertOk();
        $this->actingAs($owner)->get('/trash')->assertOk();
        $this->assertSame(1, $this->companyCalls(), 'one call for two requests');

        Carbon::setTestNow(now()->addSeconds(EntitlementCheck::KNOWN_FOR - 5));
        $this->actingAs($owner)->get('/files')->assertOk();
        $this->assertSame(1, $this->companyCalls());

        // After ten minutes it asks again, and the answer has changed.
        Carbon::setTestNow(now()->addSeconds(10));
        $this->infodotSays(Http::response($this->holding(['status' => 'suspended', 'entitled' => false])));
        $this->actingAs($owner)->get('/files')->assertStatus(403);
        $this->assertSame(1, $this->companyCalls());

        // Another company is cached separately.
        $other = $this->ownerOf(self::GLOBEX, 'Globex');
        $this->infodotSays(Http::response('', 500));
        $this->actingAs($other)->get('/files')->assertOk();
        $this->actingAs($other)->get('/files')->assertOk();
        $this->assertSame(1, $this->companyCalls());

        // A failure is forgotten after a minute.
        Carbon::setTestNow(now()->addSeconds(EntitlementCheck::UNKNOWN_FOR + 1));
        $this->actingAs($other)->get('/files')->assertOk();
        $this->assertSame(2, $this->companyCalls());

        Carbon::setTestNow();
    }

    public function test_the_flag_off_makes_no_call(): void
    {
        $owner = $this->ownerOf();
        config(['ecosystem.entitlement_check' => false]);
        $this->infodotSays(Http::response($this->holding(['status' => 'suspended', 'entitled' => false])));

        $this->actingAs($owner)->get('/files')->assertOk()->assertSee('Budget 2027.txt');

        $this->assertSame(0, $this->companyCalls());
    }

    public function test_the_flag_defaults_to_on(): void
    {
        $this->assertTrue((require config_path('ecosystem.php'))['entitlement_check']);
        $this->assertStringContainsString('FILES_ENTITLEMENT_CHECK=true', file_get_contents(base_path('.env.example')));
    }

    public function test_public_pages_sign_in_and_sign_out_never_call_infodot(): void
    {
        $owner = $this->ownerOf();
        $this->infodotSays(Http::response($this->holding(['status' => 'suspended', 'entitled' => false])));

        // Signed out: nothing to ask about.
        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/files')->assertRedirect('/login');

        // Signed in, with a company that has lost access: these stay reachable.
        $this->actingAs($owner);
        $this->get('/')->assertOk();
        $this->get('/cookies')->assertOk();
        $this->get('/terms-of-service')->assertOk();
        $this->get('/privacy-policy')->assertOk();
        $this->get('/up')->assertOk();
        $this->get('/auth/ecosystem')->assertRedirect('/login');
        $this->post('/csp-report', [])->assertSuccessful();
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();

        $this->assertSame(0, $this->companyCalls());
    }

    public function test_the_handoff_receiver_is_not_checked_so_a_signed_in_person_can_still_arrive_from_infodot(): void
    {
        $owner = $this->ownerOf();
        $this->actingAs($owner);

        Http::swap(new Factory);
        Http::fake([
            self::REDEEM => Http::response([
                'platform' => 'files',
                'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => $owner->name, 'email' => $owner->email, 'email_verified' => true],
                'teams' => [$this->listed(self::ACME, 'Acme', 'owner')],
                'current_team_id' => 1,
                'current_team_uuid' => self::ACME,
            ]),
            self::COMPANIES => Http::response($this->holding(['status' => 'suspended', 'entitled' => false])),
        ]);

        $this->post(route('auth.ecosystem'), ['token' => Str::random(40)], ['Origin' => 'https://infodot.test'])->assertRedirect(route('files'));

        $this->assertSame(0, $this->companyCalls());
    }

    /**
     * Other Dot platforms store and fetch files here server to server. Those routes are signed, session-less
     * and outside the web group: a company's entitlement to the Dot.Files workspace never touches them.
     */
    public function test_the_platform_file_api_is_untouched_and_never_calls_infodot(): void
    {
        $owner = $this->ownerOf();
        $signing = Str::random(40);
        config(['ecosystem.platform_secrets' => ['docs' => $signing]]);
        $this->infodotSays(Http::response($this->holding(['status' => 'suspended', 'entitled' => false])));

        $signed = function (string $path, array $payload) use ($signing) {
            $body = json_encode($payload);
            $timestamp = time();

            return $this->call('POST', $path, [], [], [], [
                'HTTP_X-DOT-PLATFORM' => 'docs',
                'HTTP_X-DOT-TIMESTAMP' => (string) $timestamp,
                'HTTP_X-DOT-SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $signing),
                'CONTENT_TYPE' => 'application/json',
            ], $body);
        };

        $stored = $signed('/api/platform/files/store', ['team_uuid' => self::ACME, 'name' => 'Invoice INV-204.pdf', 'content_b64' => base64_encode('%PDF-1.4 real bytes')])
            ->assertCreated()->json();

        $signed('/api/platform/files/meta', ['team_uuid' => self::ACME, 'uuid' => $stored['uuid']])->assertOk();
        $signed('/api/platform/files/fetch', ['team_uuid' => self::ACME, 'uuid' => $stored['uuid']])->assertOk();
        $signed('/api/platform/files/list', ['team_uuid' => self::ACME])->assertOk();

        // The same routes are not softened either: an unsigned call is still refused, by the API's own check.
        $this->postJson('/api/platform/files/meta', ['team_uuid' => self::ACME, 'uuid' => $stored['uuid']])->assertUnauthorized();

        // InfoDot's own signed calls into this app are on the same footing.
        $this->postJson('/api/ecosystem/membership', [])->assertUnauthorized();
        $this->postJson('/api/ecosystem/search', [])->assertUnauthorized();

        $this->assertSame(0, $this->companyCalls());

        foreach (['api.platform.files.store', 'api.platform.files.fetch', 'api.platform.files.meta', 'api.platform.files.list', 'api.platform.files.update', 'api.platform.files.trash', 'api.ecosystem.membership', 'api.ecosystem.search'] as $name) {
            $this->assertNotContains(EnsureCompanyHoldsPlatform::class, app('router')->getRoutes()->getByName($name)->gatherMiddleware(), $name);
            $this->assertNotContains('web', app('router')->getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    /**
     * A file another company shared with a person belongs to that person, by verified email. Their own
     * company losing Dot.Files does not take it away, and the download behaves exactly as before.
     */
    public function test_shared_file_downloads_are_untouched_and_never_call_infodot(): void
    {
        $sharer = $this->ownerOf();
        $file = File::withoutGlobalScopes()->firstOrFail();

        $recipient = $this->ownerOf(self::GLOBEX, 'Globex');
        $recipient->forceFill(['email' => 'pat@globex.test', 'email_verified_at' => now()])->save();

        $share = app(FileShareService::class)->share($sharer, $file, 'pat@globex.test', 30);

        $this->infodotSays(Http::response($this->holding(['status' => 'suspended', 'entitled' => false], self::GLOBEX)));

        $this->actingAs($recipient)->get(route('shared'))->assertOk()->assertSee('Budget 2027.txt');
        $this->actingAs($recipient)->get(route('shared.download', $share))->assertOk()->assertDownload('Budget 2027.txt');

        $this->assertSame(0, $this->companyCalls());

        // The share's own rules still decide: a revoked share stops at once, as it always did.
        app(FileShareService::class)->revoke($sharer, $share);
        $this->actingAs($recipient)->get(route('shared.download', $share->fresh()))->assertNotFound();

        // And the recipient's own company workspace is closed.
        $this->actingAs($recipient)->get('/files')->assertStatus(403)->assertDontSee('Budget 2027.txt');
    }

    public function test_a_second_company_that_is_entitled_can_still_be_switched_to(): void
    {
        $owner = $this->ownerOf();
        $globex = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Globex', 'personal_team' => false, 'infodot_team_uuid' => self::GLOBEX]);
        $this->fileIn($globex, 'Globex lease.txt');

        Http::swap(new Factory);
        Http::fake([
            'https://infodot.test/api/ecosystem/companies/'.self::ACME.'/*' => Http::response($this->holding(['status' => 'suspended', 'entitled' => false])),
            'https://infodot.test/api/ecosystem/companies/'.self::GLOBEX.'/*' => Http::response($this->holding(['status' => 'included', 'entitled' => true], self::GLOBEX)),
        ]);

        // The refusal page offers the person's other workspaces, by name, and nothing of the refused company's.
        $this->actingAs($owner)->get('/files')
            ->assertStatus(403)
            ->assertSee('Globex')
            ->assertSee(route('current-team.update'), false)
            ->assertDontSee('Globex lease.txt')
            ->assertDontSee('Budget 2027.txt');

        $this->actingAs($owner)->put(route('current-team.update'), ['team_id' => $globex->id])->assertRedirect('/files');
        $this->assertSame($globex->id, $owner->fresh()->current_team_id);

        $this->actingAs($owner->fresh())->get('/files')->assertOk()->assertSee('Globex lease.txt')->assertDontSee('Budget 2027.txt');

        // The personal workspace is always there too.
        $this->actingAs($owner->fresh())->put(route('current-team.update'), ['team_id' => $owner->personalTeam()->id])->assertRedirect('/files');
        $this->actingAs($owner->fresh())->get('/files')->assertOk();
    }
}
