<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Team;
use App\Models\User;
use App\Services\FilePreviewService;
use App\Services\FileShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R-26 document snapshots: rendered on the server from the real blob, streamed only through
 * policy-checked routes, never faked and never public. A format or host that can't render
 * degrades to the type glyph (`preview_status: none`) — no errors, no pretend thumbnails.
 */
class FilePreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $outsider;

    private Team $acme;

    private FilePreviewService $previews;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection']);
        $this->previews = app(FilePreviewService::class);

        $this->owner = User::factory()->withPersonalTeam()->create(['email' => 'owner@acme.test']);
        $this->acme = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Acme', 'personal_team' => false]);
        $this->owner->switchTeam($this->acme);

        $this->outsider = User::factory()->withPersonalTeam()->create(['email' => 'pat@rival.test', 'email_verified_at' => Carbon::now()]);
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(24, 16);
        imagefilledrectangle($im, 0, 0, 23, 15, imagecolorallocate($im, 179, 64, 42));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function fileFor(Team $team, string $name, string $contents, ?string $mime = null): File
    {
        Storage::disk('local')->put($path = 'files/'.Str::random(10), $contents);

        return $team->files()->create(['name' => $name, 'size' => strlen($contents), 'path' => $path, 'mime' => $mime]);
    }

    public function test_an_image_gets_a_real_snapshot_only_its_company_can_fetch(): void
    {
        if (! $this->previews->hasGd()) {
            $this->markTestSkipped('GD is not available on this host.');
        }

        $file = $this->fileFor($this->acme, 'site-plan.png', $this->png(), 'image/png');
        $this->previews->generate($file);

        $file->refresh();
        $this->assertSame('ready', $file->preview_status);
        $this->assertTrue(Storage::disk('local')->exists($file->preview_path));
        $this->assertStringStartsWith("\xFF\xD8", Storage::disk('local')->get($file->preview_path), 'the snapshot is a flat JPEG');

        $this->actingAs($this->owner)->get('/files/'.$file->id.'/preview')
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // Another business never resolves the file at all — binding is team-scoped (R-25).
        $this->actingAs($this->outsider)->get('/files/'.$file->id.'/preview')->assertNotFound();
    }

    public function test_snapshots_are_never_public(): void
    {
        $file = $this->fileFor($this->acme, 'site-plan.png', $this->png(), 'image/png');

        $this->get('/files/'.$file->id.'/preview')->assertRedirect('/login');
    }

    public function test_unsupported_formats_degrade_to_the_glyph_and_svg_is_never_rasterised(): void
    {
        $txt = $this->fileFor($this->acme, 'notes.txt', 'minutes of the meeting', 'text/plain');
        $this->previews->generate($txt);
        $this->assertSame('none', $txt->refresh()->preview_status);

        // SVG is active content; rendering it is out by design, whatever the host can do.
        $svg = $this->fileFor($this->acme, 'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml');
        $this->previews->generate($svg);
        $this->assertSame('none', $svg->refresh()->preview_status);

        $this->actingAs($this->owner)->get('/files/'.$txt->id.'/preview')->assertNotFound();
    }

    public function test_a_recipient_sees_the_snapshot_only_while_the_share_lives(): void
    {
        if (! $this->previews->hasGd()) {
            $this->markTestSkipped('GD is not available on this host.');
        }

        $file = $this->fileFor($this->acme, 'quote.png', $this->png(), 'image/png');
        $this->previews->generate($file);

        $shares = app(FileShareService::class);
        $share = $shares->share($this->owner, $file, 'pat@rival.test', 30);

        $this->actingAs($this->outsider)->get('/shared/'.$share->id.'/preview')
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $shares->revoke($this->owner, $share);
        $this->actingAs($this->outsider)->get('/shared/'.$share->id.'/preview')->assertNotFound();
    }

    public function test_deleting_the_file_deletes_its_snapshot(): void
    {
        if (! $this->previews->hasGd()) {
            $this->markTestSkipped('GD is not available on this host.');
        }

        $file = $this->fileFor($this->acme, 'plan.png', $this->png(), 'image/png');
        $this->previews->generate($file);
        $previewPath = $file->refresh()->preview_path;

        $this->actingAs($this->owner);
        $file->delete();

        $this->assertFalse(Storage::disk('local')->exists($previewPath));
    }
}
