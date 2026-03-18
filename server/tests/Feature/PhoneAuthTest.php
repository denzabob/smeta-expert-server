<?php

namespace Tests\Feature;

use App\Models\AuthVerificationChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhoneAuthTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Sanctum requires Origin/Referer from a stateful domain for session middleware
        $this->withHeaders(['Origin' => 'http://localhost']);
    }

    // ─── Request Code ───────────────────────────────────────────────

    public function test_request_code_with_valid_phone(): void
    {
        // Test mode returns fixed challenge
        config(['verification.test_mode' => true]);

        $response = $this->postJson('/api/auth/phone/request-code', [
            'phone' => '+79991234567',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'challenge_id',
                'channel',
                'phone_masked',
                'resend_available_at',
                'expires_at',
            ]);

        $this->assertDatabaseHas('auth_verification_challenges', [
            'phone' => '+79991234567',
            'purpose' => 'phone_auth',
            'status' => 'pending',
        ]);
    }

    public function test_request_call_challenge_success(): void
    {
        config(['verification.test_mode' => true]);

        $response = $this->postJson('/api/auth/phone/call/request', [
            'phone' => '+7 (999) 123-45-67',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'verification_id',
                'challenge_id',
                'status',
                'call_phone_pretty',
                'expires_at',
                'ttl_seconds',
            ])
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('auth_verification_challenges', [
            'phone' => '+79991234567',
            'purpose' => 'phone_auth',
            'status' => 'pending',
            'current_channel' => 'sms_ru_callcheck',
        ]);
    }

    public function test_request_call_challenge_falls_back_to_http_when_official_library_has_no_callcheck_methods(): void
    {
        config([
            'verification.test_mode' => false,
            'verification.sms_ru.enabled' => true,
            'verification.sms_ru.api_id' => 'test-api-id',
            'verification.sms_ru.official_library.enabled' => true,
            'verification.sms_ru.official_library.path' => base_path('integrations/smsru/official/sms.ru.php'),
            'verification.sms_ru.official_library.class' => 'SMSRU',
        ]);

        Http::fake([
            'https://sms.ru/callcheck/add*' => Http::response([
                'status' => 'OK',
                'status_code' => 100,
                'check_id' => 'check_fallback_1',
                'check_status' => '400',
                'call_phone' => '74991234567',
                'call_phone_pretty' => '+7 (499) 123-45-67',
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/phone/call/request', [
            'phone' => '+7 (999) 123-45-67',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('call_phone_pretty', '+7 (499) 123-45-67');

        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), '/callcheck/add');
        });

        $this->assertDatabaseHas('auth_verification_challenges', [
            'phone' => '+79991234567',
            'purpose' => 'phone_auth',
            'status' => 'pending',
            'current_channel' => 'sms_ru_callcheck',
            'provider_message_id' => 'check_fallback_1',
        ]);
    }

    public function test_request_code_normalizes_russian_phone(): void
    {
        config(['verification.test_mode' => true]);

        $response = $this->postJson('/api/auth/phone/request-code', [
            'phone' => '89991234567',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('auth_verification_challenges', [
            'phone' => '+79991234567',
        ]);
    }

    public function test_request_code_rejects_invalid_phone(): void
    {
        $response = $this->postJson('/api/auth/phone/request-code', [
            'phone' => '123',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_request_code_anti_enumeration(): void
    {
        // Same 200 response for both new and existing phones (anti-enumeration)
        config(['verification.test_mode' => true]);

        User::factory()->create(['phone' => '+79991234567']);

        $response = $this->postJson('/api/auth/phone/request-code', [
            'phone' => '+79991234567',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['challenge_id', 'channel', 'phone_masked']);
    }

    // ─── Verify Code ────────────────────────────────────────────────

    public function test_verify_code_for_existing_user(): void
    {
        config(['verification.test_mode' => true, 'verification.test_code' => '123456']);

        $user = User::factory()->create([
            'phone' => '+79991234567',
            'phone_verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        // Create a challenge
        $challenge = AuthVerificationChallenge::create([
            'purpose' => 'phone_auth',
            'phone' => '+79991234567',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts_left' => 5,
            'resend_available_at' => now(),
            'status' => 'pending',
            'current_channel' => 'test',
            'channel_attempt_order' => ['test'],
            'ip_address' => '127.0.0.1',
            'user_id' => $user->id,
        ]);

        $response = $this->postJson('/api/auth/phone/verify-code', [
            'challenge_id' => $challenge->id,
            'code' => '123456',
        ]);

        $response->assertOk()
            ->assertJson(['status' => 'authenticated']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_verify_code_for_new_user_needs_onboarding(): void
    {
        config(['verification.test_mode' => true, 'verification.test_code' => '123456']);

        $challenge = AuthVerificationChallenge::create([
            'purpose' => 'phone_auth',
            'phone' => '+79998887766',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts_left' => 5,
            'resend_available_at' => now(),
            'status' => 'pending',
            'current_channel' => 'test',
            'channel_attempt_order' => ['test'],
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->postJson('/api/auth/phone/verify-code', [
            'challenge_id' => $challenge->id,
            'code' => '123456',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'needs_onboarding',
                'need_profile_completion' => true,
            ]);

        // A new user was created
        $this->assertDatabaseHas('users', [
            'phone' => '+79998887766',
        ]);
    }

    public function test_verify_code_fails_with_wrong_code(): void
    {
        $challenge = AuthVerificationChallenge::create([
            'purpose' => 'phone_auth',
            'phone' => '+79991234567',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts_left' => 5,
            'resend_available_at' => now(),
            'status' => 'pending',
            'current_channel' => 'test',
            'channel_attempt_order' => ['test'],
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->postJson('/api/auth/phone/verify-code', [
            'challenge_id' => $challenge->id,
            'code' => '999999',
        ]);

        $response->assertUnprocessable()
            ->assertJson(['message' => 'Неверный код подтверждения.']);
    }

    public function test_verify_code_fails_when_expired(): void
    {
        $challenge = AuthVerificationChallenge::create([
            'purpose' => 'phone_auth',
            'phone' => '+79991234567',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->subMinute(),
            'attempts_left' => 5,
            'resend_available_at' => now(),
            'status' => 'pending',
            'current_channel' => 'test',
            'channel_attempt_order' => ['test'],
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->postJson('/api/auth/phone/verify-code', [
            'challenge_id' => $challenge->id,
            'code' => '123456',
        ]);

        $response->assertGone();
    }

    public function test_verify_callcheck_without_code_when_confirmed(): void
    {
        config([
            'verification.test_mode' => true,
            'verification.sms_ru.test_confirmed' => true,
        ]);

        $challenge = AuthVerificationChallenge::create([
            'purpose' => 'phone_auth',
            'phone' => '+79995554433',
            'code_hash' => Hash::make('000000'),
            'expires_at' => now()->addMinutes(5),
            'attempts_left' => 5,
            'resend_available_at' => now(),
            'status' => 'pending',
            'current_channel' => 'sms_ru_callcheck',
            'channel_attempt_order' => ['sms_ru_callcheck'],
            'provider_message_id' => 'test_callcheck_123',
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->postJson('/api/auth/phone/verify-code', [
            'challenge_id' => $challenge->id,
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'needs_onboarding',
                'need_profile_completion' => true,
            ]);

        $this->assertDatabaseHas('users', [
            'phone' => '+79995554433',
        ]);
    }

    public function test_verify_callcheck_returns_pending_when_not_confirmed(): void
    {
        config([
            'verification.test_mode' => true,
            'verification.sms_ru.test_confirmed' => false,
        ]);

        $challenge = AuthVerificationChallenge::create([
            'purpose' => 'phone_auth',
            'phone' => '+79995550011',
            'code_hash' => Hash::make('000000'),
            'expires_at' => now()->addMinutes(5),
            'attempts_left' => 5,
            'resend_available_at' => now(),
            'status' => 'pending',
            'current_channel' => 'sms_ru_callcheck',
            'channel_attempt_order' => ['sms_ru_callcheck'],
            'provider_message_id' => 'test_callcheck_456',
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->postJson('/api/auth/phone/verify-code', [
            'challenge_id' => $challenge->id,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Звонок ещё не подтверждён. Позвоните на указанный номер и повторите проверку.');
    }

    public function test_callcheck_webhook_marks_challenge_verified(): void
    {
        config([
            'verification.sms_ru.webhook.enabled' => true,
            'verification.sms_ru.webhook.token' => 'webhook-secret',
        ]);

        $challenge = AuthVerificationChallenge::create([
            'purpose' => 'phone_auth',
            'phone' => '+79991230000',
            'code_hash' => Hash::make('000000'),
            'expires_at' => now()->addMinutes(5),
            'attempts_left' => 5,
            'resend_available_at' => now(),
            'status' => 'pending',
            'current_channel' => 'sms_ru_callcheck',
            'channel_attempt_order' => ['sms_ru_callcheck'],
            'provider_message_id' => 'check_abc_1',
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->post('/api/auth/phone/callcheck/webhook', [
            'token' => 'webhook-secret',
            'check_id' => 'check_abc_1',
            'check_status' => '401',
        ]);

        $response->assertOk();

        $challenge->refresh();
        $this->assertSame('verified', $challenge->status);
    }

    public function test_verify_callcheck_succeeds_after_webhook_confirmation(): void
    {
        config([
            'verification.sms_ru.webhook.enabled' => true,
            'verification.sms_ru.webhook.token' => 'webhook-secret',
        ]);

        $challenge = AuthVerificationChallenge::create([
            'purpose' => 'phone_auth',
            'phone' => '+79997776655',
            'code_hash' => Hash::make('000000'),
            'expires_at' => now()->addMinutes(5),
            'attempts_left' => 5,
            'resend_available_at' => now(),
            'status' => 'pending',
            'current_channel' => 'sms_ru_callcheck',
            'channel_attempt_order' => ['sms_ru_callcheck'],
            'provider_message_id' => 'check_abc_2',
            'ip_address' => '127.0.0.1',
        ]);

        $this->post('/api/auth/phone/callcheck/webhook', [
            'token' => 'webhook-secret',
            'check_id' => 'check_abc_2',
            'check_status' => '401',
        ])->assertOk();

        $response = $this->postJson('/api/auth/phone/verify-code', [
            'challenge_id' => $challenge->id,
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'needs_onboarding',
                'need_profile_completion' => true,
            ]);
    }

    public function test_call_status_logs_in_existing_user_after_webhook(): void
    {
        config([
            'verification.sms_ru.webhook.enabled' => true,
            'verification.sms_ru.webhook.token' => 'webhook-secret',
        ]);

        $user = User::factory()->create([
            'phone' => '+79990000001',
            'phone_verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        $challenge = AuthVerificationChallenge::create([
            'purpose' => 'phone_auth',
            'phone' => '+79990000001',
            'code_hash' => Hash::make('000000'),
            'expires_at' => now()->addMinutes(5),
            'attempts_left' => 5,
            'resend_available_at' => now(),
            'status' => 'pending',
            'current_channel' => 'sms_ru_callcheck',
            'channel_attempt_order' => ['sms_ru_callcheck'],
            'provider_message_id' => 'check_existing_1',
            'ip_address' => '127.0.0.1',
            'user_id' => $user->id,
        ]);

        $this->post('/api/auth/phone/call/webhook', [
            'token' => 'webhook-secret',
            'check_id' => 'check_existing_1',
            'check_status' => '401',
        ])->assertOk();

        $statusResponse = $this->postJson('/api/auth/phone/call/status', [
            'verification_id' => $challenge->id,
        ]);

        $statusResponse->assertOk()
            ->assertJsonPath('status', 'verified')
            ->assertJsonPath('auth.status', 'authenticated');

        $this->assertAuthenticatedAs($user);
    }

    public function test_call_status_creates_new_user_and_returns_onboarding_when_verified(): void
    {
        config([
            'verification.sms_ru.webhook.enabled' => true,
            'verification.sms_ru.webhook.token' => 'webhook-secret',
        ]);

        $challenge = AuthVerificationChallenge::create([
            'purpose' => 'phone_auth',
            'phone' => '+79990000002',
            'code_hash' => Hash::make('000000'),
            'expires_at' => now()->addMinutes(5),
            'attempts_left' => 5,
            'resend_available_at' => now(),
            'status' => 'pending',
            'current_channel' => 'sms_ru_callcheck',
            'channel_attempt_order' => ['sms_ru_callcheck'],
            'provider_message_id' => 'check_new_1',
            'ip_address' => '127.0.0.1',
        ]);

        $this->post('/api/auth/phone/call/webhook', [
            'token' => 'webhook-secret',
            'check_id' => 'check_new_1',
            'check_status' => '401',
        ])->assertOk();

        $statusResponse = $this->postJson('/api/auth/phone/call/status', [
            'verification_id' => $challenge->id,
        ]);

        $statusResponse->assertOk()
            ->assertJsonPath('status', 'verified')
            ->assertJsonPath('auth.status', 'needs_onboarding')
            ->assertJsonPath('auth.need_profile_completion', true);

        $this->assertDatabaseHas('users', [
            'phone' => '+79990000002',
        ]);
    }

    public function test_call_status_returns_expired_for_expired_challenge(): void
    {
        $challenge = AuthVerificationChallenge::create([
            'purpose' => 'phone_auth',
            'phone' => '+79990000003',
            'code_hash' => Hash::make('000000'),
            'expires_at' => now()->subMinute(),
            'attempts_left' => 5,
            'resend_available_at' => now(),
            'status' => 'pending',
            'current_channel' => 'sms_ru_callcheck',
            'channel_attempt_order' => ['sms_ru_callcheck'],
            'provider_message_id' => 'check_expired_1',
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->postJson('/api/auth/phone/call/status', [
            'verification_id' => $challenge->id,
        ]);

        $response->assertStatus(410)
            ->assertJsonPath('status', 'expired');
    }

    public function test_request_new_call_challenge_after_expiration(): void
    {
        config(['verification.test_mode' => true]);

        $expired = AuthVerificationChallenge::create([
            'purpose' => 'phone_auth',
            'phone' => '+79990000004',
            'code_hash' => Hash::make('000000'),
            'expires_at' => now()->subMinute(),
            'attempts_left' => 0,
            'resend_available_at' => now()->subMinute(),
            'status' => 'expired',
            'current_channel' => 'sms_ru_callcheck',
            'channel_attempt_order' => ['sms_ru_callcheck'],
            'provider_message_id' => 'check_expired_2',
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->postJson('/api/auth/phone/call/request', [
            'phone' => '+7 999 000 00 04',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'pending');

        $newChallengeId = (string) $response->json('verification_id');
        $this->assertNotSame($expired->id, $newChallengeId);

        $this->assertDatabaseHas('auth_verification_challenges', [
            'id' => $newChallengeId,
            'phone' => '+79990000004',
            'status' => 'pending',
        ]);
    }

    // ─── Complete Registration (Onboarding) ─────────────────────────

    public function test_complete_registration(): void
    {
        $user = User::factory()->create([
            'phone' => '+79991234567',
            'phone_verified_at' => now(),
            'registration_completed_at' => null,
            'full_name' => null,
            'email' => null,
            'activity_profile' => null,
        ]);

        $response = $this->actingAs($user)->postJson('/api/register/complete', [
            'full_name' => 'Иванов Иван Иванович',
            'email' => 'ivanov@example.com',
            'activity_profile' => 'appraiser',
            'accept_terms' => true,
            'accept_privacy' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('user.full_name', 'Иванов Иван Иванович')
            ->assertJsonPath('user.email', 'ivanov@example.com')
            ->assertJsonPath('user.activity_profile', 'appraiser');

        $user->refresh();
        $this->assertNotNull($user->registration_completed_at);
    }

    public function test_complete_registration_requires_all_fields(): void
    {
        $user = User::factory()->create([
            'phone' => '+79991234567',
            'registration_completed_at' => null,
        ]);

        $response = $this->actingAs($user)->postJson('/api/register/complete', [
            'full_name' => '',
            'email' => 'invalid',
            'activity_profile' => '',
            'accept_terms' => false,
            'accept_privacy' => true,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['full_name', 'email', 'activity_profile', 'accept_terms']);
    }

    public function test_complete_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $user = User::factory()->create([
            'phone' => '+79991234567',
            'registration_completed_at' => null,
            'email' => null,
        ]);

        $response = $this->actingAs($user)->postJson('/api/register/complete', [
            'full_name' => 'Test User',
            'email' => 'taken@example.com',
            'activity_profile' => 'appraiser',
            'accept_terms' => true,
            'accept_privacy' => true,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    // ─── Resend Code ────────────────────────────────────────────────

    public function test_resend_code_with_cooldown(): void
    {
        config(['verification.test_mode' => true]);

        $challenge = AuthVerificationChallenge::create([
            'purpose' => 'phone_auth',
            'phone' => '+79991234567',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts_left' => 5,
            'resend_available_at' => now()->addSeconds(30),
            'status' => 'pending',
            'current_channel' => 'test',
            'channel_attempt_order' => ['test'],
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->postJson('/api/auth/phone/resend-code', [
            'challenge_id' => $challenge->id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Повторная отправка ещё недоступна.');
    }

    public function test_resend_code_succeeds_after_cooldown(): void
    {
        config(['verification.test_mode' => true]);

        $challenge = AuthVerificationChallenge::create([
            'purpose' => 'phone_auth',
            'phone' => '+79991234567',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts_left' => 5,
            'resend_available_at' => now()->subSecond(), // cooldown elapsed
            'status' => 'pending',
            'current_channel' => 'test',
            'channel_attempt_order' => ['test'],
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->postJson('/api/auth/phone/resend-code', [
            'challenge_id' => $challenge->id,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['channel', 'resend_available_at']);
    }

    public function test_resend_code_fails_for_nonexistent_challenge(): void
    {
        $response = $this->postJson('/api/auth/phone/resend-code', [
            'challenge_id' => '00000000-0000-0000-0000-000000000000',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Сессия подтверждения не найдена или истекла.');
    }

    // ─── Single Session Enforcement ─────────────────────────────────

    public function test_phone_login_enforces_single_session(): void
    {
        config(['verification.test_mode' => true, 'verification.test_code' => '123456']);

        $user = User::factory()->create([
            'phone' => '+79991234567',
            'phone_verified_at' => now(),
            'registration_completed_at' => now(),
            'current_session_id' => 'old-session-id',
        ]);

        $challenge = AuthVerificationChallenge::create([
            'purpose' => 'phone_auth',
            'phone' => '+79991234567',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts_left' => 5,
            'resend_available_at' => now(),
            'status' => 'pending',
            'current_channel' => 'test',
            'channel_attempt_order' => ['test'],
            'ip_address' => '127.0.0.1',
            'user_id' => $user->id,
        ]);

        $response = $this->postJson('/api/auth/phone/verify-code', [
            'challenge_id' => $challenge->id,
            'code' => '123456',
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertNotEquals('old-session-id', $user->current_session_id);
    }
}
