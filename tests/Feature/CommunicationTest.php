<?php

namespace Tests\Feature;

use App\Http\Livewire\NotificationBell;
use App\Models\BulkDeletionRequest;
use App\Models\File;
use App\Models\FileShare;
use App\Models\Obj;
use App\Models\Team;
use App\Models\User;
use App\Notifications\FilesActivity;
use App\Services\TrashPurge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EA-3: the people who must decide are told, the people who asked hear the decision, in the bell and by email; the
 * bell reads and changes only its owner's notices; the approvals link counts what waits; the trash window holds
 * without cron.
 */
class CommunicationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private User $member;

    private Team $acme;

    private File $file;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection']);

        $this->owner = User::factory()->withPersonalTeam()->create(['email' => 'owner@acme.test']);
        $this->acme = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Acme', 'personal_team' => false]);
        $this->owner->switchTeam($this->acme);

        $this->admin = User::factory()->withPersonalTeam()->create(['email' => 'admin@acme.test']);
        $this->acme->users()->attach($this->admin, ['role' => 'admin']);
        $this->member = User::factory()->withPersonalTeam()->create(['email' => 'member@acme.test', 'name' => 'Thandi']);
        $this->acme->users()->attach($this->member, ['role' => 'editor']);
        $this->member->switchTeam($this->acme);

        Storage::disk('local')->put($path = 'files/'.Str::random(8), 'minutes');
        $this->file = $this->acme->files()->create(['name' => 'Board [minutes](https://evil.example).txt', 'size' => 7, 'path' => $path, 'mime' => 'text/plain']);
    }

    /** @return list<string> recipients of every email the array mailer captured */
    private function mailedTo(): array
    {
        return collect(app('mailer')->getSymfonyTransport()->messages())
            ->flatMap(fn ($m) => array_map(fn ($a) => $a->getAddress(), $m->getOriginalMessage()->getTo()))
            ->values()->all();
    }

    private function noticesOf(User $user, string $kind)
    {
        return $user->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? null) === $kind);
    }

    public function test_a_share_request_tells_the_owner_and_administrators_but_not_the_asker(): void
    {
        $this->actingAs($this->member)->post(route('files.share-request', $this->file), ['email' => 'guest@partner.test', 'days' => 30, 'permission' => 'view'])->assertRedirect();

        $this->assertCount(1, $this->noticesOf($this->owner, 'share.requested'));
        $this->assertCount(1, $this->noticesOf($this->admin, 'share.requested'));
        $this->assertCount(0, $this->noticesOf($this->member, 'share.requested'));
        $this->assertEqualsCanonicalizing(['owner@acme.test', 'admin@acme.test'], $this->mailedTo());
    }

    public function test_the_asker_hears_an_approval_and_a_decline_once(): void
    {
        $this->actingAs($this->member)->post(route('files.share-request', $this->file), ['email' => 'guest@partner.test', 'days' => 30]);
        $share = FileShare::where('email', 'guest@partner.test')->firstOrFail();

        $this->actingAs($this->owner)->post(route('approvals.approve', $share))->assertRedirect();
        $this->actingAs($this->owner)->post(route('approvals.approve', $share));

        $this->assertCount(1, $this->noticesOf($this->member, 'share.approved'));

        $this->actingAs($this->member)->post(route('files.share-request', $this->file), ['email' => 'other@partner.test', 'days' => 7]);
        $second = FileShare::where('email', 'other@partner.test')->firstOrFail();
        $this->actingAs($this->admin)->post(route('approvals.decline', $second), ['note' => 'Not this one'])->assertRedirect();

        $this->assertCount(1, $this->noticesOf($this->member, 'share.declined'));
        $this->assertContains('member@acme.test', $this->mailedTo());
    }

    public function test_operator_decisions_reach_the_person_who_asked(): void
    {
        $root = Obj::withoutGlobalScopes()->where('team_id', $this->acme->id)->whereNull('parent_id')->first();
        $obj = $this->acme->objects()->make(['parent_id' => $root->id]);
        $obj->objectable()->associate($this->file);
        $obj->save();
        $request = BulkDeletionRequest::create(['team_id' => $this->acme->id, 'requested_by' => $this->member->id, 'object_ids' => [$obj->id], 'object_names' => ['Board minutes']]);
        $operator = User::factory()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();

        $this->actingAs($operator)->post(route('operator.deletion-requests.bulk.approve', $request))->assertRedirect();

        $this->assertCount(1, $this->noticesOf($this->member, 'deletion.approved'));
    }

    public function test_user_text_cannot_inject_markdown_into_mail(): void
    {
        $plain = FilesActivity::plain("Board [minutes](https://evil.example)\n# Heading <b>bold</b>");

        $this->assertStringNotContainsString('](', $plain);
        $this->assertStringNotContainsString("\n", $plain);
        $this->assertStringNotContainsString('<b>', $plain);
    }

    public function test_the_bell_counts_opens_and_clears_only_its_owners_notices(): void
    {
        $notice = fn (string $title) => new FilesActivity('share.requested', $title, ['Line.'], route('approvals'), 'Review');
        $this->owner->notifyNow($notice('Mine'), ['database']);
        $this->owner->notifyNow($notice('Mine too'), ['database']);
        $this->admin->notifyNow($notice('Theirs'), ['database']);
        $theirs = $this->admin->notifications()->first();

        $this->actingAs($this->owner);
        $bell = Livewire::test(NotificationBell::class)->assertSee('Mine')->assertDontSee('Theirs')->assertSeeHtml('Notifications, 2 unread');

        $bell->call('open', $theirs->id)->assertNoRedirect();
        $this->assertNull($theirs->fresh()->read_at, 'another person\'s notice is never touched');

        $bell->call('open', $this->owner->notifications()->first()->id)->assertRedirect(route('approvals'));
        $bell->call('markAllRead');

        $this->assertSame(0, $this->owner->unreadNotifications()->count());
        $this->assertSame(1, $this->admin->unreadNotifications()->count());
    }

    public function test_the_approvals_link_counts_what_waits_for_a_decider(): void
    {
        FileShare::forceCreate(['file_id' => $this->file->id, 'email' => 'guest@partner.test', 'status' => 'pending', 'created_by' => $this->member->id, 'expires_at' => now()->addDays(7)]);

        $this->actingAs($this->owner)->get(route('files'))->assertOk()->assertSee('Sharing approvals, 1 waiting');
        $this->actingAs($this->member)->get(route('files'))->assertOk()->assertDontSee('Sharing approvals, 1 waiting')->assertDontSee('<span class="dh-count">', false);
    }

    public function test_the_trash_window_holds_without_cron_and_sweeps_at_most_hourly(): void
    {
        $root = Obj::withoutGlobalScopes()->where('team_id', $this->acme->id)->whereNull('parent_id')->first();
        $make = function (string $name, int $daysAgo) use ($root) {
            Storage::disk('local')->put($path = 'files/'.Str::random(8), 'x');
            $obj = $this->acme->objects()->make(['parent_id' => $root->id]);
            $obj->objectable()->associate($this->acme->files()->create(['name' => $name, 'size' => 1, 'path' => $path]));
            $obj->save();
            $obj->forceFill(['trashed_at' => now()->subDays($daysAgo)])->save();

            return $obj;
        };
        $overdue = $make('Old.txt', 31);
        $recent = $make('Recent.txt', 3);
        Cache::flush();

        // Any page visit, by anyone, sweeps after the response.
        $this->get('/');

        $this->assertNull(Obj::withoutGlobalScopes()->find($overdue->id));
        $this->assertNotNull(Obj::withoutGlobalScopes()->find($recent->id));

        $later = $make('Later.txt', 40);
        $this->get('/');
        $this->assertNotNull(Obj::withoutGlobalScopes()->find($later->id), 'one sweep an hour');

        $this->assertSame(1, app(TrashPurge::class)->overdue());
    }

    public function test_only_email_work_is_allowed_to_run_from_cron(): void
    {
        $this->assertTrue((bool) config('dot_cron.essential_only'));
        $this->artisan('dot:cron-status')->expectsOutputToContain('PAUSED')->assertSuccessful();
    }
}
