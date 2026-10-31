<?php

namespace Tests\Feature;

use App\Http\Livewire\FileBrowser;
use App\Models\File;
use App\Models\FilePin;
use App\Models\Obj;
use App\Models\Team;
use App\Models\User;
use App\Services\FileShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-27: trash with restore, pins, InfoDot-only entry, inline rendering — each enforced
 * server-side and each on the company's own data only.
 */
class WorkspaceR27Test extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $outsider;

    private Team $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection']);

        $this->owner = User::factory()->withPersonalTeam()->create(['email' => 'owner@acme.test']);
        $this->acme = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Acme', 'personal_team' => false]);
        $this->owner->switchTeam($this->acme);

        $this->outsider = User::factory()->withPersonalTeam()->create(['email' => 'pat@rival.test', 'email_verified_at' => Carbon::now()]);
    }

    private function fileFor(Team $team, string $name, string $mime = 'text/plain', string $contents = 'contents'): File
    {
        Storage::disk('local')->put($path = 'files/'.Str::random(10), $contents);
        $file = $team->files()->create(['name' => $name, 'size' => strlen($contents), 'path' => $path, 'mime' => $mime]);

        $obj = $team->objects()->make(['parent_id' => $this->rootOf($team)->id]);
        $obj->objectable()->associate($file);
        $obj->save();

        return $file;
    }

    private function rootOf(Team $team): Obj
    {
        return $team->objects()->withoutGlobalScope('team')->whereNull('parent_id')->firstOrFail();
    }

    public function test_a_deleted_file_waits_in_the_trash_blocked_to_outsiders_and_comes_back_on_restore(): void
    {
        $file = $this->fileFor($this->acme, 'Contract.txt');
        $share = app(FileShareService::class)->share($this->owner, $file, 'pat@rival.test', 30);
        $obj = $file->obj()->withoutGlobalScope('team')->first();

        Livewire::actingAs($this->owner)
            ->test(FileBrowser::class, ['object' => $this->rootOf($this->acme), 'ancestors' => collect([$this->rootOf($this->acme)])])
            ->set('confirmingObjectDeletion', $obj->id)
            ->call('deleteObject');

        $obj->refresh();
        $this->assertTrue($obj->isTrashed());
        $this->assertTrue(Storage::disk('local')->exists($file->path), 'the blob survives the trash');

        // Outside the company the share goes dark at once; nothing says why.
        $this->actingAs($this->outsider)->get('/shared/'.$share->id.'/download')->assertNotFound();
        $this->actingAs($this->outsider)->get('/o/'.$file->uuid)->assertForbidden();

        // The trash lists it; restore puts it back; the share works again.
        $this->actingAs($this->owner)->get('/trash')->assertOk()->assertSee('Contract.txt');
        $this->actingAs($this->owner)->post('/trash/'.$obj->id.'/restore')->assertRedirect('/trash');
        $this->assertFalse($obj->refresh()->isTrashed());
        $this->actingAs($this->outsider)->get('/shared/'.$share->id.'/download')->assertOk();
    }

    public function test_one_companys_trash_is_invisible_to_another_and_purge_clears_only_old_records(): void
    {
        $file = $this->fileFor($this->acme, 'Old ledger.txt');
        $obj = $file->obj()->withoutGlobalScope('team')->first();
        $obj->trash($this->owner->id);

        // Another business: the trash page never shows it, the rows never bind.
        $this->actingAs($this->outsider)->get('/trash')->assertOk()->assertDontSee('Old ledger.txt');
        $this->actingAs($this->outsider)->post('/trash/'.$obj->id.'/restore')->assertNotFound();
        $this->actingAs($this->outsider)->post('/trash/'.$obj->id.'/destroy')->assertNotFound();

        // The purge takes only records past the window.
        Artisan::call('files:purge-trash');
        $this->assertNotNull(Obj::withoutGlobalScope('team')->find($obj->id), 'fresh trash survives the purge');

        $obj->forceFill(['trashed_at' => now()->subDays(Obj::TRASH_DAYS + 1)])->save();
        Artisan::call('files:purge-trash');
        $this->assertNull(Obj::withoutGlobalScope('team')->find($obj->id));
        $this->assertFalse(Storage::disk('local')->exists($file->path), 'the purge unlinks the blob');
    }

    public function test_pins_are_personal_bookmarks_that_grant_nothing(): void
    {
        $file = $this->fileFor($this->acme, 'Price list.txt');

        Livewire::actingAs($this->owner)
            ->test(FileBrowser::class, ['object' => $this->rootOf($this->acme), 'ancestors' => collect([$this->rootOf($this->acme)])])
            ->call('togglePin', $file->id);

        $this->assertTrue($file->isPinnedBy($this->owner));

        // Another business pinning a foreign id: the scoped lookup finds nothing, no pin lands.
        Livewire::actingAs($this->outsider)
            ->test(FileBrowser::class, ['object' => $this->rootOf($this->outsider->personalTeam()), 'ancestors' => collect()])
            ->call('togglePin', $file->id);

        $this->assertSame(0, FilePin::where('user_id', $this->outsider->id)->count());

        // And even a forged pin row shows nothing: the pins shelf reads through the team scope.
        FilePin::create(['user_id' => $this->outsider->id, 'file_id' => $file->id]);
        $this->actingAs($this->outsider)->get('/files')->assertOk()->assertDontSee('Price list.txt');
    }

    public function test_sso_only_mode_sends_the_login_page_to_infodot(): void
    {
        config(['ecosystem.sso_only' => true, 'ecosystem.platforms.infodot.url' => 'https://infodot.co.za']);
        $this->get('/login')->assertRedirect('https://infodot.co.za/login');

        // R-28 §14–15: every other local authentication page goes to InfoDot too.
        $this->get('/register')->assertRedirect('https://infodot.co.za/register');
        $this->get('/forgot-password')->assertRedirect('https://infodot.co.za/forgot-password');
        $this->get('/reset-password/some-token')->assertRedirect('https://infodot.co.za/forgot-password');

        // Break-glass: with the switch off, the local form is back.
        // (Registration stays closed in production — RegistrationClosedTest owns that;
        // the suite itself runs with ALLOW_REGISTRATION=true for Jetstream's own tests.)
        config(['ecosystem.sso_only' => false]);
        $this->get('/login')->assertOk();
    }

    public function test_inline_rendering_is_policy_checked_and_passive_formats_only(): void
    {
        $pdf = $this->fileFor($this->acme, 'Terms.pdf', 'application/pdf', '%PDF-1.4 fake');
        $svg = $this->fileFor($this->acme, 'logo.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        // Signed out first (actingAs sticks for the rest of the test): no public URL exists.
        $this->get('/files/'.$pdf->id.'/inline')->assertRedirect('/login');

        $this->actingAs($this->owner)->get('/files/'.$pdf->id.'/inline')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="Terms.pdf"');

        // Active content never renders inline, and other companies never resolve the file.
        $this->actingAs($this->owner)->get('/files/'.$svg->id.'/inline')->assertNotFound();
        $this->actingAs($this->outsider)->get('/files/'.$pdf->id.'/inline')->assertNotFound();
    }
}
