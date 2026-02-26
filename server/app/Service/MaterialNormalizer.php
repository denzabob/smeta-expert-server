<?php

namespace App\Service;

/**
 * Сервис нормализации материалов
 * Извлекает размеры листа из названия/характеристик и определяет класс товара
 */
class MaterialNormalizer
{
    /**
     * Классы товаров
     */
    const CLASS_PLATE = 'plate';
    const CLASS_EDGE = 'edge';
    const CLASS_OTHER = 'other';

    /**
     * Нормализует материал
     * 
     * @param array $material Данные материала с ключами: name, characteristics, type
     * @return array Нормализованные данные с length_mm, width_mm, thickness_mm, normalized_type
     */
    public function normalize(array $material): array
    {
        $result = [
            'length_mm' => null,
            'width_mm' => null,
            'thickness_mm' => null,
            'normalized_type' => $this->determineClass($material),
        ];

        // Парсим размеры из названия или характеристик
        $dimensions = $this->extractDimensions(
            $material['name'] ?? '',
            $material['characteristics'] ?? ''
        );

        if ($dimensions) {
            // Для ЛДСП и подобных обычно порядок: L x W x T (длина х ширина х толщина)
            $result['length_mm'] = $dimensions[0] ?? null;      // L
            $result['width_mm'] = $dimensions[1] ?? null;       // W
            $result['thickness_mm'] = $dimensions[2] ?? null;   // T
        }

        return $result;
    }

    /**
     * Определяет класс товара по названию и характеристикам
     * 
     * @param array $material
     * @return string
     */
    private function determineClass(array $material): string
    {
        $text = strtolower($material['name'] ?? '' . ' ' . $material['characteristics'] ?? '');

        // Ключевые слова для определения класса
        $plateKeywords = ['лдсп', 'мдф', 'шпон', 'пластик', 'ламинат', 'фанера', 'лист', 'плита', 'доска'];
        $edgeKeywords = ['кромка', 'edge', 'меланин', 'пвх', 'лента', 'полоса'];

        foreach ($edgeKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return self::CLASS_EDGE;
            }
        }

        foreach ($plateKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return self::CLASS_PLATE;
            }
        }

        return self::CLASS_OTHER;
    }

    /**
     * Извлекает размеры из текста (название или характеристики)
     * Ищет паттерны вроде: 2800х2070х16 мм, 2800*2070*16, 2800 х 2070 х 16 и т.д.
     * 
     * @param string $name
     * @param string $characteristics
     * @return array|null [L, W, T] в миллиметрах или null
     */
    public function extractDimensions(string $name, string $characteristics): ?array
    {
        $text = $name . ' ' . $characteristics;

        // Нормализуем разделители: ×, *, x на х (кириллицу)
        $text = preg_replace('/[×*xX]/u', 'х', $text);

        // Ищем паттерн: число х число х число (с опциональными пробелами и единицами)
        // Вариант 1: число х число х число мм (например: 2800 х 2070 х 16 мм)
        $pattern = '/(\d+)\s*х\s*(\d+)\s*х\s*(\d+)\s*мм/u';
        
        if (preg_match($pattern, $text, $matches)) {
            return [
                (int)$matches[1],  // L
                (int)$matches[2],  // W
                (int)$matches[3],  // T
            ];
        }

        // Вариант 2: число х число х число (без мм, например: 2800х2070х16)
        $pattern2 = '/(\d+)\s*х\s*(\d+)\s*х\s*(\d+)(?:\s|$)/u';
        if (preg_match($pattern2, $text, $matches)) {
            // Проверяем что это не часть большего числа
            $fullMatch = $matches[0];
            if (preg_match('/[а-яёa-z]/iu', substr($fullMatch, -1))) {
                // Если после числа буква (не пробел/конец), это не размер
                goto tryTwoDimensions;
            }
            return [
                (int)$matches[1],  // L
                (int)$matches[2],  // W
                (int)$matches[3],  // T
            ];
        }

        tryTwoDimensions:
        // Вариант 3: число х число (если не нашли трёхмерный паттерн)
        // Паттерн: число х число (без третьего измерения или после мм)
        $pattern3 = '/(\d+)\s*х\s*(\d+)(?:\s*х|\s+мм|$)/u';
        if (preg_match($pattern3, $text, $matches)) {
            return [
                (int)$matches[1],  // L
                (int)$matches[2],  // W
                null,              // T
            ];
        }

        return null;
    }

    /**
     * Рассчитывает стоимость за квадратный метр для пластины
     * 
     * @param float $pricePerUnit Цена за единицу
     * @param int|null $lengthMm Длина листа в мм
     * @param int|null $widthMm Ширина листа в мм
     * @return float|null Цена за м²
     */
    public function calculatePricePerM2(float $pricePerUnit, ?int $lengthMm, ?int $widthMm): ?float
    {
        if (!$lengthMm || !$widthMm) {
            return null;
        }

        // Переводим мм² в м²
        // 1 м² = 1,000,000 мм²
        $areaM2 = ($lengthMm * $widthMm) / 1_000_000;

        if ($areaM2 <= 0) {
            return null;
        }

        return $pricePerUnit / $areaM2;
    }
}
