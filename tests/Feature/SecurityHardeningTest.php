<?php

namespace Tests\Feature;

use App\Http\Livewire\FileShares;
use App\Models\File;
use App\Models\Team;
use App\Models\User;
use App\Services\FilePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EA-1 security hardening: signed writes honoured once, the share panel's file pinned, PDF rendering switchable and
 * bounded, inline documents sandboxed, throttled 2FA.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const INFODOT_SECRET = 'infodot-test-secret';

    private User $owner;

    private Team $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['scout.driver' => 'collection', 'ecosystem.platform_secrets' => ['docs' => 'secret-docs'], 'ecosystem.infodot.secret' => self::INFODOT_SECRET]);

        $this->owner = User::factory()->withPersonalTeam()->create(['email' => 'owner@acme.test']);
        $this->acme = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Acme', 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);
        $this->owner->switchTeam($this->acme);
    }

    private function platformCall(string $path, array $payload, int $timestamp)
    {
        $body = json_encode($payload);

        return $this->call('POST', $path, [], [], [], [
            'HTTP_X-DOT-PLATFORM' => 'docs',
            'HTTP_X-DOT-TIMESTAMP' => (string) $timestamp,
            'HTTP_X-DOT-SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'secret-docs'),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function membershipCall(array $payload, int $timestamp)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/ecosystem/membership', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INFODOT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INFODOT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::INFODOT_SECRET),
        ], $body);
    }

    private function companyFile(string $name, string $mime, string $bytes): File
    {
        Storage::disk('local')->put($path = 'files/'.Str::random(12), $bytes);

        return $this->acme->files()->create(['name' => $name, 'size' => strlen($bytes), 'path' => $path, 'mime' => $mime]);
    }

    public function test_a_signed_platform_write_is_honoured_once(): void
    {
        $payload = ['team_uuid' => $this->acme->infodot_team_uuid, 'name' => 'Invoice.pdf', 'content_b64' => base64_encode('%PDF-1.4 bytes')];
        $at = time();

        $this->platformCall('/api/platform/files/store', $payload, $at)->assertCreated();
        $this->platformCall('/api/platform/files/store', $payload, $at)->assertStatus(409)->assertJson(['error' => 'duplicate_request']);

        $this->assertSame(1, File::withoutGlobalScopes()->where('team_id', $this->acme->id)->where('name', 'Invoice.pdf')->count());

        // A genuine retry signs again with a new timestamp and is accepted.
        $this->platformCall('/api/platform/files/store', $payload, $at + 1)->assertCreated();
    }

    public function test_identical_signed_reads_are_both_answered(): void
    {
        $stored = $this->platformCall('/api/platform/files/store', ['team_uuid' => $this->acme->infodot_team_uuid, 'name' => 'A.pdf', 'content_b64' => base64_encode('%PDF-1.4 a')], time())->json();
        $read = ['team_uuid' => $this->acme->infodot_team_uuid, 'uuid' => $stored['uuid']];
        $at = time();

        $this->platformCall('/api/platform/files/meta', $read, $at)->assertOk();
        $this->platformCall('/api/platform/files/meta', $read, $at)->assertOk();
    }

    public function test_a_replayed_membership_change_is_acknowledged_but_not_applied_again(): void
    {
        $member = User::factory()->withPersonalTeam()->create(['email' => 'thabo@example.test']);
        $this->acme->users()->attach($member, ['role' => 'editor']);
        $toAdmin = ['event' => 'member.role_changed', 'team_uuid' => $this->acme->infodot_team_uuid, 'email' => 'thabo@example.test', 'role' => 'admin'];
        $toEditor = ['role' => 'editor'] + $toAdmin;
        $at = time();

        $this->membershipCall($toAdmin, $at)->assertStatus(202);
        $this->membershipCall($toEditor, $at + 1)->assertStatus(202);
        $this->membershipCall($toAdmin, $at)->assertStatus(202)->assertJson(['duplicate' => true]);

        $this->assertSame('editor', $this->acme->fresh()->users()->where('users.id', $member->id)->first()->membership->role);
    }

    public function test_the_share_panel_file_cannot_be_swapped_from_the_browser(): void
    {
        $mine = $this->companyFile('Budget.txt', 'text/plain', 'numbers');
        $other = $this->companyFile('Other.txt', 'text/plain', 'other');

        $this->actingAs($this->owner);
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(FileShares::class, ['file' => $mine])->set('fileId', $other->id);
    }

    public function test_the_share_panel_re_checks_the_person_on_every_request(): void
    {
        $file = $this->companyFile('Budget.txt', 'text/plain', 'numbers');
        $this->actingAs($this->owner);
        $panel = Livewire::test(FileShares::class, ['file' => $file]);

        // The owner loses the right to manage shares (here: the company passes to someone else, and they are an editor).
        $newOwner = User::factory()->withPersonalTeam()->create();
        $this->acme->forceFill(['user_id' => $newOwner->id])->save();
        $this->acme->users()->attach($this->owner, ['role' => 'editor']);

        $panel->call('$refresh')->assertForbidden();
    }

    public function test_inline_images_and_text_are_sandboxed_and_pdfs_keep_the_browser_viewer(): void
    {
        $this->actingAs($this->owner);

        $image = $this->companyFile('Logo ✓.png', 'image/png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        $response = $this->get(route('files.inline', $image))->assertOk();
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("filename*=utf-8''Logo%20%E2%9C%93.png", (string) $response->headers->get('Content-Disposition'));

        $pdf = $this->companyFile('Terms.pdf', 'application/pdf', '%PDF-1.4 terms');
        $this->assertStringNotContainsString('sandbox', (string) $this->get(route('files.inline', $pdf))->assertOk()->headers->get('Content-Security-Policy'));
    }

    public function test_pdf_snapshots_can_be_switched_off(): void
    {
        config(['security.pdf_previews' => false]);

        $this->assertFalse(app(FilePreviewService::class)->canRenderPdf());
    }

    public function test_the_two_factor_challenge_is_throttled_and_svg_is_no_upload_preview_type(): void
    {
        $this->assertSame('two-factor', config('fortify.limiters.two-factor'));
        $this->assertNotNull(RateLimiter::limiter('two-factor'));
        $this->assertNotContains('svg', config('livewire.temporary_file_upload.preview_mimes'));
    }
}
