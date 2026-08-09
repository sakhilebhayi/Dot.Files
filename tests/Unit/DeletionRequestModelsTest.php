<?php

namespace Tests\Unit;

use App\Models\BulkDeletionRequest;
use App\Models\TeamDeletionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeletionRequestModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_deletion_request_relations(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $reviewer = User::factory()->create();

        $request = TeamDeletionRequest::create([
            'team_id' => $owner->currentTeam->id,
            'requested_by' => $owner->id,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        // ->fresh() is required here for the same reason as
        // UserOperatorFlagTest: the DB-level status default is applied
        // server-side and never populates the just-created in-memory model.
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertTrue($request->team->is($owner->currentTeam));
        $this->assertTrue($request->requester->is($owner));
        $this->assertTrue($request->reviewer->is($reviewer));
    }

    public function test_bulk_deletion_request_stores_object_ids_as_an_array(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $request = BulkDeletionRequest::create([
            'team_id' => $owner->currentTeam->id,
            'requested_by' => $owner->id,
            'object_ids' => [1, 2, 3],
        ]);

        $fresh = $request->fresh();
        $this->assertSame([1, 2, 3], $fresh->object_ids);
        $this->assertTrue($fresh->team->is($owner->currentTeam));
        $this->assertTrue($fresh->requester->is($owner));
    }
}
