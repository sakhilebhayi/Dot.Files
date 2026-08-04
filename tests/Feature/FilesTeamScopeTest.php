<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilesTeamScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_alone_blocks_cross_team_access_even_without_an_explicit_where(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $file = File::create([
            'team_id' => $owner->currentTeam->id,
            'name' => 'Owner File',
            'size' => 1024,
            'path' => 'files/owner-file.txt',
        ]);

        $this->actingAs($outsider);

        $this->assertNull(File::find($file->id));
        $this->assertSame(0, File::query()->count());

        $this->actingAs($owner);

        $this->assertNotNull(File::find($file->id));
        $this->assertSame(1, File::query()->count());
    }
}
