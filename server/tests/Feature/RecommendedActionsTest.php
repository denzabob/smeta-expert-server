<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Recommended Actions Feature Tests (Block 5)
 *
 * Asserts that GET /api/security/auth-status returns only genuinely actionable
 * recommended_actions for each user type.
 *
 * Key invariant: an action is NOT recommended if the user cannot complete it
 * with their current set of factors (no "phantom" recommendations).
 *
 * User archetypes tested:
 *   1. Yandex-only, no phone, no password → bootstrap_add_phone (NOT set_password)
 *   2. Fully-verified user → nothing critical remains
 *   3. Password-only with no phone → add_phone recommended (actionable)
 *   4. Phone-only, no password → set_password (actionable via phone OTP step-up)
 *   5. User with unverified phone → verify_phone (NOT add_phone)
 *   6. All verified, PIN not enabled → enable_quick_pin
 */
class RecommendedActionsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://localhost']);
    }

    private function getRecommended(User $user): array
    {
        $response = $this->actingAs($user)->getJson('/api/security/auth-status');
        $response->assertOk();
        return $response->json('recommended_actions');
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

    // ─── Yandex-only ─────────────────────────────────────────────────────────

    public function test_yandex_only_user_sees_bootstrap_add_phone_not_set_password(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => null,
            'email'             => fake()->safeEmail(),
            'email_verified_at' => null, // factory defaults to now(); must be null to deny email OTP step-up
        ]);
        $this->attachYandex($user);

        $actions = $this->getRecommended($user);

        $this->assertContains('bootstrap_add_phone', $actions,
            'Yandex-only user should see bootstrap_add_phone'
        );
        $this->assertNotContains('set_password', $actions,
            'set_password must not be recommended when step-up is impossible'
        );
        $this->assertNotContains('add_phone', $actions,
            'add_phone must not appear when bootstrap_add_phone is the correct path'
        );
    }

    public function test_yandex_only_user_does_not_see_enable_quick_pin(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => null,
            'email_verified_at' => null,
        ]);
        $this->attachYandex($user);

        $actions = $this->getRecommended($user);

        $this->assertNotContains('enable_quick_pin', $actions,
            'PIN cannot be enabled without a step-up factor'
        );
    }

    // ─── Password-only ───────────────────────────────────────────────────────

    public function test_password_only_user_sees_add_phone(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret'),
            'phone'    => null,
        ]);

        $actions = $this->getRecommended($user);

        $this->assertContains('add_phone', $actions,
            'User with password but no phone should be recommended to add a phone for recovery'
        );
    }

    public function test_password_only_user_does_not_see_bootstrap_add_phone(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret'),
            'phone'    => null,
        ]);

        $actions = $this->getRecommended($user);

        $this->assertNotContains('bootstrap_add_phone', $actions,
            'bootstrap_add_phone is only for Yandex-only users'
        );
    }

    // ─── Phone-only ──────────────────────────────────────────────────────────

    public function test_phone_verified_user_with_no_password_sees_set_password(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => '+7999' . fake()->numerify('#######'),
            'phone_verified_at' => now(),
        ]);

        $actions = $this->getRecommended($user);

        $this->assertContains('set_password', $actions,
            'Phone-verified passwordless user can step-up via phone OTP, so set_password is actionable'
        );
    }

    public function test_phone_verified_user_does_not_see_bootstrap_add_phone(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => '+7999' . fake()->numerify('#######'),
            'phone_verified_at' => now(),
        ]);

        $actions = $this->getRecommended($user);

        $this->assertNotContains('bootstrap_add_phone', $actions);
    }

    // ─── Unverified phone ────────────────────────────────────────────────────

    public function test_user_with_unverified_phone_sees_verify_phone_not_add_phone(): void
    {
        $user = User::factory()->create([
            'password'          => Hash::make('secret'),
            'phone'             => '+7999' . fake()->numerify('#######'),
            'phone_verified_at' => null,
        ]);

        $actions = $this->getRecommended($user);

        $this->assertContains('verify_phone', $actions);
        $this->assertNotContains('add_phone', $actions);
        $this->assertNotContains('bootstrap_add_phone', $actions);
    }

    // ─── Fully verified ──────────────────────────────────────────────────────

    public function test_fully_verified_user_has_no_critical_recommended_actions(): void
    {
        $user = User::factory()->create([
            'password'          => Hash::make('secret'),
            'phone'             => '+7999' . fake()->numerify('#######'),
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
            'pin_enabled'       => true,
        ]);

        $actions = $this->getRecommended($user);

        foreach (['bootstrap_add_phone', 'add_phone', 'verify_phone', 'set_password', 'enable_quick_pin'] as $action) {
            $this->assertNotContains($action, $actions,
                "Fully verified user should not see action: {$action}"
            );
        }
    }

    public function test_fully_verified_user_without_pin_sees_enable_quick_pin(): void
    {
        $user = User::factory()->create([
            'password'          => Hash::make('secret'),
            'phone'             => '+7999' . fake()->numerify('#######'),
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
            'pin_enabled'       => false,
        ]);

        $actions = $this->getRecommended($user);

        $this->assertContains('enable_quick_pin', $actions,
            'Fully verified user without PIN can enable it (step-up is possible)'
        );
    }

    // ─── Ordering invariants ──────────────────────────────────────────────────

    public function test_email_action_precedes_phone_action(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'email'             => fake()->safeEmail(),
            'email_verified_at' => null,
            'phone'             => null,
        ]);
        $this->attachYandex($user);

        $actions = $this->getRecommended($user);

        // verify_email should appear before bootstrap_add_phone
        $verifyEmailIdx     = array_search('verify_email', $actions);
        $bootstrapPhoneIdx  = array_search('bootstrap_add_phone', $actions);

        if ($verifyEmailIdx !== false && $bootstrapPhoneIdx !== false) {
            $this->assertLessThan($bootstrapPhoneIdx, $verifyEmailIdx,
                'verify_email (phase 1) should precede bootstrap_add_phone (phase 2)'
            );
        }
    }

    // ─── No phantom actions ───────────────────────────────────────────────────

    public function test_no_duplicate_actions(): void
    {
        $user = User::factory()->create([
            'password' => null,
            'phone'    => null,
        ]);
        $this->attachYandex($user);

        $actions = $this->getRecommended($user);

        $this->assertEquals(
            count($actions),
            count(array_unique($actions)),
            'recommended_actions must not contain duplicates'
        );
    }

    // ─── Block 6A: email OTP step-up policy ──────────────────────────────────

    public function test_verified_email_user_without_password_gets_set_password_recommended(): void
    {
        $user = User::factory()->create([
            'email'             => fake()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => null,
            'phone'             => null,
        ]);

        $actions = $this->getRecommended($user);

        $this->assertContains(
            'set_password',
            $actions,
            'A verified-email user can step-up via email OTP, so set_password should be recommended'
        );
    }

    public function test_unverified_email_only_user_does_not_get_set_password_recommended(): void
    {
        config()->set('verification.test_mode', false);
        config()->set('verification.telegram_gateway.enabled', false);
        config()->set('verification.sms_ru.enabled', false);

        $user = User::factory()->create([
            'email'             => fake()->safeEmail(),
            'email_verified_at' => null,
            'password'          => null,
            'phone'             => null,
        ]);

        $actions = $this->getRecommended($user);

        $this->assertNotContains(
            'set_password',
            $actions,
            'No verified step-up factor → set_password must not be recommended'
        );
    }

    public function test_verified_email_only_user_gets_email_otp_in_available_step_up_methods(): void
    {
        $user = User::factory()->create([
            'email'             => fake()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => null,
            'phone'             => null,
        ]);

        $response = $this->actingAs($user)->getJson('/api/security/auth-status');
        $response->assertOk();

        $methods = $response->json('available_step_up_methods');
        $this->assertNotNull($methods, 'available_step_up_methods should be present in the profile');
        $this->assertContains('email_otp', $methods);
        $this->assertNotContains('phone_otp', $methods);
        $this->assertNotContains('password', $methods);
    }

    public function test_initiate_includes_email_masked_for_verified_email_user(): void
    {
        $email = 'block6a-' . uniqid() . '@example.com';
        $user = User::factory()->create([
            'email'             => $email,
            'email_verified_at' => now(),
            'password'          => null,
            'phone'             => null,
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/security/step-up/initiate', ['scope' => 'set_password']);
        $response->assertOk();

        $this->assertArrayHasKey('email_masked', $response->json());
        $this->assertNotNull($response->json('email_masked'));
        // Full email must not be exposed
        $this->assertStringNotContainsString($email, $response->json('email_masked'));
    }
}

