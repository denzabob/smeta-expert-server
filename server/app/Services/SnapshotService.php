<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectRevision;
use App\Service\ReportService;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для создания и управления снимками проектов (revisions)
 * 
 * Обеспечивает:
 * - Детерминированную сериализацию JSON (сортировка ключей)
 * - Вычисление SHA256 хеша для контроля целостности
 * - Единый источник истины через ReportService
 */
class SnapshotService
{
    public function __construct(
        private ReportService $reportService
    ) {}

    /**
     * Создать новую ревизию (snapshot) проекта
     * 
     * @param Project $project Проект для фиксации
     * @param int $userId ID пользователя, создающего ревизию
     * @return ProjectRevision Созданная ревизия
     */
    public function createSnapshot(Project $project, int $userId): ProjectRevision
    {
        return DB::transaction(function () use ($project, $userId) {
            // 1. Получить полный отчёт через единый источник истины
            $reportDto = $this->reportService->buildReport($project);
            $snapshot = $reportDto->toArray();

            // 2. Канонизировать JSON (рекурсивная сортировка ключей)
            $canonicalJson = $this->canonicalizeJson($snapshot);

            // 3. Вычислить SHA256 хеш
            $snapshotHash = hash('sha256', $canonicalJson);

            // 4. Получить следующий номер ревизии для проекта
            $nextNumber = ProjectRevision::nextNumberForProject($project->id);

            // 5. Создать запись ревизии
            $revision = ProjectRevision::create([
                'project_id' => $project->id,
                'created_by_user_id' => $userId,
                'number' => $nextNumber,
                'status' => 'locked',
                'snapshot_json' => $canonicalJson,
                'snapshot_hash' => $snapshotHash,
                'app_version' => config('app.version', '1.0.0'),
                'calculation_engine_version' => $this->getCalculationEngineVersion(),
                'locked_at' => now(),
            ]);

            return $revision;
        });
    }

    /**
     * Канонизировать JSON: рекурсивно отсортировать ключи и вернуть строку
     * 
     * @param array $data Данные для канонизации
     * @return string Детерминированная JSON-строка
     */
    public function canonicalizeJson(array $data): string
    {
        $canonical = $this->sortKeysRecursive($data);
        return json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Рекурсивно отсортировать ключи массива
     * 
     * @param mixed $data Данные для сортировки
     * @return mixed Данные с отсортированными ключами
     */
    private function sortKeysRecursive($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        // Проверить, является ли массив ассоциативным
        if ($this->isAssociativeArray($data)) {
            // Отсортировать ключи
            ksort($data, SORT_STRING);
        }

        // Рекурсивно обработать значения
        foreach ($data as $key => $value) {
            $data[$key] = $this->sortKeysRecursive($value);
        }

        return $data;
    }

    /**
     * Проверить, является ли массив ассоциативным
     * 
     * @param array $array Массив для проверки
     * @return bool True если ассоциативный
     */
    private function isAssociativeArray(array $array): bool
    {
        if (empty($array)) {
            return false;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Получить версию движка расчётов
     * 
     * @return string Версия движка
     */
    private function getCalculationEngineVersion(): string
    {
        // Версию можно хранить в конфиге или константе
        return config('smeta.calculation_engine_version', '1.0.0');
    }

    /**
     * Восстановить проект из ревизии (future use)
     * 
     * @param ProjectRevision $revision Ревизия для восстановления
     * @return array Данные снимка
     */
    public function restoreFromSnapshot(ProjectRevision $revision): array
    {
        // Проверить целостность хеша
        if (!$revision->verifySnapshot()) {
            throw new \RuntimeException('Snapshot hash verification failed');
        }

        return json_decode($revision->snapshot_json, true);
    }
}
