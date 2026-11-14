<?php

namespace Tests\Feature;

use App\Http\Livewire\FileSearch;
use App\Http\Middleware\EnsureCompanyHoldsPlatform;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionClass;
use Tests\TestCase;

/**
 * R-51: the header carries the shared Dot UI (dot-ui): the search pill (replacing Files' own hand copy of InfoDot's
 * search) and the platform switcher. Presentation changed; the search's route, parameter, keys, tenant scope and
 * results did not, the entitlement check's exemptions did not, and the switcher only ever shows what InfoDot says the
 * CURRENT company holds, linking through InfoDot's /go (never straight to a host, never with a token).
 */
class DotUiHeaderTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection']);

        config([
            'ecosystem.infodot.redeem_url' => self::REDEEM,
            'ecosystem.infodot.companies_url' => null,
            'ecosystem.infodot.platform' => 'files',
            'ecosystem.infodot.secret' => 'a-test-secret',
            'ecosystem.infodot.timeout' => 2,
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
            'ecosystem.entitlement_check' => true,
        ]);
    }

    private static function url(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** InfoDot's read endpoint, as it answers this platform about one company. */
    private static function body(string $uuid, array $held = ['files', 'engage', 'infodot']): array
    {
        $rows = [];
        foreach (['engage' => 'Dot.Engage', 'files' => 'Dot.Files', 'finance' => 'Dot.Finance', 'hr' => 'Dot.HR', 'infodot' => 'InfoDot', 'press' => 'Dot.Press'] as $key => $name) {
            $rows[] = ['key' => $key, 'name' => $name, 'status' => 'active', 'entitled' => in_array($key, $held, true), 'is_core' => false, 'access_mode' => 'everyone'];
        }

        return ['platform' => 'files', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => $rows];
    }

    private function fakeInfoDot(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    private function memberOf(string $uuid, string $name): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $user->switchTeam($team);

        return $user->fresh();
    }

    private function fileFor(Team $team, string $name, ?string $ref = null)
    {
        Storage::disk('local')->put($path = 'files/'.Str::random(8), 'x');
        $file = $team->files()->create(['name' => $name, 'size' => 1, 'path' => $path, 'mime' => 'text/plain']);

        if ($ref) {
            $file->forceFill(['infodot_reference' => $ref])->saveQuietly();
        }

        $obj = $team->objects()->make(['parent_id' => $team->objects()->withoutGlobalScope('team')->whereNull('parent_id')->first()->id]);
        $obj->objectable()->associate($file);
        $obj->save();

        return $file;
    }

    /** The markup of the switcher only, so the assertions cannot be satisfied by the rest of the page. */
    private function switcherHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="dsw dsw--header".*?<p class="dsw-sr" data-dsw-live[^>]*><\/p>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $m), 'the header carries the switcher');

        return $m[0];
    }

    /** The markup of the header search only. */
    private function pillHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="fx-top-search">.*?(?=<div class="fx-top-switch">)/s', $html, $m), 'the header carries the search');

        return $m[0];
    }

    // ── The search pill ──────────────────────────────────────────────────────

    public function test_the_header_search_is_the_shared_pill_with_its_form_route_and_parameter_unchanged(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($owner)->get('/files')->assertOk()->getContent();
        $pill = $this->pillHtml($html);

        $this->assertSame(1, preg_match('/<form role="search" method="get"\s+action="([^"]+)"/', $pill, $form), 'a GET search form');
        $this->assertSame(route('files'), html_entity_decode($form[1]));
        $this->assertMatchesRegularExpression('/<input[^>]*\btype="search"[^>]*\bname="search"/', $pill, 'the same parameter');
        $this->assertStringContainsString('id="dsr-search"', $pill, 'the shared pill\'s stable input id');
        $this->assertStringContainsString('placeholder="Search your files — or paste a reference"', $pill);
        $this->assertStringContainsString('class="dsr-input"', $pill, 'the shared pill');
        $this->assertStringContainsString('<kbd class="dsr-kbd"', $pill, 'Files keeps its Ctrl K hint');
        $this->assertStringContainsString('data-dsr-shortcut', $pill);

        // Files' own hand copy of the pill is gone, markup and styles both.
        $this->assertStringNotContainsString('ix-search', $html);
        $this->assertStringNotContainsString('ix-input', $html);
        $this->assertStringNotContainsString('ix-results', $html);
        $this->assertDoesNotMatchRegularExpression('/\.ix-(search|input|results|kbd|arrow)/', (string) file_get_contents(public_path('build/assets/'.$this->builtCss())));
    }

    private function builtCss(): string
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

        return basename($manifest['resources/css/app.css']['file']);
    }

    public function test_the_keyboard_model_and_live_binding_are_the_same(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $pill = $this->pillHtml($this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/files')->getContent());

        foreach ([
            'wire:model.live.debounce.250ms="query"',
            'wire:keydown.escape="$set(\'query\', \'\')"',
            'wire:keydown.arrow-up.prevent="decrementHighlight"',
            'wire:keydown.arrow-down.prevent="incrementHighlight"',
            'wire:keydown.enter="openHighlighted"',
            'x-on:keydown.enter="if ($wire.query.trim() !== \'\') $event.preventDefault()"',
            'role="combobox"',
            'aria-controls="dsr-search-list"',
        ] as $binding) {
            $this->assertStringContainsString($binding, $pill);
        }
    }

    public function test_the_dropdown_still_offers_what_it_did_with_nothing_typed(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');
        $this->fileFor($owner->currentTeam, 'Quarterly budget.txt', 'ACME-F-000001');

        Livewire::actingAs($owner)->test(FileSearch::class)
            ->assertSee('New in Acme Ltd')
            ->assertSee('Quarterly budget.txt')
            ->assertSee('Upload documents')
            ->assertSee('Sharing approvals')
            ->assertSee('Shared with me')
            ->assertSee('Trash')
            ->assertSee('ACME-F-000001');
    }

    public function test_a_guest_sees_no_search_and_no_switcher(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('dsr-input', false)->assertDontSee('data-dsw', false);
    }

    // ── Search results: the logic and the permissions are unchanged ─────────

    public function test_search_finds_this_companys_records_and_never_another_business(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME)),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX)),
        ]);
        $acme = $this->memberOf(self::ACME, 'Acme Ltd');
        $globex = $this->memberOf(self::GLOBEX, 'Globex Ltd');
        $this->fileFor($acme->currentTeam, 'Quarterly budget.txt', 'ACME-F-000001');
        $this->fileFor($globex->currentTeam, 'Quarterly secrets.txt', 'GLBX-F-000001');

        // The header's dropdown, as Acme sees it: only its own record, the other company's neither by name nor reference.
        Livewire::actingAs($acme)->test(FileSearch::class)->set('query', 'Quarterly')
            ->assertSee('Quarterly budget.txt')->assertDontSee('Quarterly secrets.txt');
        Livewire::actingAs($acme)->test(FileSearch::class)->set('query', 'GLBX-F-000001')->assertDontSee('Quarterly secrets.txt');

        // The Go button's landing page, GET ?search=: the same.
        $this->actingAs($acme)->get('/files?search=Quarterly')->assertOk()->assertSee('Quarterly budget.txt')->assertDontSee('Quarterly secrets.txt');
        $this->actingAs($globex)->get('/files?search=Quarterly')->assertOk()->assertSee('Quarterly secrets.txt')->assertDontSee('Quarterly budget.txt');
    }

    public function test_search_still_needs_a_signed_in_person(): void
    {
        $this->get('/files?search=Quarterly')->assertRedirect(route('login'));
    }

    public function test_the_workspace_filter_is_the_shared_compact_filter_keeping_its_binding_and_shortcut_target(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');
        $this->fileFor($owner->currentTeam, 'Depot plan.txt');

        $html = $this->actingAs($owner)->get('/files?search=Depot')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<input[^>]*\bid="fx-search"[^>]*>/s', $html, $input));
        $this->assertStringContainsString('class="dsr-filter"', $input[0]);
        $this->assertStringContainsString('type="search"', $input[0]);
        $this->assertStringContainsString('wire:model.live.debounce.300ms="query"', $html);
        $this->assertStringContainsString('aria-label="Search this company’s records"', $html);
        $this->assertStringContainsString("document.getElementById('fx-search')", $html, 'the / shortcut still targets it');
        $this->assertStringNotContainsString('fx-input pl-10', $html, 'the old icon-padded input is gone');
    }

    // ── The switcher ────────────────────────────────────────────────────────

    public function test_a_member_sees_the_switcher_with_what_their_company_holds_and_the_current_platform_marked(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($owner)->get('/files')->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="ok"', $panel);
        $this->assertStringContainsString('data-platform="files"', $panel);
        $this->assertStringContainsString('Acme Ltd', $panel, 'the sentence names the company');

        // Current platform: a marker, not a link.
        $this->assertSame(1, preg_match('/<div class="dsw-bay is-current" aria-current="page">.*?You are here/s', $panel, $here));
        $this->assertStringContainsString('Dot.Files', $here[0]);

        // Held platforms are links through InfoDot, with this company; InfoDot itself goes home.
        $this->assertStringContainsString('href="https://infodot.test/go/engage?company='.self::ACME.'"', $panel);
        $this->assertStringContainsString('href="https://infodot.test/home"', $panel);
        // Not held: not a tile, only in the "not switched on" list that points at InfoDot's explanation.
        $this->assertStringNotContainsString('/go/hr', $panel);
        $this->assertStringNotContainsString('/go/press', $panel);
        $this->assertStringContainsString('href="https://infodot.test/platforms/hr"', $panel);
        $this->assertStringContainsString('Not switched on (3)', $panel);

        // Never a token, never a secret, never straight to a destination host.
        $this->assertStringNotContainsString('token', strtolower($panel));
        $this->assertStringNotContainsString('a-test-secret', $html);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href, 'every way out of the switcher goes through InfoDot');
        }
    }

    public function test_the_switcher_asks_infodot_about_the_current_company_only_with_the_service_secret(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/files')->assertOk();

        Http::assertSent(function (OutgoingRequest $request) {
            return str_contains($request->url(), '/companies/'.self::ACME.'/platforms')
                && $request->hasHeader('Authorization', 'Bearer a-test-secret')
                && $request->hasHeader('X-Dot-Platform', 'files');
        });
        Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), self::GLOBEX));
    }

    public function test_two_companies_never_see_each_others_switcher(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME, ['files', 'infodot', 'press'])),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX, ['files', 'infodot', 'hr'])),
        ]);

        $acme = $this->switcherHtml($this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/files')->getContent());
        $globex = $this->switcherHtml($this->actingAs($this->memberOf(self::GLOBEX, 'Globex Ltd'))->get('/files')->getContent());

        $this->assertStringContainsString('/go/press?company='.self::ACME, $acme);
        $this->assertStringNotContainsString('/go/hr', $acme);
        $this->assertStringNotContainsString(self::GLOBEX, $acme);
        $this->assertStringContainsString('/go/hr?company='.self::GLOBEX, $globex);
        $this->assertStringNotContainsString('/go/press', $globex);
        $this->assertStringNotContainsString(self::ACME, $globex);
    }

    public function test_when_infodot_cannot_be_reached_the_switcher_says_so_and_never_shows_active(): void
    {
        // The entitlement check and the switcher both get a 503: the check carries on (as it always did), the switcher degrades.
        $this->fakeInfoDot([self::url(self::ACME) => Http::response('down', 503)]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/files')->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="degraded"', $panel);
        $this->assertStringContainsString('Could not check your platforms just now', $panel);
        $this->assertStringContainsString('opened through InfoDot', $panel);
        $this->assertStringNotContainsString('Active', $panel);
        $this->assertStringContainsString('/go/docs', $panel, 'the core set still leaves through InfoDot, which decides again');
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href);
        }
    }

    public function test_a_connection_failure_never_breaks_the_page(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => fn () => throw new ConnectionException('refused')]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/files')->assertOk()->getContent();

        $this->assertStringContainsString('data-state="degraded"', $html);
    }

    public function test_a_personal_workspace_gets_the_honest_sentence_and_infodot_is_not_asked(): void
    {
        $this->fakeInfoDot([]);
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/files')->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="personal"', $panel);
        $this->assertStringContainsString('dsw-personal', $panel);
        $this->assertStringNotContainsString('dsw-bay', $panel, 'no platforms are listed for a workspace that is not a company');
        $this->assertStringContainsString('href="https://infodot.test/platforms"', $panel, 'the way to InfoDot is kept');
        Http::assertNothingSent();
    }

    public function test_the_in_page_way_back_goes_only_where_the_switcher_carries_infodot(): void
    {
        // A linked company: the switcher has the InfoDot tile, so the two-link way back under the heading goes.
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $linked = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/files')->assertOk()->getContent();
        $this->assertStringNotContainsString('fx-wayback', $linked);
        $this->assertStringContainsString('href="https://infodot.test/home"', $linked, 'InfoDot stays one click away, from the switcher');

        // A personal workspace gets only a sentence from the switcher, so it keeps the way back.
        $this->fakeInfoDot([]);
        $personal = $this->actingAs(User::factory()->withPersonalTeam()->create())->get('/files')->assertOk()->getContent();
        $this->assertStringContainsString('<a href="https://infodot.test/home">InfoDot</a>', $personal);
        $this->assertStringContainsString('<a href="https://infodot.test/platforms">Platforms</a>', $personal);
    }

    // ── The entitlement check is exactly as it was ──────────────────────────

    public function test_the_entitlement_check_keeps_exactly_its_exemptions(): void
    {
        $class = new ReflectionClass(EnsureCompanyHoldsPlatform::class);

        $this->assertSame([
            'login', 'login.*', 'logout', 'register', 'register.*', 'auth.ecosystem',
            'password.request', 'password.email', 'password.reset', 'password.update',
            'two-factor.login', 'two-factor.login.*', 'verification.*',
            'cookies', 'terms.show', 'policy.show', 'csp-report', 'sanctum.csrf-cookie',
            'current-team.update',
            'shared', 'shared.*',
        ], $class->getReflectionConstant('EXEMPT')->getValue());

        $this->assertSame(['/', 'up', 'auth/ecosystem', 'livewire/livewire.js', 'livewire/livewire.min.js.map'], $class->getReflectionConstant('EXEMPT_PATHS')->getValue());
    }

    public function test_a_company_that_lost_the_platform_still_gets_the_refusal_not_a_header(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(['platform' => 'files', 'company' => ['uuid' => self::ACME, 'personal' => false], 'platforms' => [
            ['key' => 'files', 'name' => 'Dot.Files', 'status' => 'suspended', 'entitled' => false, 'is_core' => true, 'access_mode' => 'everyone'],
        ]])]);

        $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/files')->assertStatus(403)->assertDontSee('dsr-input', false);
    }

    // ── CSP: self-hosted, nothing inline, nothing external ──────────────────

    public function test_the_shared_assets_are_served_from_this_site_and_resolve(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/files')->assertOk()->getContent();

        foreach (['css/dot-fonts.css', 'css/dot-tokens.css', 'css/dot-search.css', 'css/dot-switcher-fonts.css', 'css/dot-switcher.css', 'js/dot-switcher.js', 'js/dot-search.js'] as $asset) {
            $this->assertStringContainsString(asset('dot-ui/'.$asset), $html, $asset.' is linked from this site');
            $this->assertFileExists(public_path('dot-ui/'.$asset));
        }

        // Every relative url() in the shared CSS lands on a file that is really there (fonts, arrow mask, stencil face).
        foreach (glob(public_path('dot-ui/css/*.css')) as $css) {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', (string) file_get_contents($css), $urls);
            foreach ($urls[1] as $url) {
                $this->assertDoesNotMatchRegularExpression('#^(https?:)?//#', $url, basename($css).' reaches outside this site');
                $this->assertFileExists(dirname($css).'/'.$url, basename($css).' -> '.$url);
            }
        }

        // Logos: this site's own copies.
        preg_match_all('/<img src="([^"]+)" alt="Dot\./', $this->switcherHtml($html), $imgs);
        $this->assertNotEmpty($imgs[1]);
        foreach ($imgs[1] as $src) {
            $this->assertStringStartsWith('/dot-ui/img/platforms/', $src);
            $this->assertFileExists(public_path($src));
        }

        // The hand copy's font and arrow are gone with it: the shared package ships its own.
        $this->assertFileDoesNotExist(public_path('fonts/archivo-latin.woff2'));
        $this->assertFileDoesNotExist(public_path('img/arrow-mask.png'));
    }

    public function test_the_shared_markup_has_no_inline_style_or_script(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/files')->getContent();

        foreach ([$this->pillHtml($html), $this->switcherHtml($html)] as $fragment) {
            $this->assertStringNotContainsString(' style=', $fragment);
            $this->assertStringNotContainsString('<style', $fragment);
            $this->assertStringNotContainsString('<script', $fragment);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $fragment, 'no inline event handlers');
        }
    }

    public function test_the_header_mapping_block_keeps_the_pill_unmapped(): void
    {
        $css = (string) file_get_contents(public_path('build/assets/'.$this->builtCss()));

        foreach (['--dsr-panel:var(--panel)', '--dsw-btn-ink:var(--ink-soft)'] as $mapped) {
            $this->assertStringContainsString($mapped, str_replace(' ', '', $css), $mapped.' is mapped onto the ledger');
        }
        foreach (['--dsr-pill-bg', '--dsr-h:', '--dsr-radius:', '--dsr-ring', '--dsr-shadow'] as $fixed) {
            $this->assertStringNotContainsString($fixed, $css, $fixed.' is the pill: never remapped by the host');
        }
    }
}
