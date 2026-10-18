<?php

namespace Tests\Feature;

use App\Actions\Ecosystem\ApplyMembershipChange;
use App\Models\File;
use App\Models\FileShare;
use App\Models\Team;
use App\Models\User;
use App\Services\FileShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Cross-company sharing v1: one file, one person outside the company, by verified email, for a
 * limited time, checked on every open, revoked on every way access should end, audited.
 */
class FileSharingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $editor;

    private User $outsider;

    private Team $acme;

    private File $file;

    private FileShareService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection']);
        $this->service = app(FileShareService::class);

        $this->owner = User::factory()->withPersonalTeam()->create(['email' => 'owner@acme.test']);
        $this->acme = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Acme', 'personal_team' => false]);
        $this->owner->switchTeam($this->acme);

        $this->editor = User::factory()->withPersonalTeam()->create(['email' => 'editor@acme.test']);
        $this->acme->users()->attach($this->editor, ['role' => 'editor']);

        $this->outsider = User::factory()->withPersonalTeam()->create(['email' => 'Pat@Rival.test', 'email_verified_at' => Carbon::now()]);

        Storage::disk('local')->put($path = 'files/'.Str::random(8).'.txt', 'budget numbers');
        $this->file = $this->acme->files()->create(['name' => 'Budget 2027.txt', 'size' => 14, 'path' => $path]);
    }

    private function share(string $email = 'pat@rival.test', int $days = 30): FileShare
    {
        return $this->service->share($this->owner, $this->file, $email, $days);
    }

    public function test_an_owner_or_administrator_can_share_but_an_editor_or_outsider_cannot(): void
    {
        $this->assertTrue($this->service->canShare($this->owner, $this->file));

        $admin = User::factory()->withPersonalTeam()->create();
        $this->acme->users()->attach($admin, ['role' => 'admin']);
        $this->assertTrue($this->service->canShare($admin, $this->file));

        $this->assertFalse($this->service->canShare($this->editor, $this->file));
        $this->assertFalse($this->service->canShare($this->outsider, $this->file));

        $this->editor->switchTeam($this->acme);
        $this->actingAs($this->editor)->get('/files/'.$this->file->id.'/share')->assertForbidden();
        $this->actingAs($this->owner)->get('/files/'.$this->file->id.'/share')->assertOk()->assertSee('Budget 2027.txt');
    }

    public function test_personal_workspaces_cannot_share(): void
    {
        $personal = $this->owner->personalTeam();
        Storage::disk('local')->put('files/p.txt', 'x');
        $mine = $personal->files()->create(['name' => 'mine.txt', 'size' => 1, 'path' => 'files/p.txt']);

        $this->assertFalse($this->service->canShare($this->owner, $mine));
    }

    public function test_the_rules_of_a_share(): void
    {
        $days = now()->diffInHours($this->share('a@x.test', 365)->expires_at, true) / 24;
        $this->assertLessThanOrEqual(FileShare::MAX_DAYS + 0.1, $days, 'never longer than 90 days');
        $this->assertGreaterThan(FileShare::MAX_DAYS - 1, $days);

        foreach (['not-an-email', 'owner@acme.test', 'editor@acme.test'] as $bad) {
            try {
                $this->service->share($this->owner, $this->file, $bad, 30);
                $this->fail("$bad should be refused");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $first = $this->share('pat@rival.test');
        $again = $this->share('PAT@rival.test', 60);
        $this->assertTrue($first->is($again), 'sharing again renews instead of stacking');
        $this->assertSame(2, FileShare::where('file_id', $this->file->id)->active()->count());
    }

    public function test_at_most_twenty_five_people_per_file(): void
    {
        foreach (range(1, FileShare::MAX_ACTIVE_PER_FILE) as $i) {
            $this->share("p$i@x.test");
        }

        $this->expectException(ValidationException::class);
        $this->share('one-too-many@x.test');
    }

    public function test_the_person_it_was_shared_with_can_open_it_and_nothing_else(): void
    {
        $share = $this->share();

        $this->actingAs($this->outsider)->get('/o/'.$this->file->uuid)
            ->assertOk()
            ->assertSee('Budget 2027.txt')
            ->assertSee(route('shared.download', $share), false)
            ->assertDontSee('Acme')
            ->assertDontSee('Open folder');

        $this->assertNotSame($this->acme->id, $this->outsider->fresh()->current_team_id, 'their workspace is not switched to the company');
        $this->assertSame(1, $share->fresh()->open_count);
        $this->assertNotNull($share->fresh()->first_opened_at);

        $this->actingAs($this->outsider)->get(route('shared.download', $share))->assertOk()->assertDownload('Budget 2027.txt');
        $this->actingAs($this->outsider)->get('/files/'.$this->file->id)->assertNotFound();
        $this->actingAs($this->outsider)->get('/files')->assertOk()->assertDontSee('Budget 2027.txt');
    }

    public function test_nobody_else_can_use_the_share_or_its_link(): void
    {
        $share = $this->share();
        $other = User::factory()->withPersonalTeam()->create(['email' => 'forwarded@elsewhere.test', 'email_verified_at' => Carbon::now()]);

        $this->actingAs($other)->get('/o/'.$this->file->uuid)->assertForbidden()->assertDontSee('Budget');
        $this->actingAs($other)->get(route('shared.download', $share))->assertNotFound();

        $unverified = User::factory()->withPersonalTeam()->create(['email' => 'pat@rival.test2', 'email_verified_at' => null]);
        $unverified->forceFill(['email' => 'pat@rival.test'])->saveQuietly();
        $this->assertNull($this->service->activeShareFor($unverified, $this->file), 'an unverified address never matches a share');

        auth()->logout();
        $this->get('/o/'.$this->file->uuid)->assertRedirect('/login');
    }

    public function test_expired_and_revoked_shares_stop_working_at_once(): void
    {
        $share = $this->share();
        $this->actingAs($this->outsider)->get('/o/'.$this->file->uuid)->assertOk();

        $this->service->revoke($this->owner, $share);
        $this->actingAs($this->outsider)->get('/o/'.$this->file->uuid)->assertForbidden();
        $this->actingAs($this->outsider)->get(route('shared.download', $share))->assertNotFound();

        $second = $this->share();
        $second->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->actingAs($this->outsider)->get('/o/'.$this->file->uuid)->assertForbidden();
    }

    public function test_the_shared_with_me_list_shows_only_my_live_shares(): void
    {
        $this->share();
        Storage::disk('local')->put('files/o.txt', 'x');
        $other = $this->acme->files()->create(['name' => 'Someone elses.txt', 'size' => 1, 'path' => 'files/o.txt']);
        $this->service->share($this->owner, $other, 'nobody-else@x.test', 30);

        $this->actingAs($this->outsider)->get('/shared')->assertOk()->assertSee('Budget 2027.txt')->assertDontSee('Someone elses.txt');
    }

    public function test_every_step_is_in_the_audit_trail_and_it_holds_no_file_names(): void
    {
        $share = $this->share();
        $this->actingAs($this->outsider)->get('/o/'.$this->file->uuid);
        $this->actingAs($this->outsider)->get(route('shared.download', $share));
        $this->service->revoke($this->owner, $share);

        $events = DB::table('file_share_events')->where('file_uuid', $this->file->uuid)->orderBy('id')->pluck('event')->all();
        $this->assertSame(['created', 'opened', 'downloaded', 'revoked'], $events);

        $columns = Schema::getColumnListing('file_share_events');
        $this->assertNotContains('name', $columns);
        $this->assertSame(0, DB::table('file_share_events')->where('note', 'like', '%Budget%')->count());
    }

    public function test_a_refused_open_is_recorded_as_denied(): void
    {
        $this->actingAs($this->outsider)->get('/o/'.$this->file->uuid)->assertForbidden();

        $this->assertSame(1, DB::table('file_share_events')->where('event', 'denied')->where('actor_email', 'pat@rival.test')->count());
    }

    public function test_deleting_the_file_ends_every_share_and_says_why(): void
    {
        $share = $this->share();
        $uuid = $this->file->uuid;

        $this->file->delete();

        $this->assertDatabaseMissing('file_shares', ['id' => $share->id]);
        $this->assertSame('file deleted', DB::table('file_share_events')->where('file_uuid', $uuid)->where('event', 'revoked')->value('note'));
        $this->actingAs($this->outsider)->get('/o/'.$uuid)->assertNotFound();
    }

    public function test_when_the_sharer_leaves_or_loses_admin_rights_or_the_company_goes_the_shares_go(): void
    {
        config(['ecosystem.infodot.secret' => 's']);
        $admin = User::factory()->withPersonalTeam()->create(['email' => 'admin@acme.test']);
        $this->acme->users()->attach($admin, ['role' => 'admin']);
        $this->acme->forceFill(['infodot_team_uuid' => (string) Str::uuid()])->save();
        $apply = app(ApplyMembershipChange::class);

        $byAdmin = $this->service->share($admin, $this->file, 'one@x.test', 30);
        $apply(['event' => 'member.role_changed', 'team_uuid' => $this->acme->infodot_team_uuid, 'email' => 'admin@acme.test', 'role' => 'editor']);
        $this->assertNotNull($byAdmin->fresh()->revoked_at, 'demoted: their shares are revoked');

        $this->acme->users()->updateExistingPivot($admin->id, ['role' => 'admin']);
        $again = $this->service->share($admin, $this->file, 'two@x.test', 30);
        $apply(['event' => 'member.removed', 'team_uuid' => $this->acme->infodot_team_uuid, 'email' => 'admin@acme.test']);
        $this->assertNotNull($again->fresh()->revoked_at, 'removed: their shares are revoked');

        $kept = $this->share('three@x.test');
        $apply(['event' => 'team.deleted', 'team_uuid' => $this->acme->infodot_team_uuid]);
        $this->assertNotNull($kept->fresh()->revoked_at, 'company deleted: every share is revoked');
    }
}
