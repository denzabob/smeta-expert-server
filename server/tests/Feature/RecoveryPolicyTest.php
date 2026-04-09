<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Recovery Policy Feature Tests (Block 5)
 *
 * Asserts the recovery policy data returned in GET /api/security/auth-status:
 *   - recovery_methods: which methods exist for this user
 *   - can_self_recover: whether any recovery method is available
 *   - blocked_actions: which actions are blocked due to missing prerequisite
 *   - prerequisite_actions: map of blocked_action → next_step
 *
 * Also asserts can_manage_sessions and can_manage_trusted_devices are always true.
 */
class RecoveryPolicyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://localhost']);
    }

    private function getProfile(User $user): array
    {
        $response = $this->actingAs($user)->getJson('/api/security/auth-status');
        $response->assertOk();
        return $response->json();
    }

    private function attachYandex(User $user): void
    {
        SocialAccount::create([
            'user_id'          => $user->id,
            'provider'         => 'yandex',
            'provider_user_id' => 'ya_' . Str::random(8),
            'is_active'        => true,
            'linked_at'        => now(),
            'raw_profile_json' => ['id' => 'ya_test'],
        ]);
    }

    // ─── can_self_recover ────────────────────────────────────────────────────

    public function test_yandex_only_user_can_self_recover_via_yandex_oauth(): void
    {
        $user = User::factory()->create(['password' => null, 'phone' => null]);
        $this->attachYandex($user);

        $profile = $this->getProfile($user);

        $this->assertTrue($profile['can_self_recover']);
        $this->assertContains('yandex_oauth', $profile['recovery_methods']);
    }

    public function test_phone_verified_user_can_self_recover_via_phone_otp(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => '+7999' . fake()->numerify('#######'),
            'phone_verified_at' => now(),
        ]);

        $profile = $this->getProfile($user);

        $this->assertTrue($profile['can_self_recover']);
        $this->assertContains('phone_otp', $profile['recovery_methods']);
    }

    public function test_user_with_password_and_email_can_self_recover_via_password_reset(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret'),
            'email'    => fake()->safeEmail(),
            'phone'    => null,
        ]);

        $profile = $this->getProfile($user);

        $this->assertTrue($profile['can_self_recover']);
        $this->assertContains('password_reset', $profile['recovery_methods']);
    }

    public function test_user_with_password_but_no_email_lacks_password_reset(): void
    {
        // No email means password reset is not deliverable
        $user = User::factory()->create([
            'password' => Hash::make('secret'),
            'email'    => null,
            'phone'    => null,
        ]);

        $profile = $this->getProfile($user);

        $this->assertNotContains('password_reset', $profile['recovery_methods']);
    }

    public function test_fully_isolated_user_no_yandex_no_phone_no_password_cannot_self_recover(): void
    {
        // Edge case: newly registered user with email only — no recovery path yet
        $user = User::factory()->create([
            'password' => null,
            'phone'    => null,
            'email'    => fake()->safeEmail(),
        ]);

        $profile = $this->getProfile($user);

        $this->assertFalse($profile['can_self_recover']);
        $this->assertEmpty($profile['recovery_methods']);
    }

    // ─── recovery_methods completeness ───────────────────────────────────────

    public function test_fully_verified_user_has_multiple_recovery_methods(): void
    {
        $user = User::factory()->create([
            'password'          => Hash::make('secret'),
            'phone'             => '+7999' . fake()->numerify('#######'),
            'phone_verified_at' => now(),
            'email'             => fake()->safeEmail(),
        ]);
        $this->attachYandex($user);

        $profile = $this->getProfile($user);

        $this->assertContains('phone_otp', $profile['recovery_methods']);
        $this->assertContains('password_reset', $profile['recovery_methods']);
        $this->assertContains('yandex_oauth', $profile['recovery_methods']);
        $this->assertTrue($profile['can_self_recover']);
    }

    public function test_unverified_phone_does_not_count_as_recovery_method(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => '+7999' . fake()->numerify('#######'),
            'phone_verified_at' => null, // unverified
        ]);

        $profile = $this->getProfile($user);

        $this->assertNotContains('phone_otp', $profile['recovery_methods']);
    }

    // ─── blocked_actions ─────────────────────────────────────────────────────

    public function test_yandex_only_user_has_set_password_and_enable_pin_blocked(): void
    {
        // email_verified_at=null: factory defaults to now(), which would grant email OTP step-up
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => null,
            'email_verified_at' => null,
        ]);
        $this->attachYandex($user);

        $profile = $this->getProfile($user);

        $this->assertContains('set_password', $profile['blocked_actions'],
            'set_password is semantically available but requires a step-up factor to complete'
        );
        $this->assertContains('enable_quick_pin', $profile['blocked_actions']);
    }

    public function test_password_user_has_no_blocked_actions(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret'),
            'phone'    => null,
        ]);

        $profile = $this->getProfile($user);

        // password is set, so set_password is already done — not blocked
        $this->assertNotContains('set_password', $profile['blocked_actions']);
    }

    public function test_phone_verified_user_has_no_blocked_actions(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => '+7999' . fake()->numerify('#######'),
            'phone_verified_at' => now(),
        ]);

        $profile = $this->getProfile($user);

        // Phone OTP enables step-up → set_password is now actionable, not blocked
        $this->assertNotContains('set_password', $profile['blocked_actions']);
    }

    // ─── prerequisite_actions ────────────────────────────────────────────────

    public function test_yandex_only_blocked_set_password_requires_bootstrap_add_phone(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => null,
            'email_verified_at' => null,
        ]);
        $this->attachYandex($user);

        $profile = $this->getProfile($user);

        $this->assertArrayHasKey('set_password', $profile['prerequisite_actions']);
        $this->assertEquals('bootstrap_add_phone', $profile['prerequisite_actions']['set_password']);
    }

    public function test_yandex_only_blocked_pin_requires_bootstrap_add_phone(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => null,
            'pin_enabled'       => false,
            'email_verified_at' => null,
        ]);
        $this->attachYandex($user);

        $profile = $this->getProfile($user);

        $this->assertArrayHasKey('enable_quick_pin', $profile['prerequisite_actions']);
        $this->assertEquals('bootstrap_add_phone', $profile['prerequisite_actions']['enable_quick_pin']);
    }

    public function test_fully_verified_user_has_no_prerequisite_actions(): void
    {
        $user = User::factory()->create([
            'password'          => Hash::make('secret'),
            'phone'             => '+7999' . fake()->numerify('#######'),
            'phone_verified_at' => now(),
            'pin_enabled'       => true,
        ]);

        $profile = $this->getProfile($user);

        $this->assertEmpty($profile['prerequisite_actions']);
    }

    // ─── Control surface availability ────────────────────────────────────────

    public function test_all_authenticated_users_can_manage_sessions(): void
    {
        $user = User::factory()->create(['password' => null, 'phone' => null]);

        $profile = $this->getProfile($user);

        $this->assertTrue($profile['can_manage_sessions']);
        $this->assertTrue($profile['can_manage_trusted_devices']);
    }

    // ─── Response contract completeness ──────────────────────────────────────

    public function test_auth_status_includes_all_recovery_policy_fields(): void
    {
        $user = User::factory()->create();

        $profile = $this->getProfile($user);

        foreach (['can_self_recover', 'recovery_methods', 'can_manage_sessions', 'can_manage_trusted_devices', 'blocked_actions', 'prerequisite_actions'] as $field) {
            $this->assertArrayHasKey($field, $profile, "auth-status response must include '{$field}'");
        }
    }
}
