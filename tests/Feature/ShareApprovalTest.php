<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\FileShare;
use App\Models\Team;
use App\Models\User;
use App\Services\FileShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * R-26 owner-approved sharing: members ask, owners/administrators decide, and the decision is
 * enforced where access is checked (FileShare::active()), not in anything the interface hides.
 */
class ShareApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $editor;

    private User $recipient;

    private Team $acme;

    private File $file;

    private FileShareService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection']);
        $this->service = app(FileShareService::class);

        $this->owner = User::factory()->withPersonalTeam()->create(['email' => 'owner@acme.test']);
        $this->acme = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Acme', 'personal_team' => false]);
        $this->owner->switchTeam($this->acme);

        $this->editor = User::factory()->withPersonalTeam()->create(['email' => 'editor@acme.test']);
        $this->acme->users()->attach($this->editor, ['role' => 'editor']);
        $this->editor->switchTeam($this->acme);

        $this->recipient = User::factory()->withPersonalTeam()->create(['email' => 'pat@rival.test', 'email_verified_at' => Carbon::now()]);

        Storage::disk('local')->put($path = 'files/'.Str::random(8), 'the contract');
        $this->file = $this->acme->files()->create(['name' => 'Contract.pdf', 'size' => 12, 'path' => $path]);
    }

    public function test_a_pending_request_grants_nothing_until_an_owner_approves_it(): void
    {
        $share = $this->service->requestShare($this->editor, $this->file, 'pat@rival.test', 30);

        $this->assertSame('pending', $share->status);
        $this->assertNull($this->service->activeShareFor($this->recipient, $this->file), 'pending is not access');
        $this->actingAs($this->recipient)->get('/o/'.$this->file->uuid)->assertForbidden();
        $this->actingAs($this->recipient)->get('/shared/'.$share->id.'/download')->assertNotFound();

        $this->service->approve($this->owner, $share);

        $this->assertSame('active', $share->refresh()->status);
        $this->assertNotNull($this->service->activeShareFor($this->recipient, $this->file));
        $this->actingAs($this->recipient)->get('/o/'.$this->file->uuid)->assertOk()->assertSee('Contract.pdf');

        $events = DB::table('file_share_events')->where('share_id', $share->id)->pluck('event');
        $this->assertTrue($events->contains('requested'));
        $this->assertTrue($events->contains('approved'));
    }

    public function test_only_an_owner_or_administrator_decides(): void
    {
        $share = $this->service->requestShare($this->editor, $this->file, 'pat@rival.test', 30);

        try {
            $this->service->approve($this->editor, $share);
            $this->fail('An editor approved their own request.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame('pending', $share->refresh()->status);

        // Over HTTP too: the decide endpoints refuse anyone below admin.
        $this->actingAs($this->editor)->post('/approvals/'.$share->id.'/approve')->assertForbidden();
        $this->actingAs($this->owner)->post('/approvals/'.$share->id.'/approve')->assertRedirect('/approvals');
        $this->assertSame('active', $share->refresh()->status);
    }

    public function test_a_declined_request_never_becomes_access(): void
    {
        $share = $this->service->requestShare($this->editor, $this->file, 'pat@rival.test', 30);

        $this->actingAs($this->owner)->post('/approvals/'.$share->id.'/decline', ['note' => 'not for outside eyes'])->assertRedirect('/approvals');

        $this->assertSame('declined', $share->refresh()->status);
        $this->assertNull($this->service->activeShareFor($this->recipient, $this->file));
        $this->actingAs($this->recipient)->get('/o/'.$this->file->uuid)->assertForbidden();

        $this->assertTrue(DB::table('file_share_events')->where('share_id', $share->id)->where('event', 'declined')->exists());
    }

    public function test_an_owner_sharing_directly_absorbs_the_pending_request(): void
    {
        $pending = $this->service->requestShare($this->editor, $this->file, 'pat@rival.test', 7);
        $share = $this->service->share($this->owner, $this->file, 'pat@rival.test', 30);

        $this->assertTrue($pending->is($share), 'no duplicate row for the same person and file');
        $this->assertSame('active', $share->status);
        $this->assertSame(1, FileShare::where('file_id', $this->file->id)->count());
    }

    public function test_members_request_over_http_and_each_side_sees_its_own_queue(): void
    {
        $this->actingAs($this->editor)
            ->post('/files/'.$this->file->id.'/share-request', ['email' => 'pat@rival.test', 'days' => 30])
            ->assertRedirect('/approvals');

        $share = FileShare::where('file_id', $this->file->id)->first();
        $this->assertSame('pending', $share->status);
        $this->assertSame($this->editor->id, $share->requested_by);

        // The owner's desk shows the request with the decision controls.
        $this->actingAs($this->owner)->get('/approvals')
            ->assertOk()->assertSee('Contract.pdf')->assertSee('pat@rival.test')->assertSee('Approve');

        // The editor sees their request, without decision controls.
        $this->actingAs($this->editor)->get('/approvals')
            ->assertOk()->assertSee('Contract.pdf')->assertDontSee('Approve');

        // An unrelated signed-in person sees none of it (their own email in the nav aside).
        $this->actingAs($this->recipient)->get('/approvals')
            ->assertOk()->assertDontSee('Contract.pdf')->assertDontSee('editor@acme.test');
    }
}
