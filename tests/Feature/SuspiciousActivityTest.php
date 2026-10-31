<?php

namespace Tests\Feature;

use App\Http\Livewire\FileBrowser;
use App\Mail\SuspiciousActivityAlert;
use App\Models\File;
use App\Models\FileFlag;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-28 §29, owner policy 2026-10-31: the hide-the-evidence rules, review-only. A flag is
 * evidence for owners and administrators to judge — it blocks nothing, accuses no one, and
 * closing it records only who decided.
 */
class SuspiciousActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private User $editor;

    private Team $acme;

    private File $file;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection']);

        $this->owner = User::factory()->withPersonalTeam()->create(['email' => 'owner@acme.test']);
        $this->acme = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Acme', 'personal_team' => false]);
        $this->owner->switchTeam($this->acme);

        $this->admin = User::factory()->withPersonalTeam()->create(['email' => 'admin@acme.test']);
        $this->acme->users()->attach($this->admin, ['role' => 'admin']);

        $this->editor = User::factory()->withPersonalTeam()->create(['email' => 'editor@acme.test']);
        $this->acme->users()->attach($this->editor, ['role' => 'editor']);
        $this->editor->switchTeam($this->acme);

        $this->file = $this->makeFile('Contract.txt');
    }

    private function makeFile(string $name): File
    {
        Storage::disk('local')->put($path = 'files/'.Str::random(8), 'first draft of '.$name);
        $file = $this->acme->files()->create(['name' => $name, 'size' => 11, 'path' => $path, 'mime' => 'text/plain', 'uploaded_by' => $this->owner->id]);

        $root = $this->acme->objects()->withoutGlobalScope('team')->whereNull('parent_id')->firstOrFail();
        $obj = $this->acme->objects()->make(['parent_id' => $root->id]);
        $obj->objectable()->associate($file);
        $obj->save();

        return $file;
    }

    public function test_rapid_rewrites_raise_one_review_flag_and_email_the_owners(): void
    {
        Mail::fake();
        config(['governance.version_churn_count' => 3]);

        $this->actingAs($this->editor);

        foreach (range(1, 3) as $i) {
            $this->post('/files/'.$this->file->id.'/replace', [
                'upload' => UploadedFile::fake()->createWithContent('Contract.txt', 'draft '.$i),
            ])->assertRedirect();
        }

        $flag = FileFlag::sole();
        $this->assertSame('version-churn', $flag->rule);
        $this->assertSame('open', $flag->status);
        $this->assertSame('review', $flag->severity);
        $this->assertSame($this->editor->id, $flag->actor_id);
        $this->assertSame(3, $flag->evidence['count']);

        // The same open question is never asked twice.
        $this->post('/files/'.$this->file->id.'/replace', [
            'upload' => UploadedFile::fake()->createWithContent('Contract.txt', 'draft 4'),
        ])->assertRedirect();
        $this->assertSame(1, FileFlag::count());

        // The alert reached the owner and the administrator — not the person flagged.
        Mail::assertSent(SuspiciousActivityAlert::class, fn ($mail) => $mail->hasTo('owner@acme.test'));
        Mail::assertSent(SuspiciousActivityAlert::class, fn ($mail) => $mail->hasTo('admin@acme.test'));
        Mail::assertNotSent(SuspiciousActivityAlert::class, fn ($mail) => $mail->hasTo('editor@acme.test'));
    }

    public function test_replacing_then_trashing_is_flagged_and_an_untouched_trash_is_not(): void
    {
        Mail::fake();
        $this->actingAs($this->editor);

        $this->post('/files/'.$this->file->id.'/replace', [
            'upload' => UploadedFile::fake()->createWithContent('Contract.txt', 'rewritten'),
        ])->assertRedirect();

        $obj = $this->file->obj()->withoutGlobalScope('team')->firstOrFail();
        $root = $this->acme->objects()->withoutGlobalScope('team')->whereNull('parent_id')->firstOrFail();

        Livewire::actingAs($this->editor)->test(FileBrowser::class, ['object' => $root, 'ancestors' => collect()])
            ->set('confirmingObjectDeletion', $obj->id)
            ->call('deleteObject');

        $flag = FileFlag::where('rule', 'replace-then-trash')->sole();
        $this->assertSame($this->editor->id, $flag->actor_id);
        $this->assertSame(2, $flag->evidence['replaced_version']);

        // A file trashed with no recent content change raises nothing.
        $untouched = $this->makeFile('Rates.txt');
        $untouchedObj = $untouched->obj()->withoutGlobalScope('team')->firstOrFail();

        Livewire::actingAs($this->editor)->test(FileBrowser::class, ['object' => $root->fresh(), 'ancestors' => collect()])
            ->set('confirmingObjectDeletion', $untouchedObj->id)
            ->call('deleteObject');

        $this->assertSame(1, FileFlag::count());
    }

    public function test_flags_are_the_owners_to_see_and_to_close(): void
    {
        $flag = FileFlag::create([
            'team_id' => $this->acme->id,
            'file_id' => $this->file->id,
            'rule' => 'version-churn',
            'actor_id' => $this->editor->id,
            'evidence' => ['count' => 5, 'window_minutes' => 60, 'versions' => [2, 3, 4, 5, 6]],
        ]);

        // Owners see the evidence on the approvals desk; the member does not.
        $this->actingAs($this->owner)->get('/approvals')
            ->assertOk()->assertSee('Activity to review')->assertSee('Rapid content changes');
        $this->actingAs($this->editor)->get('/approvals')
            ->assertOk()->assertDontSee('Activity to review');

        // A member cannot close the question about their own conduct.
        $this->actingAs($this->editor)->post('/approvals/flags/'.$flag->id.'/review')->assertForbidden();

        $this->actingAs($this->owner)->post('/approvals/flags/'.$flag->id.'/review')->assertRedirect(route('approvals'));
        $flag->refresh();
        $this->assertSame('reviewed', $flag->status);
        $this->assertSame($this->owner->id, $flag->decided_by);
        $this->assertNotNull($flag->decided_at);

        // Closed is closed: the decision is not rewritable.
        $this->actingAs($this->owner)->post('/approvals/flags/'.$flag->id.'/dismiss')->assertNotFound();
    }
}
