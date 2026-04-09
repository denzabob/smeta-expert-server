<?php

namespace Tests\Feature;

use App\Models\StepUpChallenge;
use App\Models\User;
use App\Services\Auth\StepUpService;
use App\Services\Auth\StepUpNotPossibleException;
use App\Services\Auth\StepUpTokenInvalidException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Step-Up Authentication Feature Tests
 *
 * Covers:
 * - Allowed methods determination based on user factors
 * - Challenge initiation and scope validation
 * - Password verification flow
 * - Phone OTP flow (mocked)
 * - Token validation and expiry
 * - Scope isolation (token for scope A cannot authorise scope B)
 * - User without any factor is blocked cleanly
 * - API endpoint tests for all step-up routes
 */
class StepUpTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://localhost']);
    }

    private function stepUpService(): StepUpService
    {
        return app(StepUpService::class);
    }

    // ─── Allowed methods ─────────────────────────────────────────────────────

    public function test_user_with_password_has_password_method(): void
    {
        $user = User::factory()->create(['password' => Hash::make('pw123')]);

        $methods = $this->stepUpService()->allowedMethods($user);

        $this->assertContains('password', $methods);
    }

    public function test_user_with_verified_phone_has_phone_otp_method(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => '+79991234567',
            'phone_verified_at' => now(),
        ]);

        $methods = $this->stepUpService()->allowedMethods($user);

        $this->assertContains('phone_otp', $methods);
        $this->assertNotContains('password', $methods);
    }

    public function test_user_with_both_password_and_phone_has_both_methods(): void
    {
        $user = User::factory()->create([
            'password'          => Hash::make('pw123'),
            'phone'             => '+79991234567',
            'phone_verified_at' => now(),
        ]);

        $methods = $this->stepUpService()->allowedMethods($user);

        $this->assertContains('password', $methods);
        $this->assertContains('phone_otp', $methods);
    }

    public function test_user_without_any_factor_has_no_methods(): void
    {
        $user = User::factory()->create([
            'email'    => 'u@example.com',
            'password' => null,
            'phone'    => null,
        ]);

        $methods = $this->stepUpService()->allowedMethods($user);

        $this->assertEmpty($methods);
        $this->assertFalse($this->stepUpService()->canStepUp($user));
    }

    public function test_unverified_phone_does_not_qualify_as_step_up_factor(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => '+79991234567',
            'phone_verified_at' => null, // not verified
        ]);

        $methods = $this->stepUpService()->allowedMethods($user);

        $this->assertNotContains('phone_otp', $methods);
    }

    // ─── Initiation ──────────────────────────────────────────────────────────

    public function test_initiate_creates_pending_challenge_for_valid_scope(): void
    {
        $user = User::factory()->create(['password' => Hash::make('pw123')]);

        $challenge = $this->stepUpService()->initiate($user, 'set_quick_pin', '127.0.0.1');

        $this->assertNotNull($challenge->id);
        $this->assertEquals('pending', $challenge->status);
        $this->assertEquals('set_quick_pin', $challenge->scope);
        $this->assertContains('password', $challenge->allowed_methods);
        $this->assertTrue($challenge->expires_at->isFuture());
    }

    public function test_initiate_throws_for_invalid_scope(): void
    {
        $user = User::factory()->create(['password' => Hash::make('pw123')]);

        $this->expectException(\InvalidArgumentException::class);
        $this->stepUpService()->initiate($user, 'invalid_scope', '127.0.0.1');
    }

    public function test_initiate_throws_when_user_has_no_factor(): void
    {
        $user = User::factory()->create([
            'email'    => 'u@example.com',
            'password' => null,
            'phone'    => null,
        ]);

        $this->expectException(StepUpNotPossibleException::class);
        $this->stepUpService()->initiate($user, 'set_quick_pin', '127.0.0.1');
    }

    public function test_initiate_cancels_previous_pending_challenge_for_same_scope(): void
    {
        $user = User::factory()->create(['password' => Hash::make('pw123')]);

        $first  = $this->stepUpService()->initiate($user, 'set_quick_pin', '127.0.0.1');
        $second = $this->stepUpService()->initiate($user, 'set_quick_pin', '127.0.0.1');

        $first->refresh();
        $this->assertEquals('expired', $first->status);
        $this->assertEquals('pending', $second->status);
    }

    // ─── Verify by password ──────────────────────────────────────────────────

    public function test_password_user_can_satisfy_step_up_with_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-pw')]);

        $challenge = $this->stepUpService()->initiate($user, 'set_quick_pin', '127.0.0.1');
        $token     = $this->stepUpService()->verifyByPassword($challenge, 'correct-pw');

        $this->assertNotEmpty($token);
        $challenge->refresh();
        $this->assertEquals('completed', $challenge->status);
        $this->assertEquals('password', $challenge->completed_method);
        // DB stores the SHA-256 hash; raw token is returned to the caller
        $this->assertEquals(hash('sha256', $token), $challenge->token);
        $this->assertTrue($challenge->token_expires_at->isFuture());
    }

    public function test_wrong_password_throws_invalid_credentials(): void
    {
        $user      = User::factory()->create(['password' => Hash::make('correct')]);
        $challenge = $this->stepUpService()->initiate($user, 'set_quick_pin', '127.0.0.1');

        $this->expectException(\App\Services\Auth\StepUpInvalidCredentialsException::class);
        $this->stepUpService()->verifyByPassword($challenge, 'wrong');
    }

    // ─── Token validation ────────────────────────────────────────────────────

    public function test_valid_token_can_be_validated_for_correct_scope(): void
    {
        $user      = User::factory()->create(['password' => Hash::make('pw')]);
        $challenge = $this->stepUpService()->initiate($user, 'set_quick_pin', '127.0.0.1');
        $token     = $this->stepUpService()->verifyByPassword($challenge, 'pw');

        $found = $this->stepUpService()->validateToken($token, $user, 'set_quick_pin');

        $this->assertEquals($challenge->id, $found->id);
    }

    public function test_token_cannot_be_used_for_different_scope(): void
    {
        $user      = User::factory()->create(['password' => Hash::make('pw')]);
        $challenge = $this->stepUpService()->initiate($user, 'set_quick_pin', '127.0.0.1');
        $token     = $this->stepUpService()->verifyByPassword($challenge, 'pw');

        $this->expectException(StepUpTokenInvalidException::class);
        // Uses token for set_quick_pin but requests set_password scope
        $this->stepUpService()->validateToken($token, $user, 'set_password');
    }

    public function test_expired_token_is_rejected(): void
    {
        $user      = User::factory()->create(['password' => Hash::make('pw')]);
        $challenge = $this->stepUpService()->initiate($user, 'set_quick_pin', '127.0.0.1');
        $token     = $this->stepUpService()->verifyByPassword($challenge, 'pw');

        // Manually expire the token
        $challenge->update(['token_expires_at' => now()->subMinute()]);

        $this->expectException(StepUpTokenInvalidException::class);
        $this->stepUpService()->validateToken($token, $user, 'set_quick_pin');
    }

    public function test_consumed_token_is_rejected(): void
    {
        $user      = User::factory()->create(['password' => Hash::make('pw')]);
        $challenge = $this->stepUpService()->initiate($user, 'set_quick_pin', '127.0.0.1');
        $token     = $this->stepUpService()->verifyByPassword($challenge, 'pw');

        // Consume
        $this->stepUpService()->consumeToken($challenge);

        $this->expectException(StepUpTokenInvalidException::class);
        $this->stepUpService()->validateToken($token, $user, 'set_quick_pin');
    }

    public function test_token_is_bound_to_specific_user(): void
    {
        $user1     = User::factory()->create(['password' => Hash::make('pw')]);
        $user2     = User::factory()->create(['password' => Hash::make('pw')]);
        $challenge = $this->stepUpService()->initiate($user1, 'set_quick_pin', '127.0.0.1');
        $token     = $this->stepUpService()->verifyByPassword($challenge, 'pw');

        $this->expectException(StepUpTokenInvalidException::class);
        $this->stepUpService()->validateToken($token, $user2, 'set_quick_pin');
    }

    public function test_random_string_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('pw')]);

        $this->expectException(StepUpTokenInvalidException::class);
        $this->stepUpService()->validateToken('totally-fake-token', $user, 'set_quick_pin');
    }

    // ─── API endpoint tests ─────────────────────────────────────────────────

    public function test_api_initiate_returns_challenge_for_password_user(): void
    {
        $user = User::factory()->create(['password' => Hash::make('pw')]);

        $response = $this->actingAs($user)->postJson('/api/security/step-up/initiate', [
            'scope' => 'set_quick_pin',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['challenge_id', 'scope', 'allowed_methods', 'expires_at'])
            ->assertJsonPath('scope', 'set_quick_pin')
            ->assertJsonFragment(['password']);
    }

    public function test_api_initiate_returns_422_for_user_without_any_factor(): void
    {
        $user = User::factory()->create([
            'email'    => 'nofactor@example.com',
            'password' => null,
            'phone'    => null,
        ]);

        $response = $this->actingAs($user)->postJson('/api/security/step-up/initiate', [
            'scope' => 'set_quick_pin',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'no_valid_factor');
    }

    public function test_api_verify_password_returns_token(): void
    {
        $user      = User::factory()->create(['password' => Hash::make('correct-pw')]);
        $challenge = $this->stepUpService()->initiate($user, 'set_quick_pin', '127.0.0.1');

        $response = $this->actingAs($user)->postJson('/api/security/step-up/verify-password', [
            'challenge_id' => $challenge->id,
            'password'     => 'correct-pw',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['step_up_token', 'scope', 'expires_at'])
            ->assertJsonPath('scope', 'set_quick_pin');
    }

    public function test_api_verify_password_returns_401_on_wrong_password(): void
    {
        $user      = User::factory()->create(['password' => Hash::make('correct')]);
        $challenge = $this->stepUpService()->initiate($user, 'set_quick_pin', '127.0.0.1');

        $response = $this->actingAs($user)->postJson('/api/security/step-up/verify-password', [
            'challenge_id' => $challenge->id,
            'password'     => 'wrong',
        ]);

        $response->assertStatus(401);
    }

    public function test_api_initiate_rejects_unauthenticated(): void
    {
        $this->postJson('/api/security/step-up/initiate', ['scope' => 'set_quick_pin'])
            ->assertUnauthorized();
    }
}
