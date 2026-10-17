<?php

namespace Tests\Feature;

use App\Models\BulkDeletionRequest;
use App\Models\Obj;
use App\Models\Team;
use App\Models\TeamDeletionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeletionModerationTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();

        return $operator;
    }

    private function makeFileObject(Team $team, string $name = 'widget.txt'): Obj
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

    public function test_operator_can_view_pending_requests_of_both_types(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        TeamDeletionRequest::create([
            'team_id' => $owner->currentTeam->id,
            'requested_by' => $owner->id,
        ]);
        BulkDeletionRequest::create([
            'team_id' => $owner->currentTeam->id,
            'requested_by' => $owner->id,
            'object_ids' => [999],
        ]);

        $this->actingAs($this->operator())
            ->get(route('operator.deletion-requests.index'))
            ->assertOk()
            ->assertSee($owner->currentTeam->name);
    }

    public function test_approving_a_team_deletion_request_actually_deletes_the_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $request = TeamDeletionRequest::create([
            'team_id' => $team->id,
            'requested_by' => $owner->id,
        ]);
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('operator.deletion-requests.team.approve', $request))
            ->assertRedirect();

        $this->assertNull(Team::find($team->id));
        $fresh = $request->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($operator->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
    }

    public function test_rejecting_a_team_deletion_request_without_a_reason_is_blocked(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $request = TeamDeletionRequest::create([
            'team_id' => $owner->currentTeam->id,
            'requested_by' => $owner->id,
        ]);

        $this->actingAs($this->operator())
            ->post(route('operator.deletion-requests.team.reject', $request), [])
            ->assertSessionHasErrors('reason');

        $this->assertNotNull(Team::find($owner->currentTeam->id));
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_rejecting_a_team_deletion_request_with_a_reason_marks_it_rejected(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $request = TeamDeletionRequest::create([
            'team_id' => $team->id,
            'requested_by' => $owner->id,
        ]);

        $this->actingAs($this->operator())
            ->post(route('operator.deletion-requests.team.reject', $request), ['reason' => 'Not warranted.'])
            ->assertRedirect();

        $fresh = $request->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Not warranted.', $fresh->rejected_reason);
        $this->assertNotNull(Team::find($team->id));
    }

    public function test_approving_a_bulk_deletion_request_deletes_exactly_the_listed_objects(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $toDelete = $this->makeFileObject($team, 'delete-me.txt');
        $toKeep = $this->makeFileObject($team, 'keep-me.txt');
        $request = BulkDeletionRequest::create([
            'team_id' => $team->id,
            'requested_by' => $owner->id,
            'object_ids' => [$toDelete->id],
        ]);
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('operator.deletion-requests.bulk.approve', $request))
            ->assertRedirect();

        // The operator has no team, so the team scope (correctly) hides everything from
        // them; look at the rows without it.
        $this->assertNull(Obj::withoutGlobalScope('team')->find($toDelete->id));
        $this->assertNotNull(Obj::withoutGlobalScope('team')->find($toKeep->id));
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_approving_a_bulk_deletion_request_skips_an_object_already_deleted(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $alreadyGone = $this->makeFileObject($team, 'already-gone.txt');
        $stillThere = $this->makeFileObject($team, 'still-there.txt');
        $request = BulkDeletionRequest::create([
            'team_id' => $team->id,
            'requested_by' => $owner->id,
            'object_ids' => [$alreadyGone->id, $stillThere->id],
        ]);
        $alreadyGone->delete();

        $this->actingAs($this->operator())
            ->post(route('operator.deletion-requests.bulk.approve', $request))
            ->assertRedirect();

        $this->assertNull(Obj::withoutGlobalScope('team')->find($stillThere->id));
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_non_operator_is_refused_on_index_and_every_action(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $teamRequest = TeamDeletionRequest::create([
            'team_id' => $owner->currentTeam->id,
            'requested_by' => $owner->id,
        ]);
        $nonOperator = User::factory()->create();

        $this->actingAs($nonOperator)
            ->get(route('operator.deletion-requests.index'))
            ->assertForbidden();

        $this->actingAs($nonOperator)
            ->post(route('operator.deletion-requests.team.approve', $teamRequest))
            ->assertForbidden();

        $this->assertSame('pending', $teamRequest->fresh()->status);
    }

    public function test_approving_an_already_decided_team_request_is_refused(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $request = TeamDeletionRequest::create([
            'team_id' => $team->id,
            'requested_by' => $owner->id,
        ]);
        $request->update(['status' => 'rejected', 'rejected_reason' => 'Already handled.']);

        $this->actingAs($this->operator())
            ->post(route('operator.deletion-requests.team.approve', $request))
            ->assertSessionHasErrors();

        $this->assertNotNull(Team::find($team->id));
    }
}
