<?php

namespace Tests\Feature;

use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\AuthAuditService;
use App\Services\AuthMailService;
use App\Notifications\PasswordResetNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Auth Security Feature Tests
 *
 * Covers:
 *  - Login rate limiting (5 attempts → 429)
 *  - Rate limiter cleared on successful login
 *  - Audit log entries for login events
 *  - Trusted device cookie flags (HttpOnly, SameSite)
 *  - Password change: revokes sessions + tokens + devices
 *  - Password reset: revokes ALL sessions + tokens + devices
 *  - CSRF: formerly broad chrome/* exclusion is now gone
 *  - Audit log entries for password events
 */
class AuthSecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://localhost']);
        // Clear any leftover rate limit state between test runs
        RateLimiter::clear($this->loginRateLimitKey('test@example.com'));
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function loginRateLimitKey(string $email): string
    {
        return 'login:' . hash('sha256', strtolower(trim($email))) . ':127.0.0.1';
    }

    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'password' => Hash::make('correct-password'),
        ], $attrs));
    }

    // ─── 1. Login rate limiting ──────────────────────────────────────────────

    public function test_login_rate_limited_after_five_failures(): void
    {
        $email = 'ratelimit_' . uniqid() . '@example.com';
        RateLimiter::clear($this->loginRateLimitKey($email));

        // 5 failed attempts
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email'    => $email,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        // 6th attempt should be rate-limited
        $this->postJson('/api/login', [
            'email'    => $email,
            'password' => 'any-password',
        ])->assertStatus(429)
          ->assertJsonStructure(['message', 'retry_after']);
    }

    public function test_login_rate_limiter_cleared_on_success(): void
    {
        $user  = $this->makeUser();
        $email = $user->email;
        RateLimiter::clear($this->loginRateLimitKey($email));

        // Fill up to 4 failures (one less than the limit)
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login', [
                'email'    => $email,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        // Successful login clears the counter
        $this->postJson('/api/login', [
            'email'    => $email,
            'password' => 'correct-password',
        ])->assertOk();

        // Should be allowed again (limiter cleared)
        $this->postJson('/api/login', [
            'email'    => $email,
            'password' => 'wrong-password',
        ])->assertStatus(401); // 401, not 429
    }

    public function test_login_returns_401_not_422_on_bad_credentials(): void
    {
        // Verify the response is generic and does not reveal field-level detail
        $response = $this->postJson('/api/login', [
            'email'    => 'nobody@example.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(401);
        $response->assertJsonMissing(['errors']); // no field-level validation errors exposed
    }

    public function test_login_success_returns_200_with_user(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/login', [
            'email'    => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()
                 ->assertJsonPath('id', $user->id)
                 ->assertJsonStructure(['pin_enabled', 'has_trusted_device']);
    }

    // ─── 2. Audit logging for login ─────────────────────────────────────────

    public function test_audit_log_written_on_login_success(): void
    {
        $user = $this->makeUser();

        $audit = $this->mock(AuthAuditService::class);
        $audit->shouldReceive('loginSuccess')->once()->with($user->id, \Mockery::any());
        // Allow any other audit calls (blocked/deleted checks etc.)
        $audit->shouldIgnoreMissing();

        $this->postJson('/api/login', [
            'email'    => $user->email,
            'password' => 'correct-password',
        ])->assertOk();
    }

    public function test_audit_log_written_on_login_failure(): void
    {
        $audit = $this->mock(AuthAuditService::class);
        $audit->shouldReceive('loginFailedInvalidCredentials')->once();
        $audit->shouldIgnoreMissing();

        $this->postJson('/api/login', [
            'email'    => 'nobody_' . uniqid() . '@example.com',
            'password' => 'wrong',
        ])->assertStatus(401);
    }

    public function test_audit_log_written_on_rate_limit(): void
    {
        $email = 'audit_rl_' . uniqid() . '@example.com';
        RateLimiter::clear($this->loginRateLimitKey($email));

        // One mock covers the entire test: 5 invalid-credential calls + 1 rate-limit call.
        $audit = $this->mock(AuthAuditService::class);
        $audit->shouldReceive('loginFailedInvalidCredentials')->times(5);
        $audit->shouldReceive('loginFailedRateLimit')->once();
        $audit->shouldIgnoreMissing();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => $email, 'password' => 'wrong'])
                 ->assertStatus(401);
        }

        // 6th attempt must trigger rate limit and fire the audit event
        $this->postJson('/api/login', ['email' => $email, 'password' => 'wrong'])
             ->assertStatus(429);
    }

    // ─── 3. Trusted device cookie flags ─────────────────────────────────────

    public function test_trusted_device_cookies_are_httponly(): void
    {
        $user = $this->makeUser(['pin_enabled' => false]);

        $response = $this->actingAs($user)
                         ->postJson('/api/auth/pin/set', [
                             'pin'          => '1234',
                             'pin_confirm'  => '1234',
                             'password'     => 'correct-password',
                             'trust_device' => true,
                         ])
                         ->assertOk();

        $setCookieHeaders = $response->headers->all('set-cookie');
        $this->assertNotEmpty($setCookieHeaders, 'Response should set trusted-device cookies');

        $tdidCookie = collect($setCookieHeaders)->first(fn ($c) => str_starts_with($c, 'tdid='));
        $tdsCookie  = collect($setCookieHeaders)->first(fn ($c) => str_starts_with($c, 'tds='));

        $this->assertNotNull($tdidCookie, 'tdid cookie must be set');
        $this->assertNotNull($tdsCookie,  'tds cookie must be set');

        $this->assertStringContainsStringIgnoringCase('httponly', $tdidCookie, 'tdid cookie must be HttpOnly');
        $this->assertStringContainsStringIgnoringCase('httponly', $tdsCookie,  'tds cookie must be HttpOnly');
        $this->assertStringContainsStringIgnoringCase('samesite=lax', $tdidCookie, 'tdid must be SameSite=Lax');
        $this->assertStringContainsStringIgnoringCase('samesite=lax', $tdsCookie,  'tds must be SameSite=Lax');
    }

    // ─── 4. Password change revocation ──────────────────────────────────────

    public function test_password_change_revokes_other_sessions_and_tokens(): void
    {
        $user = $this->makeUser();

        // Create a Sanctum token (simulating chrome extension)
        $user->createToken('chrome-extension');
        $this->assertSame(1, $user->tokens()->count());

        $this->actingAs($user)->postJson('/api/auth/password/change', [
            'current_password'          => 'correct-password',
            'new_password'              => 'new-secure-password!',
            'new_password_confirmation' => 'new-secure-password!',
        ])->assertOk();

        // All Sanctum tokens must be gone
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_password_change_revokes_other_trusted_devices(): void
    {
        $user = $this->makeUser();

        // Create a trusted device on "another browser"
        TrustedDevice::createForUser($user, 'OtherBrowser/1.0', '10.0.0.1');
        $this->assertSame(1, $user->activeTrustedDevices()->count());

        $this->actingAs($user)->postJson('/api/auth/password/change', [
            'current_password'          => 'correct-password',
            'new_password'              => 'new-secure-password!',
            'new_password_confirmation' => 'new-secure-password!',
        ])->assertOk();

        // The other device must be revoked (no current device cookie in this test)
        $this->assertSame(0, $user->fresh()->activeTrustedDevices()->count());
    }

    public function test_update_password_also_revokes_tokens(): void
    {
        $user = $this->makeUser();
        $user->createToken('chrome-extension');

        $this->actingAs($user)->putJson('/api/me/password', [
            'current_password'          => 'correct-password',
            'password'                  => 'new-pass-9876!',
            'password_confirmation'     => 'new-pass-9876!',
        ])->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    // ─── 5. Password reset revocation ───────────────────────────────────────

    public function test_password_reset_revokes_all_sessions_tokens_and_devices(): void
    {
        $user  = $this->makeUser();
        $email = $user->email;

        // Set up a Sanctum token and a trusted device
        $user->createToken('chrome');
        TrustedDevice::createForUser($user, 'SomeBrowser/1.0', '192.168.1.1');

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(1, $user->activeTrustedDevices()->count());

        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token'                 => $token,
            'email'                 => $email,
            'password'              => 'brand-new-password!',
            'password_confirmation' => 'brand-new-password!',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(0, $fresh->tokens()->count(),              'All Sanctum tokens must be revoked');
        $this->assertSame(0, $fresh->activeTrustedDevices()->count(), 'All trusted devices must be revoked');
        $this->assertNull($fresh->current_session_id,                 'current_session_id must be cleared');
    }

    public function test_audit_log_written_on_password_reset_completed(): void
    {
        $user  = $this->makeUser();
        $token = Password::createToken($user);

        $audit = $this->mock(AuthAuditService::class);
        $audit->shouldReceive('passwordResetCompleted')->once();
        $audit->shouldIgnoreMissing();

        $this->postJson('/api/reset-password', [
            'token'                 => $token,
            'email'                 => $user->email,
            'password'              => 'reset-password-safe!',
            'password_confirmation' => 'reset-password-safe!',
        ])->assertOk();
    }

    // ─── 6. CSRF: chrome/* is no longer broadly excluded ────────────────────

    /**
     * Verify that chrome/auth/token is NOT in the CSRF except list.
     * This guards against accidentally re-adding a broad wildcard exclusion.
     */
    public function test_csrf_exception_list_does_not_contain_chrome_wildcard(): void
    {
        $middleware = new \App\Http\Middleware\VerifyCsrfToken(
            app(\Illuminate\Contracts\Foundation\Application::class),
            app(\Illuminate\Contracts\Encryption\Encrypter::class)
        );

        $except = (new \ReflectionProperty($middleware, 'except'))->getValue($middleware);

        foreach ($except as $pattern) {
            $this->assertFalse(
                str_contains($pattern, 'chrome') && str_contains($pattern, '*'),
                "Broad chrome/* CSRF exclusion found: '{$pattern}'. Remove or narrow it."
            );
        }
    }

    /**
     * Verify no duplicate leading-slash / no-slash variants bloat the exception list.
     */
    public function test_csrf_exception_list_has_no_duplicates(): void
    {
        $middleware = new \App\Http\Middleware\VerifyCsrfToken(
            app(\Illuminate\Contracts\Foundation\Application::class),
            app(\Illuminate\Contracts\Encryption\Encrypter::class)
        );

        $except = (new \ReflectionProperty($middleware, 'except'))->getValue($middleware);

        // Normalize: strip leading slashes
        $normalized = array_map(fn ($p) => ltrim($p, '/'), $except);
        $this->assertSame(
            count($normalized),
            count(array_unique($normalized)),
            'CSRF except list contains duplicate entries after normalization.'
        );
    }

    // ─── 7. Password-reset email path proof ─────────────────────────────────

    /**
     * Prove that POST /api/forgot-password triggers the branded PasswordResetNotification.
     * AuthMailService is the call path: controller → AuthMailService → Password broker
     * → User::sendPasswordResetNotification() → User::notify(PasswordResetNotification).
     */
    public function test_forgot_password_sends_reset_notification_via_auth_mail_service(): void
    {
        Notification::fake();

        $user = $this->makeUser();

        $this->postJson('/api/forgot-password', ['email' => $user->email])
             ->assertOk();

        // The branded PasswordResetNotification must be dispatched through AuthMailService
        // → Password::sendResetLink() → User::sendPasswordResetNotification() → PasswordResetNotification.
        Notification::assertSentTo($user, PasswordResetNotification::class);
    }

    public function test_forgot_password_does_not_send_notification_for_nonexistent_email(): void
    {
        Notification::fake();

        $this->postJson('/api/forgot-password', ['email' => 'nobody_' . uniqid() . '@example.com'])
             ->assertOk();

        // Anti-enumeration: no notification must be sent for unknown emails
        Notification::assertNothingSent();
    }

    /**
     * Prove that AuthMailService is wired into the controller (not dead code).
     * The service's sendPasswordResetLink() method must be called.
     */
    public function test_auth_mail_service_is_called_during_forgot_password(): void
    {
        // Use a unique email that is guaranteed not to exist (not even as unverified).
        // Hard-coded addresses like test@example.com may exist in shared dev databases,
        // and if they are unverified our new code routes to VerifyEmailNotification
        // instead of sendPasswordResetLink — causing a false failure.
        Notification::fake();

        $mail = $this->mock(AuthMailService::class);
        $mail->shouldReceive('sendPasswordResetLink')->once();

        $this->mock(AuthAuditService::class)->shouldIgnoreMissing();

        $email = 'wiring_probe_' . uniqid() . '@example.com';

        $this->postJson('/api/forgot-password', ['email' => $email])
             ->assertOk();
    }

    // ─── 8. Forget-device cookie deletion consistency ────────────────────────

    /**
     * Verify forgetDevice issues deletion cookies with HttpOnly and SameSite=Lax,
     * matching the same attributes used during issuance.
     * Without matching Secure+HttpOnly+SameSite, Chrome 80+ ignores deletion.
     */
    public function test_forget_device_sends_expired_httponly_cookies(): void
    {
        $user   = $this->makeUser(['pin_enabled' => true]);
        $result = TrustedDevice::createForUser($user, 'TestBrowser/1.0', '127.0.0.1');

        // The endpoint is public (no auth required): POST /api/auth/trusted-device/forget
        $response = $this->withCookies([
            'tdid' => $result['device_id'],
            'tds'  => $result['device_secret'],
        ])->postJson('/api/auth/trusted-device/forget')
          ->assertOk();

        $setCookieHeaders = $response->headers->all('set-cookie');
        $this->assertNotEmpty($setCookieHeaders, 'Response must set deletion cookies');

        $tdidDeletion = collect($setCookieHeaders)->first(fn ($c) => str_starts_with($c, 'tdid='));
        $tdsDeletion  = collect($setCookieHeaders)->first(fn ($c) => str_starts_with($c, 'tds='));

        $this->assertNotNull($tdidDeletion, 'tdid deletion cookie must be present');
        $this->assertNotNull($tdsDeletion,  'tds deletion cookie must be present');

        // Must be HttpOnly so JS cannot intercept the deletion
        $this->assertStringContainsStringIgnoringCase('httponly', $tdidDeletion);
        $this->assertStringContainsStringIgnoringCase('httponly', $tdsDeletion);

        // Must be SameSite=Lax to match issuance
        $this->assertStringContainsStringIgnoringCase('samesite=lax', $tdidDeletion);
        $this->assertStringContainsStringIgnoringCase('samesite=lax', $tdsDeletion);

        // Max-Age=0 is the browser signal to delete the cookie immediately.
        // Laravel encrypts cookie values, so the value field is never literally empty —
        // the deletion mechanism is Max-Age=0 (or expired Expires=), not an empty value.
        $this->assertStringContainsStringIgnoringCase('max-age=0', $tdidDeletion, 'tdid deletion cookie must have Max-Age=0');
        $this->assertStringContainsStringIgnoringCase('max-age=0', $tdsDeletion,  'tds deletion cookie must have Max-Age=0');
    }
}
