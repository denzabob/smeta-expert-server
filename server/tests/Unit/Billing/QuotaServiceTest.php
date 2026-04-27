<?php

namespace Tests\Unit\Billing;

use App\Models\User;
use App\Services\Billing\BillingCodes;
use App\Services\Billing\QuotaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class QuotaServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_limits_disabled_always_allows(): void
    {
        config(['billing.enforce_limits' => false]);

        $user = User::factory()->create();
        $result = app(QuotaService::class)->check($user, BillingCodes::METRIC_PDF_SMETA_GENERATED, 10);

        $this->assertTrue($result->allowed);
        $this->assertFalse($result->enforced);
        $this->assertSame('enforcement_disabled', $result->reason);
    }
}
