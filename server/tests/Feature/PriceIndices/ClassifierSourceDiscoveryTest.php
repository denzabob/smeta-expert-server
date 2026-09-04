<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Contracts\ClassifierHttpTransport;
use App\Domain\PriceIndices\Application\Data\ClassifierHttpResponse;
use App\Domain\PriceIndices\Application\Data\TrustedClassifierDescriptor;
use App\Domain\PriceIndices\Application\Services\DiscoverTrustedClassifierArtifact;
use App\Domain\PriceIndices\Application\Services\TrustedClassifierDescriptorRegistry;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ClassifierSourceDiscoveryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_discovery_selects_the_current_rar_and_captures_publication_metadata(): void
    {
        $html = $this->pageHtml('<a href="/storage/mediabank/OKPD2.rar">Скачать</a>');
        $transport = new DiscoveryFakeClassifierTransport([
            $this->response(200, $html, ['Content-Type' => ['text/html'], 'Content-Length' => [(string) strlen($html)]]),
        ]);
        $this->app->instance(ClassifierHttpTransport::class, $transport);

        $artifact = app(DiscoverTrustedClassifierArtifact::class)->discover($this->descriptor());

        $this->assertSame('https://rosstat.gov.ru/storage/mediabank/OKPD2.rar', $artifact->url);
        $this->assertSame('rar', $artifact->artifactType);
        $this->assertSame('OKPD2.rar', $artifact->originalFilename);
        $this->assertSame('148/2026', $artifact->versionLabel);
        $this->assertSame('2026-09-02', $artifact->publicationDate?->format('Y-m-d'));
        $this->assertSame(1, count($transport->requestedUrls));
    }

    public function test_discovery_rejects_missing_section_and_ambiguous_artifacts(): void
    {
        $missing = '<h2>ОКВЭД2 ОК 029-2014</h2><a href="/storage/mediabank/OKPD2.rar">RAR</a>';
        $this->assertDiscoveryError('classifier_source_section_missing', $missing);

        $ambiguous = $this->pageHtml(
            '<a href="/storage/mediabank/OKPD2.rar">Первый</a><a href="/storage/mediabank/OKPD2-new.rar">Второй</a>',
        );
        $this->assertDiscoveryError('classifier_source_discovery_ambiguous', $ambiguous);
    }

    public function test_discovery_follows_only_same_host_https_redirects(): void
    {
        $this->app->instance(ClassifierHttpTransport::class, new DiscoveryFakeClassifierTransport([
            $this->response(302, '', ['Location' => ['https://example.com/classification']]),
        ]));

        try {
            app(DiscoverTrustedClassifierArtifact::class)->discover($this->descriptor());
            $this->fail('An unsafe source page redirect was accepted.');
        } catch (ClassifierAcquisitionException $exception) {
            $this->assertSame('url_policy_rejected', $exception->errorCode);
        }
    }

    private function descriptor(): TrustedClassifierDescriptor
    {
        return app(TrustedClassifierDescriptorRegistry::class)->get('okpd2');
    }

    private function pageHtml(string $links): string
    {
        return '<section class="toggle-section"><h2>Общероссийский классификатор продукции по видам экономической деятельности ОК 034-2014 (КПЕС 2008)</h2><div class="document-list__item">ОКПД2 (с учетом изменений с 1/2015 по 148/2026), 1.01 Мб, 02.09.2026 '.$links.'</div></section>';
    }

    private function response(int $status, string $body, array $headers): ClassifierHttpResponse
    {
        return new ClassifierHttpResponse($status, $headers, Utils::streamFor($body));
    }

    private function assertDiscoveryError(string $expectedCode, string $html): void
    {
        $this->app->instance(ClassifierHttpTransport::class, new DiscoveryFakeClassifierTransport([
            $this->response(200, $html, ['Content-Type' => ['text/html'], 'Content-Length' => [(string) strlen($html)]]),
        ]));

        try {
            app(DiscoverTrustedClassifierArtifact::class)->discover($this->descriptor());
            $this->fail("Discovery error [{$expectedCode}] was not raised.");
        } catch (ClassifierAcquisitionException $exception) {
            $this->assertSame($expectedCode, $exception->errorCode);
        }
    }
}

class DiscoveryFakeClassifierTransport implements ClassifierHttpTransport
{
    /** @var list<ClassifierHttpResponse> */
    private array $responses;

    /** @var list<string> */
    public array $requestedUrls = [];

    /** @param list<ClassifierHttpResponse> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function get(string $url, TrustedClassifierDescriptor $descriptor): ClassifierHttpResponse
    {
        $this->requestedUrls[] = $url;
        $response = array_shift($this->responses);

        if (! $response instanceof ClassifierHttpResponse) {
            throw new \LogicException('No fake classifier source page response remains.');
        }

        return $response;
    }
}
