<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\AuthMethodProfileService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Auth-Method Profile Feature Tests
 *
 * Verifies that AuthMethodProfileService returns correct state for:
 * - phone-only users
 * - Yandex-created users (no password)
 * - email-linked-but-unverified users
 * - users with password
 * - account completion flags
 * - GET /api/security/auth-status endpoint
 */
class AuthMethodProfileTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://localhost']);
    }

    private function service(): AuthMethodProfileService
    {
        return app(AuthMethodProfileService::class);
    }

    // ─── Service-level tests ─────────────────────────────────────────────────

    public function test_phone_only_user_has_correct_state(): void
    {
        $user = User::factory()->create([
            'email'              => null,
            'password'           => null,
            'phone'              => '+79991234567',
            'phone_verified_at'  => now(),
        ]);

        $profile = $this->service()->profile($user);

        $this->assertTrue($profile['phone']['linked']);
        $this->assertTrue($profile['phone']['verified']);
        $this->assertFalse($profile['password']['set']);
        $this->assertFalse($profile['email']['linked']);
        $this->assertFalse($profile['yandex']['linked']);
        $this->assertTrue($profile['completion']['needs_email']);
        $this->assertTrue($profile['completion']['needs_password_setup']);
        $this->assertTrue($profile['completion']['can_enable_quick_pin']); // verified phone → can set PIN
        $this->assertContains('add_email', $profile['recommended_actions']);
        $this->assertContains('set_password', $profile['recommended_actions']);
    }

    public function test_phone_only_user_has_verified_phone_true_and_has_password_false(): void
    {
        $user = User::factory()->create([
            'email'             => null,
            'password'          => null,
            'phone'             => '+79991234567',
            'phone_verified_at' => now(),
        ]);

        $svc = $this->service();

        $this->assertTrue($svc->hasVerifiedPhone($user));
        $this->assertFalse($svc->hasPassword($user));
    }

    public function test_yandex_created_user_has_no_password(): void
    {
        $user = User::factory()->create([
            'email'    => 'ya@example.com',
            'password' => null,
            'phone'    => null,
        ]);

        SocialAccount::create([
            'user_id'          => $user->id,
            'provider'         => 'yandex',
            'provider_user_id' => 'ya_9999',
            'is_active'        => true,
            'linked_at'        => now(),
            'raw_profile_json' => ['id' => 'ya_9999'],
        ]);

        $profile = $this->service()->profile($user);

        $this->assertFalse($profile['password']['set']);
        $this->assertTrue($profile['yandex']['linked']);
        $this->assertTrue($profile['completion']['needs_password_setup']);
    }

    public function test_email_linked_but_unverified_state(): void
    {
        $user = User::factory()->create([
            'email'              => 'u@example.com',
            'email_verified_at'  => null,
        ]);

        $profile = $this->service()->profile($user);

        $this->assertTrue($profile['email']['linked']);
        $this->assertFalse($profile['email']['verified']);
        $this->assertFalse($profile['completion']['needs_email']);
        $this->assertTrue($profile['completion']['needs_email_verification']);
        $this->assertContains('verify_email', $profile['recommended_actions']);
    }

    public function test_password_set_state_is_correct(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);

        $profile = $this->service()->profile($user);

        $this->assertTrue($profile['password']['set']);
        $this->assertFalse($profile['completion']['needs_password_setup']);
    }

    public function test_email_is_masked_correctly(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);

        $profile = $this->service()->profile($user);

        $masked = $profile['email']['masked'];
        $this->assertStringContainsString('@example.com', $masked);
        $this->assertStringNotContainsString('user', $masked); // local part must be partially hidden
    }

    public function test_phone_is_masked_in_profile(): void
    {
        $user = User::factory()->create([
            'phone'             => '+79991234567',
            'phone_verified_at' => now(),
        ]);

        $profile = $this->service()->profile($user);

        $this->assertStringNotContainsString('9991234567', $profile['phone']['masked']);
    }

    public function test_can_enable_quick_pin_requires_phone_or_password(): void
    {
        // User with verified phone → can enable PIN
        $userPhone = User::factory()->create([
            'password'          => null,
            'phone'             => '+79991234567',
            'phone_verified_at' => now(),
        ]);

        // User with password only → can enable PIN
        $userPassword = User::factory()->create([
            'password' => Hash::make('pw'),
            'phone'    => null,
        ]);

        // User with neither → cannot enable PIN
        $userNone = User::factory()->create([
            'email'    => 'none@example.com',
            'password' => null,
            'phone'    => null,
        ]);

        $svc = $this->service();
        $this->assertTrue($svc->profile($userPhone)['completion']['can_enable_quick_pin']);
        $this->assertTrue($svc->profile($userPassword)['completion']['can_enable_quick_pin']);
        $this->assertFalse($svc->profile($userNone)['completion']['can_enable_quick_pin']);
    }

    // ─── Endpoint tests ─────────────────────────────────────────────────────

    public function test_auth_status_endpoint_returns_profile_for_authenticated_user(): void
    {
        $user = User::factory()->create([
            'phone'             => '+79991234567',
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/security/auth-status');

        $response->assertOk()
            ->assertJsonStructure([
                'phone'    => ['linked', 'verified', 'masked'],
                'email'    => ['linked', 'verified', 'masked'],
                'password' => ['set'],
                'yandex'   => ['linked'],
                'quick_pin'  => ['enabled'],
                'trusted_devices' => ['count'],
                'recommended_actions',
                'completion' => ['needs_email', 'needs_email_verification', 'needs_password_setup', 'can_enable_quick_pin'],
            ]);
    }

    public function test_auth_status_returns_401_for_unauthenticated(): void
    {
        $this->getJson('/api/security/auth-status')->assertUnauthorized();
    }
}
