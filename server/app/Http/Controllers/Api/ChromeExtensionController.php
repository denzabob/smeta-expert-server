<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParserSupplierCollectProfile;
use App\Models\User;
use App\Services\ChromeExtractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ChromeExtensionController extends Controller
{
    protected ChromeExtractService $service;

    public function __construct(ChromeExtractService $service)
    {
        $this->service = $service;
    }

    /**
     * POST /api/chrome/auth/token
     * Issue a Sanctum API token for chrome extension (public, no auth middleware).
     */
    public function issueToken(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Неверный email или пароль'], 401);
        }

        // Revoke old chrome extension tokens
        $user->tokens()->where('name', 'chrome-extension')->delete();

        // Create new token
        $token = $user->createToken('chrome-extension', ['chrome-ext']);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * POST /api/chrome/auth/token/session (protected)
     * Issue a Sanctum API token for chrome extension from current authenticated session.
     */
    public function issueTokenFromSession(Request $request): JsonResponse
    {
        $user = $request->user();

        // Revoke old chrome extension tokens
        $user->tokens()->where('name', 'chrome-extension')->delete();

        // Create new token
        $token = $user->createToken('chrome-extension', ['chrome-ext']);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * GET /api/chrome/auth/status (protected)
     * Return current chrome-extension token presence for authenticated user.
     */
    public function tokenStatus(Request $request): JsonResponse
    {
        $token = $request->user()
            ->tokens()
            ->where('name', 'chrome-extension')
            ->latest('id')
            ->first();

        return response()->json([
            'has_token' => (bool) $token,
            'token_meta' => $token ? [
                'id' => $token->id,
                'created_at' => optional($token->created_at)->toIso8601String(),
                'last_used_at' => optional($token->last_used_at)->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * POST /api/chrome/auth/revoke (protected)
     * Revoke the chrome extension token.
     */
    public function revokeToken(Request $request): JsonResponse
    {
        $request->user()->tokens()->where('name', 'chrome-extension')->delete();
        return response()->json(['message' => 'Токен отозван']);
    }

    /**
     * GET /api/chrome/me
     * Returns user info + region_id + auth status for the chrome extension.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $user->settings;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'region_id' => $settings?->region_id,
            'authenticated' => true,
        ]);
    }

    /**
     * POST /api/chrome/templates
     * Save or update an extraction template for a domain.
     *
     * Body:
     *  - domain: string (required)
     *  - name: string (required)
     *  - selectors: object (required) — CSS/XPath selectors for each field
     *  - url_patterns: array (optional) — patterns to match URL
     *  - extraction_rules: object (optional) — trim/replace/normalize rules
     *  - validation_rules: object (optional) — required fields config
     *  - test_case: object (optional) — extracted values as validation
     *  - is_default: bool (optional)
     */
    public function saveTemplate(Request $request): JsonResponse
    {
        $hasSchemaMapping = $request->has('schema_mapping');

        $rules = [
            'domain' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'selectors' => $hasSchemaMapping ? 'nullable|array' : 'required|array',
            'selectors.title' => $hasSchemaMapping ? 'nullable|string' : 'required|string',
            'selectors.price' => $hasSchemaMapping ? 'nullable|string' : 'required|string',
            'selectors.article' => 'nullable|string',
            'selectors.thickness' => 'nullable|string',
            'selectors.length' => 'nullable|string',
            'selectors.width' => 'nullable|string',
            'selectors.currency' => 'nullable|string',
            'url_patterns' => 'nullable|array',
            'url_patterns.*.regex' => 'nullable|string',
            'url_patterns.*.path_contains' => 'nullable|string',
            'extraction_rules' => 'nullable|array',
            'validation_rules' => 'nullable|array',
            'test_case' => 'nullable|array',
            'is_default' => 'nullable|boolean',
            'schema_mapping' => 'nullable|array',
            'schema_mapping.schemaIndex' => 'nullable|integer',
            'schema_mapping.mapping' => 'nullable|array',
        ];

        $validated = $request->validate($rules);

        // Merge schema_mapping into extraction_rules for storage
        $extractionRules = $validated['extraction_rules'] ?? [];
        if ($hasSchemaMapping && !empty($validated['schema_mapping'])) {
            $extractionRules['schema_mapping'] = $validated['schema_mapping'];
        }

        $user = $request->user();

        $template = $this->service->saveTemplate(
            userId: $user->id,
            domain: $validated['domain'],
            name: $validated['name'],
            selectors: $validated['selectors'] ?? [],
            urlPatterns: $validated['url_patterns'] ?? null,
            extractionRules: $extractionRules ?: null,
            validationRules: $validated['validation_rules'] ?? null,
            testCase: $validated['test_case'] ?? null,
            isDefault: (bool) ($validated['is_default'] ?? false),
        );

        return response()->json([
            'template' => $template,
            'message' => 'Шаблон сохранён (версия ' . $template->version . ')',
        ]);
    }

    /**
     * GET /api/chrome/templates
     * List templates for a domain (user's + system defaults).
     *
     * Query: ?domain=example.com
     */
    public function listTemplates(Request $request): JsonResponse
    {
        $request->validate([
            'domain' => 'required|string|max:255',
        ]);

        $user = $request->user();
        $domain = $request->input('domain');

        $templates = ParserSupplierCollectProfile::where('supplier_name', $domain)
            ->where('source', 'chrome_ext')
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhereNull('user_id');
            })
            ->orderByDesc('is_default')
            ->orderByDesc('version')
            ->get();

        return response()->json([
            'domain' => $domain,
            'templates' => $templates,
        ]);
    }

    /**
     * GET /api/chrome/templates/{id}
     * Get a single template by ID.
     */
    public function getTemplate(int $id, Request $request): JsonResponse
    {
        $user = $request->user();

        $template = ParserSupplierCollectProfile::where('id', $id)
            ->where('source', 'chrome_ext')
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhereNull('user_id');
            })
            ->first();

        if (!$template) {
            return response()->json(['message' => 'Шаблон не найден'], 404);
        }

        return response()->json(['template' => $template]);
    }

    /**
     * DELETE /api/chrome/templates/{id}
     * Delete user's own template.
     */
    public function deleteTemplate(int $id, Request $request): JsonResponse
    {
        $user = $request->user();

        $template = ParserSupplierCollectProfile::where('id', $id)
            ->where('source', 'chrome_ext')
            ->where('user_id', $user->id)
            ->first();

        if (!$template) {
            return response()->json(['message' => 'Шаблон не найден или вы не являетесь владельцем'], 404);
        }

        $template->delete();

        return response()->json(['success' => true, 'message' => 'Шаблон удалён']);
    }

    /**
     * POST /api/chrome/extract
     * Extract material from URL using captured values.
     *
     * Body:
     *  - url: string (required)
     *  - extracted: object (required)
     *    - title: string
     *    - price: string
     *    - article: string|null
     *    - unit: string|null
     *    - availability: string|null
     *  - template_id: int|null (optional)
     *  - region_id: int|null (optional, defaults to user's profile region)
     */
    public function extract(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => 'required|url|max:2048',
            'extracted' => 'required|array',
            'extracted.title' => 'required|string|max:500',
            'extracted.price' => 'required|string|max:100',
            'extracted.article' => 'nullable|string|max:255',
            'extracted.thickness' => 'nullable|string|max:20',
            'extracted.length' => 'nullable|string|max:20',
            'extracted.width' => 'nullable|string|max:20',
            'data_sources' => 'nullable|array',
            'data_sources.*' => 'nullable|string|in:auto,capture,schema,manual',
            'template_id' => 'nullable|integer|exists:parser_supplier_collect_profiles,id',
            'region_id' => 'nullable|integer|exists:regions,id',
        ]);

        $user = $request->user();

        // Determine region_id
        $regionId = $validated['region_id'] ?? $user->settings?->region_id;

        $result = $this->service->createOrUpdateMaterial(
            userId: $user->id,
            url: $validated['url'],
            extractedFields: $validated['extracted'],
            regionId: $regionId,
            templateId: $validated['template_id'] ?? null,
            dataSources: $validated['data_sources'] ?? [],
        );

        if ($result['status'] === 'failed') {
            return response()->json([
                'success' => false,
                'errors' => $result['errors'],
                'message' => 'Не удалось создать материал: ' . implode('; ', $result['errors']),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'material' => $result['material'],
            'observation' => $result['observation'],
            'is_new' => $result['is_new'],
            'dedup_match' => $result['dedup_match'],
            'errors' => $result['errors'],
            'status' => $result['status'],
            'message' => $result['is_new']
                ? 'Материал создан и добавлен в библиотеку'
                : 'Существующий материал обновлён (дедупликация: ' . ($result['dedup_match'] ?? '-') . ')',
        ]);
    }

    /**
     * POST /api/chrome/find-template
     * Find matching template for a given URL.
     *
     * Body: { url: string }
     */
    public function findTemplate(Request $request): JsonResponse
    {
        $request->validate(['url' => 'required|url|max:2048']);

        $user = $request->user();
        $url = $request->input('url');
        $domain = ChromeExtractService::extractDomain($url);

        $template = $this->service->findTemplate($url, $user->id);

        return response()->json([
            'domain' => $domain,
            'template' => $template,
            'has_template' => $template !== null,
        ]);
    }

    /**
     * POST /api/chrome/validate
     * Validate extracted fields without creating a material.
     *
     * Body: { extracted: { title, price, unit, article, availability } }
     */
    public function validateFields(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'extracted' => 'required|array',
            'extracted.title' => 'nullable|string',
            'extracted.price' => 'nullable|string',
            'extracted.article' => 'nullable|string',
            'extracted.thickness' => 'nullable|string',
            'extracted.length' => 'nullable|string',
            'extracted.width' => 'nullable|string',
            'data_sources' => 'nullable|array',
            'data_sources.*' => 'nullable|string|in:auto,capture,schema,manual',
            'url' => 'nullable|string|max:2048',
        ]);

        $dataSources = $validated['data_sources'] ?? [];
        $sourceUrl = $validated['url'] ?? null;

        $errors = $this->service->validateExtractedFields($validated['extracted'], $sourceUrl);
        $trustInfo = $this->service->determineTrustLevel($validated['extracted'], $errors, $dataSources, $sourceUrl);

        $title = trim($validated['extracted']['title'] ?? '');
        $materialType = $title ? ChromeExtractService::detectMaterialType($title, $sourceUrl) : 'hardware';
        $unit = match ($materialType) {
            'edge' => 'м.п.',
            'plate' => 'м²',
            default => 'шт',
        };

        // Resolve dimensions based on material type
        $thickness = null;
        $length = null;
        $width = null;

        if ($materialType === 'edge') {
            $parsedEdge = ChromeExtractService::parseEdgeDimensionsFromName($title);
            $length = !empty($validated['extracted']['length'])
                ? (int) $validated['extracted']['length']
                : ($parsedEdge['length_mm'] ?? null);
            $width = !empty($validated['extracted']['width'])
                ? round((float) str_replace(',', '.', $validated['extracted']['width'] ?? ''), 2)
                : ($parsedEdge['width_mm'] ?? null);
        } elseif ($materialType === 'plate') {
            $parsedDims = $title ? ChromeExtractService::parseDimensionsFromName($title) : [];
            $thickness = !empty($validated['extracted']['thickness'])
                ? (int) round((float) str_replace(',', '.', $validated['extracted']['thickness']))
                : ($parsedDims['thickness_mm'] ?? null);
            $length = !empty($validated['extracted']['length'])
                ? (int) $validated['extracted']['length']
                : ($parsedDims['length_mm'] ?? null);
            $width = !empty($validated['extracted']['width'])
                ? (int) $validated['extracted']['width']
                : ($parsedDims['width_mm'] ?? null);
        }
        // Hardware: no dimensions in preview

        // Type labels for preview
        $typeLabels = [
            'plate' => 'Плита',
            'edge' => 'Кромка',
            'hardware' => 'Фурнитура',
        ];

        // Return parsed values preview
        $preview = [
            'title' => $title,
            'price' => ChromeExtractService::parsePrice($validated['extracted']['price'] ?? null),
            'price_raw' => $validated['extracted']['price'] ?? null,
            'currency' => ChromeExtractService::parseCurrency($validated['extracted']['price'] ?? null),
            'unit' => $unit,
            'article' => trim($validated['extracted']['article'] ?? ''),
            'thickness' => $thickness,
            'length' => $length,
            'width' => $width,
            'material_type' => $materialType,
            'material_type_label' => $typeLabels[$materialType] ?? $materialType,
        ];

        return response()->json([
            'valid' => empty($errors),
            'errors' => $errors,
            'trust' => $trustInfo,
            'preview' => $preview,
        ]);
    }
}
