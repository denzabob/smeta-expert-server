<?php

namespace App\Http\Controllers\Api\Parser;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use Carbon\Carbon;

class MaterialController extends Controller
{
    /**
     * Создаёт или обновляет материал, полученный от парсера.
     * Работает без авторизации (публичный endpoint для фоновых задач).
     * 
     * Семантика данных:
     * - price_per_unit: текущая цена материала
     * - price_checked_at: момент последней успешной проверки цены парсером
     * - material_price_histories.valid_from: дата изменения цены (не updated_at)
     * 
     * Правила:
     * - price_checked_at обновляется ТОЛЬКО если парсер успешно извлёк цену
     * - Если цена не распарсилась/пустая/ошибка — price_checked_at НЕ трогать
     * - В историю пишем ТОЛЬКО при изменении цены
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'article' => 'required|string|max:255',
            'type' => 'required|in:plate,edge,fitting',
            'unit' => 'required|in:м²,м.п.,шт',
            'price_per_unit' => 'nullable|numeric|min:0', // nullable — цена могла не распарситься
            'source_url' => 'required|url',
            'screenshot_path' => 'nullable|string|max:512',
            'availability_status' => 'nullable|string|max:50',
            'supplier_id' => 'nullable|integer',
            'currency' => 'nullable|string|max:10',
            'parsed_at' => 'nullable|date', // опционально; иначе сервер ставит now()
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $data = $validator->validated();
        
        // Определяем время парсинга
        $parsedAt = isset($data['parsed_at']) 
            ? Carbon::parse($data['parsed_at']) 
            : Carbon::now();
        $parseDate = $parsedAt->toDateString();

        // Проверяем, успешно ли распарсилась цена
        $priceSuccessfullyParsed = isset($data['price_per_unit']) 
            && $data['price_per_unit'] !== null 
            && $data['price_per_unit'] >= 0;

        // Парсерные материалы — всегда общедоступные
        $data['user_id'] = null;
        $data['origin'] = 'parser';
        $data['is_active'] = true;

        // Ищем **существующий парсерный** материал по артикулу
        $material = Material::where('article', $data['article'])
            ->where('origin', 'parser')
            ->first();

        if ($material) {
            // === Сценарий: материал уже существует ===
            
            // Если цена не распарсилась — НЕ трогаем price_checked_at
            if (!$priceSuccessfullyParsed) {
                // Можем обновить только статус наличия и скриншот, но НЕ price_checked_at
                $needsUpdate = false;
                
                if (isset($data['availability_status']) && 
                    $material->availability_status !== $data['availability_status']) {
                    $material->availability_status = $data['availability_status'];
                    $needsUpdate = true;
                }
                
                if ($needsUpdate) {
                    $material->save();
                }
                
                return response()->json([
                    'material' => $material,
                    'price_parsed' => false,
                    'message' => 'Цена не распарсилась, price_checked_at не обновлён'
                ], 200);
            }
            
            // === Цена успешно распарсилась ===
            
            // Всегда обновляем price_checked_at при успешном парсинге цены
            $material->price_checked_at = $parsedAt;
            
            // Получаем последнюю запись из истории цен с valid_to IS NULL
            $lastHistory = MaterialPriceHistory::where('material_id', $material->id)
                ->whereNull('valid_to')
                ->orderBy('valid_from', 'desc')
                ->first();

            $incomingPrice = (float) $data['price_per_unit'];
            $currentPrice = (float) $material->price_per_unit;
            
            // Сравниваем с погрешностью (цены в decimal)
            $priceChanged = abs($incomingPrice - $currentPrice) > 0.001;
            
            $statusChanged = isset($data['availability_status']) && 
                           $material->availability_status != $data['availability_status'];

            // Определяем, нужен ли новый скриншот
            $needNewScreenshot = $priceChanged || $statusChanged;
            $screenshotPath = $needNewScreenshot 
                ? ($data['screenshot_path'] ?? null)
                : ($lastHistory ? $lastHistory->screenshot_path : ($data['screenshot_path'] ?? null));

            if ($priceChanged) {
                // === Цена изменилась ===
                
                // Закрываем предыдущую "активную" запись в истории
                if ($lastHistory) {
                    // valid_to = вчера (интервальная модель)
                    $lastHistory->valid_to = Carbon::parse($parseDate)->subDay()->toDateString();
                    $lastHistory->save();
                }

                // Обновляем материал
                $material->price_per_unit = $incomingPrice;
                $material->source_url = $data['source_url'];
                $material->last_price_screenshot_path = $screenshotPath;
                if (isset($data['availability_status'])) {
                    $material->availability_status = $data['availability_status'];
                }
                $material->version += 1;
                $material->save();

                // Создаём новую запись в истории цен
                MaterialPriceHistory::create([
                    'material_id' => $material->id,
                    'version' => $material->version,
                    'valid_from' => $parseDate,
                    'valid_to' => null,
                    'price_per_unit' => $incomingPrice,
                    'source_url' => $data['source_url'],
                    'screenshot_path' => $screenshotPath,
                ]);
                
                return response()->json([
                    'material' => $material->fresh(),
                    'price_parsed' => true,
                    'price_changed' => true,
                    'old_price' => $currentPrice,
                    'new_price' => $incomingPrice,
                ], 200);
                
            } else {
                // === Цена НЕ изменилась ===
                
                // price_per_unit не трогаем
                // в material_price_histories ничего не пишем
                
                // Но можем обновить статус и другие поля
                if ($statusChanged) {
                    $material->availability_status = $data['availability_status'];
                    $material->last_price_screenshot_path = $screenshotPath;
                    
                    // Обновляем скриншот в текущей активной записи истории
                    if ($lastHistory) {
                        $lastHistory->screenshot_path = $screenshotPath;
                        $lastHistory->save();
                    }
                }
                
                $material->save();
                
                return response()->json([
                    'material' => $material->fresh(),
                    'price_parsed' => true,
                    'price_changed' => false,
                    'current_price' => $currentPrice,
                ], 200);
            }
            
        } else {
            // === Сценарий: создание нового материала ===
            
            // Если цена не распарсилась — не создаём материал без цены
            if (!$priceSuccessfullyParsed) {
                return response()->json([
                    'error' => 'Cannot create material without valid price',
                    'price_parsed' => false,
                ], 422);
            }
            
            // Создаём новый парсерный материал
            $material = Material::create([
                'user_id' => null,
                'origin' => 'parser',
                'name' => $data['name'],
                'article' => $data['article'],
                'type' => $data['type'],
                'unit' => $data['unit'],
                'price_per_unit' => $data['price_per_unit'],
                'source_url' => $data['source_url'],
                'last_price_screenshot_path' => $data['screenshot_path'] ?? null,
                'availability_status' => $data['availability_status'] ?? null,
                'price_checked_at' => $parsedAt, // Сразу ставим время проверки
                'is_active' => true,
                'version' => 1,
            ]);

            // Первая запись в историю цен
            MaterialPriceHistory::create([
                'material_id' => $material->id,
                'version' => 1,
                'valid_from' => $parseDate,
                'valid_to' => null,
                'price_per_unit' => $data['price_per_unit'],
                'source_url' => $data['source_url'],
                'screenshot_path' => $data['screenshot_path'] ?? null,
            ]);
            
            return response()->json([
                'material' => $material,
                'price_parsed' => true,
                'created' => true,
            ], 201);
        }
    }

    /**
     * POST /api/parser/materials/batch
     * 
     * ЭТАП 5: Батч-сохранение материалов.
     * Принимает массив материалов и сохраняет их пачкой.
     */
    public function storeBatch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'session_id' => 'required|integer|exists:parsing_sessions,id',
            'supplier' => 'required|string|max:255',
            'materials' => 'required|array|min:1|max:200',
            'materials.*.name' => 'required|string|max:255',
            'materials.*.article' => 'required|string|max:255',
            'materials.*.type' => 'required|in:plate,edge,fitting',
            'materials.*.unit' => 'required|in:м²,м.п.,шт',
            'materials.*.price_per_unit' => 'nullable|numeric|min:0',
            'materials.*.source_url' => 'required|url',
            'materials.*.screenshot_path' => 'nullable|string|max:512',
            'materials.*.availability_status' => 'nullable|string|max:50',
            'materials.*.supplier_id' => 'nullable|integer',
            'materials.*.currency' => 'nullable|string|max:10',
            'materials.*.parsed_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $materials = $request->input('materials');
        $results = [];

        foreach ($materials as $index => $materialData) {
            try {
                $result = $this->processSingleMaterial($materialData);
                $results[] = [
                    'index' => $index,
                    'article' => $materialData['article'],
                    'success' => true,
                    'price_parsed' => $result['price_parsed'],
                    'price_changed' => $result['price_changed'] ?? false,
                    'created' => $result['created'] ?? false,
                    'material_id' => $result['material']->id ?? null,
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'index' => $index,
                    'article' => $materialData['article'] ?? 'unknown',
                    'success' => false,
                    'error_code' => 'SAVE_ERROR',
                    'error_message' => $e->getMessage(),
                ];
            }
        }

        $successCount = collect($results)->where('success', true)->count();
        $failedCount = collect($results)->where('success', false)->count();

        return response()->json([
            'success' => true,
            'results' => $results,
            'summary' => [
                'total' => count($materials),
                'success' => $successCount,
                'failed' => $failedCount,
            ],
        ]);
    }

    /**
     * Обработка одного материала (для batch и single).
     */
    protected function processSingleMaterial(array $data): array
    {
        $parsedAt = isset($data['parsed_at']) 
            ? Carbon::parse($data['parsed_at']) 
            : Carbon::now();
        $parseDate = $parsedAt->toDateString();

        $priceSuccessfullyParsed = isset($data['price_per_unit']) 
            && $data['price_per_unit'] !== null 
            && $data['price_per_unit'] >= 0;

        // Ищем существующий парсерный материал
        $material = Material::where('article', $data['article'])
            ->where('origin', 'parser')
            ->first();

        if ($material) {
            // Материал существует
            if (!$priceSuccessfullyParsed) {
                // Цена не распарсилась
                if (isset($data['availability_status']) && 
                    $material->availability_status !== $data['availability_status']) {
                    $material->availability_status = $data['availability_status'];
                    $material->save();
                }
                
                return [
                    'material' => $material,
                    'price_parsed' => false,
                    'price_changed' => false,
                ];
            }
            
            // Цена успешно распарсилась
            $material->price_checked_at = $parsedAt;
            
            $lastHistory = MaterialPriceHistory::where('material_id', $material->id)
                ->whereNull('valid_to')
                ->orderBy('valid_from', 'desc')
                ->first();

            $incomingPrice = (float) $data['price_per_unit'];
            $currentPrice = (float) $material->price_per_unit;
            $priceChanged = abs($incomingPrice - $currentPrice) > 0.001;

            if ($priceChanged) {
                // Закрываем старую запись
                if ($lastHistory) {
                    $lastHistory->valid_to = Carbon::parse($parseDate)->subDay()->toDateString();
                    $lastHistory->save();
                }

                // Обновляем материал
                $material->price_per_unit = $incomingPrice;
                $material->source_url = $data['source_url'];
                $material->last_price_screenshot_path = $data['screenshot_path'] ?? null;
                if (isset($data['availability_status'])) {
                    $material->availability_status = $data['availability_status'];
                }
                $material->version += 1;
                $material->save();

                // Новая запись в историю
                MaterialPriceHistory::create([
                    'material_id' => $material->id,
                    'version' => $material->version,
                    'valid_from' => $parseDate,
                    'valid_to' => null,
                    'price_per_unit' => $incomingPrice,
                    'source_url' => $data['source_url'],
                    'screenshot_path' => $data['screenshot_path'] ?? null,
                ]);
                
                return [
                    'material' => $material->fresh(),
                    'price_parsed' => true,
                    'price_changed' => true,
                ];
            } else {
                // Цена не изменилась
                // Всегда обновляем время проверки цены
                $material->price_checked_at = $parsedAt;
                if (isset($data['availability_status'])) {
                    $material->availability_status = $data['availability_status'];
                }
                $material->save();
                
                return [
                    'material' => $material->fresh(),
                    'price_parsed' => true,
                    'price_changed' => false,
                ];
            }
        } else {
            // Новый материал
            if (!$priceSuccessfullyParsed) {
                throw new \Exception('Cannot create material without valid price');
            }
            
            $material = Material::create([
                'user_id' => null,
                'origin' => 'parser',
                'name' => $data['name'],
                'article' => $data['article'],
                'type' => $data['type'],
                'unit' => $data['unit'],
                'price_per_unit' => $data['price_per_unit'],
                'source_url' => $data['source_url'],
                'last_price_screenshot_path' => $data['screenshot_path'] ?? null,
                'availability_status' => $data['availability_status'] ?? null,
                'price_checked_at' => $parsedAt,
                'is_active' => true,
                'version' => 1,
            ]);

            MaterialPriceHistory::create([
                'material_id' => $material->id,
                'version' => 1,
                'valid_from' => $parseDate,
                'valid_to' => null,
                'price_per_unit' => $data['price_per_unit'],
                'source_url' => $data['source_url'],
                'screenshot_path' => $data['screenshot_path'] ?? null,
            ]);
            
            return [
                'material' => $material,
                'price_parsed' => true,
                'created' => true,
            ];
        }
    }

    /**
     * Получает материал по артикулу для проверки перед парсингом.
     */
    public function show(string $article)
    {
        $material = Material::where('article', $article)
            ->where('origin', 'parser')
            ->first();

        if (!$material) {
            return response()->json(['message' => 'Material not found'], 404);
        }

        return response()->json($material);
    }
}
