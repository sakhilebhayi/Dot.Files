<?php

namespace Tests\Feature;

use App\Http\Livewire\FileBrowser;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for the null-currentTeam gap: users bridged in via
 * EcosystemAuthController (or anyone whose current_team_id is null and who
 * has no personal team for EnsureCurrentTeam to fall back to) must not hit
 * an unguarded `->currentTeam->` null-pointer crash. See
 * app/Http/Controllers/FileController.php and app/Http/Livewire/FileBrowser.php.
 */
class NoCurrentTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_with_no_team_is_redirected_to_team_creation(): void
    {
        $user = User::factory()->create(['current_team_id' => null]);

        $response = $this->actingAs($user)->get('/files');

        $response->assertRedirect(route('teams.create'));
    }

    public function test_file_browser_create_folder_aborts_for_user_with_no_team(): void
    {
        // Root object is auto-created by Team::booted() when the team is
        // created; which team it belongs to is irrelevant here since the
        // abort must happen before the folder-creation logic ever touches it.
        $team = Team::factory()->create();
        $object = $team->objects()->whereNull('parent_id')->firstOrFail();

        $user = User::factory()->create(['current_team_id' => null]);

        Livewire::actingAs($user)
            ->test(FileBrowser::class, ['object' => $object, 'ancestors' => collect()])
            ->set('newFolderState.name', 'New Folder')
            ->call('createFolder')
            ->assertStatus(403);
    }
}
