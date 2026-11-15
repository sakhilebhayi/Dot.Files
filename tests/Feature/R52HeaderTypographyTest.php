<?php

namespace Tests\Feature;

use App\Http\Livewire\FileBrowser;
use App\Http\Livewire\FileSearch;
use App\Models\File;
use App\Models\Team;
use App\Models\User;
use App\Services\FileShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-52: the shared header (one row, search in the centre column, drawer + search sheet on a phone), Roboto as the only
 * text face, FilePond bundled instead of loaded from a CDN, a quiet realtime client, and the touch/overflow fixes.
 */
class R52HeaderTypographyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $acme;

    private File $file;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection', 'ecosystem.entitlement_check' => false]);

        $this->owner = User::factory()->withPersonalTeam()->create(['email' => 'owner@acme.test']);
        $this->acme = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Acme', 'personal_team' => false]);
        $this->owner->switchTeam($this->acme);

        $this->file = $this->makeFile($this->acme, 'Quarterly budget.txt');
    }

    private function makeFile(Team $team, string $name): File
    {
        Storage::disk('local')->put($path = 'files/'.Str::random(8), 'x');
        $file = $team->files()->create(['name' => $name, 'size' => 1, 'path' => $path, 'mime' => 'text/plain']);
        $obj = $team->objects()->make(['parent_id' => $team->objects()->withoutGlobalScope('team')->whereNull('parent_id')->first()->id]);
        $obj->objectable()->associate($file);
        $obj->save();

        return $file;
    }

    /** @return list<string> every signed-in page type this platform has */
    private function pages(): array
    {
        return ['/files', '/shared', '/approvals', '/trash', '/o/'.$this->file->uuid, '/user/profile', '/teams/'.$this->acme->id];
    }

    // ── Header: on every page type ───────────────────────────────────────────

    public function test_every_signed_in_page_carries_the_shared_header_with_the_search_in_it(): void
    {
        foreach ($this->pages() as $path) {
            $html = $this->actingAs($this->owner)->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('<header class="dh" id="dh" data-dh>', $html, $path.' carries the shared header');
            $this->assertSame(1, substr_count($html, '<header class="dh"'), $path.' has exactly one header');
            $this->assertMatchesRegularExpression('/<div class="dh-search" id="dh-search" data-dh-search>.*?<input[^>]*class="dsr-input"[^>]*name="search"/s', $html, $path.' has the search in the header grid');
            $this->assertStringContainsString('data-dh-search-toggle', $html, $path.' has the phone search button');
            $this->assertStringContainsString('id="dsr-search-list"', $html, $path.' has the results panel');
            $this->assertStringNotContainsString('fx-top', $html, $path.' no longer prints the old bar');
        }
    }

    public function test_the_header_has_one_row_a_short_primary_nav_and_no_duplicates(): void
    {
        $html = $this->actingAs($this->owner)->get('/approvals')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<nav class="dh-nav".*?<\/nav>/s', $html, $nav));
        preg_match_all('/<a class="dh-nav__link" href="([^"]+)"[^>]*>([^<]+)/', $nav[0], $links, PREG_SET_ORDER);
        $labels = array_map(fn ($m) => trim($m[2]), $links);

        $this->assertSame(['Files', 'Shared with me', 'Sharing approvals', 'Trash'], $labels, 'four primary items, no Operator queue for a member');
        $this->assertSame($labels, array_values(array_unique($labels)), 'no duplicate items');
        $this->assertLessThanOrEqual(6, count($labels));
        $this->assertSame(1, preg_match_all('/aria-current="page"/', $nav[0]), 'exactly one item is current');
        $this->assertMatchesRegularExpression('/href="[^"]*\/approvals"\s+aria-current="page"/', $nav[0], 'and it is the page being viewed');

        $operator = User::factory()->withPersonalTeam()->create(['is_platform_operator' => true]);
        $this->assertStringContainsString('Operator queue', $this->actingAs($operator)->get('/files')->getContent());
    }

    public function test_the_phone_menu_is_the_shared_drawer_and_keeps_account_and_switching(): void
    {
        $second = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Second Co', 'personal_team' => false]);
        $html = $this->actingAs($this->owner)->get('/files')->assertOk()->getContent();

        $this->assertStringContainsString('data-dh-menu-button aria-expanded="false" aria-controls="dh-drawer"', $html);
        $this->assertSame(1, preg_match('/<div class="dh-drawer[^"]*" id="dh-drawer"[^>]*>/', $html, $drawer));
        $this->assertMatchesRegularExpression('/<div class="dh-drawer__panel" role="dialog" aria-modal="true"/', $html);
        $this->assertStringContainsString('data-dh-close', $html);

        // Account and company switching are in the menu (and the drawer repeats them), as real PUT forms with the token.
        $this->assertStringContainsString('Sign out', $html);
        $this->assertStringContainsString('Switch company', $html);
        $this->assertStringContainsString('Second Co', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*action="[^"]*\/current-team"[^>]*>.*?name="_method" value="PUT".*?name="team_id" value="'.$second->id.'"/s', $html);
        $this->assertStringContainsString('Company settings', $html);
        $this->assertSame(Jetstream::hasApiFeatures(), str_contains($html, 'API tokens'), 'API tokens only where the feature is on');

        // The platform switcher stays in the bar (compact on a phone by the shared CSS).
        $this->assertStringContainsString('data-dsw-trigger', $html);
    }

    public function test_the_page_still_loads_the_scripts_the_header_needs_and_no_inline_script_belongs_to_it(): void
    {
        $html = $this->actingAs($this->owner)->get('/files')->assertOk()->getContent();

        foreach (['dot-ui-core.min.js'] as $js) {
            $this->assertStringContainsString(asset('dot-ui/js/'.$js), $html);
        }

        $this->assertSame(1, preg_match('/<header class="dh".*?<\/header>/s', $html, $header));
        $this->assertStringNotContainsString(' style=', $header[0]);
        $this->assertStringNotContainsString('<script', $header[0]);
        $this->assertStringNotContainsString('<style', $header[0]);
    }

    // ── Search: present, and still this company's own ────────────────────────

    public function test_the_search_in_the_header_answers_only_with_the_signed_in_companys_records(): void
    {
        $other = User::factory()->withPersonalTeam()->create();
        $globex = Team::forceCreate(['user_id' => $other->id, 'name' => 'Globex', 'personal_team' => false]);
        $this->makeFile($globex, 'Quarterly secrets.txt');

        Livewire::actingAs($this->owner)->test(FileSearch::class)->set('query', 'Quarterly')
            ->assertSee('Quarterly budget.txt')->assertDontSee('Quarterly secrets.txt');
        Livewire::actingAs($this->owner)->test(FileSearch::class)->set('query', 'zzzz-no-such')->assertSee('Nothing matches');
    }

    /** 0.6.1 tenant sweep: the file register's own filter (the header's ?search= lands here) keeps the company scope beside the text match. */
    public function test_the_register_filter_never_shows_another_companys_match(): void
    {
        $other = User::factory()->withPersonalTeam()->create();
        $globex = Team::forceCreate(['user_id' => $other->id, 'name' => 'Globex', 'personal_team' => false]);
        $this->makeFile($globex, 'Quarterly secrets.txt');

        $root = $this->acme->objects()->withoutGlobalScope('team')->whereNull('parent_id')->first();

        Livewire::actingAs($this->owner)->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect()])
            ->set('query', 'Quarterly')
            ->assertSee('Quarterly budget.txt')->assertDontSee('Quarterly secrets.txt');

        $this->actingAs($this->owner)->get('/files?search=Quarterly')->assertOk()
            ->assertSee('Quarterly budget.txt')->assertDontSee('Quarterly secrets.txt');
    }

    /** The 32px header logo was the 2362px, 196 kB brand PNG (and the hidden night logo another 84 kB): the largest paint of /o/<doc> on a phone. */
    public function test_the_header_logos_are_small_derivatives_not_the_full_brand_files(): void
    {
        $html = $this->actingAs($this->owner)->get('/files')->assertOk()->getContent();

        foreach (['images/logo-header.png', 'images/logo-header-light.png'] as $logo) {
            $this->assertStringContainsString(asset($logo), $html);
            $this->assertLessThan(20_000, filesize(public_path($logo)), $logo.' stays small');
            [$w, $h] = getimagesize(public_path($logo));
            $this->assertSame([96, 96], [$w, $h], 'three times the 32px it is drawn at');
        }
        $this->assertStringNotContainsString(asset('images/logo.png'), substr($html, 0, (int) strpos($html, '</header>')), 'the full brand file is not fetched by the header');
    }

    public function test_a_signed_out_visitor_gets_no_header_search(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('<header class="dh"', false)->assertDontSee('dsr-input', false);
    }

    // ── Typography: Roboto, self-hosted, nothing external ────────────────────

    public function test_roboto_is_declared_and_served_from_this_site(): void
    {
        $html = $this->actingAs($this->owner)->get('/files')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<html[^>]*class="dot-roboto"/', $html);
        // 0.6.1: one minified stylesheet (roboto, type, tokens, search, switcher, header) first in the head.
        $this->assertStringContainsString(asset('dot-ui/css/dot-ui.min.css'), $html);
        $this->assertStringContainsString('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2', $html);

        $roboto = (string) file_get_contents(public_path('dot-ui/css/dot-roboto.css'));
        $this->assertStringContainsString("font-family: 'Roboto'", $roboto);
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2'));
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/roboto-latin-ext-wght-normal.woff2'));
    }

    public function test_no_page_loads_a_font_or_script_from_another_host_and_geist_is_gone(): void
    {
        $pages = [];
        foreach ($this->pages() as $path) {
            $pages[$path] = $this->actingAs($this->owner)->get($path)->assertOk()->getContent();
        }
        auth()->logout();
        foreach (['/', '/login', '/register', '/cookies', '/terms', '/does-not-exist'] as $path) {
            $pages[$path] = $this->get($path)->getContent();
        }

        foreach ($pages as $path => $html) {
            foreach (['fonts.googleapis.com', 'fonts.gstatic.com', 'unpkg.com', 'bootstrapcdn', 'cdn.jsdelivr', 'Fraunces', 'Work+Sans', 'IBM Plex', 'Material+Symbols', 'material-symbols', 'geist-latin', 'Archivo'] as $banned) {
                $this->assertStringNotContainsString($banned, $html, $path.' mentions '.$banned);
            }
        }

        $css = '';
        foreach (glob(public_path('build/assets/*.css')) as $file) {
            $css .= file_get_contents($file);
        }
        $this->assertStringNotContainsStringIgnoringCase('geist', $css);
        $this->assertStringNotContainsString('fonts.googleapis', $css);
        $this->assertStringContainsString('var(--dot-font-sans)', $css, 'the ledger tokens map onto the shared Roboto stack');
        $this->assertDirectoryDoesNotExist(public_path('fonts'));

        $source = (string) file_get_contents(resource_path('css/app.css'));
        $this->assertStringNotContainsStringIgnoringCase('geist', $source);
    }

    public function test_tailwind_takes_its_font_scale_from_the_shared_preset_and_the_csp_names_no_font_cdn(): void
    {
        $this->assertStringContainsString('dot-ui/tailwind/tailwind-preset.cjs', (string) file_get_contents(base_path('tailwind.config.js')));

        $policy = (string) $this->get('/login')->headers->get('Content-Security-Policy-Report-Only');
        foreach (['unpkg.com', 'fonts.googleapis.com', 'fonts.gstatic.com', 'bootstrapcdn'] as $host) {
            $this->assertStringNotContainsString($host, $policy);
        }
        $this->assertStringContainsString("font-src 'self'", $policy);
    }

    // ── FilePond: bundled ────────────────────────────────────────────────────

    public function test_filepond_is_bundled_with_the_app_so_upload_needs_no_cdn(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $entry = $manifest['resources/js/app.js'];
        $js = (string) file_get_contents(public_path('build/'.$entry['file']));

        $this->assertStringContainsString('FilePond', $js);
        $this->assertMatchesRegularExpression('/window\.FilePond\s*=/', $js, 'the upload tray finds FilePond on window');
        $this->assertNotEmpty($entry['css'] ?? [], 'its stylesheet is bundled too');
        $this->assertStringContainsString('.filepond--root', (string) file_get_contents(public_path('build/'.$entry['css'][0])));

        $package = json_decode((string) file_get_contents(base_path('package.json')), true);
        $this->assertArrayHasKey('filepond', $package['dependencies'], 'pinned through package.json and the lock file');

        $html = $this->actingAs($this->owner)->get('/files')->assertOk()->getContent();
        $this->assertStringNotContainsString('filepond.min.js', $html);
        $this->assertStringContainsString('FilePond.create', $html, 'the upload tray still initialises it');
        $this->assertStringContainsString(asset('build/'.$entry['file']), $html);
    }

    // ── Realtime: a missing key degrades quietly ─────────────────────────────

    public function test_a_build_with_no_reverb_key_does_not_throw_on_every_page(): void
    {
        $bootstrap = (string) file_get_contents(resource_path('js/bootstrap.js'));
        $this->assertStringContainsString("import { connectRealtime } from './realtime'", $bootstrap);
        $this->assertStringNotContainsString('new Echo(', $bootstrap, 'Echo is only ever constructed behind the guard');

        $realtime = (string) file_get_contents(resource_path('js/realtime.js'));
        $this->assertStringContainsString("key === ''", $realtime);
        $this->assertStringContainsString('catch', $realtime);

        // The behaviour itself: the node suite (no key, a key, a constructor that throws).
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed here; npm run test:js runs the same checks.');
        }
        $output = [];
        exec(escapeshellarg($node).' --test '.escapeshellarg(base_path('tests/js/realtime.test.mjs')).' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    // ── Responsive defects from the audit ────────────────────────────────────

    public function test_the_approvals_decline_form_wraps_instead_of_running_off_a_phone(): void
    {
        $editor = User::factory()->withPersonalTeam()->create(['email' => 'editor@acme.test']);
        $this->acme->users()->attach($editor, ['role' => 'editor']);
        $editor->switchTeam($this->acme);
        app(FileShareService::class)->requestShare($editor, $this->file, 'pat@rival.test', 30);

        $html = $this->actingAs($this->owner)->get('/approvals')->assertOk()->getContent();

        $this->assertStringContainsString('flex w-full flex-wrap items-center gap-2 sm:w-auto sm:flex-nowrap', $html, 'the action row wraps below 640px');
        $this->assertMatchesRegularExpression('/<form[^>]*approvals\/\d+\/decline[^>]*class="flex w-full min-w-0 items-center gap-2 sm:w-auto"/', $html, 'the decline form takes its own row on a phone');
        $this->assertMatchesRegularExpression('/id="note-\d+"[^>]*class="[^"]*min-w-0 flex-1[^"]*sm:w-44/', $html, 'the reason field shrinks instead of overflowing');
    }

    public function test_the_header_is_one_row_by_css_not_a_second_row_for_the_search(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringNotContainsString('fx-top-search', $css, 'the old full-width search row is gone');
        $this->assertStringNotContainsString('1180px', $css);
        $this->assertStringContainsString('.fx-chrome', $css);
        $this->assertStringContainsString('--dh-bg: var(--paper)', $css, 'the header sits on the ledger ground');

        $shared = (string) file_get_contents(public_path('dot-ui/css/dot-header.css'));
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) minmax(280px, var(--dh-search-w)) minmax(0, 1fr)', $shared, 'search centred by the grid');
    }
}
