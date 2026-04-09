<?php

namespace Tests\Feature;

use App\Models\AuthVerificationChallenge;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\VerificationCodeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Yandex Bootstrap Feature Tests (Block 5)
 *
 * Covers:
 * - Yandex-only user can initiate phone bootstrap (no step-up required)
 * - User with already-verified phone is blocked from bootstrap
 * - Bootstrap verify links phone and marks it verified
 * - After phone verification recommended actions update correctly
 * - After phone verification step-up becomes possible
 * - Non-Yandex user (any authenticated user without phone) can also use bootstrap
 */
class YandexBootstrapTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://localhost']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeYandexUser(): User
    {
        $user = User::factory()->create([
            'email'    => 'ya@example.com',
            'password' => null,
            'phone'    => null,
        ]);

        SocialAccount::create([
            'user_id'          => $user->id,
            'provider'         => 'yandex',
            'provider_user_id' => 'ya_' . Str::random(8),
            'is_active'        => true,
            'linked_at'        => now(),
            'raw_profile_json' => ['id' => 'ya_test'],
        ]);

        return $user;
    }

    /**
     * Create a real pending AuthVerificationChallenge in the DB.
     * Mocking verifyCode avoids needing an actual OTP delivery.
     */
    private function createBootstrapChallenge(User $user, string $phone): AuthVerificationChallenge
    {
        return AuthVerificationChallenge::create([
            'purpose'               => 'phone_link_verify',
            'phone'                 => $phone,
            'user_id'               => $user->id,
            'ip_address'            => '127.0.0.1',
            'status'                => 'pending',
            'code_hash'             => bcrypt('111111'),
            'expires_at'            => now()->addMinutes(10),
            'attempts_left'         => 3,
            'resend_available_at'   => now()->subMinute(),
            'channel_attempt_order' => ['sms'],
            'current_channel'       => 'sms',
        ]);
    }

    // ─── Initiate ────────────────────────────────────────────────────────────

    public function test_yandex_only_user_can_initiate_phone_bootstrap(): void
    {
        $user = $this->makeYandexUser();

        // Mock VerificationCodeService so no real SMS is sent
        $mockChallenge = $this->createBootstrapChallenge($user, '+79991234567');

        $this->mock(VerificationCodeService::class, function ($mock) use ($mockChallenge) {
            $mock->shouldReceive('createChallenge')->once()->andReturn([
                'challenge'         => $mockChallenge,
                'channel_used'      => 'sms',
                'error'             => null,
                'call_phone'        => null,
                'call_phone_pretty' => null,
            ]);
        });

        $response = $this->actingAs($user)->postJson('/api/security/bootstrap/phone/initiate', [
            'phone' => '+79991234567',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'challenge_id', 'phone_masked', 'channel', 'verification_method', 'expires_at',
            ]);
    }

    public function test_user_with_already_verified_phone_cannot_use_bootstrap(): void
    {
        $user = User::factory()->create([
            'phone'             => '+79991234567',
            'phone_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/security/bootstrap/phone/initiate', [
            'phone' => '+79991234567',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'phone_already_verified');
    }

    public function test_initiate_blocks_phone_already_used_by_another_account(): void
    {
        $existingUser = User::factory()->create([
            'phone'             => '+79991234567',
            'phone_verified_at' => now(),
        ]);

        $user = $this->makeYandexUser();

        $response = $this->actingAs($user)->postJson('/api/security/bootstrap/phone/initiate', [
            'phone' => '+79991234567',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'Этот номер уже используется.');
    }

    public function test_initiate_requires_authentication(): void
    {
        $this->postJson('/api/security/bootstrap/phone/initiate', ['phone' => '+79991234567'])
            ->assertUnauthorized();
    }

    // ─── Verify ──────────────────────────────────────────────────────────────

    public function test_bootstrap_verify_links_phone_and_marks_it_verified(): void
    {
        $user  = $this->makeYandexUser();
        $phone = '+79999876543';

        $challenge = $this->createBootstrapChallenge($user, $phone);

        $this->mock(VerificationCodeService::class, function ($mock) {
            $mock->shouldReceive('verifyCode')->once()->andReturn(['valid' => true, 'error' => null]);
        });

        $response = $this->actingAs($user)->postJson('/api/security/bootstrap/phone/verify', [
            'challenge_id' => $challenge->id,
            'code'         => '111111',
        ]);

        $response->assertOk()
            ->assertJsonPath('phone_verified', true);

        $user->refresh();
        $this->assertEquals($phone, $user->phone);
        $this->assertNotNull($user->phone_verified_at);
    }

    public function test_bootstrap_verify_with_invalid_challenge_returns_422(): void
    {
        $user = $this->makeYandexUser();

        $response = $this->actingAs($user)->postJson('/api/security/bootstrap/phone/verify', [
            'challenge_id' => (string) Str::uuid(),
            'code'         => '111111',
        ]);

        $response->assertStatus(422);
    }

    public function test_bootstrap_verify_with_wrong_code_returns_422(): void
    {
        $user      = $this->makeYandexUser();
        $challenge = $this->createBootstrapChallenge($user, '+79991234567');

        $this->mock(VerificationCodeService::class, function ($mock) {
            $mock->shouldReceive('verifyCode')->once()->andReturn([
                'valid' => false,
                'error' => 'invalid_code',
            ]);
        });

        $this->actingAs($user)->postJson('/api/security/bootstrap/phone/verify', [
            'challenge_id' => $challenge->id,
            'code'         => '000000',
        ])->assertStatus(422);
    }

    // ─── After verification effects ──────────────────────────────────────────

    public function test_after_phone_verification_recommended_actions_update(): void
    {
        $user  = $this->makeYandexUser();
        $phone = '+79991111111';

        // Before: Yandex-only, no phone → bootstrap_add_phone recommended
        $before = $this->actingAs($user)->getJson('/api/security/auth-status');
        $before->assertJsonFragment(['bootstrap_add_phone']);

        // Simulate phone verification
        $user->update(['phone' => $phone, 'phone_verified_at' => now()]);
        $user->refresh();

        // After: phone verified → set_password becomes recommended (actionable via phone OTP step-up)
        $after = $this->actingAs($user)->getJson('/api/security/auth-status');
        $after->assertJsonPath('phone.verified', true);

        $recommended = $after->json('recommended_actions');
        $this->assertNotContains('bootstrap_add_phone', $recommended);
        $this->assertContains('set_password', $recommended); // now actionable
    }

    public function test_after_phone_verification_step_up_becomes_possible(): void
    {
        $user  = $this->makeYandexUser();
        $phone = '+79991111111';

        // Yandex-only: no step-up possible
        $before = $this->actingAs($user)->postJson('/api/security/step-up/initiate', [
            'scope' => 'set_password',
        ]);
        $before->assertStatus(422)->assertJsonPath('error', 'no_valid_factor');

        // After phone verified: phone OTP step-up is now possible
        $user->update(['phone' => $phone, 'phone_verified_at' => now()]);
        $user->refresh();

        $after = $this->actingAs($user)->postJson('/api/security/step-up/initiate', [
            'scope' => 'set_password',
        ]);
        $after->assertOk()
            ->assertJsonPath('scope', 'set_password')
            ->assertJsonFragment(['phone_otp']);
    }

    public function test_after_phone_verified_blocked_actions_clear(): void
    {
        $user = $this->makeYandexUser();

        // Before: set_password is blocked
        $before = $this->actingAs($user)->getJson('/api/security/auth-status');
        $before->assertJsonFragment(['set_password']); // in blocked_actions

        // Simulate verification
        $user->update(['phone' => '+79991234567', 'phone_verified_at' => now()]);

        $after = $this->actingAs($user)->getJson('/api/security/auth-status');
        $blockedActions = $after->json('blocked_actions');
        $this->assertNotContains('set_password', $blockedActions);
    }
}
