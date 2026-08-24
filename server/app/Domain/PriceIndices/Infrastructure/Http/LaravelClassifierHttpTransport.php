<?php

namespace App\Domain\PriceIndices\Infrastructure\Http;

use App\Domain\PriceIndices\Application\Contracts\ClassifierHttpTransport;
use App\Domain\PriceIndices\Application\Data\ClassifierHttpResponse;
use App\Domain\PriceIndices\Application\Data\TrustedClassifierDescriptor;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class LaravelClassifierHttpTransport implements ClassifierHttpTransport
{
    public function get(string $url, TrustedClassifierDescriptor $descriptor): ClassifierHttpResponse
    {
        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'connect_timeout' => $descriptor->connectTimeoutSeconds,
                'http_errors' => false,
                'stream' => true,
                'timeout' => $descriptor->timeoutSeconds,
                'verify' => true,
            ])->withHeaders([
                'Accept' => 'application/zip, application/octet-stream;q=0.8',
            ])->get($url);
        } catch (ConnectionException $exception) {
            throw new ClassifierAcquisitionException(
                'transport_failure',
                'Strict HTTPS classifier acquisition failed: '.$exception->getMessage(),
                $exception,
            );
        }

        $psrResponse = $response->toPsrResponse();

        return new ClassifierHttpResponse(
            status: $psrResponse->getStatusCode(),
            headers: $psrResponse->getHeaders(),
            body: $psrResponse->getBody(),
        );
    }
}
