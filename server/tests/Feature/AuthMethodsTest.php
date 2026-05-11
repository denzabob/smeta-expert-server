<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\AuthVerificationChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthMethodsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://localhost']);
    }

    public function test_auth_methods_endpoint_returns_linked_methods(): void
    {
        $user = User::factory()->create([
            'phone' => '+79995551122',
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
        ]);

        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'yandex',
            'provider_user_id' => 'ya_100',
            'provider_username' => 'tester',
            'provider_email' => $user->email,
            'provider_phone' => '+79995551122',
            'linked_at' => now(),
            'last_used_at' => now(),
            'is_active' => true,
            'raw_profile_json' => ['id' => 'ya_100'],
        ]);

        $response = $this->actingAs($user)->getJson('/api/auth/methods');

        $response->assertOk()
            ->assertJsonPath('password.enabled', true)
            ->assertJsonPath('phone.value', '+79995551122')
            ->assertJsonPath('phone.verified', true)
            ->assertJsonPath('linked_providers.0.provider', 'yandex')
            ->assertJsonPath('login_methods_count', 3);
    }

    public function test_unlink_provider_rejects_last_login_method(): void
    {
        $user = User::factory()->create([
            'email' => null,
            'password' => null,
            'phone' => null,
            'phone_verified_at' => null,
        ]);

        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'yandex',
            'provider_user_id' => 'ya_101',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/auth/methods/providers/yandex/unlink');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Нельзя отвязать последний способ входа. Сначала добавьте другой метод.');
    }

    public function test_unlink_provider_succeeds_when_phone_method_exists(): void
    {
        $user = User::factory()->create([
            'phone' => '+79991112233',
            'phone_verified_at' => now(),
        ]);

        $account = SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'yandex',
            'provider_user_id' => 'ya_102',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/auth/methods/providers/yandex/unlink');

        $response->assertOk()
            ->assertJsonPath('message', 'Аккаунт успешно отвязан.');

        $account->refresh();
        $this->assertFalse((bool) $account->is_active);
        $this->assertNotNull($account->unlinked_at);
    }

    public function test_auth_methods_show_connect_action_when_yandex_not_linked(): void
    {
        config([
            'services.yandex.client_id' => 'test-client-id',
            'services.yandex.client_secret' => 'test-secret',
            'services.yandex.redirect_uri' => 'http://localhost/api/auth/yandex/callback',
            'services.vk.client_id' => '123456',
            'services.vk.client_secret' => 'test-secret',
            'services.vk.redirect_uri' => 'http://localhost/api/auth/vk/callback',
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/auth/methods');
        $response->assertOk();

        $providers = $response->json('supported_providers');
        $this->assertIsArray($providers);
        $yandex = collect($providers)->firstWhere('provider', 'yandex');

        $this->assertNotNull($yandex);
        $this->assertFalse((bool) ($yandex['linked'] ?? true));
        $this->assertSame('not_connected', $yandex['connection_status'] ?? null);
        $this->assertTrue((bool) ($yandex['can_connect'] ?? false));

        $vk = collect($providers)->firstWhere('provider', 'vk');

        $this->assertNotNull($vk);
        $this->assertFalse((bool) ($vk['linked'] ?? true));
        $this->assertSame('not_connected', $vk['connection_status'] ?? null);
        $this->assertTrue((bool) ($vk['can_connect'] ?? false));
    }

    public function test_after_unlink_auth_methods_show_connect_again(): void
    {
        config([
            'services.yandex.client_id' => 'test-client-id',
            'services.yandex.client_secret' => 'test-secret',
            'services.yandex.redirect_uri' => 'http://localhost/api/auth/yandex/callback',
        ]);

        $user = User::factory()->create([
            'phone' => '+79991119988',
            'phone_verified_at' => now(),
        ]);

        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'yandex',
            'provider_user_id' => 'ya_reconnect_1',
            'provider_username' => 'linked_yandex',
            'is_active' => true,
            'linked_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/api/auth/methods/providers/yandex/unlink')
            ->assertOk();

        $response = $this->actingAs($user)->getJson('/api/auth/methods');
        $response->assertOk();

        $providers = $response->json('supported_providers');
        $yandex = collect($providers)->firstWhere('provider', 'yandex');

        $this->assertNotNull($yandex);
        $this->assertFalse((bool) ($yandex['linked'] ?? true));
        $this->assertTrue((bool) ($yandex['can_connect'] ?? false));

        $this->assertSame([], $response->json('linked_providers'));
    }

    public function test_phone_change_requires_password_when_password_exists(): void
    {
        $user = User::factory()->create([
            'phone' => '+79990001122',
            'phone_verified_at' => now(),
            'password' => Hash::make('secret-1234'),
        ]);

        $response = $this->actingAs($user)->postJson('/api/auth/methods/phone/request-change', [
            'phone' => '+79997776655',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Для этого действия требуется текущий пароль.');
    }

    public function test_phone_change_request_and_confirm_updates_phone(): void
    {
        $user = User::factory()->create([
            'phone' => '+79990001123',
            'phone_verified_at' => now(),
            'password' => null,
        ]);

        $challenge = AuthVerificationChallenge::create([
            'purpose' => 'phone_change',
            'phone' => '+79998887766',
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

        $confirmResponse = $this->actingAs($user)->postJson('/api/auth/methods/phone/confirm-change', [
            'challenge_id' => $challenge->id,
            'code' => '123456',
        ]);

        $confirmResponse->assertOk()
            ->assertJsonPath('phone', '+79998887766');

        $user->refresh();
        $this->assertSame('+79998887766', $user->phone);
        $this->assertNotNull($user->phone_verified_at);
    }

    public function test_email_change_requires_password_when_password_exists(): void
    {
        $user = User::factory()->create([
            'email' => 'user1@example.com',
            'password' => Hash::make('secret-1234'),
        ]);

        $response = $this->actingAs($user)->postJson('/api/auth/methods/email/change', [
            'email' => 'user2@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Для этого действия требуется текущий пароль.');
    }

    public function test_email_change_updates_email_and_resets_verification(): void
    {
        $user = User::factory()->create([
            'email' => 'user3@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('secret-1234'),
        ]);

        $response = $this->actingAs($user)->postJson('/api/auth/methods/email/change', [
            'email' => 'user4@example.com',
            'current_password' => 'secret-1234',
        ]);

        $response->assertOk()
            ->assertJsonPath('email', 'user4@example.com')
            ->assertJsonPath('email_verified', false);

        $user->refresh();
        $this->assertSame('user4@example.com', $user->email);
    }

    public function test_provider_redirect_returns_url_for_link_intent(): void
    {
        config([
            'services.yandex.client_id' => 'test-client-id',
            'services.yandex.client_secret' => 'test-secret',
            'services.yandex.redirect_uri' => 'http://localhost/api/auth/yandex/callback',
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/auth/methods/providers/yandex/redirect');

        $response->assertOk()
            ->assertJsonStructure(['redirect_url'])
            ->assertSessionHas('yandex_oauth_context');

        $this->assertStringContainsString('oauth.yandex.ru', (string) $response->json('redirect_url'));
    }

    public function test_vk_provider_redirect_returns_url_for_link_intent(): void
    {
        config([
            'services.vk.client_id' => '123456',
            'services.vk.client_secret' => 'test-secret',
            'services.vk.redirect_uri' => 'http://localhost/api/auth/vk/callback',
            'services.vk.scope' => 'vkid.personal_info email phone',
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/auth/methods/providers/vk/redirect');

        $response->assertOk()
            ->assertJsonStructure(['redirect_url'])
            ->assertSessionHas('vk_oauth_context');

        $url = (string) $response->json('redirect_url');
        $this->assertStringContainsString('id.vk.com/authorize', $url);
        $this->assertStringContainsString('code_challenge=', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
    }

    public function test_unlink_vk_provider_rejects_last_login_method(): void
    {
        $user = User::factory()->create([
            'email' => null,
            'password' => null,
            'phone' => null,
            'phone_verified_at' => null,
        ]);

        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'vk',
            'provider_user_id' => 'vk_101',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/auth/methods/providers/vk/unlink');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Нельзя отвязать последний способ входа. Сначала добавьте другой метод.');
    }

    public function test_unlink_vk_provider_succeeds_when_phone_method_exists(): void
    {
        $user = User::factory()->create([
            'phone' => '+79991112233',
            'phone_verified_at' => now(),
        ]);

        $account = SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'vk',
            'provider_user_id' => 'vk_102',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/auth/methods/providers/vk/unlink');

        $response->assertOk()
            ->assertJsonPath('message', 'Аккаунт успешно отвязан.');

        $account->refresh();
        $this->assertFalse((bool) $account->is_active);
        $this->assertNotNull($account->unlinked_at);
    }
}
