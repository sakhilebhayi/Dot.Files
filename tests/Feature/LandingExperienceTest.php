<?php

namespace Tests\Feature;

use App\Http\Livewire\FileBrowser;
use App\Models\File;
use App\Models\Obj;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-50: the arrival on /files. A compact band over the file browser (real logo, welcome from
 * real data, the company's real tallies, one dominant action) and a fuller first-run state
 * for a company that has never filed anything.
 */
class LandingExperienceTest extends TestCase
{
    use RefreshDatabase;

    /** sha256 of the original artwork — the landing may present the logo, never alter it. */
    private const LOGO_SHA256 = '88f514b0dd45e02af685545d8e927ea12a1c9f0c77b8fb130cd698bfdc24619c';

    private const LOGO_LIGHT_SHA256 = '15b73f1e0b9ddd3342bf6edd036f931db5d9ed29d9e111f01346fc79159c7c7b';

    private User $owner;

    private User $admin;

    private User $editor;

    private Team $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection']);

        $this->owner = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Mokoena']);
        $this->admin = User::factory()->withPersonalTeam()->create(['name' => 'Sipho Dlamini']);
        $this->editor = User::factory()->withPersonalTeam()->create(['name' => 'Lerato Khumalo']);

        $this->acme = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Acme Mining Supplies', 'personal_team' => false]);
        $this->acme->users()->attach($this->admin, ['role' => 'admin']);
        $this->acme->users()->attach($this->editor, ['role' => 'editor']);

        foreach ([$this->owner, $this->admin, $this->editor] as $user) {
            $user->switchTeam($this->acme);
        }
    }

    /** @return array<string, array{string}> */
    public static function arrivingRoles(): array
    {
        return ['owner' => ['owner'], 'administrator' => ['admin'], 'editor' => ['editor']];
    }

    private function rootOf(Team $team): Obj
    {
        return $team->objects()->withoutGlobalScope('team')->whereNull('parent_id')->firstOrFail();
    }

    private function fileFor(Team $team, string $name, string $contents): File
    {
        Storage::disk('local')->put($path = 'files/'.Str::random(10), $contents);
        $file = $team->files()->create(['name' => $name, 'size' => strlen($contents), 'path' => $path, 'mime' => 'text/plain']);

        $obj = $team->objects()->make(['parent_id' => $this->rootOf($team)->id]);
        $obj->objectable()->associate($file);
        $obj->save();

        return $file;
    }

    private function folderFor(Team $team, string $name): Obj
    {
        $obj = $team->objects()->make(['parent_id' => $this->rootOf($team)->id]);
        $obj->objectable()->associate($team->folders()->create(['name' => $name]));
        $obj->save();

        return $obj;
    }

    /** The text of every element carrying the given data attribute, in document order. */
    private function tally(string $html, string $key): string
    {
        $this->assertSame(1, preg_match('/data-tally="'.$key.'"[^>]*>([^<]*)</', $html, $m), "tally {$key} is on the page once");

        return trim($m[1]);
    }

    #[DataProvider('arrivingRoles')]
    public function test_the_first_run_arrival_renders_for_each_role_with_one_primary_action(string $role): void
    {
        $user = $this->{$role};

        $html = $this->actingAs($user)->get('/files')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-primary-action'), 'exactly one primary action');
        $this->assertStringContainsString('data-landing="first-run"', $html);
        $this->assertStringContainsString('Hello '.Str::before($user->name, ' ').'. The filing room for Acme Mining Supplies is ready.', $html);
        $this->assertStringContainsString('Nothing is filed here yet.', $html);
        $this->assertStringContainsString('Upload the first document', $html);

        // An empty library shows no tallies at all — there is nothing to count.
        $this->assertStringNotContainsString('data-tally=', $html);
    }

    #[DataProvider('arrivingRoles')]
    public function test_the_band_renders_for_each_role_with_one_primary_action_and_real_tallies(string $role): void
    {
        $user = $this->{$role};

        $this->fileFor($this->acme, 'Supplier agreement.txt', str_repeat('a', 300));
        $this->fileFor($this->acme, 'Stock count.txt', str_repeat('b', 212));
        $this->fileFor($this->acme, 'Delivery note.txt', str_repeat('c', 512));
        $this->folderFor($this->acme, 'Contracts');

        $html = $this->actingAs($user)->get('/files')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-primary-action'), 'exactly one primary action');
        $this->assertStringContainsString('data-landing="band"', $html);
        $this->assertStringNotContainsString('data-landing="first-run"', $html);
        $this->assertStringContainsString('Hello '.Str::before($user->name, ' ').'. This is the filing room for Acme Mining Supplies.', $html);

        // Every figure in the band is the seeded records, nothing else.
        $this->assertSame('3', $this->tally($html, 'documents'));
        $this->assertSame('1', $this->tally($html, 'folders'));
        $this->assertSame(File::bytesForHumans(1024), $this->tally($html, 'bytes'));
        $this->assertSame('0', $this->tally($html, 'shared'));
    }

    #[DataProvider('arrivingRoles')]
    public function test_the_primary_action_leads_to_a_working_upload_for_each_role(string $role): void
    {
        $user = $this->{$role};
        $root = $this->rootOf($this->acme);

        $this->actingAs($user)->get(route('files'))->assertOk();

        Livewire::actingAs($user)
            ->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect([$root])])
            ->assertSeeHtml('data-primary-action')
            ->set('showingObjectloadForm', true)
            ->assertSet('showingObjectloadForm', true)
            ->set('upload', UploadedFile::fake()->create('First record.pdf', 12, 'application/pdf'))
            ->assertHasNoErrors()
            ->assertSee('First record.pdf')
            ->assertSeeHtml('data-landing="band"')
            ->assertDontSeeHtml('data-landing="first-run"');

        $file = File::withoutGlobalScopes()->where('name', 'First record.pdf')->firstOrFail();
        $this->assertSame($this->acme->id, $file->team_id);
        $this->assertSame($user->id, $file->uploaded_by);
        $this->assertTrue(Storage::disk('local')->exists($file->path));
    }

    public function test_a_personal_workspace_arrives_too(): void
    {
        $solo = User::factory()->withPersonalTeam()->create(['name' => 'Naledi Jacobs']);

        $html = $this->actingAs($solo)->get('/files')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-primary-action'));
        $this->assertStringContainsString('Hello Naledi.', $html);
        // Sharing is a company matter: a personal workspace is told nothing about it.
        $this->assertStringNotContainsString('Sharing outside', $html);
    }

    public function test_the_landing_shows_the_original_logo_files_unaltered(): void
    {
        $this->assertSame(self::LOGO_SHA256, hash_file('sha256', public_path('images/logo.png')));
        $this->assertSame(self::LOGO_LIGHT_SHA256, hash_file('sha256', public_path('images/logo-light.png')));

        $html = $this->actingAs($this->owner)->get('/files')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<img src="[^"]*/images/logo\.png" alt="Dot\.Files"[^>]*data-landing-logo#', $html);
        $this->assertStringContainsString('/images/logo-light.png', $html);
    }

    public function test_the_way_back_to_infodot_uses_the_configured_address(): void
    {
        config(['ecosystem.platforms.infodot.url' => 'https://infodot.co.za/']);

        $this->actingAs($this->owner)->get('/files')->assertOk()
            ->assertSee('<a href="https://infodot.co.za/home">InfoDot</a>', false)
            ->assertSee('<a href="https://infodot.co.za/platforms">Platforms</a>', false);
    }

    public function test_an_empty_folder_in_a_working_library_keeps_the_band_and_one_primary_action(): void
    {
        $this->fileFor($this->acme, 'Supplier agreement.txt', 'contents');
        $folder = $this->folderFor($this->acme, 'Contracts');

        $html = $this->actingAs($this->owner)->get('/files?uuid='.$folder->uuid)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-primary-action'));
        $this->assertStringContainsString('data-landing="band"', $html);
        $this->assertStringContainsString('Upload to this folder', $html);
    }

    public function test_the_arrival_never_carries_another_companys_records(): void
    {
        $rival = User::factory()->withPersonalTeam()->create(['name' => 'Pat Rival']);
        $globex = Team::forceCreate(['user_id' => $rival->id, 'name' => 'Globex Holdings', 'personal_team' => false]);
        $this->fileFor($globex, 'Globex payroll.txt', str_repeat('x', 4096));
        $this->folderFor($globex, 'Globex board packs');

        // Acme has filed nothing: Globex's records neither show nor end Acme's first run.
        $html = $this->actingAs($this->owner)->get('/files')->assertOk()->getContent();

        $this->assertStringContainsString('data-landing="first-run"', $html);
        $this->assertStringNotContainsString('Globex', $html);

        // And once Acme files something, its tallies count Acme's records only.
        $this->fileFor($this->acme, 'Acme note.txt', str_repeat('a', 10));
        $html = $this->actingAs($this->owner)->get('/files')->assertOk()->getContent();

        $this->assertSame('1', $this->tally($html, 'documents'));
        $this->assertSame('0', $this->tally($html, 'folders'));
        $this->assertSame(File::bytesForHumans(10), $this->tally($html, 'bytes'));
        $this->assertStringNotContainsString('Globex', $html);
    }

    public function test_the_platform_list_holds_only_current_platforms(): void
    {
        $current = ['agents', 'analytics', 'auction', 'central', 'charts', 'design', 'docs', 'dopemine', 'ehail', 'emall',
            'engage', 'files', 'finance', 'forms', 'hr', 'memory', 'mines', 'notify', 'press', 'projects', 'pulse', 'sheet',
            'tasks', 'tutor', 'infodot'];

        $this->assertSame([], array_values(array_diff(array_keys(config('ecosystem.platforms')), $current)));
    }
}
