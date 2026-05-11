<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\VkAuthService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

class VkAuthTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://localhost']);
    }

    public function test_redirect_returns_pkce_url_when_configured(): void
    {
        config([
            'services.vk.client_id' => '123456',
            'services.vk.client_secret' => 'test-secret',
            'services.vk.redirect_uri' => 'http://localhost/api/auth/vk/callback',
            'services.vk.scope' => 'vkid.personal_info email phone',
        ]);

        $response = $this->getJson('/api/auth/vk/redirect');

        $response->assertOk()
            ->assertJsonStructure(['redirect_url'])
            ->assertSessionHas('vk_oauth_context');

        $url = (string) $response->json('redirect_url');
        $this->assertStringContainsString('id.vk.com/authorize', $url);
        $this->assertStringContainsString('client_id=123456', $url);
        $this->assertStringContainsString('code_challenge=', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
        $this->assertStringContainsString('scope=vkid.personal_info+email+phone', $url);
    }

    public function test_redirect_returns_503_when_not_configured(): void
    {
        config([
            'services.vk.client_id' => '',
            'services.vk.client_secret' => '',
            'services.vk.redirect_uri' => '',
        ]);

        $response = $this->getJson('/api/auth/vk/redirect');

        $response->assertStatus(503)
            ->assertJson(['message' => 'Вход через VK ID временно недоступен.']);
    }

    public function test_callback_rejects_mismatched_state(): void
    {
        $response = $this->withSession([
            'vk_oauth_state' => 'correct-state',
            'vk_oauth_code_verifier' => 'verifier',
        ])->get('/api/auth/vk/callback?state=wrong-state&code=test-code&device_id=device-1');

        $response->assertRedirect();
        $this->assertStringContainsString('error=oauth_state_mismatch', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('provider=vk', (string) $response->headers->get('Location'));
    }

    public function test_callback_rejects_missing_code(): void
    {
        $response = $this->withSession([
            'vk_oauth_state' => 'valid-state',
            'vk_oauth_code_verifier' => 'verifier',
        ])->get('/api/auth/vk/callback?state=valid-state&device_id=device-1');

        $response->assertRedirect();
        $this->assertStringContainsString('error=oauth_no_code', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('provider=vk', (string) $response->headers->get('Location'));
    }

    public function test_callback_rejects_token_failure(): void
    {
        $mock = Mockery::mock(VkAuthService::class);
        $mock->shouldReceive('exchangeCode')
            ->with('test-code', 'verifier', 'device-1', 'valid-state')
            ->andReturn(null);
        $this->app->instance(VkAuthService::class, $mock);

        $response = $this->withSession([
            'vk_oauth_state' => 'valid-state',
            'vk_oauth_code_verifier' => 'verifier',
        ])->get('/api/auth/vk/callback?state=valid-state&code=test-code&device_id=device-1');

        $response->assertRedirect();
        $this->assertStringContainsString('error=oauth_token_failed', (string) $response->headers->get('Location'));
    }

    public function test_callback_rejects_profile_failure(): void
    {
        $mock = Mockery::mock(VkAuthService::class);
        $mock->shouldReceive('exchangeCode')->andReturn(['access_token' => 'token']);
        $mock->shouldReceive('getUserProfile')->with('token')->andReturn(null);
        $this->app->instance(VkAuthService::class, $mock);

        $response = $this->withSession([
            'vk_oauth_state' => 'valid-state',
            'vk_oauth_code_verifier' => 'verifier',
        ])->get('/api/auth/vk/callback?state=valid-state&code=test-code&device_id=device-1');

        $response->assertRedirect();
        $this->assertStringContainsString('error=oauth_profile_failed', (string) $response->headers->get('Location'));
    }

    public function test_callback_with_full_flow(): void
    {
        $user = User::factory()->create([
            'email' => 'vk-user@example.com',
            'registration_completed_at' => now(),
        ]);

        $mock = Mockery::mock(VkAuthService::class);
        $mock->shouldReceive('exchangeCode')
            ->with('test-code', 'verifier', 'device-1', 'valid-state')
            ->andReturn(['access_token' => 'token']);
        $mock->shouldReceive('getUserProfile')->with('token')->andReturn([
            'user' => [
                'user_id' => 'vk-123',
                'email' => 'vk-user@example.com',
                'first_name' => 'VK',
                'last_name' => 'User',
            ],
        ]);
        $mock->shouldReceive('findOrCreateUser')->andReturn([
            'user' => $user,
            'is_new' => false,
            'needs_onboarding' => false,
        ]);
        $this->app->instance(VkAuthService::class, $mock);

        $response = $this->withSession([
            'vk_oauth_state' => 'valid-state',
            'vk_oauth_code_verifier' => 'verifier',
        ])->get('/api/auth/vk/callback?state=valid-state&code=test-code&device_id=device-1');

        $response->assertRedirect();
        $this->assertStringContainsString('/projects', (string) $response->headers->get('Location'));
        $this->assertAuthenticatedAs($user);

        $user->refresh();
        $this->assertNotNull($user->current_session_id);
        $this->assertEquals('vk', $user->last_login_channel);
    }

    public function test_callback_redirects_to_onboarding_for_new_user(): void
    {
        $user = User::factory()->create([
            'email' => 'new-vk@example.com',
            'registration_completed_at' => null,
        ]);

        $mock = Mockery::mock(VkAuthService::class);
        $mock->shouldReceive('exchangeCode')->andReturn(['access_token' => 'token']);
        $mock->shouldReceive('getUserProfile')->andReturn(['user' => ['user_id' => 'vk-456']]);
        $mock->shouldReceive('findOrCreateUser')->andReturn([
            'user' => $user,
            'is_new' => true,
            'needs_onboarding' => true,
        ]);
        $this->app->instance(VkAuthService::class, $mock);

        $response = $this->withSession([
            'vk_oauth_state' => 'valid-state',
            'vk_oauth_code_verifier' => 'verifier',
        ])->get('/api/auth/vk/callback?state=valid-state&code=test-code&device_id=device-1');

        $response->assertRedirect();
        $this->assertStringContainsString('mode=onboarding', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('via=vk', (string) $response->headers->get('Location'));
    }

    public function test_callback_links_provider_when_intent_is_link(): void
    {
        $user = User::factory()->create(['registration_completed_at' => now()]);

        $mock = Mockery::mock(VkAuthService::class);
        $mock->shouldReceive('exchangeCode')->andReturn(['access_token' => 'token']);
        $mock->shouldReceive('getUserProfile')->andReturn([
            'user' => [
                'user_id' => 'vk-link-1',
                'email' => 'linked@example.com',
            ],
        ]);
        $mock->shouldReceive('linkProfileToUser')->andReturn([
            'linked' => true,
            'already_linked' => false,
            'error' => null,
        ]);
        $this->app->instance(VkAuthService::class, $mock);

        $response = $this->actingAs($user)
            ->withSession([
                'vk_oauth_context' => [
                    'state' => 'valid-state',
                    'code_verifier' => 'verifier',
                    'intent' => 'link',
                    'provider' => 'vk',
                    'user_id' => $user->id,
                ],
            ])
            ->get('/api/auth/vk/callback?state=valid-state&code=test-code&device_id=device-1');

        $response->assertRedirect();
        $this->assertStringContainsString('oauth_link=success', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('provider=vk', (string) $response->headers->get('Location'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_guest_login_reactivates_historical_unlinked_identity(): void
    {
        $user = User::factory()->create(['registration_completed_at' => now()]);

        $social = SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'vk',
            'provider_user_id' => 'vk-historical-1',
            'is_active' => false,
            'unlinked_at' => now()->subDay(),
            'linked_at' => now()->subDays(3),
        ]);

        $service = Mockery::mock(VkAuthService::class)->makePartial();
        $service->shouldReceive('exchangeCode')->andReturn(['access_token' => 'token']);
        $service->shouldReceive('getUserProfile')->andReturn([
            'user' => [
                'user_id' => 'vk-historical-1',
                'email' => 'historical@example.com',
                'first_name' => 'Historical',
            ],
        ]);
        $this->app->instance(VkAuthService::class, $service);

        $response = $this->withSession([
            'vk_oauth_state' => 'valid-state',
            'vk_oauth_code_verifier' => 'verifier',
        ])->get('/api/auth/vk/callback?state=valid-state&code=test-code&device_id=device-1');

        $response->assertRedirect();
        $this->assertStringContainsString('/projects', (string) $response->headers->get('Location'));
        $this->assertAuthenticatedAs($user);

        $social->refresh();
        $this->assertTrue((bool) $social->is_active);
        $this->assertNull($social->unlinked_at);
        $this->assertNotNull($social->last_used_at);
    }

    public function test_guest_login_with_existing_local_account_redirects_to_controlled_flow(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'existing.vk@example.com',
            'registration_completed_at' => now(),
        ]);

        $service = Mockery::mock(VkAuthService::class)->makePartial();
        $service->shouldReceive('exchangeCode')->andReturn(['access_token' => 'token']);
        $service->shouldReceive('getUserProfile')->andReturn([
            'user' => [
                'user_id' => 'vk-unlinked-2',
                'email' => 'existing.vk@example.com',
            ],
        ]);
        $this->app->instance(VkAuthService::class, $service);

        $response = $this->withSession([
            'vk_oauth_state' => 'valid-state',
            'vk_oauth_code_verifier' => 'verifier',
        ])->get('/api/auth/vk/callback?state=valid-state&code=test-code&device_id=device-1');

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('error=oauth_link_required', $location);
        $this->assertStringContainsString('provider=vk', $location);
        $this->assertGuest();
        $this->assertDatabaseMissing('social_accounts', [
            'provider' => 'vk',
            'provider_user_id' => 'vk-unlinked-2',
            'user_id' => $existingUser->id,
        ]);
    }

    public function test_authenticated_link_conflict_redirects_safely_without_switching_user(): void
    {
        $currentUser = User::factory()->create(['registration_completed_at' => now()]);
        $otherUser = User::factory()->create(['registration_completed_at' => now()]);

        SocialAccount::create([
            'user_id' => $otherUser->id,
            'provider' => 'vk',
            'provider_user_id' => 'vk-conflict-1',
            'is_active' => true,
            'linked_at' => now(),
        ]);

        $service = Mockery::mock(VkAuthService::class)->makePartial();
        $service->shouldReceive('exchangeCode')->andReturn(['access_token' => 'token']);
        $service->shouldReceive('getUserProfile')->andReturn([
            'user' => ['user_id' => 'vk-conflict-1'],
        ]);
        $this->app->instance(VkAuthService::class, $service);

        $response = $this->actingAs($currentUser)
            ->withSession([
                'vk_oauth_context' => [
                    'state' => 'valid-state',
                    'code_verifier' => 'verifier',
                    'intent' => 'link',
                    'provider' => 'vk',
                    'user_id' => $currentUser->id,
                ],
            ])
            ->get('/api/auth/vk/callback?state=valid-state&code=test-code&device_id=device-1');

        $response->assertRedirect();
        $this->assertStringContainsString('oauth_link=already_linked_to_other_user', (string) $response->headers->get('Location'));
        $this->assertAuthenticatedAs($currentUser);
    }

    public function test_callback_redirects_with_server_error_on_unhandled_exception(): void
    {
        $service = Mockery::mock(VkAuthService::class);
        $service->shouldReceive('exchangeCode')->andReturn(['access_token' => 'token']);
        $service->shouldReceive('getUserProfile')->andReturn(['user' => ['user_id' => 'vk-err-1']]);
        $service->shouldReceive('findOrCreateUser')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(VkAuthService::class, $service);

        $response = $this->withSession([
            'vk_oauth_state' => 'valid-state',
            'vk_oauth_code_verifier' => 'verifier',
        ])->get('/api/auth/vk/callback?state=valid-state&code=test-code&device_id=device-1');

        $response->assertRedirect();
        $this->assertStringContainsString('error=oauth_server_error', (string) $response->headers->get('Location'));
        $this->assertGuest();
    }
}
