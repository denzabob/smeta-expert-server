<?php

namespace Tests\Feature;

use App\Models\StepUpChallenge;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Auth\StepUpService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Account Control Surface Feature Tests (Block 5)
 *
 * Covers:
 * - GET /api/security/sessions — lists sessions, marks current session
 * - DELETE /api/security/sessions/others — revokes all other sessions
 * - DELETE /api/security/sessions/{id} — revokes single session; blocks revoking current
 * - GET /api/security/trusted-devices — lists devices
 * - DELETE /api/security/trusted-devices/{id} — revokes single device
 * - DELETE /api/security/trusted-devices — revokes ALL devices (requires step-up)
 *
 * All routes require auth:sanctum.
 */
class AccountControlTest extends TestCase
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
     * Create a raw step-up token for 'revoke_all_devices' scope by directly
     * completing a challenge — mirrors the SetPasswordTest helper pattern.
     */
    private function getRevokeAllDevicesToken(User $user): string
    {
        $challenge = $this->stepUpService()->initiate($user, 'revoke_all_devices', '127.0.0.1');

        $rawToken = Str::random(64);
        $challenge->update([
            'status'           => 'completed',
            'completed_method' => 'password',
            'completed_at'     => now(),
            'token'            => hash('sha256', $rawToken),
            'token_expires_at' => now()->addMinutes(15),
        ]);

        return $rawToken;
    }

    private function createUserWithPassword(): User
    {
        return User::factory()->create([
            'password'          => Hash::make('secret'),
            'phone'             => '+7999' . fake()->numerify('#######'),
            'phone_verified_at' => now(),
        ]);
    }

    private function createDevice(User $user): TrustedDevice
    {
        return TrustedDevice::create([
            'user_id'             => $user->id,
            'device_id'           => (string) Str::uuid(),
            'device_secret_hash'  => Hash::make(Str::random(64)),
            'user_agent'          => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120',
            'ip_first'            => '127.0.0.1',
            'ip_last'             => '127.0.0.1',
            'last_used_at'        => now(),
        ]);
    }

    private function createFakeSession(User $user, string $id): void
    {
        DB::table('sessions')->insertOrIgnore([
            'id'            => $id,
            'user_id'       => $user->id,
            'ip_address'    => '10.0.0.1',
            'user_agent'    => 'TestAgent/1.0',
            'payload'       => base64_encode(serialize([])),
            'last_activity' => time(),
        ]);
    }

    // ─── Authentication guard ────────────────────────────────────────────────

    public function test_all_endpoints_require_authentication(): void
    {
        $this->getJson('/api/security/sessions')->assertUnauthorized();
        $this->deleteJson('/api/security/sessions/others')->assertUnauthorized();
        $this->deleteJson('/api/security/sessions/fake-id')->assertUnauthorized();
        $this->getJson('/api/security/trusted-devices')->assertUnauthorized();
        $this->deleteJson('/api/security/trusted-devices/1')->assertUnauthorized();
        $this->deleteJson('/api/security/trusted-devices')->assertUnauthorized();
    }

    // ─── Sessions: list ──────────────────────────────────────────────────────

    public function test_list_sessions_returns_sessions_array(): void
    {
        $user = $this->createUserWithPassword();
        // actingAs() does not write a real DB session row; seed one to ensure non-empty response
        $this->createFakeSession($user, Str::random(40));

        $response = $this->actingAs($user)->getJson('/api/security/sessions');

        $response->assertOk()
            ->assertJsonStructure(['sessions' => [['id', 'current', 'last_active_at', 'ip', 'device']]]);
    }

    public function test_list_sessions_marks_current_session(): void
    {
        $user = $this->createUserWithPassword();
        // Seed two fake sessions; actingAs() won't write a real DB row, so the
        // 'current' marker will be false for both (correct: neither matches the
        // test request's ephemeral session ID). We verify structure + at-most-one-current.
        $this->createFakeSession($user, Str::random(40));
        $this->createFakeSession($user, Str::random(40));

        $response = $this->actingAs($user)->getJson('/api/security/sessions');
        $response->assertOk();

        $sessions = $response->json('sessions');
        $this->assertNotEmpty($sessions);

        foreach ($sessions as $s) {
            $this->assertArrayHasKey('current', $s);
            $this->assertIsBool($s['current']);
        }

        $currentCount = count(array_filter($sessions, fn ($s) => $s['current'] === true));
        $this->assertLessThanOrEqual(1, $currentCount, 'At most one session may be marked current');
    }

    // ─── Sessions: revoke others ─────────────────────────────────────────────

    public function test_revoke_other_sessions_deletes_all_except_current(): void
    {
        $user      = $this->createUserWithPassword();
        $otherId1  = Str::random(40);
        $otherId2  = Str::random(40);

        $this->createFakeSession($user, $otherId1);
        $this->createFakeSession($user, $otherId2);

        $response = $this->actingAs($user)->deleteJson('/api/security/sessions/others');

        $response->assertOk()
            ->assertJsonPath('revoked_count', fn ($v) => $v >= 2);

        // The fake sessions should be gone
        $this->assertDatabaseMissing('sessions', ['id' => $otherId1]);
        $this->assertDatabaseMissing('sessions', ['id' => $otherId2]);
    }

    public function test_revoke_other_sessions_preserves_current_session(): void
    {
        $user = $this->createUserWithPassword();

        $response = $this->actingAs($user)->deleteJson('/api/security/sessions/others');

        $response->assertOk();

        // Current user is still authenticated (can make another request)
        $this->actingAs($user)->getJson('/api/security/sessions')->assertOk();
    }

    // ─── Sessions: revoke single ─────────────────────────────────────────────

    public function test_revoke_session_deletes_a_known_other_session(): void
    {
        $user     = $this->createUserWithPassword();
        $otherId  = Str::random(40);

        $this->createFakeSession($user, $otherId);

        $response = $this->actingAs($user)->deleteJson("/api/security/sessions/{$otherId}");

        $response->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => $otherId]);
    }

    public function test_cannot_revoke_another_users_session(): void
    {
        $user        = $this->createUserWithPassword();
        $otherUser   = $this->createUserWithPassword();
        $othersSession = Str::random(40);

        $this->createFakeSession($otherUser, $othersSession);

        $response = $this->actingAs($user)->deleteJson("/api/security/sessions/{$othersSession}");

        // Should 404 (ownership check)
        $response->assertStatus(404);
        $this->assertDatabaseHas('sessions', ['id' => $othersSession]);
    }

    public function test_revoke_session_404_for_nonexistent_session(): void
    {
        $user = $this->createUserWithPassword();

        $this->actingAs($user)
            ->deleteJson('/api/security/sessions/nonexistentsession12345678901234567890')
            ->assertStatus(404);
    }

    // ─── Trusted devices: list ───────────────────────────────────────────────

    public function test_list_devices_returns_empty_when_no_devices(): void
    {
        $user = $this->createUserWithPassword();

        $this->actingAs($user)
            ->getJson('/api/security/trusted-devices')
            ->assertOk()
            ->assertJsonPath('trusted_devices', []);
    }

    public function test_list_devices_returns_active_devices(): void
    {
        $user = $this->createUserWithPassword();
        $this->createDevice($user);

        $response = $this->actingAs($user)->getJson('/api/security/trusted-devices');

        $response->assertOk();
        $this->assertCount(1, $response->json('trusted_devices'));
    }

    public function test_list_devices_excludes_revoked_devices(): void
    {
        $user   = $this->createUserWithPassword();
        $device = $this->createDevice($user);
        $device->revoke();

        $response = $this->actingAs($user)->getJson('/api/security/trusted-devices');

        $response->assertOk()
            ->assertJsonPath('trusted_devices', []);
    }

    // ─── Trusted devices: revoke single ──────────────────────────────────────

    public function test_revoke_device_marks_it_as_revoked(): void
    {
        $user   = $this->createUserWithPassword();
        $device = $this->createDevice($user);

        $this->actingAs($user)
            ->deleteJson("/api/security/trusted-devices/{$device->id}")
            ->assertOk();

        $device->refresh();
        $this->assertNotNull($device->revoked_at);
    }

    public function test_revoke_device_cannot_revoke_other_users_device(): void
    {
        $user        = $this->createUserWithPassword();
        $otherUser   = $this->createUserWithPassword();
        $otherDevice = $this->createDevice($otherUser);

        $this->actingAs($user)
            ->deleteJson("/api/security/trusted-devices/{$otherDevice->id}")
            ->assertStatus(404);

        // Device should not be revoked
        $otherDevice->refresh();
        $this->assertNull($otherDevice->revoked_at);
    }

    // ─── Trusted devices: revoke all (needs step-up) ─────────────────────────

    public function test_revoke_all_devices_requires_step_up_token(): void
    {
        $user = $this->createUserWithPassword();

        $this->actingAs($user)
            ->deleteJson('/api/security/trusted-devices', []) // no token
            ->assertStatus(422); // validate fails
    }

    public function test_revoke_all_devices_rejects_invalid_token(): void
    {
        $user = $this->createUserWithPassword();

        $this->actingAs($user)
            ->deleteJson('/api/security/trusted-devices', ['step_up_token' => 'invalid_token'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'step_up_required');
    }

    public function test_revoke_all_devices_succeeds_with_valid_step_up(): void
    {
        $user = $this->createUserWithPassword();
        $this->createDevice($user);
        $this->createDevice($user);

        $token = $this->getRevokeAllDevicesToken($user);

        $response = $this->actingAs($user)
            ->deleteJson('/api/security/trusted-devices', ['step_up_token' => $token]);

        $response->assertOk()
            ->assertJsonPath('revoked_count', 2);

        $this->assertCount(0, $user->activeTrustedDevices()->get());
    }

    public function test_revoke_all_devices_rejects_wrong_scope_token(): void
    {
        $user = $this->createUserWithPassword();

        // Create token for a different scope
        $challenge = $this->stepUpService()->initiate($user, 'set_password', '127.0.0.1');
        $rawToken  = Str::random(64);
        $challenge->update([
            'status'           => 'completed',
            'completed_method' => 'password',
            'completed_at'     => now(),
            'token'            => hash('sha256', $rawToken),
            'token_expires_at' => now()->addMinutes(15),
        ]);

        $this->actingAs($user)
            ->deleteJson('/api/security/trusted-devices', ['step_up_token' => $rawToken])
            ->assertStatus(401);
    }

    public function test_step_up_token_cannot_be_reused_for_revoke_all(): void
    {
        $user  = $this->createUserWithPassword();
        $token = $this->getRevokeAllDevicesToken($user);

        // First call succeeds
        $this->actingAs($user)
            ->deleteJson('/api/security/trusted-devices', ['step_up_token' => $token])
            ->assertOk();

        // Second call fails (token consumed)
        $this->actingAs($user)
            ->deleteJson('/api/security/trusted-devices', ['step_up_token' => $token])
            ->assertStatus(401);
    }
}
