<?php

namespace App\Domain\PriceIndices\Infrastructure\Http;

use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

class ClassifierUrlPolicy
{
    /** @param list<string> $allowedHosts */
    public function validate(string $url, array $allowedHosts): string
    {
        if ($url === '' || strlen($url) > 2_048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            $this->reject('URL is empty, too long, or contains unsafe characters.');
        }

        $parsed = parse_url($url);

        if (! is_array($parsed)) {
            $this->reject('URL is invalid.');
        }

        try {
            $uri = new Uri($url);
        } catch (\InvalidArgumentException $exception) {
            throw new ClassifierAcquisitionException('url_policy_rejected', 'URL is invalid.', $exception);
        }

        if (strtolower($uri->getScheme()) !== 'https') {
            $this->reject('Only HTTPS classifier URLs are allowed.');
        }

        if ($uri->getUserInfo() !== '') {
            $this->reject('Classifier URLs must not contain user information.');
        }

        if (array_key_exists('port', $parsed)) {
            $this->reject('Classifier URLs must not contain an explicit port.');
        }

        $host = strtolower($uri->getHost());
        $normalizedAllowedHosts = array_map(fn (string $allowed): string => strtolower($allowed), $allowedHosts);

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || $host === 'localhost') {
            $this->reject('Classifier URL host must be an allowed public hostname.');
        }

        if (! in_array($host, $normalizedAllowedHosts, true)) {
            $this->reject('Classifier URL host is not allowed.');
        }

        if ($uri->getFragment() !== '') {
            $this->reject('Classifier URLs must not contain a fragment.');
        }

        return (string) $uri->withScheme('https')->withHost($host);
    }

    /**
     * @param  list<string>  $allowedHosts
     * @param  list<string>  $visitedUrls
     */
    public function resolveRedirect(
        string $currentUrl,
        string $location,
        array $allowedHosts,
        array $visitedUrls,
        int $redirectCount,
        int $maxRedirects,
    ): string {
        if ($redirectCount >= $maxRedirects) {
            throw new ClassifierAcquisitionException(
                'redirect_limit_exceeded',
                'Classifier download exceeded the configured redirect limit.'
            );
        }

        if ($location === '' || strlen($location) > 2_048 || preg_match('/[\x00-\x1f\x7f]/', $location) === 1) {
            $this->reject('Redirect Location is empty, too long, or contains unsafe characters.');
        }

        try {
            $resolved = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));
        } catch (\InvalidArgumentException $exception) {
            throw new ClassifierAcquisitionException('url_policy_rejected', 'Redirect Location is invalid.', $exception);
        }

        $validated = $this->validate($resolved, $allowedHosts);

        if (in_array($validated, $visitedUrls, true)) {
            throw new ClassifierAcquisitionException('redirect_loop', 'Classifier download redirect loop detected.');
        }

        return $validated;
    }

    private function reject(string $message): never
    {
        throw new ClassifierAcquisitionException('url_policy_rejected', $message);
    }
}
