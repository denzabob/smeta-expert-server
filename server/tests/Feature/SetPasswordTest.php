<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\StepUpService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Set-Password Feature Tests (Block 4 — Universal Step-Up)
 *
 * Covers:
 * - Passwordless user can set a password after step-up
 * - User with existing password cannot use set-password endpoint
 * - Step-up token is required
 * - Expired/invalid token blocks the action
 * - Account completion flag needs_password_setup flips after setting password
 */
class SetPasswordTest extends TestCase
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

    /**
     * Get step-up token via phone OTP path — for passwordless user with verified phone
     * we simulate by adjusting the challenge directly.
     */
    private function getPhoneStepUpTokenDirect(User $user): string
    {
        // Create and immediately complete a step-up challenge via internal service invocation
        $challenge = $this->stepUpService()->initiate($user, 'set_password', '127.0.0.1');

        // Simulate the phone OTP being verified by directly manipulating the challenge
        // (proper phone OTP sends a real SMS; here we test the token/set flow)
        $rawToken = \Illuminate\Support\Str::random(64);
        $challenge->update([
            'status'           => 'completed',
            'completed_method' => 'phone_otp',
            'completed_at'     => now(),
            'token'            => hash('sha256', $rawToken), // mirrors production: store hash only
            'token_expires_at' => now()->addMinutes(15),
        ]);

        return $rawToken;
    }

    /**
     * Get step-up token via password path.
     */
    private function getPasswordStepUpToken(User $user, string $password): string
    {
        $challenge = $this->stepUpService()->initiate($user, 'set_password', '127.0.0.1');
        return $this->stepUpService()->verifyByPassword($challenge, $password);
    }

    // ─── Set password flow ───────────────────────────────────────────────────

    public function test_passwordless_user_can_set_password_after_step_up(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => '+79991234567',
            'phone_verified_at' => now(),
        ]);

        $token = $this->getPhoneStepUpTokenDirect($user);

        $response = $this->actingAs($user)->postJson('/api/security/password/set', [
            'step_up_token'         => $token,
            'password'              => 'NewSecurePassword1!',
            'password_confirmation' => 'NewSecurePassword1!',
        ]);

        $response->assertOk()
            ->assertJsonPath('has_password', true);

        $user->refresh();
        $this->assertNotNull($user->password);
        $this->assertTrue(Hash::check('NewSecurePassword1!', $user->password));
    }

    public function test_set_password_endpoint_rejects_user_with_existing_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('already-set'),
        ]);

        $token = $this->getPasswordStepUpToken($user, 'already-set');

        $response = $this->actingAs($user)->postJson('/api/security/password/set', [
            'step_up_token'         => $token,
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'password_already_set');
    }

    public function test_set_password_requires_step_up_token(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => '+79991234567',
            'phone_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/security/password/set', [
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertUnprocessable(); // validation: step_up_token required
    }

    public function test_set_password_rejects_invalid_step_up_token(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => '+79991234567',
            'phone_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/security/password/set', [
            'step_up_token'         => 'fake-token',
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error', 'step_up_required');
    }

    public function test_set_password_rejects_wrong_scope_token(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => '+79991234567',
            'phone_verified_at' => now(),
        ]);

        // Create a token for set_quick_pin scope, not set_password
        $challenge = $this->stepUpService()->initiate($user, 'set_quick_pin', '127.0.0.1');
        $wrongScopeToken = \Illuminate\Support\Str::random(64);
        $challenge->update([
            'status'           => 'completed',
            'completed_method' => 'phone_otp',
            'completed_at'     => now(),
            'token'            => hash('sha256', $wrongScopeToken), // store hash
            'token_expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->actingAs($user)->postJson('/api/security/password/set', [
            'step_up_token'         => $wrongScopeToken,
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error', 'step_up_required');
    }

    public function test_completion_flag_changes_after_password_set(): void
    {
        $user = User::factory()->create([
            'password'          => null,
            'phone'             => '+79991234567',
            'phone_verified_at' => now(),
            'email'             => null,
        ]);

        // Before
        $before = $this->actingAs($user)->getJson('/api/security/auth-status');
        $before->assertJsonPath('completion.needs_password_setup', true);

        $token = $this->getPhoneStepUpTokenDirect($user);

        $this->actingAs($user)->postJson('/api/security/password/set', [
            'step_up_token'         => $token,
            'password'              => 'MyNewPass1!',
            'password_confirmation' => 'MyNewPass1!',
        ])->assertOk();

        // After
        $after = $this->actingAs($user)->getJson('/api/security/auth-status');
        $after->assertJsonPath('completion.needs_password_setup', false)
              ->assertJsonPath('password.set', true);
    }

    public function test_unauthenticated_cannot_use_set_password_endpoint(): void
    {
        $this->postJson('/api/security/password/set', [
            'step_up_token'         => 'anything',
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertUnauthorized();
    }
}
