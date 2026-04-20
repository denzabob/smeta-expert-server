<?php

namespace App\Services;

use App\Evidence\CaptureMethod;
use App\Evidence\CostComponent;
use App\Evidence\SourceType;
use App\Models\EvidenceLink;
use App\Models\EvidenceRecord;
use App\Models\GenericEvidenceAsset;
use App\Models\Material;
use App\Models\Operation;
use App\Models\ProjectProfileRate;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManualPricingSourceService
{
    public function create(Request $request, array $validated): EvidenceRecord
    {
        return DB::transaction(function () use ($request, $validated) {
            [$linkableType, $targetModelClass] = $this->resolveTargetMapping($validated['target_type']);
            $target = $targetModelClass::findOrFail($validated['target_id']);
            $costComponent = $this->resolveCostComponent($validated['target_type'], $target);

            $metadata = [
                'unit' => $validated['unit'],
                'manual_pricing_target_type' => $validated['target_type'],
            ];
            if (!empty($validated['notes'])) {
                $metadata['justification_text'] = $validated['notes'];
            }

            $recordUuid = (string) Str::uuid();
            $record = EvidenceRecord::create([
                'uuid'                => $recordUuid,
                'cost_component'      => $costComponent,
                'source_type'         => $this->mapSourceType($validated['source_type']),
                'capture_method'      => $this->resolveCaptureMethod($validated['source_type']),
                'verification_status' => 'pending',
                'source_url'          => $validated['source_url'] ?? null,
                'observed_price'      => $validated['value'],
                'currency'            => 'RUB',
                'metadata_json'       => $metadata,
                'created_by'          => (int) $request->user()->id,
            ]);

            EvidenceLink::create([
                'evidence_record_id' => $record->id,
                'linkable_type'      => $linkableType,
                'linkable_id'        => $target->getKey(),
                'relation_type'      => 'primary',
            ]);

            foreach ($this->uploadedFiles($request) as $file) {
                $path = $file->store('evidence-records/' . $recordUuid, 'public');
                GenericEvidenceAsset::create([
                    'uuid'               => (string) Str::uuid(),
                    'evidence_record_id' => $record->id,
                    'asset_type'         => 'document',
                    'file_path'          => $path,
                    'original_filename'  => $file->getClientOriginalName(),
                    'mime_type'          => $file->getMimeType(),
                    'file_size'          => $file->getSize(),
                    'sha256'             => hash_file('sha256', $file->getRealPath()),
                ]);
            }

            return $record;
        });
    }

    private function resolveTargetMapping(string $targetType): array
    {
        return match ($targetType) {
            'material' => ['material', Material::class],
            'operation' => ['operation', Operation::class],
            'labor' => ['labor', ProjectProfileRate::class],
            'product' => ['product', Material::class],
        };
    }

    private function resolveCostComponent(string $targetType, object $target): string
    {
        return match ($targetType) {
            'material' => match (($target instanceof Material) ? $target->type : null) {
                Material::TYPE_EDGE => CostComponent::EDGE,
                Material::TYPE_HARDWARE => CostComponent::FITTING,
                default => CostComponent::PLATE,
            },
            'operation' => CostComponent::OPERATION,
            'labor' => CostComponent::LABOR_WORK,
            'product' => CostComponent::FACADE,
        };
    }

    private function mapSourceType(string $sourceType): string
    {
        return match ($sourceType) {
            'manual' => SourceType::MANUAL_INPUT,
            'url' => SourceType::SUPPLIER_WEBSITE,
            'file' => SourceType::DOCUMENT,
        };
    }

    private function resolveCaptureMethod(string $sourceType): string
    {
        return match ($sourceType) {
            'file' => CaptureMethod::FILE_UPLOAD,
            default => CaptureMethod::MANUAL_ENTRY,
        };
    }

    private function uploadedFiles(Request $request): array
    {
        $files = [];

        if ($request->hasFile('file')) {
            $files[] = $request->file('file');
        }

        if ($request->hasFile('files')) {
            $files = array_merge($files, Arr::wrap($request->file('files')));
        }

        return $files;
    }
}