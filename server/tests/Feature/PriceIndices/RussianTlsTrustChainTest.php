<?php

namespace Tests\Feature\PriceIndices;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RussianTlsTrustChainTest extends TestCase
{
    public function test_runtime_uses_current_russian_trusted_sub_ca_rsa_2024(): void
    {
        $rootPem = File::get($this->certificatePath('russian-trusted-root-ca.crt'));
        $subPem = File::get($this->certificatePath('russian-trusted-sub-ca.crt'));
        $root = openssl_x509_parse($rootPem);
        $sub = openssl_x509_parse($subPem);

        $this->assertIsArray($root);
        $this->assertIsArray($sub);
        $this->assertSame('Russian Trusted Root CA', $root['subject']['CN']);
        $this->assertSame('Russian Trusted Sub CA', $sub['subject']['CN']);
        $this->assertSame('Russian Trusted Root CA', $sub['issuer']['CN']);
        $this->assertSame('1005', $sub['serialNumberHex']);
        $this->assertSame('240715125041Z', $sub['validFrom']);
        $this->assertSame('290719125041Z', $sub['validTo']);
        $this->assertSame(
            '77:3D:D9:39:AF:42:BD:DC:5B:CA:76:EA:EE:FD:CE:3E:61:29:30:5F',
            $sub['extensions']['subjectKeyIdentifier'],
        );
        $this->assertNotSame(
            'D1:E1:71:0D:0B:2D:81:4E:6E:8A:4A:8F:4C:23:B3:4C:5E:AB:69:0B',
            $sub['extensions']['subjectKeyIdentifier'],
        );

        $rootPublicKey = openssl_pkey_get_public($rootPem);
        $this->assertNotFalse($rootPublicKey);

        if ($rootPublicKey !== false) {
            $this->assertSame(1, openssl_x509_verify($subPem, $rootPublicKey));
        }

        $this->assertSame(
            'D26D2D0231B7C39F92CC738512BA54103519E4405D68B5BD703E9788CA8ECF31',
            $this->derSha256($rootPem),
        );
        $this->assertSame(
            '2155785036C900DBB5F1BB2A1569C80C55595BD6BF94867A29BBDDBC7D88A3F2',
            $this->derSha256($subPem),
        );
    }

    private function certificatePath(string $filename): string
    {
        $runtimePath = '/usr/local/share/ca-certificates/'.$filename;

        return is_file($runtimePath)
            ? $runtimePath
            : dirname(base_path()).'/docker/certificates/'.$filename;
    }

    private function derSha256(string $pem): string
    {
        $der = base64_decode(
            preg_replace('~-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+~', '', $pem),
            true,
        );

        return strtoupper(hash('sha256', $der));
    }
}
