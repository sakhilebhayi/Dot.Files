<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\FileVersion;
use App\Models\Team;
use App\Models\User;
use App\Services\FileVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R-28 Part F: the append-only content trail. Replacing stores a new blob and a new version,
 * restoring copies an old version forward, nothing in between is ever erased, and every row
 * carries who/when/whence plus the content's sha-256.
 */
class VersionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $acme;

    private File $file;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection']);

        $this->owner = User::factory()->withPersonalTeam()->create(['email' => 'owner@acme.test']);
        $this->acme = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Acme', 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);
        $this->owner->switchTeam($this->acme);

        Storage::disk('local')->put($path = 'files/'.Str::random(8), 'first draft');
        $this->file = $this->acme->files()->create(['name' => 'Contract.txt', 'size' => 11, 'path' => $path, 'mime' => 'text/plain', 'uploaded_by' => $this->owner->id]);

        $obj = $this->acme->objects()->make(['parent_id' => $this->acme->objects()->withoutGlobalScope('team')->whereNull('parent_id')->first()->id]);
        $obj->objectable()->associate($this->file);
        $obj->save();
    }

    public function test_replace_keeps_the_old_content_and_restore_erases_nothing(): void
    {
        $this->actingAs($this->owner);
        $versions = app(FileVersions::class);

        // Replace twice: three versions exist, each with its own blob and checksum.
        $versions->replace($this->file, 'second draft', 'text/plain', $this->owner->id, null);
        $versions->replace($this->file->refresh(), 'third draft', 'text/plain', $this->owner->id, null);

        $trail = FileVersion::where('file_id', $this->file->id)->orderBy('version')->get();
        $this->assertSame([1, 2, 3], $trail->pluck('version')->all());
        $this->assertSame(3, (int) $this->file->refresh()->current_version);
        $this->assertSame('third draft', Storage::disk('local')->get($this->file->path));
        $this->assertSame('second draft', Storage::disk('local')->get($trail[1]->path), 'the old blob is untouched');
        $this->assertSame(hash('sha256', 'third draft'), $this->file->checksum);

        // Restore version 1: it comes BACK as version 4; 2 and 3 survive.
        $new = $versions->restore($this->file, $trail[0], $this->owner->id);
        $this->assertSame(4, (int) $new->version);
        $this->assertSame(1, (int) $new->restored_from_version);
        $this->assertSame('first draft', Storage::disk('local')->get($this->file->refresh()->path));
        $this->assertSame([1, 2, 3, 4], FileVersion::where('file_id', $this->file->id)->orderBy('version')->pluck('version')->all());
    }

    public function test_the_web_flow_replaces_restores_and_downloads_versions_under_the_team_policy(): void
    {
        $this->actingAs($this->owner)
            ->post('/files/'.$this->file->id.'/replace', ['upload' => UploadedFile::fake()->createWithContent('Contract v2.txt', 'web second')])
            ->assertRedirect('/o/'.$this->file->uuid);

        $this->assertSame(2, (int) $this->file->refresh()->current_version);
        $this->assertSame('web second', Storage::disk('local')->get($this->file->path));

        $v1 = FileVersion::where('file_id', $this->file->id)->where('version', 1)->firstOrFail();
        $this->actingAs($this->owner)->get('/files/'.$this->file->id.'/versions/'.$v1->id.'/download')->assertOk();
        $this->actingAs($this->owner)->post('/files/'.$this->file->id.'/versions/'.$v1->id.'/restore')->assertRedirect('/o/'.$this->file->uuid);
        $this->assertSame('first draft', Storage::disk('local')->get($this->file->refresh()->path));

        // The document page shows the trail.
        $this->actingAs($this->owner)->get('/o/'.$this->file->uuid)->assertOk()
            ->assertSee('Version history')->assertSee('Restored from version 1')->assertSee('Current');

        // Another business can neither see nor touch any of it.
        $outsider = User::factory()->withPersonalTeam()->create();
        $this->actingAs($outsider)->get('/files/'.$this->file->id.'/versions/'.$v1->id.'/download')->assertNotFound();
        $this->actingAs($outsider)->post('/files/'.$this->file->id.'/versions/'.$v1->id.'/restore')->assertNotFound();
    }

    public function test_a_platform_update_lands_as_a_version_with_the_platform_as_author(): void
    {
        config(['ecosystem.platform_secrets' => ['docs' => 'secret-docs']]);

        // File the document through the API so origin scoping applies.
        $body = json_encode(['team_uuid' => $this->acme->infodot_team_uuid, 'name' => 'Minutes.txt', 'content_b64' => base64_encode('v1 by docs')]);
        $ts = time();
        $stored = $this->call('POST', '/api/platform/files/store', [], [], [], [
            'HTTP_X-DOT-PLATFORM' => 'docs', 'HTTP_X-DOT-TIMESTAMP' => (string) $ts,
            'HTTP_X-DOT-SIGNATURE' => 'sha256='.hash_hmac('sha256', $ts.'.'.$body, 'secret-docs'),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertCreated()->json();

        $body = json_encode(['team_uuid' => $this->acme->infodot_team_uuid, 'uuid' => $stored['uuid'], 'content_b64' => base64_encode('v2 by docs')]);
        $ts = time();
        $updated = $this->call('POST', '/api/platform/files/update', [], [], [], [
            'HTTP_X-DOT-PLATFORM' => 'docs', 'HTTP_X-DOT-TIMESTAMP' => (string) $ts,
            'HTTP_X-DOT-SIGNATURE' => 'sha256='.hash_hmac('sha256', $ts.'.'.$body, 'secret-docs'),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk()->json();

        $this->assertSame(2, $updated['version']);
        $this->assertSame(hash('sha256', 'v2 by docs'), $updated['checksum']);

        $file = File::withoutGlobalScopes()->where('uuid', $stored['uuid'])->firstOrFail();
        $v2 = FileVersion::where('file_id', $file->id)->where('version', 2)->firstOrFail();
        $this->assertSame('platform-update', $v2->action);
        $this->assertSame('docs', $v2->origin_platform);
        $this->assertSame('v1 by docs', Storage::disk('local')->get(FileVersion::where('file_id', $file->id)->where('version', 1)->value('path')));
    }
}
