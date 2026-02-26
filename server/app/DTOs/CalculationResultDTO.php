<?php

namespace App\DTOs;

/**
 * Результат расчета нормо-часовой ставки по профилю
 */
class CalculationResultDTO
{
    /**
     * @var float Рассчитанная ставка (руб/ч)
     */
    public float $rate;

    /**
     * @var array Снимок используемых источников
     */
    public array $sourcesSnapshot;

    /**
     * @var string Текст обоснования расчета
     */
    public string $justificationSnapshot;

    /**
     * @var float Волатильность данных (%)
     */
    public float $volatility;

    /**
     * @var array Предупреждения о качестве данных
     */
    public array $warnings;

    /**
     * @var string Использованный метод расчета (average, median)
     */
    public string $method;

    /**
     * @var int Количество использованных источников
     */
    public int $sourceCount;

    public function __construct(
        float $rate,
        array $sourcesSnapshot,
        string $justificationSnapshot,
        float $volatility,
        array $warnings = [],
        string $method = 'median',
        int $sourceCount = 0
    ) {
        $this->rate = $rate;
        $this->sourcesSnapshot = $sourcesSnapshot;
        $this->justificationSnapshot = $justificationSnapshot;
        $this->volatility = $volatility;
        $this->warnings = $warnings;
        $this->method = $method;
        $this->sourceCount = $sourceCount;
    }

    /**
     * Преобразовать в массив
     */
    public function toArray(): array
    {
        return [
            'rate' => $this->rate,
            'sources_snapshot' => $this->sourcesSnapshot,
            'justification_snapshot' => $this->justificationSnapshot,
            'volatility' => $this->volatility,
            'warnings' => $this->warnings,
            'method' => $this->method,
            'source_count' => $this->sourceCount,
        ];
    }

    /**
     * Преобразовать в JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Есть ли предупреждения
     */
    public function hasWarnings(): bool
    {
        return count($this->warnings) > 0;
    }

    /**
     * Высокая волатильность?
     */
    public function hasHighVolatility(): bool
    {
        return $this->volatility >= 30;
    }
}
