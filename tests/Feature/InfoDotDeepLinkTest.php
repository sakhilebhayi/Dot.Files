<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\ResourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deep links from InfoDot: files are registered as pointers only, /o/{uuid} opens a file for
 * members of its company (and nobody else), and the sign-in redirect only ever honours our own
 * /o/{uuid} shape.
 */
class InfoDotDeepLinkTest extends TestCase
{
    use RefreshDatabase;

    /** InfoDot's launch page, as the browser reports it. */
    private function fromInfodot(): array
    {
        $infodot = parse_url((string) config('ecosystem.infodot.redeem_url', ''), PHP_URL_HOST);

        if (is_string($infodot) && $infodot !== '') {
            return ['Origin' => 'https://'.$infodot];
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return ['Origin' => 'https://'.(str_contains($host, '.') ? substr($host, strpos($host, '.') + 1) : $host)];
    }

    private User $ann;

    private User $gina;

    private Team $acme;

    private Team $globex;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'scout.driver' => 'collection',
            'ecosystem.infodot.secret' => 'files-secret',
            'ecosystem.infodot.redeem_url' => 'https://infodot.test/api/ecosystem/redeem',
            'ecosystem.infodot.platform' => 'files',
        ]);

        $this->ann = User::factory()->withPersonalTeam()->create();
        $this->gina = User::factory()->withPersonalTeam()->create();
        $this->acme = $this->company($this->ann, 'Acme', (string) Str::uuid());
        $this->globex = $this->company($this->gina, 'Globex', (string) Str::uuid());
    }

    private function company(User $owner, string $name, ?string $uuid): Team
    {
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false]);
        $team->forceFill(['infodot_team_uuid' => $uuid])->save();
        $owner->switchTeam($team);

        return $team;
    }

    private function fileFor(Team $team, string $name = 'plan.txt'): File
    {
        Storage::disk('local')->put($path = 'files/'.uniqid().'.txt', 'x');
        $file = $team->files()->create(['name' => $name, 'size' => 1, 'path' => $path]);
        $obj = $team->objects()->make(['parent_id' => $team->objects()->withoutGlobalScope('team')->whereNull('parent_id')->value('id')]);
        $obj->objectable()->associate($file);
        $obj->save();

        return $file;
    }

    private function fakeRegistry(): void
    {
        $this->swap(HttpFactory::class, new HttpFactory);
        Http::fake(['infodot.test/*' => Http::response(['uuid' => (string) Str::uuid(), 'reference' => 'ACME-F-000001', 'url' => 'https://infodot.test/open/x'], 201)]);
    }

    public function test_registering_sends_a_pointer_only_and_keeps_the_reference(): void
    {
        $this->fakeRegistry();
        $file = $this->fileFor($this->acme, 'payroll-2026.xlsx');

        $this->assertTrue(app(ResourceRegistry::class)->register($file));

        Http::assertSent(function ($request) use ($file) {
            $body = $request->data();

            return $request->url() === 'https://infodot.test/api/ecosystem/resources'
                && $request->hasHeader('Authorization', 'Bearer files-secret')
                && $body['team_uuid'] === $this->acme->infodot_team_uuid
                && $body['object_uuid'] === (string) $file->uuid
                && $body['path'] === '/o/'.(string) $file->uuid
                && ! array_key_exists('name', $body) && ! str_contains(json_encode($body), 'payroll');
        });
        $this->assertSame('ACME-F-000001', $file->fresh()->infodot_reference);
    }

    public function test_personal_and_unlinked_teams_are_never_registered(): void
    {
        $this->fakeRegistry();
        $personal = $this->ann->personalTeam();
        $unlinked = $this->company($this->gina, 'Initech', null);

        foreach ([$personal, $unlinked] as $team) {
            $this->assertFalse(app(ResourceRegistry::class)->register($this->fileFor($team)));
        }

        Http::assertNothingSent();
    }

    public function test_an_unreachable_infodot_never_breaks_the_upload(): void
    {
        $this->swap(HttpFactory::class, new HttpFactory);
        Http::fake(['infodot.test/*' => Http::response('boom', 500)]);

        $file = $this->fileFor($this->acme);

        $this->assertFalse(app(ResourceRegistry::class)->register($file));
        $this->assertNull($file->fresh()->infodot_reference);
        $this->assertDatabaseHas('files', ['id' => $file->id]);
    }

    public function test_a_member_opens_the_file_by_uuid_and_the_company_becomes_current(): void
    {
        $file = $this->fileFor($this->acme, 'plan.txt');
        $this->ann->switchTeam($this->ann->personalTeam());

        $this->actingAs($this->ann)->get('/o/'.$file->uuid)
            ->assertOk()
            ->assertSee('plan.txt')
            ->assertSee(route('files.download', $file), false);

        $this->assertSame($this->acme->id, $this->ann->fresh()->current_team_id);
    }

    public function test_another_company_gets_request_access_and_learns_nothing(): void
    {
        $file = $this->fileFor($this->acme, 'secret-merger.txt');

        $this->actingAs($this->gina)->get('/o/'.$file->uuid)
            ->assertForbidden()
            ->assertSee('not in the company that owns this file')
            ->assertDontSee('secret-merger')
            ->assertDontSee('Acme');

        $this->assertSame($this->globex->id, $this->gina->fresh()->current_team_id);
        $this->actingAs($this->gina)->get('/o/'.(string) Str::uuid())->assertNotFound();
        $this->actingAs($this->gina)->get('/o/not-a-uuid')->assertNotFound();
    }

    public function test_signed_out_people_are_sent_to_login(): void
    {
        $this->get('/o/'.$this->fileFor($this->acme)->uuid)->assertRedirect('/login');
    }

    public function test_the_sign_in_redirect_only_honours_our_own_file_link_shape(): void
    {
        $identity = fn () => [
            'user' => ['id' => 9, 'name' => 'Ann', 'email' => $this->ann->email, 'email_verified' => true],
            'teams' => [],
            'current_team_id' => null,
        ];
        $uuid = (string) Str::uuid();

        $go = function (string $redirect) use ($identity) {
            $this->swap(HttpFactory::class, new HttpFactory);
            Http::fake(['infodot.test/*' => Http::response($identity())]);
            $response = $this->post(route('auth.ecosystem'), ['token' => 'one-time', 'redirect' => $redirect], $this->fromInfodot());
            auth()->logout();

            return $response;
        };

        $go('/o/'.$uuid)->assertRedirect('/o/'.$uuid);

        foreach (['https://evil.example/x', '//evil.example/x', '/files', '/o/'.$uuid.'/../../admin', '/o/zzz'] as $bad) {
            $go($bad)->assertRedirect(route('files'));
        }
    }

    public function test_the_backfill_registers_only_linked_company_files(): void
    {
        $this->fakeRegistry();
        $mine = $this->fileFor($this->acme);
        $this->fileFor($this->ann->personalTeam());

        $this->artisan('files:register-resources')->assertSuccessful();

        $this->assertNotNull($mine->fresh()->infodot_reference);
        Http::assertSentCount(1);
    }
}
