<?php

namespace Tests\Feature;

use App\Http\Livewire\FileBrowser;
use App\Models\File;
use App\Models\Folder;
use App\Models\Obj;
use App\Models\Team;
use App\Models\User;
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
}
