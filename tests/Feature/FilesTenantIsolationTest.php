<?php

namespace Tests\Feature;

use App\Http\Livewire\FileBrowser;
use App\Models\File;
use App\Models\FileFlag;
use App\Models\FileShare;
use App\Models\Folder;
use App\Models\Obj;
use App\Models\Team;
use App\Models\User;
use App\Services\FileVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Two synthetic companies. Neither may list, search, download, open or delete the
 * other's files by any route, whatever id or uuid is supplied.
 */
class FilesTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $ann;

    private User $gina;

    private Team $acme;

    private Team $globex;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection']);
        $this->ann = User::factory()->withPersonalTeam()->create();
        $this->gina = User::factory()->withPersonalTeam()->create();
        $this->acme = $this->company($this->ann, 'Acme');
        $this->globex = $this->company($this->gina, 'Globex');
    }

    private function company(User $owner, string $name): Team
    {
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false]);
        $owner->switchTeam($team);

        return $team;
    }

    private function fileFor(Team $team, string $name): array
    {
        $path = 'files/'.uniqid().'.txt';
        Storage::disk('local')->put($path, 'secret of '.$team->name);
        $file = $team->files()->create(['name' => $name, 'size' => 10, 'path' => $path]);
        $obj = $team->objects()->make(['parent_id' => $team->objects()->withoutGlobalScope('team')->whereNull('parent_id')->value('id')]);
        $obj->objectable()->associate($file);
        $obj->save();

        return [$file, $obj];
    }

    public function test_one_company_cannot_download_another_companys_file_by_id(): void
    {
        [$theirs] = $this->fileFor($this->globex, 'payroll.txt');
        [$mine] = $this->fileFor($this->acme, 'mine.txt');

        $this->actingAs($this->ann)->get(route('files.download', $mine))->assertOk();
        $this->actingAs($this->ann)->get(route('files.download', $theirs))->assertNotFound();
    }

    public function test_walking_every_file_id_only_opens_own_files(): void
    {
        [$mine] = $this->fileFor($this->acme, 'mine.txt');
        [$theirs] = $this->fileFor($this->globex, 'theirs.txt');

        foreach (range(1, $theirs->id + 3) as $id) {
            $status = $this->actingAs($this->ann)->get('/files/'.$id)->getStatusCode();

            $this->assertSame($id === $mine->id ? 200 : 404, $status, "file id $id");
        }
    }

    public function test_the_browser_never_opens_another_companys_folder_by_uuid(): void
    {
        $rootTheirs = $this->globex->objects()->withoutGlobalScope('team')->whereNull('parent_id')->first();

        $this->actingAs($this->ann)->get('/files?uuid='.$rootTheirs->uuid)->assertNotFound();
    }

    public function test_a_person_with_no_active_team_sees_nothing(): void
    {
        $this->fileFor($this->globex, 'theirs.txt');
        $drifter = User::factory()->create(['current_team_id' => null]);

        $this->actingAs($drifter);

        $this->assertSame(0, File::count());
        $this->assertSame(0, Obj::count());
        $this->assertSame(0, Folder::count());
    }

    public function test_a_tampered_deletion_id_cannot_delete_another_companys_object(): void
    {
        [, $theirs] = $this->fileFor($this->globex, 'theirs.txt');
        $rootMine = $this->acme->objects()->withoutGlobalScope('team')->whereNull('parent_id')->first();

        $this->actingAs($this->ann);
        Livewire::test(FileBrowser::class, ['object' => $rootMine, 'ancestors' => collect([$rootMine])])
            ->set('confirmingObjectDeletion', $theirs->id)
            ->call('deleteObject');

        $this->assertNotNull(Obj::withoutGlobalScope('team')->find($theirs->id));
    }

    public function test_search_only_returns_the_callers_company(): void
    {
        $this->fileFor($this->globex, 'quarterly-payroll.txt');
        [, $mine] = $this->fileFor($this->acme, 'quarterly-budget.txt');

        $this->actingAs($this->ann);
        $component = Livewire::test(FileBrowser::class, ['object' => $this->acme->objects()->withoutGlobalScope('team')->whereNull('parent_id')->first(), 'ancestors' => collect()])
            ->set('query', 'quarterly');

        $ids = collect($component->get('results'))->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
    }

    public function test_a_member_of_two_companies_can_download_either_companys_file_when_it_is_current(): void
    {
        [$acmeFile] = $this->fileFor($this->acme, 'a.txt');
        [$globexFile] = $this->fileFor($this->globex, 'g.txt');
        $this->globex->users()->attach($this->ann, ['role' => 'editor']);

        $this->ann->switchTeam($this->acme);
        $this->actingAs($this->ann->fresh())->get(route('files.download', $acmeFile))->assertOk();
        $this->actingAs($this->ann->fresh())->get(route('files.download', $globexFile))->assertNotFound();

        $this->ann->switchTeam($this->globex);
        $this->actingAs($this->ann->fresh())->get(route('files.download', $globexFile))->assertOk();
    }

    public function test_guests_cannot_reach_files_at_all(): void
    {
        [$file] = $this->fileFor($this->acme, 'a.txt');

        $this->get(route('files.download', $file))->assertRedirect();
        $this->get('/files')->assertRedirect();
    }

    // ---- R-51: the dashboard's new queries. FileVersion and FileShare carry no team scope, FileFlag only a team_id. ----

    /** Globex has loud, recent, pending, ending and flagged records; Acme has none of those. */
    private function globexActivity(): File
    {
        [$g] = $this->fileFor($this->globex, 'GLOBEX-PAYROLL.txt');
        $this->actingAs($this->gina);
        app(FileVersions::class)->replace($g, 'v2', null, $this->gina->id, null, 'replaced');
        FileShare::create(['file_id' => $g->id, 'email' => 'leak@x.test', 'created_by' => $this->gina->id, 'expires_at' => now()->addDays(2), 'status' => 'active', 'permission' => 'view']);
        FileShare::create(['file_id' => $g->id, 'email' => 'leak2@x.test', 'created_by' => $this->gina->id, 'requested_by' => $this->gina->id, 'expires_at' => now()->addDays(9), 'status' => 'pending', 'permission' => 'view']);
        FileFlag::create(['team_id' => $this->globex->id, 'file_id' => $g->id, 'rule' => 'version-churn', 'actor_id' => $this->gina->id, 'severity' => 'review', 'evidence' => ['count' => 9], 'status' => 'open']);

        return $g;
    }

    private function acmeBrowser()
    {
        $root = $this->acme->objects()->withoutGlobalScope('team')->whereNull('parent_id')->first();

        return Livewire::actingAs($this->ann)->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect([$root])]);
    }

    public function test_the_room_never_lists_another_companys_versions_shares_or_flags(): void
    {
        $this->globexActivity();
        [$mine] = $this->fileFor($this->acme, 'ACME-NOTE.txt');

        $component = $this->acmeBrowser();
        $room = $component->get('room');

        $this->assertCount(0, $room['pending'], 'no Globex pending request');
        $this->assertCount(0, $room['ending'], 'no Globex ending share');
        $this->assertCount(0, $room['flags'], 'no Globex flag');
        $this->assertSame([], $room['recent']->pluck('file_id')->diff([$mine->id])->values()->all(), 'recent versions are Acme files only');
        $component->assertDontSee('GLOBEX-PAYROLL')->assertDontSee('leak@x.test');
    }

    public function test_the_headline_and_counts_ignore_another_companys_requests_flags_and_ending_shares(): void
    {
        $this->globexActivity();
        $this->fileFor($this->acme, 'ACME-NOTE.txt');

        $component = $this->acmeBrowser();

        $this->assertSame('1 document is filed for Acme.', $component->get('headline'));
        $this->assertSame(0, $component->get('pendingShares'));
        $this->assertSame(0, $component->get('openFlagCount'));
        $this->assertSame(0, $component->get('stats')['shared']);
    }

    public function test_the_outside_column_never_borrows_another_companys_shares(): void
    {
        $globexFile = $this->globexActivity();
        [$mine] = $this->fileFor($this->acme, 'ACME-NOTE.txt');

        $map = $this->acmeBrowser()->get('outsideMap');

        $this->assertArrayNotHasKey($globexFile->id, $map);
        $this->assertSame([], $map, 'Acme has nothing shared, so nothing is mapped');
        $this->assertSame(1, FileShare::where('file_id', $globexFile->id)->active()->count(), 'the Globex share really exists');
    }

    public function test_focusing_another_companys_object_selects_nothing(): void
    {
        [, $theirsObj] = $this->fileFor($this->globex, 'theirs.txt');
        $this->fileFor($this->acme, 'mine.txt');

        $component = $this->acmeBrowser()->call('focus', $theirsObj->id);

        $component->assertSet('focusId', null);
        $this->assertNull($component->get('focused'));
        $component->assertSee('That record is no longer here.')->assertDontSee('theirs.txt');
    }

    public function test_a_forged_focus_id_is_ignored_when_it_is_not_in_the_listed_rows(): void
    {
        [, $theirsObj] = $this->fileFor($this->globex, 'theirs.txt');

        $component = $this->acmeBrowser()->set('focusId', $theirsObj->id);

        $this->assertNull($component->get('focused'));
        $component->assertDontSee('theirs.txt');
    }

    public function test_the_focused_record_shows_only_its_own_versions_and_shares(): void
    {
        $this->globexActivity();
        [$mine, $mineObj] = $this->fileFor($this->acme, 'mine.txt');
        $this->actingAs($this->ann);
        app(FileVersions::class)->replace($mine, 'v2', null, $this->ann->id, null, 'replaced');

        $component = $this->acmeBrowser()->call('focus', $mineObj->id);
        $focused = $component->get('focused');

        $this->assertSame($mine->id, $focused['file']->id);
        $this->assertSame([$mine->id], $focused['versions']->pluck('file_id')->unique()->values()->all());
        $this->assertSame(0, $focused['outside']['active']);
        $this->assertFalse($focused['flagged']);
    }

    public function test_a_flag_for_the_same_file_id_in_another_company_never_marks_this_record(): void
    {
        // A flag row whose team_id says Globex but whose file_id is an Acme file (a forged or stale row): Acme must not see it.
        [$mine, $mineObj] = $this->fileFor($this->acme, 'mine.txt');
        FileFlag::create(['team_id' => $this->globex->id, 'file_id' => $mine->id, 'rule' => 'version-churn', 'actor_id' => $this->gina->id, 'severity' => 'review', 'evidence' => [], 'status' => 'open']);

        $component = $this->acmeBrowser()->call('focus', $mineObj->id);

        $this->assertFalse($component->get('focused')['flagged']);
        $this->assertSame(0, $component->get('openFlagCount'));
        $this->assertCount(0, $component->get('room')['flags']);
    }

    public function test_a_person_with_no_current_team_gets_an_empty_room_and_no_figures(): void
    {
        $this->fileFor($this->acme, 'mine.txt');
        $this->globexActivity();
        $root = $this->acme->objects()->withoutGlobalScope('team')->whereNull('parent_id')->first();
        $this->ann->forceFill(['current_team_id' => null])->save();

        $component = Livewire::actingAs($this->ann->fresh())->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect()]);
        $room = $component->get('room');

        $this->assertCount(0, $room['recent']);
        $this->assertCount(0, $room['pending']);
        $this->assertCount(0, $room['flags']);
        $this->assertCount(0, $room['ending']);
        $this->assertSame(0, $component->get('trashedCount'));
        $this->assertSame([], $component->get('outsideMap'));
        $this->assertCount(0, $component->get('spines'));
    }
}
