<?php

namespace Tests\Feature;

use App\Http\Livewire\FileSearch;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-28 §3: the ecosystem search bar (InfoDot's component) answering only with what the signed-in
 * person may see — the team scope on every match, trashed records invisible, reference jumping,
 * and nothing of any other business.
 */
class EcosystemSearchBarTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection']);

        $this->owner = User::factory()->withPersonalTeam()->create(['email' => 'owner@acme.test']);
        $this->acme = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Acme', 'personal_team' => false]);
        $this->owner->switchTeam($this->acme);
    }

    private function fileFor(Team $team, string $name, ?string $ref = null)
    {
        Storage::disk('local')->put($path = 'files/'.Str::random(8), 'x');
        $file = $team->files()->create(['name' => $name, 'size' => 1, 'path' => $path, 'mime' => 'text/plain']);

        if ($ref) {
            $file->forceFill(['infodot_reference' => $ref])->saveQuietly();
        }

        $obj = $team->objects()->make(['parent_id' => $team->objects()->withoutGlobalScope('team')->whereNull('parent_id')->first()->id]);
        $obj->objectable()->associate($file);
        $obj->save();

        return $file;
    }

    public function test_the_bar_renders_in_the_shell_and_answers_only_this_companys_records(): void
    {
        $mine = $this->fileFor($this->acme, 'Quarterly budget.txt', 'ACME-F-000001');

        $other = User::factory()->withPersonalTeam()->create();
        $globex = Team::forceCreate(['user_id' => $other->id, 'name' => 'Globex', 'personal_team' => false]);
        $theirs = $this->fileFor($globex, 'Quarterly secrets.txt');

        $this->actingAs($this->owner)->get('/files')->assertOk()
            ->assertSeeLivewire(FileSearch::class)
            ->assertSee('ix-search', false);

        Livewire::actingAs($this->owner)->test(FileSearch::class)
            ->set('query', 'Quarterly')
            ->assertSee('Quarterly budget.txt')
            ->assertDontSee('Quarterly secrets.txt');

        // A whole reference jumps straight to the file.
        Livewire::actingAs($this->owner)->test(FileSearch::class)
            ->set('query', 'acme-f-000001')
            ->assertSee('Quarterly budget.txt');

        // Trashed records leave the suggestions at once.
        $mine->obj()->withoutGlobalScope('team')->first()->trash($this->owner->id);
        Livewire::actingAs($this->owner)->test(FileSearch::class)
            ->set('query', 'Quarterly')
            ->assertDontSee('Quarterly budget.txt');
    }

    public function test_the_go_button_lands_in_the_workspace_with_the_query_applied(): void
    {
        $this->fileFor($this->acme, 'Depot plan.txt');

        $this->actingAs($this->owner)->get('/files?search=Depot')->assertOk()
            ->assertSee('Depot plan.txt')
            ->assertSee('record matches');
    }
}
