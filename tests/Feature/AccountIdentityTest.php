<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Team;
use App\Models\User;
use App\Services\FileShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EA-0: the address on an account is an identity, not a preference. While InfoDot owns sign-in (or the account is linked
 * to InfoDot) it cannot be changed here; in break-glass local mode a change resets verification, so a changed address
 * never inherits what the old one was trusted with.
 */
class AccountIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'collection', 'dotsso.enabled' => false, 'dotsso.allow_local_login' => false, 'ecosystem.sso_only' => false]);

        $this->user = User::factory()->withPersonalTeam()->create(['email' => 'sam@example.test', 'email_verified_at' => now()]);
    }

    private function updateProfile(string $email, string $name = 'Sam Updated')
    {
        return $this->actingAs($this->user)->put('/user/profile-information', ['name' => $name, 'email' => $email]);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: ?string}> */
    public static function lockedModes(): array
    {
        return [
            'central sign-in active' => [['dotsso.enabled' => true, 'dotsso.allow_local_login' => false], null],
            'sso-only ecosystem mode' => [['ecosystem.sso_only' => true], null],
            'account linked to InfoDot' => [[], 'infodot-user-uuid-1'],
        ];
    }

    /** @param  array<string, mixed>  $config */
    #[DataProvider('lockedModes')]
    public function test_the_address_cannot_be_changed_while_infodot_owns_it(array $config, ?string $infodotUuid): void
    {
        config($config);
        $this->user->forceFill(['infodot_user_uuid' => $infodotUuid])->save();

        $this->updateProfile('someone-else@other.test')->assertSessionHasErrorsIn('updateProfileInformation', 'email');

        $this->user->refresh();
        $this->assertSame('sam@example.test', $this->user->email);
        $this->assertNotNull($this->user->email_verified_at);
    }

    /** @param  array<string, mixed>  $config */
    #[DataProvider('lockedModes')]
    public function test_the_name_can_still_be_changed_while_the_address_is_locked(array $config, ?string $infodotUuid): void
    {
        config($config);
        $this->user->forceFill(['infodot_user_uuid' => $infodotUuid])->save();

        // The form always posts the address back; the same address in another case is not a change.
        $this->updateProfile('Sam@Example.TEST', 'Samantha')->assertSessionHasNoErrors();

        $this->user->refresh();
        $this->assertSame('Samantha', $this->user->name);
        $this->assertSame('sam@example.test', $this->user->email);
        $this->assertNotNull($this->user->email_verified_at);
    }

    public function test_the_profile_page_shows_the_address_as_managed_by_infodot_only_when_locked(): void
    {
        $this->actingAs($this->user)->get('/user/profile')->assertOk()->assertDontSee('managed by InfoDot');

        $this->user->forceFill(['infodot_user_uuid' => 'infodot-user-uuid-2'])->save();

        $this->actingAs($this->user)->get('/user/profile')->assertOk()->assertSee('Your email address is managed by InfoDot. Change it there.');
    }

    public function test_a_local_change_resets_verification_and_grants_nothing_shared_with_the_new_address(): void
    {
        Storage::fake('local');
        $owner = User::factory()->withPersonalTeam()->create(['email' => 'owner@acme.test']);
        $acme = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Acme', 'personal_team' => false]);
        $owner->switchTeam($acme);
        Storage::disk('local')->put($path = 'files/'.Str::random(8).'.txt', 'board minutes');
        /** @var File $file */
        $file = $acme->files()->create(['name' => 'Minutes.txt', 'size' => 13, 'path' => $path]);
        $share = app(FileShareService::class)->share($owner, $file, 'recipient@partner.test', 30);

        $this->updateProfile('Recipient@Partner.test')->assertSessionHasNoErrors();

        $this->user->refresh();
        $this->assertSame('recipient@partner.test', $this->user->email);
        $this->assertNull($this->user->email_verified_at, 'a changed address is unverified');
        $this->assertNull(app(FileShareService::class)->activeShareFor($this->user, $file));

        $this->actingAs($this->user)->get('/shared')->assertOk()->assertDontSee('Minutes.txt');
        $this->actingAs($this->user)->get('/shared/'.$share->id.'/download')->assertNotFound();
    }

    public function test_an_unchanged_address_keeps_its_verification_in_local_mode(): void
    {
        $this->updateProfile('sam@example.test', 'Sam Two')->assertSessionHasNoErrors();

        $this->assertNotNull($this->user->refresh()->email_verified_at);
    }

    public function test_an_address_already_in_use_in_another_case_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->updateProfile('TAKEN@Example.test')->assertSessionHasErrorsIn('updateProfileInformation', 'email');

        $this->assertSame('sam@example.test', $this->user->refresh()->email);
    }

    public function test_addresses_are_stored_trimmed_and_in_lower_case(): void
    {
        $user = User::factory()->create(['email' => '  Mixed.Case@Example.TEST ']);

        $this->assertSame('mixed.case@example.test', $user->email);
        $this->assertSame('mixed.case@example.test', DB::table('users')->where('id', $user->id)->value('email'));
    }

    public function test_the_migration_lowers_stored_addresses_and_leaves_case_collisions_for_review(): void
    {
        DB::table('users')->insert([
            ['name' => 'A', 'email' => 'Alpha@Example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'B', 'email' => 'Dup@Example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'C', 'email' => 'dup@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration = require database_path('migrations/2026_11_27_000001_normalise_user_email_case.php');
        $migration->up();

        $this->assertTrue(DB::table('users')->where('email', 'alpha@example.test')->exists());
        // A collision is never merged or renamed automatically: both rows stay as they were.
        $this->assertTrue(DB::table('users')->where('email', 'Dup@Example.test')->exists());
        $this->assertTrue(DB::table('users')->where('email', 'dup@example.test')->exists());
    }
}
