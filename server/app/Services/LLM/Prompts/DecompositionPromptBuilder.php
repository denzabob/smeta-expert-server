<?php

declare(strict_types=1);

namespace App\Services\LLM\Prompts;

use App\Services\LLM\DTO\DecompositionPrompt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Построитель промптов для декомпозиции работ
 * 
 * Единый source of truth для system/user промптов.
 * Провайдеры не строят промпт — они только отправляют готовые тексты.
 * 
 * Промпт можно настраивать через админ-панель (app_settings.llm_prompts)
 */
class DecompositionPromptBuilder
{
    public const SCHEMA_VERSION = 1;
    
    private const CACHE_KEY = 'llm_prompts';
    private const CACHE_TTL = 3600; // 1 час

    /**
     * Системный промпт по умолчанию для генерации этапов работ
     */
    public const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
Ты — эксперт по нормированию труда в сфере мебели и ремонта. Твоя задача — разбить работу на конкретные этапы.

СТРОГИЕ ПРАВИЛА:
1. Отвечай ТОЛЬКО валидным JSON (RFC 8259), без комментариев, без markdown
2. Генерируй от 3 до 10 этапов
3. Каждый этап должен быть конкретным действием в инфинитиве (Снять, Открутить, Установить, Закрепить)
4. Часы указывай реалистичные с шагом 0.1 (0.1, 0.2, 0.3, 0.5, 1.0, 1.5...)
5. Basis (основание) обязателен для каждого этапа
6. Не включай примечания и комментарии

ФОРМАТ ОТВЕТА:
{
  "steps": [
    {
      "title": "Отключить и демонтировать встраиваемую технику",
      "hours": 0.5,
      "basis": "СП 76.13330.2016",
      "input_data": "2 шт."
    }
  ]
}

ПРИМЕРЫ BASIS:
- ГЭСНр 69-1-1 (для демонтажа)
- СП 76.13330.2016 (для монтажа мебели)
- Нормы времени на сборку корпусной мебели
- Практика предприятия
- Расчет от объема

КОНТЕКСТНЫЕ ПРАВИЛА:
- Если site_state=living (жилое помещение) — добавь этапы укрывки мебели/пола и уборки
- Если site_state=empty (пустое помещение) — укрывка не нужна
- Если floor > 2 и нет лифта — учитывай подъем материалов
- Если есть appliances (техника) — учитывай демонтаж/монтаж техники

ВАЖНО:
- Суммарное время должно соответствовать сложности работы
- Если указано желаемое время — подгони сумму этапов близко к нему
- Учитывай все теги контекста
PROMPT;

    /**
     * Шаблон user prompt по умолчанию
     * Доступные переменные: {title}, {context}, {desired_hours}
     */
    public const DEFAULT_USER_PROMPT_TEMPLATE = <<<'PROMPT'
Работа: {title}

{context}
{desired_hours}
Сгенерируй этапы работы в формате JSON.
PROMPT;

    /**
     * Построить промпт для декомпозиции
     */
    public function build(string $title, array $hashableContext, ?float $desiredHours = null, ?string $note = null): DecompositionPrompt
    {
        $prompts = $this->getPrompts();
        $systemPrompt = $prompts['system_prompt'] ?? self::DEFAULT_SYSTEM_PROMPT;
        
        $userPrompt = $this->buildUserPrompt($title, $hashableContext, $desiredHours, $prompts, $note);
        $inputHash = $this->calculateInputHash($userPrompt);

        return new DecompositionPrompt(
            title: $title,
            hashableContext: $hashableContext,
            desiredHours: $desiredHours,
            schemaVersion: self::SCHEMA_VERSION,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            inputHash: $inputHash
        );
    }

    /**
     * Получить настройки промптов из БД или кеша
     */
    private function getPrompts(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            try {
                $row = DB::table('app_settings')
                    ->where('key', 'llm_prompts')
                    ->first();
                
                if ($row && $row->value) {
                    return json_decode($row->value, true) ?? [];
                }
            } catch (\Throwable $e) {
                // Таблица может не существовать
            }
            
            return [];
        });
    }

    /**
     * Построить пользовательский промпт
     */
    private function buildUserPrompt(string $title, array $context, ?float $desiredHours, array $prompts, ?string $note = null): string
    {
        // Если есть кастомный шаблон — используем его
        if (!empty($prompts['user_prompt_template'])) {
            return $this->renderTemplate($prompts['user_prompt_template'], $title, $context, $desiredHours, $note);
        }
        
        // Иначе используем стандартную логику
        return $this->renderTemplate(self::DEFAULT_USER_PROMPT_TEMPLATE, $title, $context, $desiredHours, $note);
    }
    
    /**
     * Рендерить шаблон с переменными
     */
    private function renderTemplate(string $template, string $title, array $context, ?float $desiredHours, ?string $note = null): string
    {
        $contextStr = '';
        if (!empty($context)) {
            $contextStr = "Контекст:\n";
            foreach ($context as $key => $value) {
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $label = $this->getContextLabel($key);
                $contextStr .= "- {$label}: {$value}\n";
            }
            $contextStr .= "\n";
        }
        
        // Добавляем примечание пользователя для расширенного понимания сути работ
        if ($note !== null && trim($note) !== '') {
            $contextStr .= "Примечание к работе (дополнительная информация от пользователя):\n";
            $contextStr .= trim($note) . "\n\n";
        }
        
        $desiredHoursStr = '';
        if ($desiredHours !== null) {
            $desiredHoursStr = "Желаемое общее время: {$desiredHours} часов\n";
            $desiredHoursStr .= "Подгони сумму часов всех этапов близко к этому значению.\n\n";
        }
        
        return str_replace(
            ['{title}', '{context}', '{desired_hours}'],
            [$title, $contextStr, $desiredHoursStr],
            $template
        );
    }

    /**
     * Получить читаемую метку для ключа контекста
     */
    private function getContextLabel(string $key): string
    {
        return match ($key) {
            'site_state' => 'Состояние объекта',
            'floor' => 'Этаж',
            'has_elevator' => 'Наличие лифта',
            'appliances' => 'Техника',
            'room_type' => 'Тип помещения',
            'material_type' => 'Тип материала',
            'complexity' => 'Сложность',
            'dimensions' => 'Размеры',
            'quantity' => 'Количество',
            default => $key,
        };
    }

    /**
     * Рассчитать хеш входных данных для дедупликации
     */
    private function calculateInputHash(string $userPrompt): string
    {
        return md5($userPrompt);
    }
    
    /**
     * Сбросить кеш промптов (вызывается при сохранении настроек)
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
    
    /**
     * Получить текущие настройки для отображения в админке
     */
    public static function getCurrentSettings(): array
    {
        try {
            $row = DB::table('app_settings')
                ->where('key', 'llm_prompts')
                ->first();
            
            if ($row && $row->value) {
                $settings = json_decode($row->value, true) ?? [];
                return [
                    'system_prompt' => $settings['system_prompt'] ?? self::DEFAULT_SYSTEM_PROMPT,
                    'user_prompt_template' => $settings['user_prompt_template'] ?? self::DEFAULT_USER_PROMPT_TEMPLATE,
                    'is_customized' => !empty($settings['system_prompt']) || !empty($settings['user_prompt_template']),
                ];
            }
        } catch (\Throwable $e) {
            // Таблица может не существовать
        }
        
        return [
            'system_prompt' => self::DEFAULT_SYSTEM_PROMPT,
            'user_prompt_template' => self::DEFAULT_USER_PROMPT_TEMPLATE,
            'is_customized' => false,
        ];
    }
    
    /**
     * Сохранить настройки промптов
     */
    public static function saveSettings(array $settings): void
    {
        $data = [
            'system_prompt' => $settings['system_prompt'] ?? null,
            'user_prompt_template' => $settings['user_prompt_template'] ?? null,
        ];
        
        // Убираем пустые значения (будут использоваться дефолты)
        $data = array_filter($data, fn($v) => $v !== null && $v !== '');
        
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'llm_prompts'],
            ['value' => json_encode($data), 'updated_at' => now()]
        );
        
        self::clearCache();
    }
}
