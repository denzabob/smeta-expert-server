<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MaterialController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        
        Log::info('materials.index request', [
            'authenticated_user_id' => $user?->id,
            'origin' => $request->header('Origin'),
            'referer' => $request->header('Referer'),
            'host' => $request->header('Host'),
        ]);

        if (! $user) {
            Log::warning('materials.index - unauthenticated access attempt');
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $query = Material::where(function ($q) use ($user) {
            $q->where(function ($sub) {
                $sub->whereNull('user_id')
                    ->where('origin', 'parser');
            })->orWhere('user_id', $user->id);
        });

        // Фильтр по типу материала
        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        $materials = $query->orderBy('name')->get();

        return response()->json($materials);
    }

    /**
     * Search materials by name for price import linking.
     */
    public function search(Request $request)
    {
        $user = auth()->user();
        $query = $request->input('q', '');
        
        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $materials = Material::where(function ($q) use ($user) {
            $q->whereNull('user_id')
              ->where('origin', 'parser')
              ->orWhere('user_id', $user?->id);
        })
        ->where(function ($q) use ($query) {
            $q->where('name', 'ILIKE', "%{$query}%")
              ->orWhere('article', 'ILIKE', "%{$query}%")
              ->orWhere('search_name', 'ILIKE', "%{$query}%");
        })
        ->limit(20)
        ->get(['id', 'name', 'unit', 'type', 'article', 'price_per_unit']);

        return response()->json($materials);
    }

    /**
     * Автозаполнение материала по ссылке.
     * - Очищает query/fragment у URL и ищет совпадение в БД.
     * - Если найдено, возвращает существующий материал.
     * - Иначе пытается получить название/цену из страницы (best effort), без скриншота.
     */
    public function fetchByUrl(Request $request)
    {
        $data = $request->validate([
            'source_url' => 'required|url',
        ]);

        $normalizedUrl = $this->normalizeUrl($data['source_url']);

        Log::info('materials.fetchByUrl.start', ['raw' => $data['source_url'], 'normalized' => $normalizedUrl]);

        $existing = Material::whereNotNull('source_url')
            ->get()
            ->first(function (Material $material) use ($normalizedUrl) {
                return $this->normalizeUrl((string) $material->source_url) === $normalizedUrl;
            });

        if ($existing) {
            Log::info('materials.fetchByUrl.hit_db', ['id' => $existing->id]);
            return response()->json([
                'source' => 'database',
                'normalized_url' => $normalizedUrl,
                'material' => $existing,
            ]);
        }

        $suggested = $this->fetchPageData($data['source_url']);

        Log::info('materials.fetchByUrl.suggested', ['status' => $suggested['status'], 'message' => $suggested['message']]);

        return response()->json([
            'source' => $suggested['status'],
            'normalized_url' => $normalizedUrl,
            'suggested' => [
                'name' => $suggested['name'],
                'article' => $suggested['article'],
                'price_per_unit' => $suggested['price_per_unit'],
                'type' => $suggested['type'], // может быть null — спросим у пользователя
                'unit' => $suggested['unit'],
                'source_url' => $data['source_url'],
                'last_price_screenshot_path' => null,
            ],
            'message' => $suggested['message'],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                Log::warning('materials.store attempt without authentication', [
                    'ip' => $request->ip(),
                    'headers' => $request->headers->all(),
                ]);
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            $validated = $this->validatePayload($request);

            // Если origin = 'parser', user_id = NULL
            if ($validated['origin'] === 'parser') {
                $validated['user_id'] = null;
            } else {
                // Для 'user' — привязываем к текущему пользователю
                $validated['user_id'] = auth()->id();
            }

            // Для type=plate: автопарсинг размеров из названия если не указаны
            if ($validated['type'] === 'plate') {
                $this->ensurePlateDimensions($validated);
            }

            $material = Material::create($validated);

            // Создаем первую запись в истории
            MaterialPriceHistory::create([
                'material_id' => $material->id,
                'version' => 1,
                'price_per_unit' => $material->price_per_unit,
                'source_url' => $material->source_url,
                'screenshot_path' => $material->last_price_screenshot_path,
                'valid_from' => now()->toDateString(),
            ]);

            Log::info('materials.store success', ['material_id' => $material->id, 'user_id' => $user->id]);

            return response()->json($material, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('materials.store validation error', ['errors' => $e->errors()]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('materials.store exception', [
                'message' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            throw $e;
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $material = Material::findOrFail($id);
        return response()->json($material);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $material = Material::findOrFail($id);
        $validated = $this->validatePayload($request);

        // Проверка на принадлежность (если не парсерный)
        if ($material->origin === 'user' && $material->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Для type=plate: автопарсинг размеров из названия если не указаны
        if ($validated['type'] === 'plate') {
            $this->ensurePlateDimensions($validated);
        }

        $originalPrice = $material->price_per_unit;

        $material->fill($validated);

        if ($material->price_per_unit != $originalPrice) {
            $material->version = ($material->version ?? 1) + 1;
        }

        $material->save();

        if ($material->price_per_unit != $originalPrice) {
            MaterialPriceHistory::create([
                'material_id' => $material->id,
                'version' => $material->version,
                'price_per_unit' => $material->price_per_unit,
                'source_url' => $material->source_url,
                'screenshot_path' => $material->last_price_screenshot_path,
                'valid_from' => now()->toDateString(),
            ]);
        }

        return response()->json($material);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $material = Material::findOrFail($id);

        // Проверка на принадлежность
        if ($material->origin === 'user' && $material->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $material->delete();

        return response()->noContent();
    }

    /**
     * Get price history for a material.
     */
    public function history(string $id)
    {
        $material = Material::findOrFail($id);
        
        // Получаем историю цен, отсортированную по дате (новые сверху)
        $history = MaterialPriceHistory::where('material_id', $id)
            ->orderBy('valid_from', 'desc')
            ->get();
        
        return response()->json($history);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'origin' => 'required|in:user,parser',
            'name' => 'required|string|max:255',
            'article' => 'required|string|max:255',
            'type' => 'required|in:plate,edge,hardware,facade',
            'price_per_unit' => 'required|numeric|min:0',
            'unit' => 'required|in:м²,м.п.,шт',
            'source_url' => 'nullable|url',
            'is_active' => 'boolean',
            'last_price_screenshot_path' => 'nullable|string|max:2048',
            'operation_ids' => 'nullable|array',
            'operation_ids.*' => 'integer|exists:operations,id',
            'length_mm' => 'nullable|integer|min:1',
            'width_mm' => 'nullable|integer|min:1',
            'thickness_mm' => 'nullable|numeric|min:0.1',
            'metadata' => 'nullable|array',
            // Canonical facade metadata (nested format per ticket)
            'metadata.base' => 'nullable|array',
            'metadata.base.material' => 'nullable|string|max:50',
            'metadata.thickness_mm' => 'nullable|integer|min:1',
            'metadata.finish' => 'nullable|array',
            'metadata.finish.type' => 'nullable|string|max:50',
            'metadata.finish.name' => 'nullable|string|max:100',
            'metadata.finish.variant' => 'nullable|string|max:50',
            'metadata.collection' => 'nullable|string|max:100',
            'metadata.decor' => 'nullable|string|max:255',
            'metadata.price_group' => 'nullable|string|max:10',
            'metadata.film_article' => 'nullable|string|max:100',
        ]);
    }

    /**
     * Проверяет что для type=plate присутствуют размеры листа
     * Если размеры не указаны - пытается распарсить из названия
     * Если не получилось - выбрасывает ValidationException
     */
    private function ensurePlateDimensions(array &$validated): void
    {
        $hasLengthWidth = !empty($validated['length_mm']) && !empty($validated['width_mm']);
        
        if ($hasLengthWidth) {
            // Размеры уже есть - всё хорошо
            return;
        }

        // Пытаемся распарсить из названия
        $dimensions = $this->extractDimensionsFromText($validated['name']);
        
        if ($dimensions && isset($dimensions[0]) && isset($dimensions[1])) {
            $validated['length_mm'] = $dimensions[0];
            $validated['width_mm'] = $dimensions[1];
            if (isset($dimensions[2])) {
                $validated['thickness_mm'] = $dimensions[2];
            }
            Log::info('materials.auto_parsed_dimensions', [
                'name' => $validated['name'],
                'length' => $dimensions[0],
                'width' => $dimensions[1],
                'thickness' => $dimensions[2] ?? null,
            ]);
            return;
        }

        // Не удалось распарсить
        Log::warning('materials.plate_dimensions_not_found', [
            'name' => $validated['name'],
        ]);

        throw \Illuminate\Validation\ValidationException::withMessages([
            'dimensions' => 'Для плитных материалов требуются размеры листа. ' .
                           'Не удалось определить размеры из названия. ' .
                           'Укажите длину и ширину листа в мм.',
        ]);
    }

    /**
     * Извлекает размеры [L, W, T] из текста
     * Поддерживает форматы: "2800х2070х16", "2800 х 2070 х 16 мм", "2800*2070*16"
     */
    private function extractDimensionsFromText(string $text): ?array
    {
        // Нормализуем разделители: ×, *, x, X → х (кириллица)
        $text = preg_replace('/[×*xX]/u', 'х', $text);

        // Паттерн 1: число х число х число мм
        if (preg_match('/(\d+)\s*х\s*(\d+)\s*х\s*(\d+)\s*мм/u', $text, $matches)) {
            return [
                (int)$matches[1],  // L
                (int)$matches[2],  // W
                (int)$matches[3],  // T
            ];
        }

        // Паттерн 2: число х число х число (без мм)
        if (preg_match('/(\d+)\s*х\s*(\d+)\s*х\s*(\d+)(?:\s|$)/u', $text, $matches)) {
            return [
                (int)$matches[1],  // L
                (int)$matches[2],  // W
                (int)$matches[3],  // T
            ];
        }

        // Паттерн 3: число х число (если нет третьего)
        if (preg_match('/(\d+)\s*х\s*(\d+)/u', $text, $matches)) {
            return [
                (int)$matches[1],  // L
                (int)$matches[2],  // W
                null,              // T
            ];
        }

        return null;
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! $parts || ! isset($parts['scheme'], $parts['host'])) {
            return strtolower(preg_replace('/[?#].*/', '', trim($url)));
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $path = rtrim($path, '/') === '' ? '/' : rtrim($path, '/');

        return $scheme . '://' . $host . $port . $path;
    }

    /**
     * Обнаруживает если сайт блокирует парсер
     */
    private function detectBlockedAccess(string $html): array
    {
        $htmlLength = strlen($html);
        
        // Сначала проверяем типовые защиты независимо от размера
        if (preg_match('/cloudflare|bot\s*check|challenge/i', $html)) {
            return [
                'blocked' => true,
                'message' => 'Сайт использует защиту от ботов (Cloudflare). Невозможно получить данные.',
                'reason' => 'cloudflare_protection',
            ];
        }
        
        if (preg_match('/location\.reload|document\.cookie.*expires/i', $html)) {
            return [
                'blocked' => true,
                'message' => 'Сайт блокирует автоматические запросы. Попробуйте загрузить данные вручную.',
                'reason' => 'redirect_protection',
            ];
        }
        
        if (preg_match('/access\s*denied|forbidden.*403|403.*forbidden|blocked.*access/i', $html)) {
            return [
                'blocked' => true,
                'message' => 'Доступ к сайту запрещен.',
                'reason' => 'forbidden',
            ];
        }
        
        // Если HTML очень короткий (менее 256 байт), это явно проблема
        if ($htmlLength < 256) {
            return [
                'blocked' => true,
                'message' => 'Не удалось получить содержимое сайта. Возможно используется защита от ботов.',
                'reason' => 'empty_response',
            ];
        }
        
        // Проверяем наличие реального контента в body
        if (preg_match('/<body[^>]*>(.+?)<\/body>/is', $html, $matches)) {
            $bodyContent = $matches[1];
            // Удаляем скрипты и стили
            $cleanContent = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $bodyContent);
            $cleanContent = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $cleanContent);
            // Удаляем все HTML теги
            $cleanContent = preg_replace('/<[^>]+>/', '', $cleanContent);
            // Удаляем пробелы
            $cleanContent = trim(preg_replace('/\s+/', ' ', $cleanContent));
            
            // Если контента меньше 30 символов - подозрительно
            if (strlen($cleanContent) < 30) {
                return [
                    'blocked' => true,
                    'message' => 'Сайт не возвращает полный контент страницы.',
                    'reason' => 'no_body_content',
                ];
            }
        } else {
            // Нет body тега вообще
            return [
                'blocked' => true,
                'message' => 'Не удалось распарсить структуру страницы.',
                'reason' => 'invalid_html',
            ];
        }
        
        return ['blocked' => false];
    }

    /**
     * Извлекает данные из JSON-LD schema.org структурированных данных
     */
    private function extractSchemaOrgData(string $html): array
    {
        $result = [
            'name' => null,
            'sku' => null,
            'articleSku' => null,
            'price' => null,
        ];

        // Ищем все JSON-LD блоки - используем более гибкий паттерн
        $patterns = [
            '/<script\s+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',
            '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',
            '/<script[^>]*>\s*<!\[CDATA\[\s*({.*?"@type".*?})\s*\]\]><\/script>/is',
        ];

        $foundBlocks = false;
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                $foundBlocks = true;
                Log::debug('Found JSON-LD blocks', ['pattern' => $pattern, 'count' => count($matches[1])]);
                
                foreach ($matches[1] as $idx => $jsonStr) {
                    // Извлекаем JSON из CDATA если нужно
                    $jsonStr = preg_replace('/^<!\[CDATA\[|\]\]>$/is', '', $jsonStr);
                    
                    try {
                        $jsonData = json_decode($jsonStr, true);
                        
                        if (!is_array($jsonData)) {
                            Log::debug("Block $idx is not array", ['type' => gettype($jsonData)]);
                            continue;
                        }
                        
                        // Нормализуем в список узлов JSON-LD:
                        // - одиночный объект
                        // - массив объектов
                        // - объект с @graph
                        $items = [];
                        if (isset($jsonData['@graph']) && is_array($jsonData['@graph'])) {
                            $items = array_values(array_filter($jsonData['@graph'], 'is_array'));
                            if (isset($jsonData['@type']) && is_array($jsonData)) {
                                $items[] = $jsonData;
                            }
                        } elseif (isset($jsonData['@type'])) {
                            $items = [$jsonData];
                        } elseif (array_is_list($jsonData)) {
                            $items = array_values(array_filter($jsonData, 'is_array'));
                        } elseif (is_array($jsonData)) {
                            $items = [$jsonData];
                        }
                        
                        foreach ($items as $itemIdx => $item) {
                            if (!is_array($item)) {
                                Log::debug("Item $itemIdx is not array", ['type' => gettype($item)]);
                                continue;
                            }
                            
                            $type = $item['@type'] ?? null;
                            $types = is_array($type) ? $type : ($type ? [$type] : []);
                            $isProduct = in_array('Product', $types, true);
                            $isOffer = in_array('Offer', $types, true) || in_array('AggregateOffer', $types, true);
                            Log::debug("Checking type", ['type' => $type, 'isProduct' => $isProduct, 'isOffer' => $isOffer]);

                            // Извлекаем название
                            if ($isProduct && isset($item['name']) && empty($result['name'])) {
                                $name = trim((string)$item['name']);
                                // Очищаем от суффиксов типа "купить в компании..."
                                $name = preg_replace('/\s+(?:купить|заказать|в компании|магазин).*/ui', '', $name);
                                $result['name'] = $name;
                                Log::debug("Extracted name from schema", ['name' => $name]);
                            }

                            // Извлекаем SKU - это уникальный идентификатор товара
                            if ($isProduct && isset($item['sku']) && empty($result['sku'])) {
                                $result['sku'] = trim((string)$item['sku']);
                                Log::debug("Extracted sku from schema", ['sku' => $result['sku']]);
                            }

                            // Ищем артикул в additionalProperty (это может быть артикул производителя)
                            if ($isProduct && isset($item['additionalProperty']) && is_array($item['additionalProperty'])) {
                                foreach ($item['additionalProperty'] as $prop) {
                                    if (!is_array($prop)) continue;
                                    
                                    $propName = $prop['name'] ?? '';
                                    $propValue = $prop['value'] ?? null;
                                    
                                    Log::debug("Checking additionalProperty", ['name' => $propName, 'value' => $propValue]);
                                    
                                    // Ищем свойство с названием, содержащим "артикул"
                                    if (preg_match('/артикул/ui', (string)$propName) && empty($result['articleSku'])) {
                                        $result['articleSku'] = trim((string)$propValue);
                                        Log::debug("Extracted articleSku from schema", ['articleSku' => $result['articleSku']]);
                                    }
                                }
                            }

                            // Цена в Product.offers
                            if ($isProduct && isset($item['offers']) && empty($result['price'])) {
                                $offers = $item['offers'];
                                
                                // Нормализуем offers в массив
                                if (!is_array($offers)) {
                                    $offers = [$offers];
                                } elseif (isset($offers['@type'])) {
                                    // Это одиночное предложение, оборачиваем в массив
                                    $offers = [$offers];
                                }
                                
                                foreach ($offers as $offerIdx => $offer) {
                                    if (!is_array($offer)) {
                                        Log::debug("Offer $offerIdx is not array", ['type' => gettype($offer)]);
                                        continue;
                                    }

                                    $rawPrice = $offer['price']
                                        ?? $offer['lowPrice']
                                        ?? $offer['highPrice']
                                        ?? ($offer['priceSpecification']['price'] ?? null);
                                    if ($rawPrice !== null) {
                                        $candidate = $this->cleanPrice((string) $rawPrice);
                                        if ($this->isValidPrice($candidate)) {
                                            $result['price'] = (float) $candidate;
                                            Log::debug("Extracted price from schema Product.offers", ['price' => $result['price']]);
                                            break;
                                        }
                                    }
                                }
                            }

                            // Цена в узле Offer/AggregateOffer
                            if ($isOffer && empty($result['price'])) {
                                $rawPrice = $item['price']
                                    ?? $item['lowPrice']
                                    ?? $item['highPrice']
                                    ?? ($item['priceSpecification']['price'] ?? null);
                                if ($rawPrice !== null) {
                                    $candidate = $this->cleanPrice((string) $rawPrice);
                                    if ($this->isValidPrice($candidate)) {
                                        $result['price'] = (float) $candidate;
                                        Log::debug("Extracted price from schema Offer", ['price' => $result['price']]);
                                    }
                                }
                            }
                            
                            // Если нашли все данные, можем выйти
                            if ($result['name'] && $result['price']) {
                                break;
                            }
                        }
                        
                        // Если нашли все нужные данные, выходим
                        if ($result['name'] && $result['price']) {
                            break;
                        }
                    } catch (\Throwable $e) {
                        Log::debug('Failed to parse JSON-LD block', ['error' => $e->getMessage(), 'block' => substr($jsonStr, 0, 100)]);
                    }
                }
                
                // Если нашли данные, выходим
                if ($result['name'] && $result['price']) {
                    break;
                }
            }
        }
        
        if (!$foundBlocks) {
            Log::debug('No JSON-LD blocks found with any pattern');
        }

        return $result;
    }

    /**
     * Извлекает данные из микроданных schema.org (microdata с itemscope/itemprop)
     * Используется когда JSON-LD недоступен
     */
    private function extractMicrodata(string $html): array
    {
        $result = [
            'name' => null,
            'sku' => null,
            'articleSku' => null,
            'price' => null,
        ];

        // Попытка 1: Ищем элементы типа Product
        if (preg_match('/itemscope[^>]*itemtype=["\'](?:https?:\/\/)?schema\.org\/Product["\'][^>]*>(.*?)<\/(?:div|section)>/is', $html, $matches)) {
            $productBlock = $matches[1];
            Log::debug('Found microdata Product block');
            
            // Извлекаем name из itemprop="name" - может быть в span, div, h1, h2, meta и т.д.
            if (preg_match('/<(?:span|div|h[1-6])[^>]*itemprop=["\']name["\'][^>]*>([^<]+)<\/(?:span|div|h[1-6])>/i', $productBlock, $m)) {
                $result['name'] = trim(strip_tags($m[1]));
                Log::debug('Extracted name from Product block (tag)', ['name' => $result['name']]);
            } elseif (preg_match('/<meta[^>]+itemprop=["\']name["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $productBlock, $m)) {
                $result['name'] = trim($m[1]);
                Log::debug('Extracted name from Product block (meta)', ['name' => $result['name']]);
            }
            
            // Извлекаем SKU из itemprop="sku"
            if (preg_match('/<(?:span|div)[^>]*itemprop=["\']sku["\'][^>]*>([^<]+)<\/(?:span|div)>/i', $productBlock, $m)) {
                $result['sku'] = trim($m[1]);
                Log::debug('Extracted sku from Product block', ['sku' => $result['sku']]);
            } elseif (preg_match('/<meta[^>]+itemprop=["\']sku["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $productBlock, $m)) {
                $result['sku'] = trim($m[1]);
                Log::debug('Extracted sku from Product block (meta)', ['sku' => $result['sku']]);
            }
        }
        
        // Попытка 2: Если name не найден в Product блоке, ищем глобально
        // (у некоторых поставщиков name может быть вне Product или вообще нет Product)
        if (!$result['name']) {
            if (preg_match('/<meta[^>]+itemprop=["\']name["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
                $result['name'] = trim($m[1]);
                Log::debug('Extracted name from global meta', ['name' => $result['name']]);
            }
        }

        // Ищем элементы типа PropertyValue для артикула
        // Структура: <div itemprop="additionalProperty" itemscope itemtype="http://schema.org/PropertyValue">
        //            <span itemprop="name">Артикул:</span>
        //            <span itemprop="value">U705 (ST9)</span>
        if (preg_match_all('/itemscope[^>]*itemtype=["\'](?:https?:\/\/)?schema\.org\/PropertyValue["\'][^>]*>(.*?)<\/(?:div|span)>/is', $html, $matches)) {
            foreach ($matches[1] as $propBlock) {
                // Ищем span с itemprop="name" содержащим "Артикул"
                if (preg_match('/<span[^>]*itemprop=["\']name["\'][^>]*>([^<]+)<\/span>/i', $propBlock, $nameMatch)) {
                    if (strpos($nameMatch[1], 'Артикул') !== false) {
                        // Извлекаем value span
                        if (preg_match('/<span[^>]*itemprop=["\']value["\'][^>]*>([^<]+)<\/span>/i', $propBlock, $m)) {
                            $result['articleSku'] = trim($m[1]);
                            Log::debug('Extracted articleSku from microdata PropertyValue', ['articleSku' => $result['articleSku']]);
                            break;
                        }
                    }
                }
            }
        }

        // Ищем элементы типа Offer для цены (может быть div, span или что-то еще)
        // Offer может содержать самозакрывающиеся теги (meta, link), поэтому ищем до закрывающего тега
        if (preg_match('/itemscope[^>]*itemtype=["\'](?:https?:\/\/)?schema\.org\/Offer["\'][^>]*>(.*?)<\/(?:div|span|p)>/is', $html, $matches)) {
            $offerBlock = $matches[1];
            Log::debug('Found microdata Offer block');
            
            // Извлекаем price из meta itemprop="price"
            // Пытаемся несколько вариантов: с одинарными/двойными кавычками, с/без кавычек вокруг значения
            if (preg_match('/<meta[^>]+itemprop=["\']price["\'][^>]+content=["\']?([0-9.,]+)["\']?[^>]*>/i', $offerBlock, $m)) {
                $price = $m[1];
                $price = str_replace(',', '.', $price); // Заменяем запятую на точку
                if (is_numeric($price)) {
                    $result['price'] = (float) $price;
                    Log::debug('Extracted price from microdata Offer', ['price' => $result['price']]);
                }
            }
        }
        
        // Если цена не найдена в Offer блоке и нет никакого Offer блока,
        // ищем price meta глобально (у некоторых поставщиков price может быть одиночным meta тегом)
        if (!$result['price']) {
            if (preg_match('/<meta[^>]+itemprop=["\']price["\'][^>]+content=["\']?([0-9.,]+)["\']?[^>]*>/i', $html, $m)) {
                $price = $m[1];
                $price = str_replace(',', '.', $price);
                if (is_numeric($price)) {
                    $result['price'] = (float) $price;
                    Log::debug('Extracted price from global meta', ['price' => $result['price']]);
                }
            }
        }

        return $result;
    }

    /**
     * Извлекает описание из микроданных для использования в качестве имени товара
     */
    private function extractMicrodataDescription(string $html): ?string
    {
        // Ищем meta itemprop="description" внутри Product блока
        if (preg_match('/itemscope[^>]*itemtype=["\'](?:https?:\/\/)?schema\.org\/Product["\'][^>]*>.*?<meta[^>]+itemprop=["\']description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/is', $html, $matches)) {
            $description = trim(html_entity_decode($matches[1]));
            Log::debug('Extracted description from microdata', ['description' => $description]);
            return $description;
        }
        
        return null;
    }

    private function fetchPageData(string $url): array
    {
        $result = [
            'status' => 'fetched',
            'name' => null,
            'article' => null,
            'price_per_unit' => null,
            'type' => null,
            'unit' => null,
            'message' => null,
        ];

        try {
            // Сначала пробуем обычный HTTP запрос
            $html = $this->fetchWithHttp($url);
            
            // Проверяем не блокирован ли парсер
            $blockDetection = $this->detectBlockedAccess($html);
            
            // Если обычный запрос заблокирован, пробуем Puppeteer
            if ($blockDetection['blocked']) {
                Log::info('HTTP fetch blocked, trying Puppeteer', ['url' => $url, 'reason' => $blockDetection['reason']]);
                
                $puppeteerResult = $this->fetchWithPuppeteer($url);
                if ($puppeteerResult['success']) {
                    $html = $puppeteerResult['html'];
                    Log::info('Puppeteer fetch succeeded', ['url' => $url]);
                } else {
                    // Оба метода не сработали
                    $result['status'] = 'blocked';
                    $result['message'] = $blockDetection['message'];
                    return $result;
                }
            }

            $result = $this->extractFieldsFromHtml($html, $result, $url);
        } catch (\Throwable $e) {
            Log::warning('materials.fetchByUrl http failed, trying Puppeteer fallback', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            $puppeteerResult = $this->fetchWithPuppeteer($url);
            if ($puppeteerResult['success']) {
                $result = $this->extractFieldsFromHtml($puppeteerResult['html'], $result, $url);
                $result['message'] = 'Данные получены через браузерный fallback';
            } else {
                $puppeteerError = $puppeteerResult['error'] ?? 'unknown';
                Log::error('materials.fetchByUrl exception', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'puppeteer_error' => $puppeteerError,
                ]);
                $result['status'] = 'fetch_failed';
                $result['message'] = 'Ошибка при загрузке страницы: ' . $e->getMessage() . '. Browser fallback: ' . $puppeteerError;
            }
        }

        return $result;
    }

    private function extractFieldsFromHtml(string $html, array $result, string $url): array
    {
        // Пытаемся извлечь из JSON-LD schema.org
        $schemaData = $this->extractSchemaOrgData($html);

        // Если JSON-LD не нашли, пробуем микроданные
        if (empty($schemaData['price']) && empty($schemaData['name'])) {
            Log::debug('JSON-LD empty, trying microdata');
            $microData = $this->extractMicrodata($html);
            if (!empty($microData['price'])) {
                $schemaData['price'] = $microData['price'];
            }
            if (!empty($microData['name'])) {
                $schemaData['name'] = $microData['name'];
            }
            if (!empty($microData['articleSku'])) {
                $schemaData['articleSku'] = $microData['articleSku'];
            }
        }

        $extractedName = $schemaData['name']
            ?? $this->extractOgMeta($html, 'og:title')
            ?? $this->extractTitle($html)
            ?? $this->extractMicrodataDescription($html);

        // Очищаем название от коммерческих суффиксов
        if ($extractedName) {
            $extractedName = preg_replace('/\s+(?:купить|заказать|в компании|в магазине|магазин|интернет[\s-]магазин|online|shop).*/ui', '', $extractedName);
            $extractedName = trim($extractedName);
        }

        $extractedArticle = $schemaData['sku']
            ?? $schemaData['articleSku']
            ?? $this->extractArticle($html, $extractedName);

        $extractedPrice = $schemaData['price']
            ?? $this->extractPrice($html);

        $result['name'] = $extractedName;
        $result['article'] = $extractedArticle;
        $result['price_per_unit'] = $extractedPrice;

        $typeGuess = $this->guessType($result['name']);
        $result['type'] = $typeGuess;
        $result['unit'] = $this->guessUnit($typeGuess);
        $result['message'] = 'Данные получены из страницы';

        Log::debug('materials.fetchByUrl extracted', [
            'url' => $url,
            'name' => $result['name'],
            'article' => $result['article'],
            'price' => $result['price_per_unit'],
            'type' => $result['type'],
        ]);

        return $result;
    }

    /**
     * Загружает страницу используя обычный HTTP запрос
     */
    private function fetchWithHttp(string $url): string
    {
        $client = Http::timeout(12)->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'same-origin',
            'Sec-Fetch-User' => '?1',
            'Sec-Ch-Ua' => '"Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"',
            'Sec-Ch-Ua-Mobile' => '?0',
            'Sec-Ch-Ua-Platform' => '"Windows"',
            'Referer' => 'https://www.google.com/',
            'DNT' => '1',
            'Cache-Control' => 'max-age=0',
        ])->withOptions([
            // Some supplier CDNs terminate TLS/HTTP2 abruptly; use conservative transport settings.
            'version' => CURL_HTTP_VERSION_1_1,
            'curl' => [
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            ],
        ]);

        try {
            $response = $client->get($url);
        } catch (\Throwable $e) {
            // Повтор с отключенной проверкой TLS
            $response = $client->withoutVerifying()->get($url);
        }

        if (!$response->ok()) {
            throw new \Exception('HTTP Error: ' . $response->status());
        }

        return $response->body();
    }

    /**
     * Загружает страницу используя Playwright (Headless Chrome)
     * Обходит Cloudflare и другие защиты
     */
    private function fetchWithPuppeteer(string $url): array
    {
        try {
            $scriptPath = base_path('scripts/scrape-page-pw.js');
            
            if (!file_exists($scriptPath)) {
                return [
                    'success' => false,
                    'error' => 'Playwright script not found',
                    'html' => null,
                ];
            }

            // Запускаем Node.js скрипт для парсинга
            $process = proc_open(
                "node " . escapeshellarg($scriptPath) . " " . escapeshellarg($url),
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                base_path(),
                [
                    'NODE_ENV' => 'production',
                    'PLAYWRIGHT_BROWSERS_PATH' => '/root/.cache/ms-playwright',
                    'HOME' => '/root',
                ]
            );

            if (!is_resource($process)) {
                throw new \Exception('Failed to start Puppeteer process');
            }

            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $returnCode = proc_close($process);

            if ($returnCode !== 0) {
                $stderrPayload = json_decode(trim($error), true);
                $stderrMessage = is_array($stderrPayload) ? ($stderrPayload['error'] ?? null) : null;
                Log::warning('Puppeteer process failed', [
                    'return_code' => $returnCode,
                    'stderr' => $error,
                    'url' => $url,
                ]);
                return [
                    'success' => false,
                    'error' => $stderrMessage ?: 'Puppeteer process failed',
                    'html' => null,
                ];
            }

            $result = json_decode($output, true);

            if (!isset($result['success']) || !$result['success']) {
                Log::warning('Puppeteer returned error', [
                    'error' => $result['error'] ?? 'Unknown',
                    'url' => $url,
                ]);
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Unknown error',
                    'html' => null,
                ];
            }

            return [
                'success' => true,
                'html' => $result['html'],
            ];
        } catch (\Throwable $e) {
            Log::error('Puppeteer fetch exception', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'html' => null,
            ];
        }
    }

    private function extractOgMeta(string $html, string $property): ?string
    {
        // Вариант 1: property="..."
        $pattern = '/<meta[^>]+property=["\']' . preg_quote($property, '/') . '["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i';
        if (preg_match($pattern, $html, $matches)) {
            return trim($matches[1]);
        }

        // Вариант 2: name="..." (для некоторых мета-тегов)
        $pattern = '/<meta[^>]+name=["\']' . preg_quote($property, '/') . '["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i';
        if (preg_match($pattern, $html, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractTitle(string $html): ?string
    {
        // 1. Пробуем h1 с id="pagetitle" или любой h1
        if (preg_match('/<h1[^>]*(?:id=["\']pagetitle["\'][^>]*)?>([^<]+)<\/h1>/i', $html, $matches)) {
            $title = trim(strip_tags($matches[1]));
            if (strlen($title) > 3) {
                return $title;
            }
        }
        
        // 2. Если нет pagetitle, ищем просто любой h1
        if (preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $html, $matches)) {
            $title = trim(strip_tags($matches[1]));
            if (strlen($title) > 3) {
                return $title;
            }
        }

        // 3. Пробуем title тег
        if (preg_match('/<title>([^<]+)<\/title>/i', $html, $matches)) {
            $title = trim($matches[1]);
            // Удаляем общие суффиксы сайтов
            $title = preg_replace('/\s*[-—|]\s*(?:интернет[\s-]магазин|магазин|shop|каталог).*/ui', '', $title);
            if (strlen($title) > 3) {
                return $title;
            }
        }

        // 4. Ищем в мета description
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            $title = trim(explode('|', $matches[1])[0]);
            if (strlen($title) > 3) {
                return $title;
            }
        }

        return null;
    }

    private function extractArticle(string $html, ?string $name = null): ?string
    {
        // 1) Common labels: "Артикул", "SKU", "Код товара"
        $patterns = [
            '/(?<![\p{L}\p{N}_])(?:артикул|арт\.?|sku|код\s*товара)(?![\p{L}\p{N}_])\s*[:#]?\s*([A-Za-z0-9._\-\/]{3,64})/ui',
            '/itemprop=["\']sku["\'][^>]*>\s*([^<\s]{3,64})\s*</ui',
            '/data-(?:sku|article)=["\']([^"\']{3,64})["\']/ui',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $candidate = trim((string) ($m[1] ?? ''));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        // 2) Fallback: if name contains token in parentheses, use it as article
        if ($name && preg_match('/\(([A-Za-z0-9._\-\/]{3,64})\)/u', $name, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractPrice(string $html): ?float
    {
        // 1. Ищем в мета тегах (og:price, product:price)
        $metaPrice = $this->extractOgMeta($html, 'product:price:amount')
            ?? $this->extractOgMeta($html, 'og:price:amount')
            ?? $this->extractOgMeta($html, 'product:price')
            ?? $this->extractOgMeta($html, 'price');

        if ($metaPrice !== null && $this->isValidPrice($metaPrice)) {
            return (float) str_replace(',', '.', $metaPrice);
        }

        // 2. Точный UI-узел цены (напр. Boyard): <li class="bd-price__current">2 703,79 ₽</li>
        if (preg_match('/<li[^>]*class=["\'][^"\']*bd-price__current[^"\']*["\'][^>]*>\s*([0-9][0-9\s]{1,}[.,][0-9]{1,2})\s*₽?\s*<\/li>/ui', $html, $matches)) {
            $price = $this->cleanPrice($matches[1]);
            if ($this->isValidPrice($price)) {
                return (float) $price;
            }
        }

        // 3. Ищем явную цену в рублях с учетом тысячных пробелов: "2 586,46 ₽"
        if (preg_match('/([0-9][0-9\s]{1,}[.,][0-9]{1,2})\s*(?:₽|руб(?:\.|ля|лей)?)/ui', $html, $matches)) {
            $price = $this->cleanPrice($matches[1]);
            if ($this->isValidPrice($price)) {
                return (float) $price;
            }
        }

        // 4. Ищем в атрибутах data-price, цена и т.д.
        if (preg_match('/data-price=["\']([0-9]+(?:[.,][0-9]{1,2})?)["\']*/ui', $html, $matches)) {
            if ($this->isValidPrice($matches[1])) {
                return (float) str_replace(',', '.', $matches[1]);
            }
        }

        // 5. Ищем в классах span/div с id или class содержащих "price"
        if (preg_match('/<(?:span|div)[^>]*(?:id|class)=["\'](?:[^"\']*)?price[^"\']*["\'][^>]*>(?:\D)*([0-9\s,.]{2,20})(?:\D)/ui', $html, $matches)) {
            $price = $this->cleanPrice($matches[1]);
            if ($this->isValidPrice($price)) {
                return (float) $price;
            }
        }

        // 6. Ищем паттерн "цена: XXXX" или "Price: XXXX"
        // Защита от ложного срабатывания на "цена за 1 компл"
        if (preg_match('/(?:цена|price|стоимость)[:\s]+([0-9\s,.]{2,20})(?:\s|₽|р|руб|грн|у\.е\.|\.)/ui', $html, $matches)) {
            $price = $this->cleanPrice($matches[1]);
            $num = is_numeric($price) ? (float) $price : 0.0;
            if ($num > 1 && $this->isValidPrice($price)) {
                return (float) $price;
            }
        }

        // 7. Ищем в JSON-LD структурированных данных (резервный вариант)
        if (preg_match('/"price"[:\s]*"?([0-9]+(?:[.,][0-9]{1,2})?)"?/ui', $html, $matches)) {
            if ($this->isValidPrice($matches[1])) {
                return (float) str_replace(',', '.', $matches[1]);
            }
        }

        // 8. Fallback: ищем любое число с разделителем (может быть в конце, рядом с валютой)
        if (preg_match('/([0-9]{3,}[.,][0-9]{1,2})\s*(?:₽|р\.п\.|грн|\.)/u', $html, $matches)) {
            $price = $this->cleanPrice($matches[1]);
            if ($this->isValidPrice($price)) {
                return (float) $price;
            }
        }

        // 9. Последний вариант - просто ищем большое число (но предварительно удаляя даты и артикулы)
        // Удаляем даты и очевидные артикулы
        $cleaned = preg_replace('/\d{1,2}[.-]\d{1,2}[.-]\d{2,4}/', '', $html);
        if (preg_match('/([0-9]{4,}[.,][0-9]{2})/u', $cleaned, $matches)) {
            $price = $this->cleanPrice($matches[1]);
            if ($this->isValidPrice($price)) {
                return (float) $price;
            }
        }

        return null;
    }

    private function cleanPrice(string $price): string
    {
        // Удаляем пробелы и неразрывные пробелы
        $price = str_replace([' ', "\u{00A0}"], '', $price);
        // Заменяем запятую на точку для float
        $price = str_replace(',', '.', $price);
        return $price;
    }

    private function isValidPrice(string $price): bool
    {
        // Очищаем цену
        $cleaned = $this->cleanPrice($price);
        
        // Проверяем, что это валидное число
        if (!is_numeric($cleaned)) {
            return false;
        }
        
        $numPrice = (float) $cleaned;
        
        // Цена должна быть разумной (от 0.1 до 10 млн рублей)
        return $numPrice >= 0.1 && $numPrice <= 10000000;
    }

    private function guessType(?string $name): ?string
    {
        if (! $name) {
            return null;
        }

        $lower = mb_strtolower($name);
        
        // Кромка (меньше приоритет, но проверяем в первую очередь для точности)
        if (preg_match('/кром|кромочн|полос|edge|trim/u', $lower)) {
            return 'edge';
        }
        
        // Плита (ЛДСП, ДСП, МДФ и т.д.)
        if (preg_match('/плита|лдсп|дсп|мдф|дереи|фанер|board|plywood/u', $lower)) {
            return 'plate';
        }
        
        // Фурнитура (петли, ручки, направляющие, ножки и т.д.)
        if (preg_match('/петл|ручк|направля|ножк|фурнитур|шарнир|крепл|замок|ручка|hardware|fixture/u', $lower)) {
            return 'hardware';
        }

        return null;
    }

    private function guessUnit(?string $type): ?string
    {
        return match ($type) {
            'edge' => 'м.п.',
            'plate' => 'м²',
            'hardware' => 'шт',
            default => null,
        };
    }
}
