<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertProject;

final class ExpertProjectCoreContextBuilder
{
    public function build(ExpertProject $project): string
    {
        $fields = [
            'Название' => $project->name,
            'Направление экспертизы' => $project->domain,
            'Вид работы' => $project->work_type,
            'Заказчик' => $project->customer,
            'Объект исследования' => $project->object_summary,
            'Адрес' => $project->address,
            'Дата исследования' => $project->research_date?->toDateString(),
            'Вопросы эксперту' => implode('; ', array_filter(array_map(
                static fn (mixed $question): string => is_array($question) ? (string) ($question['text'] ?? '') : (is_string($question) ? $question : ''),
                (array) $project->research_questions,
            ))),
        ];
        foreach ($project->researchObjects as $object) {
            $fields['Объект '.$object->sort_order] = trim($object->name.' '.(string) $object->description);
        }

        $lines = [];
        foreach ($fields as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $lines[] = $label.': '.mb_substr($value, 0, 1000);
            }
        }

        return "PROJECT CORE — сведения, введённые пользователем в проект, не содержание документов:\n".mb_substr(implode("\n", $lines), 0, 6000);
    }
}
