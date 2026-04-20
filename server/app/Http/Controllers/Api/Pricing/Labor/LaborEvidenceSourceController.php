<?php

namespace App\Http\Controllers\Api\Pricing\Labor;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLaborEvidenceSourceRequest;
use App\Http\Requests\UpdateLaborEvidenceSourceRequest;
use App\Models\LaborEvidenceSource;
use App\Models\LaborProfile;
use App\Services\LaborEvidenceSourceService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LaborEvidenceSourceController extends Controller
{
    public function __construct(
        private readonly LaborEvidenceSourceService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'region_id' => 'nullable|integer|exists:regions,id',
            'labor_profile_id' => 'nullable|integer',
            'provider_id' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);
        $query = LaborEvidenceSource::query()
            ->ownedBy((int) $request->user()->id)
            ->with($this->service->relations())
            ->orderByDesc('created_at');

        foreach (['region_id', 'labor_profile_id', 'provider_id'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, (int) $validated[$filter]);
            }
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json($query->paginate($perPage));
    }

    public function store(StoreLaborEvidenceSourceRequest $request): JsonResponse
    {
        $payload = $request->validated();
        if ($request->has('is_active')) {
            $payload['is_active'] = $request->boolean('is_active');
        }

        $this->ensureOwnedLaborProfile($request, (int) $payload['labor_profile_id']);

        try {
            $source = $this->service->create($request->user(), [
                ...$payload,
                'hours_per_month' => $payload['hours_per_month'] ?? 160,
                'currency' => strtoupper((string) ($payload['currency'] ?? 'RUB')),
                'captured_via' => $payload['captured_via'] ?? 'manual',
                'verification_status' => $payload['verification_status'] ?? 'pending',
                'is_active' => $payload['is_active'] ?? true,
            ]);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'labor_profile_id' => [$exception->getMessage()],
            ]);
        }

        return response()->json($source, 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $source = $this->findOwnedSource($request, $id);

        return response()->json($source);
    }

    public function update(UpdateLaborEvidenceSourceRequest $request, int $id): JsonResponse
    {
        $source = $this->findOwnedSource($request, $id);
        $payload = $request->validated();

        if ($request->has('is_active')) {
            $payload['is_active'] = $request->boolean('is_active');
        }

        if (array_key_exists('currency', $payload) && $payload['currency'] !== null) {
            $payload['currency'] = strtoupper((string) $payload['currency']);
        }

        if (array_key_exists('labor_profile_id', $payload)) {
            $this->ensureOwnedLaborProfile($request, (int) $payload['labor_profile_id']);
        }

        try {
            $updated = $this->service->update($source, $payload);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'labor_profile_id' => [$exception->getMessage()],
            ]);
        }

        return response()->json($updated);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $source = $this->findOwnedSource($request, $id);
        $source->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    private function findOwnedSource(Request $request, int $id): LaborEvidenceSource
    {
        return LaborEvidenceSource::query()
            ->ownedBy((int) $request->user()->id)
            ->with($this->service->relations())
            ->findOrFail($id);
    }

    private function ensureOwnedLaborProfile(Request $request, int $laborProfileId): void
    {
        $exists = LaborProfile::query()
            ->ownedBy((int) $request->user()->id)
            ->whereKey($laborProfileId)
            ->exists();

        if ($exists) {
            return;
        }

        Log::warning('Rejected labor evidence source request with чужим labor profile', [
            'user_id' => $request->user()?->id,
            'labor_profile_id' => $laborProfileId,
            'path' => $request->path(),
            'method' => $request->method(),
        ]);

        abort(403, 'Selected labor profile does not belong to current user.');
    }
}
