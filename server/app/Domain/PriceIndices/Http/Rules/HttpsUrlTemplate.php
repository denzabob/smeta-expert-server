<?php

namespace App\Domain\PriceIndices\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class HttpsUrlTemplate implements ValidationRule
{
    /** @var list<string> */
    private const TOKENS = [
        '{month}',
        '{month2}',
        '{year}',
        '{previous_month}',
        '{previous_year}',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a valid HTTPS URL template.');

            return;
        }

        preg_match_all('/\{[^{}]*\}/', $value, $matches);
        $tokens = $matches[0] ?? [];

        foreach ($tokens as $token) {
            if (! in_array($token, self::TOKENS, true)) {
                $fail('The :attribute contains an unsupported template token.');

                return;
            }
        }

        $withoutKnownTokens = str_replace(self::TOKENS, '', $value);

        if (str_contains($withoutKnownTokens, '{') || str_contains($withoutKnownTokens, '}')) {
            $fail('The :attribute contains an invalid template expression.');

            return;
        }

        $rendered = str_replace(
            self::TOKENS,
            ['8', '08', '2026', '7', '2025'],
            $value
        );
        $parts = parse_url($rendered);

        if (filter_var($rendered, FILTER_VALIDATE_URL) === false
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
        ) {
            $fail('The :attribute must resolve to a valid HTTPS URL.');
        }
    }
}
