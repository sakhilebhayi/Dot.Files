<?php

namespace Tests\Feature;

use App\Http\Livewire\DeleteTeamForm;
use App\Models\TeamDeletionRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeamDeletionRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_clicking_delete_team_creates_a_pending_request_and_does_not_delete_the_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        Livewire::actingAs($owner)
            ->test(DeleteTeamForm::class, ['team' => $team])
            ->call('deleteTeam');

        $this->assertNotNull($team->fresh());
        $this->assertSame(1, TeamDeletionRequest::where('team_id', $team->id)->count());
        $request = TeamDeletionRequest::where('team_id', $team->id)->first();
        $this->assertSame('pending', $request->status);
        $this->assertSame($owner->id, $request->requested_by);
    }

    public function test_a_second_click_while_one_is_pending_does_not_create_a_duplicate(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        $component = Livewire::actingAs($owner)->test(DeleteTeamForm::class, ['team' => $team]);
        $component->call('deleteTeam');
        $component->call('deleteTeam');

        $this->assertSame(1, TeamDeletionRequest::where('team_id', $team->id)->count());
    }

    public function test_a_non_owner_cannot_request_team_deletion(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $stranger = User::factory()->create();

        $this->withoutExceptionHandling();
        $this->expectException(AuthorizationException::class);

        Livewire::actingAs($stranger)
            ->test(DeleteTeamForm::class, ['team' => $team])
            ->call('deleteTeam');
    }
}
