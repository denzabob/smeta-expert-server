<?php

namespace App\Console\Commands;

use App\Models\Material;
use App\Service\MaterialNormalizer;
use Illuminate\Console\Command;

class NormalizeMaterials extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'materials:normalize {--force : Перезаписать существующие размеры}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Нормализует материалы: извлекает размеры листа и определяет класс товара';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $normalizer = new MaterialNormalizer();
        $force = $this->option('force');

        $query = Material::query();

        // Если не force, то обновляем только материалы без размеров
        if (!$force) {
            $query->whereNull('length_mm')->orWhereNull('width_mm');
        }

        $materials = $query->get();

        if ($materials->isEmpty()) {
            $this->info('Нет материалов для нормализации.');
            return 0;
        }

        $bar = $this->output->createProgressBar(count($materials));
        $bar->start();

        $updated = 0;
        $errors = 0;

        foreach ($materials as $material) {
            try {
                $normalized = $normalizer->normalize([
                    'name' => $material->name,
                    'characteristics' => $material->characteristics ?? '',
                    'type' => $material->type,
                ]);

                // Обновляем только если были найдены размеры
                if ($normalized['length_mm'] || $normalized['width_mm'] || $normalized['thickness_mm']) {
                    $material->update([
                        'length_mm' => $normalized['length_mm'],
                        'width_mm' => $normalized['width_mm'],
                        'thickness_mm' => $normalized['thickness_mm'],
                    ]);
                    $updated++;
                }
            } catch (\Exception $e) {
                $this->error("Ошибка при обработке материала #{$material->id}: {$e->getMessage()}");
                $errors++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Завершено. Обновлено: {$updated}, Ошибок: {$errors}");

        return 0;
    }
}
