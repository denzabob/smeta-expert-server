<?php

namespace App\Services;

use App\DTOs\CalculationResultDTO;
use App\Models\GlobalNormohourSource;
use App\Models\PositionProfile;
use App\Models\Project;
use App\Models\ProjectProfileRate;
use App\Models\Region;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Сервис расчета нормо-часовой ставки по профилю
 * 
 * Восстанавливает функциональность расчета ставок на основе:
 * - Источников из таблицы global_normohour_sources
 * - Методов расчета: median (медиана), average (среднее)
 * - Детекции волатильности данных (порог 30%)
 * - Хранения снимков и обоснований в project_profile_rates
 */
class NormohourRateService
{
    /**
     * Константа порога волатильности (%)
     */
    const VOLATILITY_WARNING_THRESHOLD = 30;

    /**
     * Рассчитать ставку по профилю на основе источников
     * 
     * @param int $projectId ID проекта
     * @param int $profileId ID профиля должности
     * @param int|null $regionId ID региона (опционально)
     * @param string $method Метод расчета: 'median' или 'average' (по умолчанию: 'median')
     * 
     * @return CalculationResultDTO Результат расчета с ставкой и метаданными
     * 
     * @throws \Exception При ошибке расчета или отсутствии источников
     */
    public function calculateForProfile(
        int $projectId,
        int $profileId,
        ?int $regionId = null,
        string $method = 'median'
    ): CalculationResultDTO {
        // Получить проект и профиль
        $project = Project::findOrFail($projectId);
        $profile = PositionProfile::findOrFail($profileId);

        // Если регион не указан, использовать регион проекта
        if ($regionId === null && $project->region_id) {
            $regionId = $project->region_id;
        }

        // Получить источники для расчета
        $sources = $this->fetchSources($profileId, $regionId);

        if ($sources->isEmpty()) {
            throw new \Exception(
                "Не найдены источники для расчета ставки профиля '{$profile->name}'" .
                ($regionId ? " в регионе '{$this->getRegionName($regionId)}'" : '')
            );
        }

        // Извлечь массив ставок
        $rates = $this->parseRateArray($sources);

        if (empty($rates)) {
            throw new \Exception("Не удалось извлечь ставки из источников");
        }

        // Вычислить метрики
        $calculatedRate = $this->calculateRate($rates, $method);
        $volatility = $this->calculateVolatility($rates);
        $sourcesSnapshot = $this->createSourcesSnapshot($sources);

        // Сгенерировать обоснование
        $warnings = [];
        if ($volatility >= self::VOLATILITY_WARNING_THRESHOLD) {
            $warnings[] = [
                'type' => 'high_volatility',
                'message' => "Высокая волатильность данных ({$volatility}%). Проверьте достоверность источников.",
            ];
        }

        if (count($rates) < 3) {
            $warnings[] = [
                'type' => 'low_source_count',
                'message' => "Использовано менее 3 источников. Результат может быть менее надежным.",
            ];
        }

        $justificationText = $this->generateJustificationText(
            $profile->name,
            $sourcesSnapshot,
            $method,
            $calculatedRate,
            $volatility,
            $warnings,
            $regionId
        );

        return new CalculationResultDTO(
            rate: $calculatedRate,
            sourcesSnapshot: $sourcesSnapshot,
            justificationSnapshot: $justificationText,
            volatility: $volatility,
            warnings: $warnings,
            method: $method,
            sourceCount: count($rates)
        );
    }

    /**
     * Создать или обновить ставку проекта с блокировкой
     * 
     * @param int $projectId ID проекта
     * @param int $profileId ID профиля должности
     * @param int|null $regionId ID региона (опционально)
     * @param string $method Метод расчета (по умолчанию: 'median')
     * 
     * @return ProjectProfileRate Запись о ставке (новая или обновленная)
     * 
     * @throws \Exception При ошибке расчета
     */
    public function upsertProjectProfileRate(
        int $projectId,
        int $profileId,
        ?int $regionId = null,
        string $method = 'median'
    ): ProjectProfileRate {
        // Проверить, не заблокирована ли существующая ставка
        $existingRate = ProjectProfileRate::where([
            ['project_id', '=', $projectId],
            ['profile_id', '=', $profileId],
            ['region_id', '=', $regionId],
        ])->first();

        if ($existingRate && $existingRate->is_locked) {
            // Не обновляем заблокированные ставки
            return $existingRate;
        }

        // Выполнить расчет
        $calculation = $this->calculateForProfile($projectId, $profileId, $regionId, $method);

        // Создать или обновить запись
        $rate = ProjectProfileRate::updateOrCreate(
            [
                'project_id' => $projectId,
                'profile_id' => $profileId,
                'region_id' => $regionId,
            ],
            [
                'rate_fixed' => $calculation->rate,
                'fixed_at' => Carbon::now(),
                'calculation_method' => $method,
                'sources_snapshot' => json_encode($calculation->sourcesSnapshot, JSON_UNESCAPED_UNICODE),
                'justification_snapshot' => $calculation->justificationSnapshot,
            ]
        );

        return $rate;
    }

    /**
     * Получить источники для расчета
     * 
     * @param int $profileId ID профиля
     * @param int|null $regionId ID региона
     * 
     * @return Collection Коллекция источников
     */
    private function fetchSources(int $profileId, ?int $regionId = null): Collection
    {
        $query = GlobalNormohourSource::query()
            ->where('position_profile_id', $profileId)
            ->where('is_active', true)
            ->with(['positionProfile', 'region']);

        // Добавить фильтр по регионам
        if ($regionId) {
            $query->where(function ($q) use ($regionId) {
                $q->where('region_id', $regionId)
                  ->orWhereNull('region_id');
            });
        } else {
            // Если регион не указан, берем все источники (с приоритетом на общие)
            // Сортируем так, чтобы общие источники (region_id = null) были первыми
            $query->orderByRaw('region_id IS NOT NULL');
        }

        return $query
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Извлечь массив ставок из источников
     * 
     * @param Collection $sources Коллекция источников
     * 
     * @return array Массив ставок (float)
     */
    private function parseRateArray(Collection $sources): array
    {
        return $sources
            ->map(fn($source) => (float) $source->rate_per_hour)
            ->filter(fn($rate) => $rate > 0)
            ->values()
            ->toArray();
    }

    /**
     * Рассчитать ставку методом медианы или среднего
     * 
     * @param array $rates Массив ставок
     * @param string $method 'median' или 'average'
     * 
     * @return float Рассчитанная ставка
     */
    private function calculateRate(array $rates, string $method): float
    {
        if ($method === 'average') {
            return $this->calculateAverage($rates);
        }

        return $this->calculateMedian($rates);
    }

    /**
     * Рассчитать медиану
     * 
     * @param array $rates Массив ставок
     * 
     * @return float Медиана
     */
    private function calculateMedian(array $rates): float
    {
        $sorted = $rates;
        sort($sorted);

        $count = count($sorted);
        $middle = (int) ($count / 2);

        if ($count % 2 === 0) {
            // Четное количество элементов - среднее арифметическое двух средних
            return ($sorted[$middle - 1] + $sorted[$middle]) / 2;
        }

        // Нечетное количество элементов - берем средний элемент
        return $sorted[$middle];
    }

    /**
     * Рассчитать среднее арифметическое
     * 
     * @param array $rates Массив ставок
     * 
     * @return float Среднее
     */
    private function calculateAverage(array $rates): float
    {
        return array_sum($rates) / count($rates);
    }

    /**
     * Рассчитать волатильность (%)
     * 
     * @param array $rates Массив ставок
     * 
     * @return float Волатильность в процентах
     */
    private function calculateVolatility(array $rates): float
    {
        if (count($rates) < 2) {
            return 0;
        }

        $min = min($rates);
        $max = max($rates);

        if ($min <= 0) {
            return 0;
        }

        // Формула: (max - min) / min * 100
        return round(((($max - $min) / $min) * 100), 2);
    }

    /**
     * Создать снимок источников для хранения
     * 
     * @param Collection $sources Коллекция источников
     * 
     * @return array Структурированный массив источников
     */
    private function createSourcesSnapshot(Collection $sources): array
    {
        return $sources->map(function ($source) {
            return [
                'source_id' => $source->id,
                'source' => $source->source,
                'salary_period' => $source->salary_period,
                'salary_original' => (string) $source->salary_value,
                'salary_month' => (float) $source->salary_month,
                'hours_per_month' => (float) $source->hours_per_month,
                'rate_per_hour' => (float) $source->rate_per_hour,
                'source_date' => $source->source_date?->toDateString(),
                'region_id' => $source->region_id,
                'region_name' => $source->region?->name,
                'link' => $source->link,
                'note' => $source->note,
            ];
        })->toArray();
    }

    /**
     * Получить название региона
     * 
     * @param int $regionId ID региона
     * 
     * @return string Название региона
     */
    private function getRegionName(int $regionId): string
    {
        $region = Region::find($regionId);
        return $region?->name ?? "Регион #{$regionId}";
    }

    /**
     * Сгенерировать текст обоснования расчета
     * 
     * @param string $profileName Название профиля
     * @param array $sourcesSnapshot Снимок источников
     * @param string $method Метод расчета
     * @param float $rate Рассчитанная ставка
     * @param float $volatility Волатильность
     * @param array $warnings Предупреждения
     * @param int|null $regionId ID региона
     * 
     * @return string Текст обоснования
     */
    private function generateJustificationText(
        string $profileName,
        array $sourcesSnapshot,
        string $method,
        float $rate,
        float $volatility,
        array $warnings,
        ?int $regionId = null
    ): string {
        $lines = [];
        $lines[] = "Расчет ставки по профилю: {$profileName}";
        $lines[] = str_repeat('=', 60);
        $lines[] = '';

        // Источники
        $lines[] = "Использованные источники (" . count($sourcesSnapshot) . " шт):";
        foreach ($sourcesSnapshot as $index => $source) {
            $sourceName = $source['source'] ?? 'Неизвестный источник';
            $rate = number_format($source['rate_per_hour'], 2, ',', ' ');
            $sourceDate = $source['source_date'] ?? 'дата не указана';

            $lines[] = "  " . ($index + 1) . ". {$sourceName}: {$rate} ₽/ч (от {$sourceDate})";

            if (!empty($source['region_name']) && $source['region_id']) {
                $lines[] = "     Регион: {$source['region_name']}";
            }
            if (!empty($source['note'])) {
                $lines[] = "     Примечание: {$source['note']}";
            }
        }
        $lines[] = '';

        // Диапазон и волатильность
        if (count($sourcesSnapshot) > 0) {
            $rates = array_column($sourcesSnapshot, 'rate_per_hour');
            $min = min($rates);
            $max = max($rates);
            $minFormatted = number_format($min, 2, ',', ' ');
            $maxFormatted = number_format($max, 2, ',', ' ');

            $lines[] = "Диапазон: {$minFormatted} - {$maxFormatted} ₽/ч (волатильность {$volatility}%)";
            $lines[] = '';
        }

        // Метод расчета
        $methodName = $method === 'median' ? 'медиана' : 'среднее арифметическое';
        $lines[] = "Использован метод: {$methodName}";

        // Результат
        $rateFormatted = number_format($rate, 2, ',', ' ');
        $lines[] = "Рассчитанная ставка: {$rateFormatted} ₽/ч";
        $lines[] = '';

        // Регион
        if ($regionId) {
            $regionName = $this->getRegionName($regionId);
            $lines[] = "Регион расчета: {$regionName}";
        }

        // Дата расчета
        $calculationDate = Carbon::now()->format('Y-m-d H:i:s');
        $lines[] = "Дата расчета: {$calculationDate}";
        $lines[] = '';

        // Предупреждения
        if (count($warnings) > 0) {
            $lines[] = "Предупреждения:";
            foreach ($warnings as $warning) {
                $lines[] = "  ⚠️ " . $warning['message'];
            }
        } else {
            $lines[] = "Предупреждения: нет";
        }

        return implode("\n", $lines);
    }
}
