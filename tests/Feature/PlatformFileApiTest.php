<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Obj;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R-25 Phase 3: the platform file API. The properties under test are structural — a verified
 * platform reaches ONLY what it itself filed, for ONLY the company named in the signed body;
 * every other combination answers the same not_found; everything the platform files is a real
 * record in the company's own workspace, under the same trash and policies.
 */
class PlatformFileApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $acme;

    private Team $globex;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection']);
        config(['ecosystem.platform_secrets' => ['docs' => 'secret-docs', 'billing' => 'secret-billing']]);

        $this->owner = User::factory()->withPersonalTeam()->create(['email' => 'owner@acme.test']);
        $this->acme = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Acme', 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);
        $this->owner->switchTeam($this->acme);

        $other = User::factory()->withPersonalTeam()->create();
        $this->globex = Team::forceCreate(['user_id' => $other->id, 'name' => 'Globex', 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);
    }

    private function signed(string $platform, string $secret, string $path, array $payload, ?int $timestamp = null)
    {
        $body = json_encode($payload);
        $timestamp ??= time();

        return $this->call('POST', $path, [], [], [], [
            'HTTP_X-DOT-PLATFORM' => $platform,
            'HTTP_X-DOT-TIMESTAMP' => (string) $timestamp,
            'HTTP_X-DOT-SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function storeFor(Team $team, string $platform = 'docs', string $secret = 'secret-docs', string $name = 'Invoice INV-204.pdf'): array
    {
        $response = $this->signed($platform, $secret, '/api/platform/files/store', [
            'team_uuid' => $team->infodot_team_uuid,
            'name' => $name,
            'content_b64' => base64_encode('%PDF-1.4 real bytes'),
        ]);

        $response->assertCreated();

        return $response->json();
    }

    public function test_only_a_correctly_signed_known_platform_gets_in(): void
    {
        $payload = ['team_uuid' => $this->acme->infodot_team_uuid, 'uuid' => (string) Str::uuid()];

        // No signature at all.
        $this->postJson('/api/platform/files/meta', $payload)->assertUnauthorized();
        // Wrong secret.
        $this->signed('docs', 'wrong-secret', '/api/platform/files/meta', $payload)->assertUnauthorized();
        // Another platform's (valid) secret under the wrong name.
        $this->signed('docs', 'secret-billing', '/api/platform/files/meta', $payload)->assertUnauthorized();
        // A platform nobody configured.
        $this->signed('charts', 'anything', '/api/platform/files/meta', $payload)->assertUnauthorized();
        // A stale signature.
        $this->signed('docs', 'secret-docs', '/api/platform/files/meta', $payload, time() - 400)->assertUnauthorized();
        // A fresh, correct one answers (not_found, since the file does not exist — but in).
        $this->signed('docs', 'secret-docs', '/api/platform/files/meta', $payload)->assertNotFound();
    }

    public function test_a_stored_file_is_a_real_record_in_the_companys_own_workspace(): void
    {
        $meta = $this->storeFor($this->acme);

        $this->assertNotEmpty($meta['uuid']);
        $this->assertSame('Invoice INV-204.pdf', $meta['name']);
        $this->assertStringContainsString('/o/'.$meta['uuid'], $meta['open_url']);

        $file = File::withoutGlobalScopes()->where('uuid', $meta['uuid'])->firstOrFail();
        $this->assertSame($this->acme->id, $file->team_id);
        $this->assertSame('docs', $file->origin_platform);
        $this->assertSame('application/pdf', $file->mime, 'the stored mime is sniffed from the bytes, not claimed');

        // It sits in a "Dot.docs" folder at the company's root and the owner can see and open it.
        $this->actingAs($this->owner);
        $folder = Obj::where('objectable_type', 'folder')->with('objectable')->get()
            ->first(fn ($o) => $o->objectable->name === 'Dot.docs');
        $this->assertNotNull($folder, 'the platform folder is created at the root');
        $this->actingAs($this->owner)->get('/o/'.$meta['uuid'])->assertOk()
            ->assertSee('Invoice INV-204.pdf')->assertSee('Dot.docs');

        $this->assertTrue(DB::table('platform_file_events')->where('file_uuid', $meta['uuid'])->where('event', 'stored')->exists());
    }

    public function test_a_platform_reaches_only_its_own_files_in_only_the_named_company(): void
    {
        $mine = $this->storeFor($this->acme);
        $theirs = $this->storeFor($this->globex, 'billing', 'secret-billing', 'Statement.pdf');

        // Another platform, same company: nothing.
        $this->signed('billing', 'secret-billing', '/api/platform/files/fetch',
            ['team_uuid' => $this->acme->infodot_team_uuid, 'uuid' => $mine['uuid']])->assertNotFound();

        // Same platform, wrong company in the signed body: nothing.
        $this->signed('docs', 'secret-docs', '/api/platform/files/fetch',
            ['team_uuid' => $this->globex->infodot_team_uuid, 'uuid' => $mine['uuid']])->assertNotFound();

        // An unknown company is indistinguishable from a missing file.
        $this->signed('docs', 'secret-docs', '/api/platform/files/fetch',
            ['team_uuid' => (string) Str::uuid(), 'uuid' => $mine['uuid']])->assertNotFound();

        // A person's own upload (no origin platform) is not reachable by any platform.
        Storage::disk('local')->put($path = 'files/'.Str::random(10), 'person bytes');
        $this->actingAs($this->owner);
        $personal = $this->acme->files()->create(['name' => 'Private.txt', 'size' => 12, 'path' => $path]);
        auth()->logout();
        $this->signed('docs', 'secret-docs', '/api/platform/files/fetch',
            ['team_uuid' => $this->acme->infodot_team_uuid, 'uuid' => (string) $personal->uuid])->assertNotFound();

        // The right platform, the right company: full round trip.
        $this->signed('docs', 'secret-docs', '/api/platform/files/fetch',
            ['team_uuid' => $this->acme->infodot_team_uuid, 'uuid' => $mine['uuid']])
            ->assertOk()->assertJsonPath('name', 'Invoice INV-204.pdf')
            ->assertJsonPath('content_b64', base64_encode('%PDF-1.4 real bytes'));

        // And billing still reaches its own in Globex.
        $this->signed('billing', 'secret-billing', '/api/platform/files/meta',
            ['team_uuid' => $this->globex->infodot_team_uuid, 'uuid' => $theirs['uuid']])
            ->assertOk()->assertJsonPath('name', 'Statement.pdf');
    }

    public function test_list_pages_through_only_the_callers_files(): void
    {
        $first = $this->storeFor($this->acme, 'docs', 'secret-docs', 'One.pdf');
        $this->storeFor($this->acme, 'billing', 'secret-billing', 'Not-yours.pdf');
        $second = $this->storeFor($this->acme, 'docs', 'secret-docs', 'Two.pdf');

        $page = $this->signed('docs', 'secret-docs', '/api/platform/files/list',
            ['team_uuid' => $this->acme->infodot_team_uuid])->assertOk()->json();

        $this->assertSame(['One.pdf', 'Two.pdf'], array_column($page['files'], 'name'));

        $next = $this->signed('docs', 'secret-docs', '/api/platform/files/list',
            ['team_uuid' => $this->acme->infodot_team_uuid, 'after_id' => File::withoutGlobalScopes()->where('uuid', $first['uuid'])->value('id')])
            ->assertOk()->json();

        $this->assertSame(['Two.pdf'], array_column($next['files'], 'name'));
        $this->assertSame($second['uuid'], $next['files'][0]['uuid']);
    }

    public function test_the_trash_governs_platform_files_too(): void
    {
        $meta = $this->storeFor($this->acme);

        // The platform parks its own file in the company's trash; fetch goes dark at once.
        $this->signed('docs', 'secret-docs', '/api/platform/files/trash',
            ['team_uuid' => $this->acme->infodot_team_uuid, 'uuid' => $meta['uuid']])
            ->assertOk()->assertJsonPath('status', 'trashed');
        $this->signed('docs', 'secret-docs', '/api/platform/files/fetch',
            ['team_uuid' => $this->acme->infodot_team_uuid, 'uuid' => $meta['uuid']])->assertNotFound();

        // The company's people see it in their trash and may restore it — then the API sees it again.
        $obj = File::withoutGlobalScopes()->where('uuid', $meta['uuid'])->firstOrFail()
            ->obj()->withoutGlobalScope('team')->firstOrFail();
        $this->actingAs($this->owner)->get('/trash')->assertOk()->assertSee('Invoice INV-204.pdf');
        $this->actingAs($this->owner)->post('/trash/'.$obj->id.'/restore')->assertRedirect('/trash');

        $this->signed('docs', 'secret-docs', '/api/platform/files/meta',
            ['team_uuid' => $this->acme->infodot_team_uuid, 'uuid' => $meta['uuid']])->assertOk();
    }

    public function test_formats_and_content_are_validated(): void
    {
        $base = ['team_uuid' => $this->acme->infodot_team_uuid, 'content_b64' => base64_encode('x')];

        $this->signed('docs', 'secret-docs', '/api/platform/files/store', $base + ['name' => 'run.exe'])
            ->assertStatus(422)->assertJsonPath('error', 'unsupported_format');
        $this->signed('docs', 'secret-docs', '/api/platform/files/store', $base + ['name' => '../../../etc/passwd.txt'])
            ->assertCreated()->assertJsonPath('name', 'passwd.txt');
        $this->signed('docs', 'secret-docs', '/api/platform/files/store',
            ['team_uuid' => $this->acme->infodot_team_uuid, 'name' => 'a.txt', 'content_b64' => '!!not-base64!!'])
            ->assertStatus(422);
    }
}
