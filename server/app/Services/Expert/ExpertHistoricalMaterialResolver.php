<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertMessage;
use App\Models\Expert\ExpertMessageMaterial;

/** Resolves explicit references only; never adds the whole project library. */
final class ExpertHistoricalMaterialResolver
{
    private const MAX_RESTORED = 4;

    /** @return list<string> */
    public function resolve(
        ExpertConversation $conversation,
        string $query,
        ?ExpertMessage $current = null,
        bool $hasCurrentMaterials = false,
    ): array
    {
        $messages = $conversation->messages()->where('role', 'user')->whereHas('attachments')
            ->when($current !== null, fn ($builder) => $builder->where('id', '<', $current->id))
            ->with('attachments.material')->reorder()->orderByDesc('id')->get();
        $attachments = $messages->flatMap(fn (ExpertMessage $message) => $message->attachments)
            ->filter(fn (ExpertMessageMaterial $attachment) => $attachment->material !== null)
            ->unique('material_public_id_snapshot')->values();

        $normal = $this->normalize($query);
        $requestedType = preg_match('/\bpdf\b|пдф/u', $normal) === 1 ? 'pdf' : null;
        $selected = [];
        $relativePattern = $hasCurrentMaterials
            ? '/\b(?:предыдущ(?:ий|его|ем)|выше|ранее)\s+(pdf|пдф|документ)\b/u'
            : '/\b(?:предыдущ(?:ий|его|ем)|тот|этот)\s+(pdf|пдф|документ)\b/u';
        if (preg_match($relativePattern, $normal, $relative) === 1) {
            $previous = $attachments->first(fn (ExpertMessageMaterial $item) => $relative[1] === 'документ'
                ? ! str_starts_with((string) $item->mime_type_snapshot, 'image/')
                    && ! in_array($this->extension($item), ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true)
                : $this->extension($item) === 'pdf');
            if ($previous !== null) {
                $selected[] = $previous->material_public_id_snapshot;
            }
        }

        // Prefer a complete filename (or complete stem) before matching individual words.
        $exact = [];
        foreach ($attachments as $item) {
            if ($requestedType !== null && $this->extension($item) !== $requestedType) {
                continue;
            }
            $name = $this->normalize($item->original_name_snapshot);
            $stem = preg_replace('/\.[\p{L}\p{N}]+$/u', '', $name) ?? $name;
            if (mb_strlen($stem) < 5) {
                continue;
            }
            foreach (array_unique([$name, $stem]) as $phrase) {
                if (preg_match('/(?:^|[^\p{L}\p{N}])'.preg_quote($phrase, '/').'(?:$|[^\p{L}\p{N}])/u', $normal) === 1) {
                    $exact[$phrase][] = $item->material_public_id_snapshot;
                }
            }
        }
        foreach ($exact as $ids) {
            if (count($ids) !== 1) {
                continue;
            }
            if (! in_array($ids[0], $selected, true)) {
                $selected[] = $ids[0];
            }
        }
        if ($exact !== []) {
            return array_slice($selected, 0, self::MAX_RESTORED);
        }

        $tokens = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $normal) ?: [],
            static fn (string $token): bool => mb_strlen($token) >= 4 && ! in_array($token, ['документ', 'предыдущий', 'предыдущего', 'предыдущем', 'файл', 'этот', 'того', 'пдф'], true)));
        foreach ($tokens as $token) {
            if (count($selected) >= self::MAX_RESTORED) {
                break;
            }
            $scores = [];
            foreach ($attachments as $item) {
                if ($requestedType !== null && $this->extension($item) !== $requestedType) {
                    continue;
                }
                $name = $this->normalize($item->original_name_snapshot);
                $stem = preg_replace('/\.[\p{L}\p{N}]+$/u', '', $name) ?? $name;
                $parts = preg_split('/[^\p{L}\p{N}]+/u', $stem) ?: [];
                $score = 0.0;
                if ($token === $stem || in_array($token, $parts, true)) {
                    $score = 1.0;
                } elseif (mb_strlen($token) >= 5) {
                    foreach ($parts as $part) {
                        if (mb_strlen($part) < 5) {
                            continue;
                        }
                        $distance = $this->distance($token, $part);
                        $similarity = 1 - $distance / max(mb_strlen($token), mb_strlen($part));
                        if ($distance <= 2) {
                            $score = max($score, $similarity);
                        }
                    }
                }
                if ($score >= .72) {
                    $scores[] = [$item->material_public_id_snapshot, $score];
                }
            }
            usort($scores, static fn (array $a, array $b): int => ($b[1] <=> $a[1]) ?: strcmp($a[0], $b[0]));
            if ($scores === [] || (isset($scores[1]) && $scores[0][1] - $scores[1][1] < .08)) {
                continue;
            }
            if (! in_array($scores[0][0], $selected, true)) {
                $selected[] = $scores[0][0];
            }
        }

        return array_slice($selected, 0, self::MAX_RESTORED);
    }

    public function historyText(ExpertMessage $message): string
    {
        $items = $message->attachments->map(fn (ExpertMessageMaterial $item): string => '- '.trim(preg_replace('/\s+/u', ' ', $item->original_name_snapshot) ?? $item->original_name_snapshot).' — '.strtoupper($this->extension($item)))->all();

        return $items === [] ? $message->content : $message->content."\n\n[Материалы сообщения:\n".implode("\n", $items)."\n]";
    }

    private function extension(ExpertMessageMaterial $item): string
    {
        $extension = pathinfo($item->original_name_snapshot, PATHINFO_EXTENSION);

        return $this->normalize($extension ?: ($item->mime_type_snapshot === 'application/pdf' ? 'pdf' : ''));
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}.]+/u', ' ', str_replace('ё', 'е', mb_strtolower($value, 'UTF-8'))) ?? '');
    }

    private function distance(string $left, string $right): int
    {
        $a = mb_str_split($left);
        $b = mb_str_split($right);
        $row = range(0, count($b));
        foreach ($a as $i => $char) {
            $next = [$i + 1];
            foreach ($b as $j => $other) {
                $next[] = min($next[$j] + 1, $row[$j + 1] + 1, $row[$j] + ($char === $other ? 0 : 1));
            }
            $row = $next;
        }

        return $row[count($b)];
    }
}
