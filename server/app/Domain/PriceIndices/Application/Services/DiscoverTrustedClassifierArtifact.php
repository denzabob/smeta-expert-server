<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Contracts\ClassifierHttpTransport;
use App\Domain\PriceIndices\Application\Data\ClassifierHttpResponse;
use App\Domain\PriceIndices\Application\Data\DiscoveredClassifierArtifact;
use App\Domain\PriceIndices\Application\Data\TrustedClassifierDescriptor;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;
use App\Domain\PriceIndices\Infrastructure\Http\ClassifierResponseMetadata;
use App\Domain\PriceIndices\Infrastructure\Http\ClassifierUrlPolicy;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

class DiscoverTrustedClassifierArtifact
{
    /** @var list<int> */
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /** @var list<string> */
    private const ARCHIVE_TYPES = ['zip', 'rar'];

    public function __construct(
        private readonly ClassifierHttpTransport $transport,
        private readonly ClassifierUrlPolicy $urlPolicy,
        private readonly ClassifierResponseMetadata $metadata,
    ) {}

    public function discover(TrustedClassifierDescriptor $descriptor): DiscoveredClassifierArtifact
    {
        $url = $this->urlPolicy->validate($descriptor->sourcePageUrl, $descriptor->allowedHosts);
        $visited = [$url];
        $redirectCount = 0;

        while (true) {
            $response = $this->transport->get($url, $descriptor);

            if (in_array($response->status, self::REDIRECT_STATUSES, true)) {
                try {
                    $url = $this->urlPolicy->resolveRedirect(
                        $url,
                        $this->metadata->location($response),
                        $descriptor->allowedHosts,
                        $visited,
                        $redirectCount,
                        $descriptor->maxRedirects,
                    );
                } finally {
                    $response->close();
                }

                $visited[] = $url;
                $redirectCount++;

                continue;
            }

            if ($response->status !== 200) {
                $response->close();

                throw new ClassifierAcquisitionException(
                    'source_page_http_status',
                    "Classifier source page returned unexpected HTTP status [{$response->status}].",
                );
            }

            return $this->parsePage($descriptor, $url, $response);
        }
    }

    private function parsePage(
        TrustedClassifierDescriptor $descriptor,
        string $pageUrl,
        ClassifierHttpResponse $response,
    ): DiscoveredClassifierArtifact {
        try {
            if ($this->metadata->mimeType($response) !== 'text/html') {
                throw new ClassifierAcquisitionException(
                    'invalid_source_page_content_type',
                    'The trusted classifier source page is not HTML.',
                );
            }

            $contentLength = $this->metadata->contentLength($response);
            $maxBytes = (int) config('price_indices.classifier_acquisition.source_page_max_bytes', 2_097_152);

            if ($maxBytes < 1 || ($contentLength !== null && $contentLength > $maxBytes)) {
                throw new ClassifierAcquisitionException(
                    'source_page_too_large',
                    'The trusted classifier source page exceeds the configured size limit.',
                );
            }

            $html = '';

            while (! $response->body->eof()) {
                $chunk = $response->body->read(65_536);

                if ($chunk === '') {
                    if ($response->body->eof()) {
                        break;
                    }

                    throw new ClassifierAcquisitionException(
                        'source_page_read_failure',
                        'The trusted classifier source page stream ended unexpectedly.',
                    );
                }

                $html .= $chunk;

                if (strlen($html) > $maxBytes) {
                    throw new ClassifierAcquisitionException(
                        'source_page_too_large',
                        'The trusted classifier source page exceeds the configured size limit.',
                    );
                }
            }

            if ($contentLength !== null && $contentLength !== strlen($html)) {
                throw new ClassifierAcquisitionException(
                    'source_page_read_failure',
                    'The trusted classifier source page bytes do not match Content-Length.',
                );
            }

            return $this->discoverFromHtml($descriptor, $pageUrl, $html);
        } catch (ClassifierAcquisitionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ClassifierAcquisitionException(
                'source_page_parse_failure',
                'The trusted classifier source page could not be parsed safely.',
                $exception,
            );
        } finally {
            $response->close();
        }
    }

    private function discoverFromHtml(
        TrustedClassifierDescriptor $descriptor,
        string $pageUrl,
        string $html,
    ): DiscoveredClassifierArtifact {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;

        try {
            if (@$document->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            ) !== true) {
                throw new ClassifierAcquisitionException(
                    'source_page_parse_failure',
                    'The trusted classifier source page is not valid HTML.',
                );
            }

            $xpath = new DOMXPath($document);
            $heading = $this->findClassifierHeading($xpath, $descriptor);

            if ($heading === null) {
                throw new ClassifierAcquisitionException(
                    'classifier_source_section_missing',
                    'The trusted classifier source page does not contain the expected classifier section.',
                );
            }

            $section = $this->closestToggleSection($heading);

            if (! $section instanceof DOMElement) {
                throw new ClassifierAcquisitionException(
                    'classifier_source_section_missing',
                    'The expected classifier heading is not attached to a source section.',
                );
            }

            $anchors = $xpath->query('.//a[@href]', $section);
            $candidates = [];

            if ($anchors === false) {
                throw new ClassifierAcquisitionException(
                    'source_page_parse_failure',
                    'The trusted classifier source page links could not be inspected.',
                );
            }

            foreach ($anchors as $anchor) {
                if (! $anchor instanceof DOMElement) {
                    continue;
                }

                $href = trim((string) $anchor->getAttribute('href'));
                $resolved = $this->resolveLink($pageUrl, $href, $descriptor);
                $path = (string) parse_url($resolved, PHP_URL_PATH);
                $artifactType = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                if (! in_array($artifactType, self::ARCHIVE_TYPES, true)
                    || $artifactType !== strtolower($descriptor->artifactType)
                ) {
                    continue;
                }

                $item = $this->closestElementWithClass($anchor, 'document-list__item');
                $itemText = $item?->textContent ?? $anchor->textContent ?? '';
                $candidates[] = [
                    'url' => $resolved,
                    'artifact_type' => $artifactType,
                    'version_label' => $this->versionLabel($itemText),
                    'publication_date' => $this->publicationDate($itemText),
                    'section_title' => $this->cleanText($heading->textContent),
                ];
            }

            if (count($candidates) !== 1) {
                throw new ClassifierAcquisitionException(
                    'classifier_source_discovery_ambiguous',
                    'The expected classifier section does not contain exactly one trusted artifact candidate.',
                );
            }

            $candidate = $candidates[0];

            return new DiscoveredClassifierArtifact(
                url: $candidate['url'],
                artifactType: $candidate['artifact_type'],
                originalFilename: $descriptor->originalFilename,
                versionLabel: $candidate['version_label'],
                publicationDate: $candidate['publication_date'],
                sectionTitle: $candidate['section_title'],
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function findClassifierHeading(DOMXPath $xpath, TrustedClassifierDescriptor $descriptor): ?DOMElement
    {
        $headings = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');

        if ($headings === false) {
            return null;
        }

        $matches = [];
        $expectedName = $this->normalizedText($descriptor->name);
        $expectedStandard = $this->normalizedText($descriptor->standardCode);

        foreach ($headings as $heading) {
            if (! $heading instanceof DOMElement) {
                continue;
            }

            $text = $this->normalizedText($heading->textContent);

            if (str_contains($text, $expectedName) && str_contains($text, $expectedStandard)) {
                $matches[] = $heading;
            }
        }

        if (count($matches) > 1) {
            throw new ClassifierAcquisitionException(
                'classifier_source_discovery_ambiguous',
                'The trusted classifier source page contains multiple matching classifier sections.',
            );
        }

        return $matches[0] ?? null;
    }

    private function closestToggleSection(DOMNode $node): ?DOMElement
    {
        while ($node instanceof DOMNode) {
            if ($node instanceof DOMElement && $this->hasClass($node, 'toggle-section')) {
                return $node;
            }

            $node = $node->parentNode;
        }

        return null;
    }

    private function closestElementWithClass(DOMNode $node, string $class): ?DOMElement
    {
        while ($node instanceof DOMNode) {
            if ($node instanceof DOMElement && $this->hasClass($node, $class)) {
                return $node;
            }

            $node = $node->parentNode;
        }

        return null;
    }

    private function hasClass(DOMElement $element, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [], true);
    }

    private function resolveLink(string $pageUrl, string $href, TrustedClassifierDescriptor $descriptor): string
    {
        if ($href === '') {
            throw new ClassifierAcquisitionException(
                'classifier_source_discovery_ambiguous',
                'The trusted classifier section contains an empty artifact link.',
            );
        }

        try {
            $resolved = (string) UriResolver::resolve(new Uri($pageUrl), new Uri($href));
        } catch (\InvalidArgumentException $exception) {
            throw new ClassifierAcquisitionException(
                'classifier_source_discovery_ambiguous',
                'The trusted classifier section contains an invalid artifact link.',
                $exception,
            );
        }

        return $this->urlPolicy->validate($resolved, $descriptor->allowedHosts);
    }

    private function normalizedText(?string $text): string
    {
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        return mb_strtolower(str_replace('ё', 'е', $text), 'UTF-8');
    }

    private function versionLabel(string $text): ?string
    {
        $normalized = $this->normalizedText($text);

        return preg_match_all('/\b(\d{1,3}\/20\d{2})\b/u', $normalized, $matches) > 0
            ? end($matches[1])
            : null;
    }

    private function cleanText(?string $text): string
    {
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    }

    private function publicationDate(string $text): ?DateTimeImmutable
    {
        $normalized = $this->normalizedText($text);

        if (preg_match('/\b(\d{2}\.\d{2}\.20\d{2})\b/u', $normalized, $matches) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!d.m.Y',
            $matches[1],
            new DateTimeZone('UTC'),
        );

        return $date !== false ? $date : null;
    }
}
