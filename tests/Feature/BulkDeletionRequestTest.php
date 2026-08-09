<?php

namespace Tests\Feature;

use App\Http\Livewire\FileBrowser;
use App\Models\BulkDeletionRequest;
use App\Models\Obj;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BulkDeletionRequestTest extends TestCase
{
    use RefreshDatabase;

    private function makeFileObject($team, string $name = 'widget.txt'): Obj
    {
        $object = $team->objects()->make(['parent_id' => null]);
        $object->objectable()->associate($team->files()->create([
            'name' => $name,
            'size' => 10,
            'path' => 'files/'.uniqid().'.txt',
        ]));
        $object->save();

        return $object;
    }

    public function test_selecting_objects_and_requesting_deletion_creates_a_request_without_deleting(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $one = $this->makeFileObject($team, 'one.txt');
        $two = $this->makeFileObject($team, 'two.txt');
        $root = Obj::whereNull('parent_id')->first();

        Livewire::actingAs($owner)
            ->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect()])
            ->set('selectedObjects', [$one->id, $two->id])
            ->call('requestBulkDeletion');

        $this->assertNotNull(Obj::find($one->id));
        $this->assertNotNull(Obj::find($two->id));

        $request = BulkDeletionRequest::where('team_id', $team->id)->first();
        $this->assertNotNull($request);
        $this->assertSame('pending', $request->status);
        $this->assertEqualsCanonicalizing([$one->id, $two->id], $request->object_ids);
    }

    public function test_requesting_with_an_empty_selection_creates_no_request(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = Obj::whereNull('parent_id')->first();

        Livewire::actingAs($owner)
            ->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect()])
            ->set('selectedObjects', [])
            ->call('requestBulkDeletion');

        $this->assertSame(0, BulkDeletionRequest::count());
    }
}
