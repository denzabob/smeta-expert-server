<?php

declare(strict_types=1);

namespace App\Services\Expert;

final class ExpertMaterialIdentityTextBuilder
{
    public function build(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $max = max(1, (int) config('expert.context.identity.routing_text_max_chars', 5000));
        $length = mb_strlen($text, 'UTF-8');
        if ($length <= $max) {
            return $text;
        }
        if ($max < 8) {
            return mb_substr($text, 0, $max, 'UTF-8');
        }

        $separator = ' ';
        $available = max(1, $max - 2 * mb_strlen($separator, 'UTF-8'));
        $head = (int) floor($available * 0.5);
        $middle = (int) floor($available * 0.25);
        $tail = $available - $head - $middle;
        $result = mb_substr($text, 0, $head, 'UTF-8')
            .$separator.mb_substr($text, (int) floor(($length - $middle) / 2), $middle, 'UTF-8')
            .$separator.mb_substr($text, -$tail, null, 'UTF-8');

        return mb_substr($result, 0, $max, 'UTF-8');
    }
}
