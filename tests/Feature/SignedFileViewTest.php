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
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `GET /files/{uuid}/view` — the only way into the private blob store.
 *
 * Three separate things have to hold, and each is a different failure if it
 * does not: the link must have been minted here and still be fresh (`signed`,
 * ten minutes), the viewer must belong to the FILE'S team (not whichever team
 * their session happens to have selected), and the bytes must only be
 * rendered in this origin when the type cannot execute. The last one is why
 * `image/svg+xml` gets a test of its own: an SVG is a script that renders as
 * a picture, and `files` is a shared table, so a row naming that type can
 * arrive from Dot.Doc or from an older Dot.Files that still accepted `.svg`
 * on upload.
 */
class SignedFileViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A real local disk: the controller proves containment with realpath().
        Storage::fake('files');
    }

    private function storeFile(Team $team, string $mime, ?string $path = null): File
    {
        $path ??= 'files/'.$team->id.'/'.uniqid().'.bin';

        Storage::disk('files')->put($path, 'the bytes');

        $file = File::create([
            'name' => 'report.bin',
            'size' => 9,
            'path' => $path,
            'mime_type' => $mime,
            'team_id' => $team->id,
        ]);

        $obj = new Obj(['parent_id' => Obj::withoutGlobalScope('team')->where('team_id', $team->id)->whereNull('parent_id')->value('id')]);
        $obj->team_id = $team->id;
        $obj->objectable()->associate($file);
        $obj->save();

        return $file;
    }

    private function viewUrl(File $file, ?int $minutes = 10): string
    {
        return URL::temporarySignedRoute('files.view', now()->addMinutes($minutes), ['file' => $file->uuid]);
    }

    public function test_a_member_opening_a_signed_link_gets_the_bytes(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $file = $this->storeFile($owner->currentTeam, 'image/png');

        $response = $this->actingAs($owner)->get($this->viewUrl($file));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
    }

    public function test_an_unsigned_link_is_refused(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $file = $this->storeFile($owner->currentTeam, 'image/png');

        $this->actingAs($owner)
            ->get(route('files.view', ['file' => $file->uuid]))
            ->assertForbidden();
    }

    public function test_a_tampered_link_is_refused(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $file = $this->storeFile($owner->currentTeam, 'image/png');
        $other = $this->storeFile($owner->currentTeam, 'image/png');

        $tampered = str_replace($file->uuid, $other->uuid, $this->viewUrl($file));

        $this->actingAs($owner)->get($tampered)->assertForbidden();
    }

    public function test_an_expired_link_is_refused(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $file = $this->storeFile($owner->currentTeam, 'image/png');

        $url = $this->viewUrl($file);

        $this->travel(11)->minutes();

        $this->actingAs($owner)->get($url)->assertForbidden();
    }

    public function test_a_signed_link_is_not_a_bearer_token_for_someone_outside_the_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();
        $file = $this->storeFile($owner->currentTeam, 'image/png');

        $this->actingAs($outsider)->get($this->viewUrl($file))->assertForbidden();
    }

    public function test_membership_is_read_off_the_file_not_the_session_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $guest = User::factory()->withPersonalTeam()->create();
        $owner->currentTeam->users()->attach($guest, ['role' => 'editor']);

        $file = $this->storeFile($owner->currentTeam, 'image/png');

        // The guest's session is on their OWN team; the file is on the
        // owner's. Membership is what decides, so this is allowed.
        $this->actingAs($guest)->get($this->viewUrl($file))->assertOk();
    }

    public function test_an_svg_is_never_rendered_in_this_origin(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $file = $this->storeFile($owner->currentTeam, 'image/svg+xml');

        $response = $this->actingAs($owner)->get($this->viewUrl($file));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function dispositions(): array
    {
        return [
            'png' => ['image/png', 'inline'],
            'jpeg' => ['image/jpeg', 'inline'],
            'gif' => ['image/gif', 'inline'],
            'webp' => ['image/webp', 'inline'],
            'pdf' => ['application/pdf', 'inline'],
            'plain text' => ['text/plain', 'inline'],
            'text with a charset' => ['text/plain; charset=utf-8', 'inline'],
            'svg' => ['image/svg+xml', 'attachment'],
            'html' => ['text/html', 'attachment'],
            'zip' => ['application/zip', 'attachment'],
            'unknown' => ['', 'attachment'],
        ];
    }

    #[DataProvider('dispositions')]
    public function test_only_a_type_on_the_allow_list_is_rendered_inline(string $mime, string $expected): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $file = $this->storeFile($owner->currentTeam, $mime);

        $response = $this->actingAs($owner)->get($this->viewUrl($file));

        $this->assertStringStartsWith($expected.';', $response->headers->get('Content-Disposition'));
    }

    public function test_a_stored_path_that_escapes_the_disk_is_not_served(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $file = $this->storeFile($owner->currentTeam, 'text/plain');

        // A row on a SHARED table was not necessarily written by this app.
        $file->forceFill(['path' => '../../../../etc/passwd'])->saveQuietly();

        $this->actingAs($owner)->get($this->viewUrl($file))->assertNotFound();
    }

    public function test_an_absolute_stored_path_is_not_served(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $file = $this->storeFile($owner->currentTeam, 'text/plain');

        $file->forceFill(['path' => '/etc/passwd'])->saveQuietly();

        $this->actingAs($owner)->get($this->viewUrl($file))->assertNotFound();
    }

    public function test_the_upload_form_no_longer_accepts_an_svg(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->firstOrFail();

        Livewire::actingAs($owner)
            ->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect()])
            ->set('upload', UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'))
            ->assertHasErrors('upload');

        $this->assertSame(0, File::withoutGlobalScope('team')->count());
    }

    public function test_an_upload_of_an_allowed_type_still_lands_in_the_tree(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->firstOrFail();

        Livewire::actingAs($owner)
            ->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect()])
            ->set('upload', UploadedFile::fake()->image('chart.png'))
            ->assertHasNoErrors();

        $file = File::withoutGlobalScope('team')->firstOrFail();

        $this->assertSame('chart.png', $file->name);
        $this->assertSame($owner->id, $file->owner_id);
        $this->assertTrue(Storage::disk('files')->exists($file->path));
    }
}
