<?php

declare(strict_types=1);

namespace App\Services\Expert;

/** Generic lexical signals for discovery and ranking; never evidence. */
final class ExpertContextLexicalMatcher
{
    private const STOP_WORDS = [
        'что', 'где', 'когда', 'какой', 'какая', 'какие', 'каков', 'кто',
        'этот', 'этом', 'этими', 'этих', 'данное', 'данном', 'предыдущий',
        'первый', 'второй', 'последний', 'только', 'кроме', 'между',
        'указано', 'указал', 'указаны', 'сказано', 'сказал', 'были',
        'поставлены', 'сравни', 'связано', 'проанализируй',
        'материалах', 'материалов', 'проекта', 'документах', 'документы',
        'файлах', 'файлы', 'приложенном', 'приложенные',
    ];

    public static function normalize(string $value): string
    {
        $value = mb_strtolower(str_replace(['Ё', 'ё'], 'е', $value), 'UTF-8');

        return trim(preg_replace('/[^\p{L}\p{N}.]+/u', ' ', $value) ?? '');
    }

    /** @return list<string> */
    public static function tokens(string $value): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', self::normalize($value)) ?: [];

        return array_values(array_unique(array_filter($parts, static fn (string $word): bool => mb_strlen($word, 'UTF-8') >= 4 && ! in_array($word, self::STOP_WORDS, true))));
    }

    public static function containsPhrase(string $query, string $phrase): bool
    {
        $phrase = self::normalize($phrase);
        if ($phrase === '') {
            return false;
        }

        return preg_match('/(?:^|[^\p{L}\p{N}])'.preg_quote($phrase, '/').'(?:$|[^\p{L}\p{N}])/u', self::normalize($query)) === 1;
    }

    public static function exactAlias(ExpertContextCandidate $candidate, string $query): bool
    {
        $aliases = $candidate->identity['aliases'] ?? [];
        foreach (array_unique([$candidate->name, ...(is_array($aliases) ? $aliases : [])]) as $alias) {
            if (is_string($alias) && mb_strlen(self::normalize($alias), 'UTF-8') >= 4
                && self::containsPhrase($query, $alias)) {
                return true;
            }
        }

        return false;
    }

    public static function discoveryFragment(string $token): string
    {
        return preg_match('/^[а-я]+$/u', $token) === 1 && mb_strlen($token, 'UTF-8') >= 6
            ? mb_substr($token, 0, -2, 'UTF-8')
            : $token;
    }

    public static function score(ExpertContextCandidate $candidate, string $query): int
    {
        $tokens = self::tokens($query);
        if ($tokens === []) {
            return 0;
        }
        $aliases = $candidate->identity['aliases'] ?? [];
        $name = self::normalize(implode(' ', array_filter([$candidate->name, ...(is_array($aliases) ? $aliases : [])], 'is_string')));
        $routing = self::normalize((string) ($candidate->identity['routing_text'] ?? ''));
        $score = 0;
        foreach ($tokens as $token) {
            $fragment = self::discoveryFragment($token);
            if (self::containsPhrase($name, $token)) {
                $score += 4;
            } elseif (str_contains($name, $token)) {
                $score += 2;
            } elseif ($fragment !== $token && str_contains($name, $fragment)) {
                $score += 2;
            } elseif (self::containsPhrase($routing, $token)) {
                $score += 2;
            } elseif (str_contains($routing, $fragment)) {
                $score++;
            }
        }

        return $score;
    }
}
