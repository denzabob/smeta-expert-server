<?php

namespace Database\Seeders;

use App\Models\Operation;
use Illuminate\Database\Seeder;

class SystemOperationsSeeder extends Seeder
{
    public function run(): void
    {
        Operation::updateOrCreate(
            [
                'user_id' => null,
                'origin' => 'system',
                'exclusion_group' => 'cutting',
                'name' => 'Распил плитных материалов',
            ],
            [
                'category' => 'cutting',
                'min_thickness' => null,
                'max_thickness' => null,
                'unit' => 'м²',
                'description' => 'Системная операция распила плитных материалов для rule-based расчёта.',
            ],
        );
    }
}
