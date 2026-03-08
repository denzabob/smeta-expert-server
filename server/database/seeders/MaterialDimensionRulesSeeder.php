<?php

namespace Database\Seeders;

use App\Models\MaterialDimensionRule;
use App\Models\User;
use Illuminate\Database\Seeder;

class MaterialDimensionRulesSeeder extends Seeder
{
    public function run(): void
    {
        $adminUserId = User::query()->orderBy('id')->value('id');

        $rules = [
            [
                'name' => 'plate_labeled_dimensions_l_w_t',
                'description' => 'Dimensions in explicit label form: длина/ширина/толщина.',
                'is_active' => true,
                'priority' => 500,
                'material_type' => 'plate',
                'source' => null,
                'rule_type' => MaterialDimensionRule::RULE_TYPE_REGEX,
                'config' => [
                    'pattern' => '(?:длина|l)\\s*[:=]?\\s*(\\d{3,5})\\s*(?:мм|mm)?[\\s,;]+(?:ширина|w)\\s*[:=]?\\s*(\\d{3,5})\\s*(?:мм|mm)?(?:[\\s,;]+(?:толщина|t)\\s*[:=]?\\s*(\\d{1,3}(?:[.,]\\d+)?))?',
                    'flags' => 'iu',
                    'use_normalized_text' => true,
                    'captures' => [
                        'length_mm' => 1,
                        'width_mm' => 2,
                        'thickness_mm' => 3,
                    ],
                ],
                'example_input' => 'ЛДСП белый длина 2800 ширина 2070 толщина 16 мм',
                'expected_result' => [
                    'length_mm' => 2800,
                    'width_mm' => 2070,
                    'thickness_mm' => 16,
                ],
                'confidence' => 0.88,
            ],
            [
                'name' => 'plate_slash_dimensions_l_w_t',
                'description' => 'Slash-separated dimensions, for example 2800/2070/16.',
                'is_active' => true,
                'priority' => 550,
                'material_type' => 'plate',
                'source' => null,
                'rule_type' => MaterialDimensionRule::RULE_TYPE_REGEX,
                'config' => [
                    'pattern' => '\\b(\\d{3,5})\\s*/\\s*(\\d{3,5})(?:\\s*/\\s*(\\d{1,3}(?:[.,]\\d+)?))?\\b',
                    'flags' => 'u',
                    'use_normalized_text' => true,
                    'captures' => [
                        'length_mm' => 1,
                        'width_mm' => 2,
                        'thickness_mm' => 3,
                    ],
                ],
                'example_input' => 'ЛДСП 2800/2070/16 Белый',
                'expected_result' => [
                    'length_mm' => 2800,
                    'width_mm' => 2070,
                    'thickness_mm' => 16,
                ],
                'confidence' => 0.84,
            ],
            [
                'name' => 'facade_labeled_size_l_w',
                'description' => 'Facade size when text contains words размер/габариты plus LxW.',
                'is_active' => true,
                'priority' => 700,
                'material_type' => 'facade',
                'source' => null,
                'rule_type' => MaterialDimensionRule::RULE_TYPE_REGEX,
                'config' => [
                    'pattern' => '(?:размер|габарит(?:ы)?)\\s*[:=]?\\s*(\\d{2,5})\\s*[xх]\\s*(\\d{2,5})(?:\\s*[xх]\\s*(\\d{1,3}(?:[.,]\\d+)?))?',
                    'flags' => 'iu',
                    'use_normalized_text' => true,
                    'captures' => [
                        'length_mm' => 1,
                        'width_mm' => 2,
                        'thickness_mm' => 3,
                    ],
                ],
                'example_input' => 'Фасад МДФ, размер 720x596x19',
                'expected_result' => [
                    'length_mm' => 720,
                    'width_mm' => 596,
                    'thickness_mm' => 19,
                ],
                'confidence' => 0.8,
            ],
            [
                'name' => 'generic_thickness_keyword_mm',
                'description' => 'Generic thickness extraction by keyword for non-plate materials.',
                'is_active' => true,
                'priority' => 900,
                'material_type' => null,
                'source' => null,
                'rule_type' => MaterialDimensionRule::RULE_TYPE_REGEX,
                'config' => [
                    'pattern' => '(?:толщина|thickness)\\s*[:=]?\\s*(\\d{1,3}(?:[.,]\\d+)?)\\s*(?:мм|mm)\\b',
                    'flags' => 'iu',
                    'use_normalized_text' => true,
                    'captures' => [
                        'thickness_mm' => 1,
                    ],
                ],
                'example_input' => 'Профиль ПВХ толщина 2 мм',
                'expected_result' => [
                    'thickness_mm' => 2,
                ],
                'confidence' => 0.78,
            ],
        ];

        foreach ($rules as $ruleData) {
            $rule = MaterialDimensionRule::query()->firstOrNew(['name' => $ruleData['name']]);
            $isNew = !$rule->exists;

            $rule->fill($ruleData);
            $rule->updated_by_user_id = $adminUserId;

            if ($isNew) {
                $rule->created_by_user_id = $adminUserId;
            }

            $rule->save();
        }
    }
}
