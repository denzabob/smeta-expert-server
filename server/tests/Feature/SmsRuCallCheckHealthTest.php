<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsRuCallCheckHealthTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://localhost']);
    }

    public function test_health_requires_auth(): void
    {
        $this->getJson('/api/auth/phone/callcheck/health')
            ->assertStatus(401);
    }

    public function test_health_returns_config_state(): void
    {
        config([
            'app.url' => 'https://app.prismcore.ru',
            'verification.sms_ru.enabled' => true,
            'verification.sms_ru.api_id' => 'api-id',
            'verification.sms_ru.timeout' => 15,
            'verification.sms_ru.webhook.enabled' => true,
            'verification.sms_ru.webhook.token' => 'webhook-token',
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/auth/phone/callcheck/health');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('provider', 'sms_ru_callcheck')
            ->assertJsonPath('config.sms_ru_enabled', true)
            ->assertJsonPath('config.api_id_present', true)
            ->assertJsonPath('config.webhook_token_present', true)
            ->assertJsonPath('webhook.url', 'https://app.prismcore.ru/api/auth/phone/callcheck/webhook');
    }

    public function test_health_probe_returns_connectivity_data(): void
    {
        config([
            'verification.sms_ru.enabled' => true,
            'verification.sms_ru.api_id' => 'api-id',
            'verification.sms_ru.timeout' => 10,
        ]);

        Http::fake([
            'https://sms.ru/callcheck/status*' => Http::response([
                'status' => 'OK',
                'status_code' => 100,
                'check_status' => '402',
                'status_text' => 'Истекло время',
            ], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/auth/phone/callcheck/health?probe=1');

        $response->assertOk()
            ->assertJsonPath('probe.attempted', true)
            ->assertJsonPath('probe.success', true)
            ->assertJsonPath('probe.http_status', 200)
            ->assertJsonPath('probe.provider_status_code', 100);
    }
}
