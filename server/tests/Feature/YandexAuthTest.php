<?php

namespace Tests\Feature;

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
        ]);

        $response = $this->getJson('/api/auth/yandex/redirect');

        $response->assertOk()
            ->assertJsonStructure(['redirect_url']);

        $url = $response->json('redirect_url');
        $this->assertStringContainsString('oauth.yandex.ru', $url);
        $this->assertStringContainsString('test-client-id', $url);
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
}
