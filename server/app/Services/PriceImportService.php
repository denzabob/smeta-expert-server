<?php

namespace App\Services;

use App\Models\Operation;
use App\Models\OperationPrice;
use App\Models\OperationPriceSource;
use App\Models\PriceImport;
use App\Models\PriceImportItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class PriceImportService
{
    public function create(User $user, array $validated, ?UploadedFile $file = null): PriceImport
    {
        return DB::transaction(function () use ($user, $validated, $file) {
            $importType = $file ? PriceImport::TYPE_EXCEL : PriceImport::TYPE_MANUAL;

            $priceImport = PriceImport::create([
                'user_id' => $user->id,
                'type' => $importType,
                'status' => PriceImport::STATUS_PENDING,
                'file_path' => $file ? $this->storeFile($user, $file) : null,
            ]);

            $items = $file
                ? $this->parseFileItems($file)
                : $this->normalizeManualItems($validated['items'] ?? []);

            foreach ($items as $item) {
                PriceImportItem::create([
                    'import_id' => $priceImport->id,
                    'operation_id' => null,
                    'name' => $item['name'],
                    'value' => $item['value'],
                    'unit' => $item['unit'],
                    'parsed_operation_hint' => $item['parsed_operation_hint'] ?? null,
                    'status' => PriceImportItem::STATUS_PENDING,
                ]);
            }

            $priceImport->update([
                'status' => PriceImport::STATUS_PROCESSED,
            ]);

            return $priceImport->fresh('items');
        });
    }

    public function bindItem(User $user, int $itemId, int $operationId): PriceImportItem
    {
        return DB::transaction(function () use ($user, $itemId, $operationId) {
            $item = $this->findOwnedItem($user, $itemId);
            $operation = $this->findWritableOperation($user, $operationId);

            $this->assertUnitsCompatible($item, $operation);

            $source = OperationPriceSource::query()->create([
                'operation_id' => $operation->id,
                'type' => OperationPriceSource::TYPE_IMPORT,
                'value' => (float) $item->value,
                'unit' => trim((string) $item->unit),
                'source_name' => $item->name,
                'document_ref' => null,
                'is_active' => false,
                'created_at' => now(),
            ]);

            $source->activate();

            $item->forceFill([
                'operation_id' => $operation->id,
                'status' => PriceImportItem::STATUS_LINKED,
            ])->save();

            return $item->fresh(['import', 'operation']);
        });
    }

    public function ignoreItem(User $user, int $itemId): PriceImportItem
    {
        $item = $this->findOwnedItem($user, $itemId);

        $item->forceFill([
            'operation_id' => null,
            'status' => PriceImportItem::STATUS_IGNORED,
        ])->save();

        return $item->fresh(['import', 'operation']);
    }

    private function storeFile(User $user, UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension() ?: 'txt';
        $path = sprintf('price_import_foundation/%d/%s.%s', $user->id, Str::uuid(), $extension);

        Storage::disk('local')->put($path, $file->getContent());

        return $path;
    }

    /**
     * @return array<int, array{name:string,value:float,unit:string,parsed_operation_hint:?string}>
     */
    private function parseFileItems(UploadedFile $file): array
    {
        $content = trim((string) $file->getContent());
        if ($content === '') {
            throw new RuntimeException('Файл импорта пустой.');
        }

        $rows = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $items = [];

        foreach ($rows as $row) {
            $line = trim($row);
            if ($line === '') {
                continue;
            }

            $columns = $this->splitLine($line);
            if (count($columns) < 3) {
                continue;
            }

            $value = $this->normalizeNumericValue($columns[1] ?? null);
            if ($value === null) {
                continue;
            }

            $items[] = [
                'name' => trim((string) ($columns[0] ?? '')),
                'value' => $value,
                'unit' => trim((string) ($columns[2] ?? '')),
                'parsed_operation_hint' => isset($columns[3]) ? trim((string) $columns[3]) : null,
            ];
        }

        if ($items === []) {
            throw new RuntimeException('Не удалось распознать строки импорта.');
        }

        return $items;
    }

    /**
     * @param array<int, array{name:mixed,value:mixed,unit:mixed,parsed_operation_hint?:mixed}> $items
     * @return array<int, array{name:string,value:float,unit:string,parsed_operation_hint:?string}>
     */
    private function normalizeManualItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            $unit = trim((string) ($item['unit'] ?? ''));
            $value = $this->normalizeNumericValue($item['value'] ?? null);

            if ($name === '' || $unit === '' || $value === null) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'value' => $value,
                'unit' => $unit,
                'parsed_operation_hint' => isset($item['parsed_operation_hint'])
                    ? trim((string) $item['parsed_operation_hint'])
                    : null,
            ];
        }

        if ($normalized === []) {
            throw new RuntimeException('Не удалось подготовить строки ручного импорта.');
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function splitLine(string $line): array
    {
        if (str_contains($line, "\t")) {
            return array_map('trim', explode("\t", $line));
        }

        if (str_contains($line, ';')) {
            return array_map('trim', explode(';', $line));
        }

        return array_map('trim', explode(',', $line));
    }

    private function normalizeNumericValue(mixed $value): ?float
    {
        if (is_numeric($value)) {
            $numeric = (float) $value;
            return $numeric > 0 ? $numeric : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], trim($value));
        if (!is_numeric($normalized)) {
            return null;
        }

        $numeric = (float) $normalized;

        return $numeric > 0 ? $numeric : null;
    }

    private function findOwnedItem(User $user, int $itemId): PriceImportItem
    {
        $item = PriceImportItem::query()
            ->whereKey($itemId)
            ->whereHas('import', fn ($query) => $query->where('user_id', $user->id))
            ->first();

        if (!$item) {
            throw (new ModelNotFoundException())->setModel(PriceImportItem::class, [$itemId]);
        }

        return $item;
    }

    private function findWritableOperation(User $user, int $operationId): Operation
    {
        $operation = Operation::query()->find($operationId);

        if (!$operation) {
            throw (new ModelNotFoundException())->setModel(Operation::class, [$operationId]);
        }

        if ($operation->origin !== 'user' || (int) $operation->user_id !== (int) $user->id) {
            throw new AuthorizationException('Нельзя привязать импорт к чужой операции.');
        }

        return $operation;
    }

    private function assertUnitsCompatible(PriceImportItem $item, Operation $operation): void
    {
        $itemUnit = OperationPrice::normalizeUnit($item->unit);
        $operationUnit = OperationPrice::normalizeUnit($operation->unit);

        if ($itemUnit !== $operationUnit) {
            throw new InvalidArgumentException('Единица импорта не совпадает с единицей операции.');
        }
    }
}
