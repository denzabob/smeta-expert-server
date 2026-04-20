<?php

namespace App\Service;

use App\Models\Material;
use App\Services\Material\EdgeMaterialNormalizer;
use App\Services\MaterialDimensionParser;

/**
 * Сервис нормализации материалов
 * Извлекает размеры листа из названия/характеристик и определяет класс товара
 */
class MaterialNormalizer
{
    private MaterialDimensionParser $dimensionParser;
    private EdgeMaterialNormalizer $edgeMaterialNormalizer;

    public function __construct(
        ?MaterialDimensionParser $dimensionParser = null,
        ?EdgeMaterialNormalizer $edgeMaterialNormalizer = null
    )
    {
        $this->dimensionParser = $dimensionParser ?? app(MaterialDimensionParser::class);
        $this->edgeMaterialNormalizer = $edgeMaterialNormalizer ?? app(EdgeMaterialNormalizer::class);
    }

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

        $text = trim(($material['name'] ?? '') . ' ' . ($material['characteristics'] ?? ''));
        $materialType = $material['type'] ?? null;

        $parsed = $this->dimensionParser->parse(
            rawText: $text,
            materialType: $materialType,
            source: 'materials_normalize_command',
            options: ['log_failed' => true]
        );

        if ($materialType === Material::TYPE_EDGE || $result['normalized_type'] === self::CLASS_EDGE) {
            $edge = $this->edgeMaterialNormalizer->normalize([
                'type' => Material::TYPE_EDGE,
                'name' => $material['name'] ?? '',
                'article' => $material['article'] ?? '',
                'length_mm' => null,
                'width_mm' => null,
                'thickness' => null,
                'thickness_mm' => null,
            ]);

            $result['length_mm'] = $edge['length_mm'] ?? null;
            $result['width_mm'] = $edge['width_mm'] ?? null;
            $result['thickness_mm'] = $edge['thickness_mm'] ?? null;
            $result['thickness'] = $edge['thickness'] ?? null;

            return $result;
        }

        if ($parsed->success) {
            $result['length_mm'] = $parsed->lengthMm !== null ? (int) round($parsed->lengthMm) : null;
            $result['width_mm'] = $parsed->widthMm !== null ? (int) round($parsed->widthMm) : null;
            $result['thickness_mm'] = $parsed->thicknessMm;
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
        $text = strtolower(($material['name'] ?? '') . ' ' . ($material['characteristics'] ?? ''));

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
