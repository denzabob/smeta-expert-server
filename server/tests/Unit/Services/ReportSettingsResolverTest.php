<?php

namespace Tests\Unit\Services;

use App\Services\Reports\ReportSettingsResolver;
use Tests\TestCase;

class ReportSettingsResolverTest extends TestCase
{
    public function testItMergesPartialReportSettingsWithDefaults(): void
    {
        $resolver = new ReportSettingsResolver();

        $settings = $resolver->normalize([
            'estimate_report' => [
                'title' => 'Расчет восстановительной стоимости',
            ],
        ]);

        $this->assertSame('Расчет восстановительной стоимости', $settings['estimate_report']['title']);
        $this->assertSame('Приложение № 1', $settings['estimate_report']['appendix_label']);
        $this->assertSame('Эксперт', $settings['common']['executor_label']);
    }

    public function testItDoesNotAcceptBrandingControlsFromRegularReportSettings(): void
    {
        $resolver = new ReportSettingsResolver();

        $settings = $resolver->normalize([
            'common' => [
                'system_header' => 'Другая система',
                'show_system_header' => false,
                'show_verification_block' => false,
                'executor_label' => 'Специалист',
            ],
        ]);

        $this->assertSame('Специалист', $settings['common']['executor_label']);
        $this->assertArrayNotHasKey('system_header', $settings['common']);
        $this->assertArrayNotHasKey('show_system_header', $settings['common']);
        $this->assertArrayNotHasKey('show_verification_block', $settings['common']);
    }
}
