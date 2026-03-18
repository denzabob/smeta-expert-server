<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use DatabaseTransactions;

    // ─── Forgot Password ────────────────────────────────────────────

    public function test_forgot_password_returns_200_for_existing_email(): void
    {
        $email = 'test_forgot_' . uniqid() . '@example.com';
        User::factory()->create(['email' => $email]);

        $response = $this->postJson('/api/forgot-password', [
            'email' => $email,
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Если указанный email зарегистрирован, мы отправили ссылку для сброса пароля.',
            ]);
    }

    public function test_forgot_password_returns_200_for_nonexistent_email(): void
    {
        // Anti-enumeration: always 200
        $response = $this->postJson('/api/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Если указанный email зарегистрирован, мы отправили ссылку для сброса пароля.',
            ]);
    }

    public function test_forgot_password_validates_email(): void
    {
        $response = $this->postJson('/api/forgot-password', [
            'email' => 'not-an-email',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_forgot_password_requires_email(): void
    {
        $response = $this->postJson('/api/forgot-password', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    // ─── Reset Password ─────────────────────────────────────────────

    public function test_reset_password_with_valid_token(): void
    {
        $email = 'test_reset_' . uniqid() . '@example.com';
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('old-password'),
        ]);

        // Generate a real token
        $token = Password::createToken($user);

        $response = $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $email,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Пароль успешно изменён.']);

        // Verify password was actually changed
        $user->refresh();
        $this->assertTrue(Hash::check('new-secure-password', $user->password));
    }

    public function test_reset_password_fails_with_invalid_token(): void
    {
        $email = 'test_invalid_token_' . uniqid() . '@example.com';
        User::factory()->create(['email' => $email]);

        $response = $this->postJson('/api/reset-password', [
            'token' => 'invalid-token',
            'email' => $email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Недействительный или истекший токен сброса.');
    }

    public function test_reset_password_validates_password_confirmation(): void
    {
        $response = $this->postJson('/api/reset-password', [
            'token' => 'some-token',
            'email' => 'user@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'different-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_reset_password_requires_minimum_length(): void
    {
        $response = $this->postJson('/api/reset-password', [
            'token' => 'some-token',
            'email' => 'user@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_reset_password_requires_all_fields(): void
    {
        $response = $this->postJson('/api/reset-password', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['token', 'email', 'password']);
    }
}
