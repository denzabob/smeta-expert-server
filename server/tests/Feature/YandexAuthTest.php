<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\YandexAuthService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

class YandexAuthTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://localhost']);
    }

    // ─── Redirect ───────────────────────────────────────────────────

    public function test_redirect_returns_url_when_configured(): void
    {
        config([
            'services.yandex.client_id' => 'test-client-id',
            'services.yandex.client_secret' => 'test-secret',
            'services.yandex.redirect_uri' => 'http://localhost/api/auth/yandex/callback',
            'services.yandex.force_confirm' => false,
        ]);

        $response = $this->getJson('/api/auth/yandex/redirect');

        $response->assertOk()
            ->assertJsonStructure(['redirect_url']);

        $url = $response->json('redirect_url');
        $this->assertStringContainsString('oauth.yandex.ru', $url);
        $this->assertStringContainsString('test-client-id', $url);
        $this->assertStringNotContainsString('force_confirm=', $url);
    }

    public function test_redirect_includes_force_confirm_when_enabled(): void
    {
        config([
            'services.yandex.client_id' => 'test-client-id',
            'services.yandex.client_secret' => 'test-secret',
            'services.yandex.redirect_uri' => 'http://localhost/api/auth/yandex/callback',
            'services.yandex.force_confirm' => true,
        ]);

        $response = $this->getJson('/api/auth/yandex/redirect');

        $response->assertOk()
            ->assertJsonStructure(['redirect_url']);

        $url = $response->json('redirect_url');
        $this->assertStringContainsString('force_confirm=yes', $url);
    }

    public function test_redirect_returns_503_when_not_configured(): void
    {
        config([
            'services.yandex.client_id' => '',
            'services.yandex.client_secret' => '',
            'services.yandex.redirect_uri' => '',
        ]);

        $response = $this->getJson('/api/auth/yandex/redirect');

        $response->assertStatus(503)
            ->assertJson(['message' => 'Вход через Яндекс временно недоступен.']);
    }

    // ─── Callback ───────────────────────────────────────────────────

    public function test_callback_rejects_mismatched_state(): void
    {
        $frontendBase = config('app.frontend_url', 'http://localhost:5173');

        $response = $this->withSession(['yandex_oauth_state' => 'correct-state'])
            ->get('/api/auth/yandex/callback?state=wrong-state&code=test-code');

        $response->assertRedirect();
        $this->assertStringContainsString('error=oauth_state_mismatch', $response->headers->get('Location'));
    }

    public function test_callback_rejects_missing_state(): void
    {
        $response = $this->get('/api/auth/yandex/callback?code=test-code');

        $response->assertRedirect();
        $this->assertStringContainsString('error=oauth_state_mismatch', $response->headers->get('Location'));
    }

    public function test_callback_rejects_missing_code(): void
    {
        $response = $this->withSession(['yandex_oauth_state' => 'some-state'])
            ->get('/api/auth/yandex/callback?state=some-state');

        $response->assertRedirect();
        $this->assertStringContainsString('error=oauth_no_code', $response->headers->get('Location'));
    }

    public function test_callback_with_full_flow(): void
    {
        config([
            'services.yandex.client_id' => 'test-client-id',
            'services.yandex.client_secret' => 'test-secret',
            'services.yandex.redirect_uri' => 'http://localhost/api/auth/yandex/callback',
        ]);

        $email = 'yandex_' . uniqid() . '@example.com';
        $user = User::factory()->create([
            'email' => $email,
            'phone' => '+79991234567',
            'registration_completed_at' => now(),
        ]);

        // Mock YandexAuthService
        $mock = Mockery::mock(YandexAuthService::class);
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('exchangeCode')
            ->with('test-code')
            ->andReturn(['access_token' => 'test-token']);
        $mock->shouldReceive('getUserProfile')
            ->with('test-token')
            ->andReturn([
                'id' => 'yandex-123',
                'default_email' => $email,
                'default_phone' => ['number' => '+79991234567'],
                'display_name' => 'Test User',
            ]);
        $mock->shouldReceive('findOrCreateUser')
            ->andReturn([
                'user' => $user,
                'is_new' => false,
                'needs_onboarding' => false,
            ]);
        $this->app->instance(YandexAuthService::class, $mock);

        $response = $this->withSession(['yandex_oauth_state' => 'valid-state'])
            ->get('/api/auth/yandex/callback?state=valid-state&code=test-code');

        $response->assertRedirect();
        $this->assertStringContainsString('/projects', $response->headers->get('Location'));

        // User should be authenticated
        $this->assertAuthenticatedAs($user);

        // Single session enforcement
        $user->refresh();
        $this->assertNotNull($user->current_session_id);
        $this->assertEquals('yandex', $user->last_login_channel);
    }

    public function test_callback_redirects_to_onboarding_for_new_user(): void
    {
        config([
            'services.yandex.client_id' => 'test-client-id',
            'services.yandex.client_secret' => 'test-secret',
            'services.yandex.redirect_uri' => 'http://localhost/api/auth/yandex/callback',
        ]);

        $email = 'new_yandex_' . uniqid() . '@example.com';
        $user = User::factory()->create([
            'email' => $email,
            'registration_completed_at' => null,
        ]);

        $mock = Mockery::mock(YandexAuthService::class);
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('exchangeCode')->andReturn(['access_token' => 'test-token']);
        $mock->shouldReceive('getUserProfile')->andReturn([
            'id' => 'yandex-456',
            'default_email' => $email,
            'display_name' => 'New User',
        ]);
        $mock->shouldReceive('findOrCreateUser')->andReturn([
            'user' => $user,
            'is_new' => true,
            'needs_onboarding' => true,
        ]);
        $this->app->instance(YandexAuthService::class, $mock);

        $response = $this->withSession(['yandex_oauth_state' => 'valid-state'])
            ->get('/api/auth/yandex/callback?state=valid-state&code=test-code');

        $response->assertRedirect();
        $this->assertStringContainsString('mode=onboarding', $response->headers->get('Location'));
    }

    public function test_callback_links_provider_when_intent_is_link(): void
    {
        config([
            'services.yandex.client_id' => 'test-client-id',
            'services.yandex.client_secret' => 'test-secret',
            'services.yandex.redirect_uri' => 'http://localhost/api/auth/yandex/callback',
        ]);

        $user = User::factory()->create([
            'registration_completed_at' => now(),
        ]);

        $mock = Mockery::mock(YandexAuthService::class);
        $mock->shouldReceive('exchangeCode')->with('test-code')->andReturn(['access_token' => 'test-token']);
        $mock->shouldReceive('getUserProfile')->with('test-token')->andReturn([
            'id' => 'yandex-link-1',
            'default_email' => 'linked@example.com',
            'login' => 'linked-user',
        ]);
        $mock->shouldReceive('linkProfileToUser')->andReturn([
            'linked' => true,
            'already_linked' => false,
            'error' => null,
        ]);
        $this->app->instance(YandexAuthService::class, $mock);

        $response = $this->actingAs($user)
            ->withSession([
                'yandex_oauth_context' => [
                    'state' => 'valid-state',
                    'intent' => 'link',
                    'provider' => 'yandex',
                    'user_id' => $user->id,
                ],
            ])
            ->get('/api/auth/yandex/callback?state=valid-state&code=test-code');

        $response->assertRedirect();
        $this->assertStringContainsString('oauth_link=success', $response->headers->get('Location'));
        $this->assertStringContainsString('open_settings=security', $response->headers->get('Location'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_guest_login_reactivates_historical_unlinked_identity(): void
    {
        config([
            'services.yandex.client_id' => 'test-client-id',
            'services.yandex.client_secret' => 'test-secret',
            'services.yandex.redirect_uri' => 'http://localhost/api/auth/yandex/callback',
        ]);

        $user = User::factory()->create([
            'registration_completed_at' => now(),
        ]);

        $social = SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'yandex',
            'provider_user_id' => 'yandex-historical-1',
            'is_active' => false,
            'unlinked_at' => now()->subDay(),
            'linked_at' => now()->subDays(3),
        ]);

        $service = Mockery::mock(YandexAuthService::class)->makePartial();
        $service->shouldReceive('exchangeCode')->with('test-code')->andReturn(['access_token' => 'token']);
        $service->shouldReceive('getUserProfile')->with('token')->andReturn([
            'id' => 'yandex-historical-1',
            'default_email' => 'historical@example.com',
            'login' => 'historical-user',
        ]);
        $this->app->instance(YandexAuthService::class, $service);

        $response = $this->withSession(['yandex_oauth_state' => 'valid-state'])
            ->get('/api/auth/yandex/callback?state=valid-state&code=test-code');

        $response->assertRedirect();
        $this->assertStringContainsString('/projects', $response->headers->get('Location'));
        $this->assertStringNotContainsString('provider_unlinked', (string) $response->headers->get('Location'));
        $this->assertAuthenticatedAs($user);

        $social->refresh();
        $this->assertTrue((bool) $social->is_active);
        $this->assertNull($social->unlinked_at);
        $this->assertNotNull($social->last_used_at);
    }

    public function test_guest_login_with_existing_local_account_redirects_to_controlled_flow(): void
    {
        config([
            'services.yandex.client_id' => 'test-client-id',
            'services.yandex.client_secret' => 'test-secret',
            'services.yandex.redirect_uri' => 'http://localhost/api/auth/yandex/callback',
        ]);

        $existingUser = User::factory()->create([
            'email' => 'existing.user@example.com',
            'registration_completed_at' => now(),
        ]);

        $service = Mockery::mock(YandexAuthService::class)->makePartial();
        $service->shouldReceive('exchangeCode')->with('test-code')->andReturn(['access_token' => 'token']);
        $service->shouldReceive('getUserProfile')->with('token')->andReturn([
            'id' => 'yandex-unlinked-2',
            'default_email' => 'existing.user@example.com',
            'login' => 'existing-user',
        ]);
        $this->app->instance(YandexAuthService::class, $service);

        $response = $this->withSession(['yandex_oauth_state' => 'valid-state'])
            ->get('/api/auth/yandex/callback?state=valid-state&code=test-code');

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('error=oauth_link_required', $location);
        $this->assertStringContainsString('mode=login', $location);
        $this->assertStringNotContainsString('provider_unlinked', $location);

        $this->assertGuest();
        $this->assertDatabaseMissing('social_accounts', [
            'provider' => 'yandex',
            'provider_user_id' => 'yandex-unlinked-2',
            'user_id' => $existingUser->id,
        ]);
    }

    public function test_authenticated_link_conflict_redirects_safely_without_switching_user(): void
    {
        config([
            'services.yandex.client_id' => 'test-client-id',
            'services.yandex.client_secret' => 'test-secret',
            'services.yandex.redirect_uri' => 'http://localhost/api/auth/yandex/callback',
        ]);

        $currentUser = User::factory()->create(['registration_completed_at' => now()]);
        $otherUser = User::factory()->create(['registration_completed_at' => now()]);

        SocialAccount::create([
            'user_id' => $otherUser->id,
            'provider' => 'yandex',
            'provider_user_id' => 'yandex-conflict-1',
            'is_active' => true,
            'linked_at' => now(),
        ]);

        $service = Mockery::mock(YandexAuthService::class)->makePartial();
        $service->shouldReceive('exchangeCode')->with('test-code')->andReturn(['access_token' => 'token']);
        $service->shouldReceive('getUserProfile')->with('token')->andReturn([
            'id' => 'yandex-conflict-1',
            'default_email' => 'conflict@example.com',
            'login' => 'conflict-user',
        ]);
        $this->app->instance(YandexAuthService::class, $service);

        $response = $this->actingAs($currentUser)
            ->withSession([
                'yandex_oauth_context' => [
                    'state' => 'valid-state',
                    'intent' => 'link',
                    'provider' => 'yandex',
                    'user_id' => $currentUser->id,
                ],
            ])
            ->get('/api/auth/yandex/callback?state=valid-state&code=test-code');

        $response->assertRedirect();
        $this->assertStringContainsString('oauth_link=already_linked_to_other_user', (string) $response->headers->get('Location'));
        $this->assertAuthenticatedAs($currentUser);
    }

    public function test_callback_redirects_with_server_error_on_unhandled_exception(): void
    {
        config([
            'services.yandex.client_id' => 'test-client-id',
            'services.yandex.client_secret' => 'test-secret',
            'services.yandex.redirect_uri' => 'http://localhost/api/auth/yandex/callback',
        ]);

        $service = Mockery::mock(YandexAuthService::class);
        $service->shouldReceive('exchangeCode')->with('test-code')->andReturn(['access_token' => 'token']);
        $service->shouldReceive('getUserProfile')->with('token')->andReturn([
            'id' => 'yandex-err-1',
            'default_email' => 'error@example.com',
            'login' => 'error-user',
        ]);
        $service->shouldReceive('findOrCreateUser')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(YandexAuthService::class, $service);

        $response = $this->withSession(['yandex_oauth_state' => 'valid-state'])
            ->get('/api/auth/yandex/callback?state=valid-state&code=test-code');

        $response->assertRedirect();
        $this->assertStringContainsString('error=oauth_server_error', (string) $response->headers->get('Location'));
        $this->assertGuest();
    }
}
