<?php

namespace Tests\Feature;

use App\Http\Livewire\FileBrowser;
use App\Models\File;
use App\Models\FileFlag;
use App\Models\FileShare;
use App\Models\Obj;
use App\Models\Team;
use App\Models\User;
use App\Services\FileVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-51: the working dashboard at /files. The headline states what needs a person, the room panel lists
 * what is waiting, ending and changing, the register shows outside shares, and the foot carries the tallies.
 * Tenant isolation for the same queries lives in FilesTenantIsolationTest.
 */
class DashboardR51Test extends TestCase
{
    use RefreshDatabase;

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

    private function root(): Obj
    {
        return $this->acme->objects()->withoutGlobalScope('team')->whereNull('parent_id')->firstOrFail();
    }

    /** @return array{0: File, 1: Obj} */
    private function fileFor(string $name, ?Obj $parent = null, ?User $by = null): array
    {
        Storage::disk('local')->put($path = 'files/'.Str::random(10), 'contents of '.$name);
        $file = $this->acme->files()->create(['name' => $name, 'size' => 20, 'path' => $path, 'mime' => 'text/plain', 'uploaded_by' => ($by ?? $this->owner)->id]);
        $obj = $this->acme->objects()->make(['parent_id' => ($parent ?? $this->root())->id]);
        $obj->objectable()->associate($file);
        $obj->save();

        return [$file, $obj];
    }

    private function share(File $file, string $status, int $days, ?User $requestedBy = null, string $email = 'outside@example.test'): FileShare
    {
        return FileShare::create([
            'file_id' => $file->id, 'email' => $email, 'created_by' => ($requestedBy ?? $this->owner)->id,
            'requested_by' => $status === 'pending' ? ($requestedBy ?? $this->editor)->id : $requestedBy?->id,
            'expires_at' => now()->addDays($days), 'status' => $status, 'permission' => 'view',
        ]);
    }

    private function flag(File $file): FileFlag
    {
        return FileFlag::create(['team_id' => $this->acme->id, 'file_id' => $file->id, 'rule' => 'version-churn', 'actor_id' => $this->owner->id, 'severity' => 'review', 'evidence' => ['count' => 5], 'status' => 'open']);
    }

    private function browser(User $user, ?Obj $at = null)
    {
        $at ??= $this->root();

        return Livewire::actingAs($user)->test(FileBrowser::class, ['object' => $at, 'ancestors' => collect([$at])]);
    }

    // ---- the shell is opt-in ----

    public function test_only_the_files_page_takes_the_desk_shell(): void
    {
        $this->fileFor('a.txt');

        $this->actingAs($this->owner)->get('/files')->assertOk()->assertSee('fx-shell-desk', false)->assertSee('fx-foot', false);

        foreach (['/trash', '/approvals', '/shared'] as $other) {
            $this->actingAs($this->owner)->get($other)->assertOk()->assertDontSee('fx-shell-desk', false);
        }
    }

    // ---- the headline: first true state wins, every number real ----

    public function test_headline_for_a_decider_counts_requests_and_flags_with_correct_plurals(): void
    {
        [$a] = $this->fileFor('a.txt');
        [$b] = $this->fileFor('b.txt');
        $this->share($a, 'pending', 30);
        $this->share($b, 'pending', 30);
        $this->flag($a);

        $this->assertSame('2 share requests and 1 flagged activity wait on you.', $this->browser($this->owner)->get('headline'));

        FileShare::where('file_id', $b->id)->delete();
        $this->assertSame('1 share request and 1 flagged activity wait on you.', $this->browser($this->admin)->get('headline'));

        FileShare::query()->delete();
        $this->assertSame('1 flagged activity waits on you.', $this->browser($this->owner)->get('headline'));
    }

    public function test_headline_for_a_member_counts_only_their_own_requests_and_never_flags(): void
    {
        [$a] = $this->fileFor('a.txt');
        [$b] = $this->fileFor('b.txt');
        $this->share($a, 'pending', 30, $this->editor);
        $this->share($b, 'pending', 30, $this->admin); // someone else's request
        $this->flag($a);

        $this->assertSame('1 of your share requests waits for approval.', $this->browser($this->editor)->get('headline'));
        $this->assertSame(0, $this->browser($this->editor)->get('openFlagCount'));
    }

    public function test_headline_uses_the_seven_day_threshold_for_ending_shares(): void
    {
        [$a] = $this->fileFor('a.txt');
        $share = $this->share($a, 'active', 8);

        $this->assertSame('1 document is filed for Acme Mining Supplies.', $this->browser($this->owner)->get('headline'), '8 days is outside the 7-day window');

        $share->update(['expires_at' => now()->addDays(4)->subHour()]);
        $this->assertSame('1 outside share ends in 4 days.', $this->browser($this->owner)->get('headline'));

        $this->share($a, 'active', 2, null, 'second@example.test');
        $this->assertSame('2 outside shares end within 7 days, the first in 2 days.', $this->browser($this->owner)->get('headline'));
    }

    public function test_headline_falls_back_to_the_plain_count_and_a_personal_workspace_has_no_share_states(): void
    {
        $this->fileFor('a.txt');
        $this->fileFor('b.txt');

        $this->assertSame('2 documents are filed for Acme Mining Supplies.', $this->browser($this->owner)->get('headline'));

        $solo = User::factory()->withPersonalTeam()->create(['name' => 'Naledi Jacobs']);
        $personal = $solo->personalTeam();
        Storage::disk('local')->put('files/solo', 'x');
        $file = $personal->files()->create(['name' => 'solo.txt', 'size' => 1, 'path' => 'files/solo', 'mime' => 'text/plain', 'uploaded_by' => $solo->id]);
        $obj = $personal->objects()->make(['parent_id' => $personal->objects()->withoutGlobalScope('team')->whereNull('parent_id')->value('id')]);
        $obj->objectable()->associate($file);
        $obj->save();
        FileShare::create(['file_id' => $file->id, 'email' => 'x@example.test', 'created_by' => $solo->id, 'expires_at' => now()->addDays(2), 'status' => 'active', 'permission' => 'view']);

        $root = $personal->objects()->withoutGlobalScope('team')->whereNull('parent_id')->first();
        $component = Livewire::actingAs($solo)->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect([$root])]);

        $this->assertSame('1 document is filed for '.$personal->name.'.', $component->get('headline'), 'a personal workspace shares with nobody');
        $this->assertSame([], $component->get('outsideMap'));
        $this->assertFalse($component->get('room')['showEnding']);
    }

    public function test_first_run_headline_and_no_tallies(): void
    {
        $this->assertSame('Nothing is filed for Acme Mining Supplies yet.', $this->browser($this->owner)->get('headline'));
        $html = $this->actingAs($this->owner)->get('/files')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-tally=', $html);
        $this->assertSame(1, substr_count($html, 'data-primary-action'));
    }

    // ---- figures match the source, per role ----

    public function test_the_foot_figures_match_the_records(): void
    {
        [$a] = $this->fileFor('a.txt');
        [$b, $bObj] = $this->fileFor('b.txt');
        $this->fileFor('c.txt');
        $this->share($a, 'active', 30);
        $this->share($a, 'active', 40, null, 'two@example.test'); // two shares, one document
        $bObj->trash($this->owner->id);

        $html = $this->actingAs($this->owner)->get('/files')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-tally="documents"[^>]*>2</', $html);
        $this->assertMatchesRegularExpression('/data-tally="shared"[^>]*>1</', $html, 'distinct documents shared, not shares');
        $this->assertStringContainsString('in the trash', $html);
        $this->assertSame(1, $this->browser($this->owner)->get('trashedCount'));
    }

    public function test_the_room_lists_requests_flags_ending_shares_and_recent_changes_for_a_decider(): void
    {
        [$a] = $this->fileFor('Alpha agreement.txt');
        [$b] = $this->fileFor('Beta permit.txt');
        $this->share($a, 'pending', 30);
        $this->flag($a);
        $this->share($b, 'active', 10, null, 'broker@cover.test');
        $this->share($b, 'active', 20, null, 'later@cover.test'); // beyond the 14-day window

        $this->actingAs($this->owner);
        app(FileVersions::class)->replace($a, 'v2', null, $this->owner->id, null, 'replaced');

        $component = $this->browser($this->owner);
        $room = $component->get('room');

        $this->assertCount(1, $room['pending']);
        $this->assertCount(1, $room['flags']);
        $this->assertSame(['broker@cover.test'], $room['ending']->pluck('email')->all(), 'only shares ending within 14 days');
        $this->assertSame('Alpha agreement.txt', $room['recent']->first()->file->name, 'newest version first');

        $component->assertSee('Waiting for you')->assertSee('Ending soon')->assertSee('Recently changed')
            ->assertSee('broker@cover.test')->assertSee('Review in Sharing approvals');
    }

    public function test_a_member_sees_only_their_own_requests_and_no_recipient_emails_or_flags(): void
    {
        [$a] = $this->fileFor('Alpha agreement.txt');
        [$b] = $this->fileFor('Beta permit.txt');
        $mine = $this->share($a, 'pending', 30, $this->editor, 'mine@example.test');
        $this->share($b, 'pending', 30, $this->admin, 'theirs@example.test');
        $this->flag($a);
        $this->share($b, 'active', 3, null, 'broker@cover.test');

        $component = $this->browser($this->editor);
        $room = $component->get('room');

        $this->assertSame([$mine->id], $room['pending']->pluck('id')->all());
        $this->assertCount(0, $room['flags']);
        $this->assertCount(0, $room['ending'], 'a member sees only their own shares ending');
        $component->assertDontSee('theirs@example.test')->assertDontSee('broker@cover.test')->assertDontSee('Review in Sharing approvals');
    }

    public function test_the_outside_column_shows_active_pending_and_nothing_for_unshared(): void
    {
        [$a] = $this->fileFor('Shared doc.txt');
        [$b] = $this->fileFor('Waiting doc.txt');
        $this->fileFor('Plain doc.txt');
        $this->share($a, 'active', 30);
        $this->share($b, 'pending', 30);

        $html = $this->actingAs($this->owner)->get('/files')->assertOk()->getContent();

        $this->assertStringContainsString('Shared with 1', $html);
        $this->assertStringContainsString('Waiting for approval', $html);
        $this->assertStringContainsString('Not shared', $html);
    }

    // ---- empty states are honest ----

    public function test_room_empty_states_say_only_what_is_true(): void
    {
        $this->fileFor('Alpha.txt');
        $component = $this->browser($this->owner);

        $component->assertSee('Nothing ends in the next 14 days.')->assertDontSee('Waiting for you')->assertDontSee('Pinned by you');

        $room = $component->get('room');
        $this->assertCount(0, $room['pending']);
    }

    // ---- selection is validated against the loaded rows ----

    public function test_focus_selects_a_listed_file_and_clear_returns_to_the_room(): void
    {
        [, $obj] = $this->fileFor('Alpha agreement.txt');

        $component = $this->browser($this->owner)->call('focus', $obj->id);
        $component->assertSet('focusId', $obj->id)->assertSee('Recent versions')->assertSee('Open the full version history');

        $component->call('clearFocus')->assertSet('focusId', null)->assertSee('Recently changed');
    }

    public function test_focus_refuses_a_folder_a_trashed_file_and_a_file_outside_the_listed_rows(): void
    {
        $folder = $this->acme->objects()->make(['parent_id' => $this->root()->id]);
        $folder->objectable()->associate($this->acme->folders()->create(['name' => 'Contracts']));
        $folder->save();
        [, $inside] = $this->fileFor('Inside.txt', $folder);
        [, $gone] = $this->fileFor('Gone.txt');
        $gone->trash($this->owner->id);

        $component = $this->browser($this->owner); // at the root: the file inside the folder is not a listed row

        $component->call('focus', $folder->id)->assertSet('focusId', null);
        $component->call('focus', $gone->id)->assertSet('focusId', null)->assertSee('That record is no longer here.');
        $component->call('focus', $inside->id)->assertSet('focusId', null);
        $component->set('focusId', $inside->id);
        $this->assertNull($component->get('focused'));
    }

    public function test_the_sort_is_validated_and_kept(): void
    {
        $this->fileFor('Bravo.txt');
        $this->fileFor('Alpha.txt');
        $this->fileFor('Charlie.txt');

        $component = $this->browser($this->owner);
        $this->assertSame(['Alpha.txt', 'Bravo.txt', 'Charlie.txt'], $component->get('results')->map->objectable->pluck('name')->all());

        $component->call('sortBy', 'name');
        $this->assertSame(['Charlie.txt', 'Bravo.txt', 'Alpha.txt'], $component->get('results')->map->objectable->pluck('name')->all());

        $component->call('sortBy', 'nonsense; drop table');
        $component->assertSet('sort', 'name')->assertSet('dir', 'desc');
        $this->assertSame('name', session('files.sort'));
    }

    public function test_folders_always_lead_the_register(): void
    {
        $folder = $this->acme->objects()->make(['parent_id' => $this->root()->id]);
        $folder->objectable()->associate($this->acme->folders()->create(['name' => 'Zebra folder']));
        $folder->save();
        $this->fileFor('Alpha.txt');

        $first = $this->browser($this->owner)->get('results')->first();

        $this->assertSame('folder', $first->objectable_type);
    }

    // ---- primary action ----

    public function test_there_is_exactly_one_primary_action_for_every_role_and_state(): void
    {
        foreach (['owner', 'admin', 'editor'] as $who) {
            $html = $this->actingAs($this->{$who})->get('/files')->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'data-primary-action'), "{$who} first run");
        }

        [$a] = $this->fileFor('a.txt');
        $this->share($a, 'pending', 30);
        $this->flag($a);

        foreach (['owner', 'admin', 'editor'] as $who) {
            $html = $this->actingAs($this->{$who})->get('/files')->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'data-primary-action'), "{$who} working");
        }
    }

    public function test_the_delete_dialog_defaults_to_keep_it(): void
    {
        [, $obj] = $this->fileFor('a.txt');

        $this->browser($this->owner)->set('confirmingObjectDeletion', $obj->id)
            ->assertSeeHtml('x-init="$el.focus()">Keep it</button>');
    }
}
