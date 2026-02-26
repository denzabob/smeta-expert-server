<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run()
    {
        $units = [
            ['name' => 'шт', 'code' => 'pcs', 'origin' => 'system'],
            ['name' => 'кв.м', 'code' => 'm2', 'origin' => 'system'],
            ['name' => 'м', 'code' => 'm', 'origin' => 'system'],
            ['name' => 'куб.м', 'code' => 'm3', 'origin' => 'system'],
            ['name' => 'кг', 'code' => 'kg', 'origin' => 'system'],
            ['name' => 'л', 'code' => 'l', 'origin' => 'system'],
            ['name' => 'компл.', 'code' => 'set', 'origin' => 'system'],
            ['name' => 'пог. м', 'code' => 'lm', 'origin' => 'system'],
            ['name' => 'Лист', 'code' => 'sheet', 'origin' => 'system'],
            ['name' => 'кв.м.', 'code' => 'm2', 'origin' => 'system'],
            ['name' => 'пар', 'code' => 'pair', 'origin' => 'system'],
            ['name' => 'комплект', 'code' => 'kit', 'origin' => 'system'],
            ['name' => 'шт.', 'code' => 'pcs', 'origin' => 'system'],
            ['name' => 'п.м.', 'code' => 'lm', 'origin' => 'system'],
            ['name' => 'литр', 'code' => 'l', 'origin' => 'system'],
            ['name' => 'комп', 'code' => 'comp', 'origin' => 'system'],
            ['name' => 'к-кт', 'code' => 'kit', 'origin' => 'system'],
        ];

        foreach ($units as $unitData) {
            Unit::firstOrCreate(['name' => $unitData['name']], $unitData);
        }
    }
}
