<?php

namespace App\Jobs;

use App\Models\Material;
use App\Service\MaterialNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NormalizeMaterial implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public Material $material)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $normalizer = new MaterialNormalizer();

        $normalized = $normalizer->normalize([
            'name' => $this->material->name,
            'characteristics' => $this->material->characteristics ?? '',
            'type' => $this->material->type,
        ]);

        // Обновляем материал
        $this->material->update([
            'length_mm' => $normalized['length_mm'],
            'width_mm' => $normalized['width_mm'],
            'thickness_mm' => $normalized['thickness_mm'],
        ]);
    }
}
