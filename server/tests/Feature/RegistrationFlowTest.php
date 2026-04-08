<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\AuthAuditService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Registration flow tests — Block 2.
 *
 * Covers:
 *  - POST /api/register (happy path, duplicates, validation)
 *  - POST /api/login (email verification gate)
 *  - GET  /api/email/verify/{id}/{hash} (account activation, audit)
 *  - POST /api/email/resend-verification (public, rate-limited, anti-enum)
 *  - POST /api/email/verification-notification (authenticated resend, rate-limited)
 *  - POST /api/forgot-password (unverified redirect to verification)
 *  - Audit events for the registration lifecycle
 */
class RegistrationFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Origin header activates Sanctum's stateful-API session middleware,
        // which is required for Auth::guard('web') and $request->session().
        $this->withHeaders(['Origin' => 'http://localhost']);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function uniqueEmail(string $prefix = 'reg'): string
    {
        return $prefix . '_' . uniqid() . '@example.com';
    }

    private function validRegisterPayload(array $overrides = []): array
    {
        return array_merge([
            'name'                  => 'Test User',
            'email'                 => $this->uniqueEmail(),
            'password'              => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ], $overrides);
    }

    // ─── 1. Register — happy path ────────────────────────────────────────────

    public function test_register_creates_unverified_account(): void
    {
        Notification::fake();

        $email = $this->uniqueEmail();

        $this->postJson('/api/register', $this->validRegisterPayload(['email' => $email]))
             ->assertStatus(201)
             ->assertJsonFragment(['email_verification_required' => true]);

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user, 'User must be created');
        $this->assertNull($user->email_verified_at, 'New account must be unverified');
    }

    public function test_register_sends_verification_email(): void
    {
        Notification::fake();

        $email = $this->uniqueEmail();

        $this->postJson('/api/register', $this->validRegisterPayload(['email' => $email]))
             ->assertStatus(201);

        $user = User::where('email', $email)->first();
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_register_response_includes_verification_required_flag(): void
    {
        Notification::fake();

        $this->postJson('/api/register', $this->validRegisterPayload())
             ->assertStatus(201)
             ->assertJson([
                 'email_verification_required' => true,
             ]);
    }

    public function test_register_stores_password_as_hash(): void
    {
        Notification::fake();

        $payload = $this->validRegisterPayload(['password' => 'TopSecret99!', 'password_confirmation' => 'TopSecret99!']);
        $this->postJson('/api/register', $payload)->assertStatus(201);

        $user = User::where('email', mb_strtolower($payload['email']))->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('TopSecret99!', $user->password), 'Password stored as bcrypt hash');
        $this->assertNotEquals('TopSecret99!', $user->password, 'Plaintext must not be stored');
    }

    public function test_register_normalises_email_to_lowercase(): void
    {
        Notification::fake();

        $base = 'RegUser_' . uniqid() . '@Example.COM';
        $this->postJson('/api/register', $this->validRegisterPayload(['email' => $base]))
             ->assertStatus(201);

        $user = User::where('email', mb_strtolower($base))->first();
        $this->assertNotNull($user);
        $this->assertSame(mb_strtolower($base), $user->email);
    }

    // ─── 2. Register — validation ────────────────────────────────────────────

    public function test_register_requires_email(): void
    {
        $this->postJson('/api/register', $this->validRegisterPayload(['email' => '']))
             ->assertUnprocessable()
             ->assertJsonValidationErrors(['email']);
    }

    public function test_register_requires_valid_email_format(): void
    {
        $this->postJson('/api/register', $this->validRegisterPayload(['email' => 'not-an-email']))
             ->assertUnprocessable()
             ->assertJsonValidationErrors(['email']);
    }

    public function test_register_requires_password(): void
    {
        $payload = $this->validRegisterPayload();
        unset($payload['password'], $payload['password_confirmation']);

        $this->postJson('/api/register', $payload)
             ->assertUnprocessable()
             ->assertJsonValidationErrors(['password']);
    }

    public function test_register_requires_password_minimum_8_chars(): void
    {
        $this->postJson('/api/register', $this->validRegisterPayload([
            'password'              => 'short1',
            'password_confirmation' => 'short1',
        ]))->assertUnprocessable()
           ->assertJsonValidationErrors(['password']);
    }

    public function test_register_requires_password_confirmation(): void
    {
        $this->postJson('/api/register', $this->validRegisterPayload([
            'password'              => 'SecurePass123!',
            'password_confirmation' => 'DifferentPass!',
        ]))->assertUnprocessable()
           ->assertJsonValidationErrors(['password']);
    }

    // ─── 3. Register — duplicate handling ───────────────────────────────────

    public function test_register_duplicate_unverified_email_does_not_create_second_account(): void
    {
        Notification::fake();

        $email = $this->uniqueEmail();
        $payload = $this->validRegisterPayload(['email' => $email]);

        // First registration
        $this->postJson('/api/register', $payload)->assertStatus(201);

        // Second registration with same unverified email
        $this->postJson('/api/register', $payload)->assertOk();

        // Still only one user with this email
        $count = User::where('email', mb_strtolower($email))->count();
        $this->assertSame(1, $count, 'No duplicate account must be created');
    }

    public function test_register_duplicate_unverified_email_resends_verification(): void
    {
        Notification::fake();

        $email = $this->uniqueEmail();
        $payload = $this->validRegisterPayload(['email' => $email]);

        $this->postJson('/api/register', $payload)->assertStatus(201);
        $user = User::where('email', mb_strtolower($email))->first();

        // Clear notification record to detect the second send specifically
        Notification::fake();

        $this->postJson('/api/register', $payload)->assertOk();

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_register_duplicate_verified_email_returns_200_without_creating_account(): void
    {
        Notification::fake();

        // Pre-existing verified user
        $email = $this->uniqueEmail();
        User::factory()->create(['email' => $email, 'email_verified_at' => now()]);

        $response = $this->postJson('/api/register', $this->validRegisterPayload(['email' => $email]));

        // Must return 200 (not 422 which would leak account existence)
        $response->assertOk();

        // No second user must be created
        $this->assertSame(1, User::where('email', mb_strtolower($email))->count());

        // No notification sent (the verified user should not receive a verification email)
        Notification::assertNothingSent();
    }

    public function test_register_duplicate_verified_email_does_not_disclose_existence(): void
    {
        Notification::fake();

        $email = $this->uniqueEmail();
        User::factory()->create(['email' => $email, 'email_verified_at' => now()]);

        $response = $this->postJson('/api/register', $this->validRegisterPayload(['email' => $email]))
                         ->assertOk();

        // Response must contain `email_verification_required` (same format as real registration)
        // so the frontend cannot distinguish between new vs duplicate-verified outcomes.
        $response->assertJsonFragment(['email_verification_required' => true]);
    }

    // ─── 4. Login — email verification gate ─────────────────────────────────

    public function test_login_blocked_for_unverified_account(): void
    {
        $user = User::factory()->create([
            'email'             => $this->uniqueEmail(),
            'password'          => Hash::make('correct-password'),
            'email_verified_at' => null,
        ]);

        $this->postJson('/api/login', [
            'email'    => $user->email,
            'password' => 'correct-password',
        ])->assertStatus(403)
          ->assertJsonFragment(['error' => 'email_unverified']);
    }

    public function test_login_blocked_response_does_not_expose_user_details(): void
    {
        $user = User::factory()->create([
            'email'             => $this->uniqueEmail(),
            'password'          => Hash::make('correct-password'),
            'email_verified_at' => null,
        ]);

        $response = $this->postJson('/api/login', [
            'email'    => $user->email,
            'password' => 'correct-password',
        ])->assertStatus(403);

        // Must not expose user ID, full name, role, etc.
        $data = $response->json();
        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('name', $data);
    }

    public function test_login_succeeds_after_email_verification(): void
    {
        $email = $this->uniqueEmail();

        $user = User::factory()->create([
            'email'             => $email,
            'password'          => Hash::make('correct-password'),
            'email_verified_at' => null,
        ]);

        // Verify the email
        $user->markEmailAsVerified();

        $this->postJson('/api/login', [
            'email'    => $email,
            'password' => 'correct-password',
        ])->assertOk()
          ->assertJsonFragment(['email' => $email]);
    }

    public function test_existing_verified_users_can_still_login(): void
    {
        $user = User::factory()->create([
            'email'             => $this->uniqueEmail(),
            'password'          => Hash::make('correct-password'),
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/login', [
            'email'    => $user->email,
            'password' => 'correct-password',
        ])->assertOk();
    }

    // ─── 5. Email verification via signed URL ────────────────────────────────

    public function test_valid_signed_url_verifies_email_and_sets_timestamp(): void
    {
        $user = User::factory()->create([
            'email'             => $this->uniqueEmail(),
            'email_verified_at' => null,
        ]);

        $url = URL::temporarySignedRoute(
            'verification.email.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );

        $this->get($url)->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verify_with_expired_url_is_rejected(): void
    {
        $user = User::factory()->create([
            'email'             => $this->uniqueEmail(),
            'email_verified_at' => null,
        ]);

        $url = URL::temporarySignedRoute(
            'verification.email.verify',
            now()->subMinute(), // already expired
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );

        // Expired signed URL → Laravel returns 403 directly
        $this->get($url)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verify_with_tampered_hash_is_rejected(): void
    {
        $user = User::factory()->create([
            'email'             => $this->uniqueEmail(),
            'email_verified_at' => null,
        ]);

        $url = URL::temporarySignedRoute(
            'verification.email.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => 'tampered-hash']
        );

        $this->get($url)->assertRedirect();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verify_already_verified_account_is_idempotent(): void
    {
        $user = User::factory()->create([
            'email'             => $this->uniqueEmail(),
            'email_verified_at' => now(),
        ]);

        $url = URL::temporarySignedRoute(
            'verification.email.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );

        $response = $this->get($url)->assertRedirect();
        $this->assertStringContainsString('email_verified=already', $response->headers->get('Location'));
    }

    // ─── 6. Public resend verification ──────────────────────────────────────

    public function test_public_resend_sends_notification_to_unverified_user(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email'             => $this->uniqueEmail(),
            'email_verified_at' => null,
        ]);

        // Clear rate limiter before test
        RateLimiter::clear('email_verify_public_resend:' . hash('sha256', $user->email));

        $this->postJson('/api/email/resend-verification', ['email' => $user->email])
             ->assertOk();

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_public_resend_does_not_send_for_verified_user(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email'             => $this->uniqueEmail(),
            'email_verified_at' => now(),
        ]);

        RateLimiter::clear('email_verify_public_resend:' . hash('sha256', $user->email));

        $this->postJson('/api/email/resend-verification', ['email' => $user->email])
             ->assertOk();

        Notification::assertNotSentTo($user, VerifyEmailNotification::class);
    }

    public function test_public_resend_is_safe_for_nonexistent_email(): void
    {
        Notification::fake();

        $ghost = 'ghost_' . uniqid() . '@example.com';
        RateLimiter::clear('email_verify_public_resend:' . hash('sha256', $ghost));

        $this->postJson('/api/email/resend-verification', ['email' => $ghost])
             ->assertOk();

        Notification::assertNothingSent();
    }

    public function test_public_resend_response_is_identical_for_all_outcomes(): void
    {
        Notification::fake();

        // Unverified user
        $unverified = User::factory()->create(['email' => $this->uniqueEmail(), 'email_verified_at' => null]);
        RateLimiter::clear('email_verify_public_resend:' . hash('sha256', $unverified->email));
        $r1 = $this->postJson('/api/email/resend-verification', ['email' => $unverified->email]);

        // Verified user
        $verified = User::factory()->create(['email' => $this->uniqueEmail(), 'email_verified_at' => now()]);
        RateLimiter::clear('email_verify_public_resend:' . hash('sha256', $verified->email));
        $r2 = $this->postJson('/api/email/resend-verification', ['email' => $verified->email]);

        // Non-existent email
        $ghost = 'ghost_' . uniqid() . '@example.com';
        RateLimiter::clear('email_verify_public_resend:' . hash('sha256', $ghost));
        $r3 = $this->postJson('/api/email/resend-verification', ['email' => $ghost]);

        // All must return 200 with the same message shape
        $r1->assertOk();
        $r2->assertOk();
        $r3->assertOk();
        $this->assertSame($r1->json('message'), $r2->json('message'));
        $this->assertSame($r1->json('message'), $r3->json('message'));
    }

    public function test_public_resend_is_rate_limited_after_three_attempts(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email'             => $this->uniqueEmail(),
            'email_verified_at' => null,
        ]);

        $key = 'email_verify_public_resend:' . hash('sha256', $user->email);
        RateLimiter::clear($key);

        // First 3 requests: notifications should be dispatched
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/email/resend-verification', ['email' => $user->email])
                 ->assertOk();
        }

        // 4th request: rate limited → no notification, same 200 response
        Notification::fake();
        $this->postJson('/api/email/resend-verification', ['email' => $user->email])
             ->assertOk(); // still 200 for anti-enumeration

        Notification::assertNotSentTo($user, VerifyEmailNotification::class);
    }

    // ─── 7. Authenticated resend (rate-limited) ──────────────────────────────

    public function test_authenticated_resend_is_rate_limited(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email'             => $this->uniqueEmail(),
            'email_verified_at' => null,
        ]);

        RateLimiter::clear('email_verify_auth_resend:' . $user->id);

        // First 3 requests: OK
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)
                 ->postJson('/api/email/verification-notification')
                 ->assertOk();
        }

        // 4th request: 429
        $this->actingAs($user)
             ->postJson('/api/email/verification-notification')
             ->assertStatus(429)
             ->assertJsonStructure(['message', 'retry_after']);
    }

    // ─── 8. Forgot-password for unverified accounts ──────────────────────────

    public function test_forgot_password_sends_verification_email_for_unverified_user(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email'             => $this->uniqueEmail(),
            'email_verified_at' => null,
        ]);

        $this->postJson('/api/forgot-password', ['email' => $user->email])
             ->assertOk();

        // Verification email sent instead of a password reset link
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_forgot_password_sends_reset_link_for_verified_user(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email'             => $this->uniqueEmail(),
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/forgot-password', ['email' => $user->email])
             ->assertOk();

        // Branded PasswordResetNotification must be sent (via User::sendPasswordResetNotification override)
        Notification::assertSentTo($user, \App\Notifications\PasswordResetNotification::class);
    }

    public function test_forgot_password_response_is_same_for_unverified_and_verified(): void
    {
        Notification::fake();

        $unverified = User::factory()->create(['email' => $this->uniqueEmail(), 'email_verified_at' => null]);
        $verified   = User::factory()->create(['email' => $this->uniqueEmail(), 'email_verified_at' => now()]);

        $r1 = $this->postJson('/api/forgot-password', ['email' => $unverified->email]);
        $r2 = $this->postJson('/api/forgot-password', ['email' => $verified->email]);

        $r1->assertOk();
        $r2->assertOk();
        $this->assertSame($r1->json('message'), $r2->json('message'));
    }

    // ─── 9. Audit events ────────────────────────────────────────────────────

    public function test_audit_registration_created_is_emitted(): void
    {
        Notification::fake();

        $audit = $this->mock(AuthAuditService::class);
        $audit->shouldReceive('registrationCreated')->once();
        $audit->shouldReceive('verificationSent')->once();
        $audit->shouldIgnoreMissing();

        $this->postJson('/api/register', $this->validRegisterPayload())
             ->assertStatus(201);
    }

    public function test_audit_verification_resent_emitted_on_duplicate_register(): void
    {
        Notification::fake();

        $email = $this->uniqueEmail();
        // Create existing unverified account
        User::factory()->create(['email' => $email, 'email_verified_at' => null]);

        $audit = $this->mock(AuthAuditService::class);
        $audit->shouldReceive('verificationResent')->once();
        $audit->shouldIgnoreMissing();

        $this->postJson('/api/register', $this->validRegisterPayload(['email' => $email]))
             ->assertOk();
    }

    public function test_audit_login_blocked_unverified_email_is_emitted(): void
    {
        $user = User::factory()->create([
            'email'             => $this->uniqueEmail(),
            'password'          => Hash::make('correct-password'),
            'email_verified_at' => null,
        ]);

        $audit = $this->mock(AuthAuditService::class);
        $audit->shouldReceive('loginBlockedUnverifiedEmail')->once();
        $audit->shouldIgnoreMissing();

        $this->postJson('/api/login', [
            'email'    => $user->email,
            'password' => 'correct-password',
        ])->assertStatus(403);
    }

    public function test_audit_email_verified_is_emitted_on_successful_verification(): void
    {
        $user = User::factory()->create([
            'email'             => $this->uniqueEmail(),
            'email_verified_at' => null,
        ]);

        $audit = $this->mock(AuthAuditService::class);
        $audit->shouldReceive('emailVerified')->once();
        $audit->shouldIgnoreMissing();

        $url = URL::temporarySignedRoute(
            'verification.email.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );

        $this->get($url)->assertRedirect();
    }
}
