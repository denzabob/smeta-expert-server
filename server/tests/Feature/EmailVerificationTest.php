<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use DatabaseTransactions;

    private function uniqueEmail(string $prefix = 'ev'): string
    {
        return $prefix . '_' . uniqid() . '@example.com';
    }

    // ─── Send Notification ──────────────────────────────────────────

    public function test_send_verification_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => $this->uniqueEmail(),
            'email_verified_at' => null,
        ]);

        $response = $this->actingAs($user)->postJson('/api/email/verification-notification');

        $response->assertOk()
            ->assertJson(['message' => 'Ссылка для подтверждения отправлена.']);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_send_notification_skips_if_already_verified(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => $this->uniqueEmail(),
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/email/verification-notification');

        $response->assertOk()
            ->assertJson(['message' => 'Email уже подтверждён.']);

        Notification::assertNotSentTo($user, VerifyEmailNotification::class);
    }

    public function test_send_notification_fails_without_email(): void
    {
        $user = User::factory()->create([
            'email' => null,
            'email_verified_at' => null,
        ]);

        $response = $this->actingAs($user)->postJson('/api/email/verification-notification');

        $response->assertUnprocessable()
            ->assertJson(['message' => 'Email не указан. Заполните профиль.']);
    }

    public function test_send_notification_requires_auth(): void
    {
        $response = $this->postJson('/api/email/verification-notification');

        $response->assertUnauthorized();
    }

    // ─── Verify Email via Signed URL ────────────────────────────────

    public function test_verify_email_with_valid_signed_url(): void
    {
        $user = User::factory()->create([
            'email' => $this->uniqueEmail(),
            'email_verified_at' => null,
        ]);

        $hash = sha1($user->getEmailForVerification());

        // Build a signed URL
        $url = URL::temporarySignedRoute(
            'verification.email.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => $hash]
        );

        $response = $this->get($url);

        $response->assertRedirect();
        $this->assertStringContainsString('email_verified=success', $response->headers->get('Location'));

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_verify_email_fails_with_invalid_hash(): void
    {
        $user = User::factory()->create([
            'email' => $this->uniqueEmail(),
            'email_verified_at' => null,
        ]);

        $url = URL::temporarySignedRoute(
            'verification.email.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => 'invalid-hash']
        );

        $response = $this->get($url);

        $response->assertRedirect();
        $this->assertStringContainsString('error=email_verify_invalid', $response->headers->get('Location'));

        $user->refresh();
        $this->assertNull($user->email_verified_at);
    }

    public function test_verify_email_fails_with_unsigned_url(): void
    {
        $user = User::factory()->create([
            'email' => $this->uniqueEmail(),
            'email_verified_at' => null,
        ]);

        $hash = sha1($user->getEmailForVerification());

        // Build URL without signing
        $response = $this->get("/api/email/verify/{$user->id}/{$hash}");

        $response->assertForbidden();
    }

    public function test_verify_already_verified_email_redirects(): void
    {
        $user = User::factory()->create([
            'email' => $this->uniqueEmail(),
            'email_verified_at' => now(),
        ]);

        $hash = sha1($user->getEmailForVerification());

        $url = URL::temporarySignedRoute(
            'verification.email.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => $hash]
        );

        $response = $this->get($url);

        $response->assertRedirect();
        $this->assertStringContainsString('email_verified=already', $response->headers->get('Location'));
    }

    public function test_verify_email_with_nonexistent_user(): void
    {
        $url = URL::temporarySignedRoute(
            'verification.email.verify',
            now()->addMinutes(60),
            ['id' => 99999, 'hash' => 'somehash']
        );

        $response = $this->get($url);

        $response->assertRedirect();
        $this->assertStringContainsString('error=email_verify_invalid', $response->headers->get('Location'));
    }
}
