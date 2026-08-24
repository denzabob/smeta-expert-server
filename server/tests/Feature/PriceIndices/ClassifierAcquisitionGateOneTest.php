<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\ResolveTrustedStatisticalClassifier;
use App\Domain\PriceIndices\Application\Services\TrustedClassifierDescriptorRegistry;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;
use App\Domain\PriceIndices\Infrastructure\Http\ClassifierUrlPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClassifierAcquisitionGateOneTest extends TestCase
{
    use DatabaseTransactions;

    public function test_okpd2_trusted_descriptor_has_canonical_identity_and_source(): void
    {
        $descriptor = app(TrustedClassifierDescriptorRegistry::class)->get('okpd2');

        $this->assertSame('okpd2', $descriptor->code);
        $this->assertSame('ОК 034-2014 (КПЕС 2008)', $descriptor->standardCode);
        $this->assertSame('Общероссийский классификатор продукции по видам экономической деятельности', $descriptor->name);
        $this->assertSame('Росстандарт', $descriptor->issuingAuthority);
        $this->assertSame('Росстат', $descriptor->officialDistributor);
        $this->assertSame('https://rosstat.gov.ru/classification', $descriptor->sourcePageUrl);
        $this->assertSame('https://rosstat.gov.ru/storage/mediabank/OKPD2.zip', $descriptor->downloadUrl);
        $this->assertSame(['rosstat.gov.ru'], $descriptor->allowedHosts);
        $this->assertSame('zip', $descriptor->artifactType);
    }

    public function test_unknown_descriptor_and_arbitrary_url_are_rejected(): void
    {
        $registry = app(TrustedClassifierDescriptorRegistry::class);

        foreach (['unknown', 'https://example.com/file.zip'] as $input) {
            try {
                $registry->get($input);
                $this->fail("Input [{$input}] should have been rejected.");
            } catch (ClassifierAcquisitionException $exception) {
                $this->assertSame('classifier_not_supported', $exception->errorCode);
            }
        }
    }

    public function test_classifier_is_created_and_reused_from_trusted_identity(): void
    {
        $descriptor = app(TrustedClassifierDescriptorRegistry::class)->get('okpd2');
        $resolver = app(ResolveTrustedStatisticalClassifier::class);

        $first = $resolver->resolve($descriptor);
        $second = $resolver->resolve($descriptor);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, StatisticalClassifier::query()->where('code', 'okpd2')->count());
        $this->assertSame($descriptor->classifierIdentity(), $first->only(array_keys($descriptor->classifierIdentity())));
    }

    public function test_conflicting_classifier_identity_fails_closed_without_update(): void
    {
        $descriptor = app(TrustedClassifierDescriptorRegistry::class)->get('okpd2');
        $classifier = StatisticalClassifier::factory()->create([
            'code' => 'okpd2',
            ...$descriptor->classifierIdentity(),
            'name' => 'Conflicting identity',
        ]);

        try {
            app(ResolveTrustedStatisticalClassifier::class)->resolve($descriptor);
            $this->fail('Identity conflict should have failed closed.');
        } catch (ClassifierAcquisitionException $exception) {
            $this->assertSame('classifier_identity_conflict', $exception->errorCode);
        }

        $this->assertSame('Conflicting identity', $classifier->fresh()->name);
    }

    public function test_official_https_and_same_host_redirects_are_accepted(): void
    {
        $policy = app(ClassifierUrlPolicy::class);
        $initial = $policy->validate('https://rosstat.gov.ru/storage/OKPD2.zip', ['rosstat.gov.ru']);

        $relative = $policy->resolveRedirect(
            $initial,
            '../mediabank/OKPD2.zip',
            ['rosstat.gov.ru'],
            [$initial],
            0,
            5,
        );
        $absolute = $policy->resolveRedirect(
            $relative,
            'https://rosstat.gov.ru/final/OKPD2.zip',
            ['rosstat.gov.ru'],
            [$initial, $relative],
            1,
            5,
        );

        $this->assertSame('https://rosstat.gov.ru/mediabank/OKPD2.zip', $relative);
        $this->assertSame('https://rosstat.gov.ru/final/OKPD2.zip', $absolute);
    }

    #[DataProvider('rejectedUrlProvider')]
    public function test_initial_and_redirect_url_policy_rejects_unsafe_targets(string $url): void
    {
        $this->expectException(ClassifierAcquisitionException::class);

        app(ClassifierUrlPolicy::class)->validate($url, ['rosstat.gov.ru']);
    }

    /** @return array<string, array{string}> */
    public static function rejectedUrlProvider(): array
    {
        return [
            'http' => ['http://rosstat.gov.ru/file.zip'],
            'wrong host' => ['https://example.com/file.zip'],
            'userinfo' => ['https://user:pass@rosstat.gov.ru/file.zip'],
            'explicit port' => ['https://rosstat.gov.ru:443/file.zip'],
            'ip' => ['https://127.0.0.1/file.zip'],
            'localhost' => ['https://localhost/file.zip'],
        ];
    }

    public function test_redirect_downgrade_wrong_host_ip_loop_and_limit_are_rejected(): void
    {
        $policy = app(ClassifierUrlPolicy::class);
        $initial = 'https://rosstat.gov.ru/file.zip';

        foreach (['http://rosstat.gov.ru/file.zip', 'https://example.com/file.zip', 'https://127.0.0.1/file.zip'] as $location) {
            try {
                $policy->resolveRedirect($initial, $location, ['rosstat.gov.ru'], [$initial], 0, 5);
                $this->fail("Redirect [{$location}] should have been rejected.");
            } catch (ClassifierAcquisitionException $exception) {
                $this->assertSame('url_policy_rejected', $exception->errorCode);
            }
        }

        try {
            $policy->resolveRedirect($initial, '/file.zip', ['rosstat.gov.ru'], [$initial], 0, 5);
            $this->fail('Redirect loop should have been rejected.');
        } catch (ClassifierAcquisitionException $exception) {
            $this->assertSame('redirect_loop', $exception->errorCode);
        }

        try {
            $policy->resolveRedirect($initial, '/next.zip', ['rosstat.gov.ru'], [$initial], 5, 5);
            $this->fail('Redirect limit should have been rejected.');
        } catch (ClassifierAcquisitionException $exception) {
            $this->assertSame('redirect_limit_exceeded', $exception->errorCode);
        }
    }
}
