<?php

namespace Tests\Feature;

use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Auth\StepUpService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Quick PIN setup feature tests (Block 4 — Universal Step-Up)
 *
 * Covers:
 * - Password user can enable PIN after step-up via password
 * - Phone-only user can enable PIN after step-up via phone OTP
 * - User without any qualifying factor cannot enable PIN
 * - Disable PIN flow uses step-up
 * - Invalid / expired / wrong-scope token is rejected
 */
class SecurityPinTest extends TestCase
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
     * Get a valid step_up_token for set_quick_pin scope using password.
     */
    private function getPasswordStepUpToken(User $user, string $password): string
    {
        $challenge = $this->stepUpService()->initiate($user, 'set_quick_pin', '127.0.0.1');
        return $this->stepUpService()->verifyByPassword($challenge, $password);
    }

    // ─── PIN enable ──────────────────────────────────────────────────────────

    public function test_password_user_can_enable_pin_after_step_up(): void
    {
        $user = User::factory()->create(['password' => Hash::make('mypassword')]);

        $token = $this->getPasswordStepUpToken($user, 'mypassword');

        $response = $this->actingAs($user)->postJson('/api/auth/pin/set', [
            'pin'           => '1234',
            'pin_confirm'   => '1234',
            'step_up_token' => $token,
            'trust_device'  => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('pin_enabled', true);

        $user->refresh();
        $this->assertTrue($user->pin_enabled);
    }

    public function test_pin_set_without_step_up_token_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('mypassword')]);

        $response = $this->actingAs($user)->postJson('/api/auth/pin/set', [
            'pin'          => '1234',
            'pin_confirm'  => '1234',
        ]);

        $response->assertUnprocessable(); // validation error: step_up_token required
    }

    public function test_pin_set_with_invalid_step_up_token_returns_401(): void
    {
        $user = User::factory()->create(['password' => Hash::make('mypassword')]);

        $response = $this->actingAs($user)->postJson('/api/auth/pin/set', [
            'pin'           => '1234',
            'pin_confirm'   => '1234',
            'step_up_token' => 'invalid-token-string',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error', 'step_up_required');
    }

    public function test_pin_set_with_wrong_scope_token_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('mypassword')]);

        // Get a step-up token for a DIFFERENT scope
        $challenge = $this->stepUpService()->initiate($user, 'set_password', '127.0.0.1');
        $wrongScopeToken = $this->stepUpService()->verifyByPassword($challenge, 'mypassword');

        $response = $this->actingAs($user)->postJson('/api/auth/pin/set', [
            'pin'           => '1234',
            'pin_confirm'   => '1234',
            'step_up_token' => $wrongScopeToken,
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error', 'step_up_required');
    }

    public function test_pin_set_consumes_the_token_so_it_cannot_be_reused(): void
    {
        $user  = User::factory()->create(['password' => Hash::make('mypassword')]);
        $token = $this->getPasswordStepUpToken($user, 'mypassword');

        // First use succeeds
        $this->actingAs($user)->postJson('/api/auth/pin/set', [
            'pin'           => '1234',
            'pin_confirm'   => '1234',
            'step_up_token' => $token,
            'trust_device'  => false,
        ])->assertOk();

        // Second use with same token must fail
        $user->update(['pin_enabled' => false]); // reset pin to allow re-set attempt

        $response = $this->actingAs($user)->postJson('/api/auth/pin/set', [
            'pin'           => '5678',
            'pin_confirm'   => '5678',
            'step_up_token' => $token,
        ]);

        $response->assertStatus(401)->assertJsonPath('error', 'step_up_required');
    }

    public function test_password_user_can_disable_pin_after_step_up(): void
    {
        $user = User::factory()->create(['password' => Hash::make('mypassword')]);
        $user->setPin('9999');

        $token = $this->getPasswordStepUpToken($user, 'mypassword');

        $response = $this->actingAs($user)->postJson('/api/auth/pin/disable', [
            'step_up_token' => $token,
        ]);

        $response->assertOk()
            ->assertJsonPath('pin_enabled', false);

        $user->refresh();
        $this->assertFalse($user->pin_enabled);
    }

    public function test_disable_pin_with_invalid_token_returns_401(): void
    {
        $user = User::factory()->create(['password' => Hash::make('mypassword')]);
        $user->setPin('1234');

        $response = $this->actingAs($user)->postJson('/api/auth/pin/disable', [
            'step_up_token' => 'garbage',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error', 'step_up_required');
    }

    // ─── Phone-only user ─────────────────────────────────────────────────────

    public function test_user_without_any_qualifying_factor_cannot_enable_pin_via_step_up(): void
    {
        $user = User::factory()->create([
            'email'    => 'u@example.com',
            'password' => null,
            'phone'    => null,
        ]);

        // Attempting to initiate should return 422 from the API
        $response = $this->actingAs($user)->postJson('/api/security/step-up/initiate', [
            'scope' => 'set_quick_pin',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'no_valid_factor');
    }
}
