<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Storage\StorageQuotaService;
use App\Services\Storage\StorageUsageService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

final class AccountStorageController extends Controller
{
    public function __invoke(Request $request, StorageUsageService $usage, StorageQuotaService $quota): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $snapshot = $usage->getUserUsage($userId);
        $limit = $quota->limit($userId)['limit'];
        $total = StorageQuotaService::add($snapshot['used_bytes'], $snapshot['reserved_bytes']);
        $modules = [];
        foreach (['expert', 'smeta'] as $module) {
            $modules[$module] = ['bytes' => $snapshot['modules'][$module]['used_bytes'] ?? 0, 'count' => $snapshot['modules'][$module]['files_count'] ?? 0];
        }
        return response()->json([
            'used_bytes' => $snapshot['used_bytes'], 'reserved_bytes' => $snapshot['reserved_bytes'],
            'limit_bytes' => $limit, 'available_bytes' => $limit === null ? null : max(0, $limit - $total),
            'is_unlimited' => $limit === null, 'over_quota' => $limit !== null && $total > $limit,
            'percent' => $limit === null ? null : ($limit === 0 ? ($total === 0 ? 0 : null) : round($total / $limit * 100, 2)),
            'files_count' => $snapshot['files_count'],
            'categories' => [
                'images' => ['bytes' => $snapshot['images_bytes'], 'count' => $snapshot['images_count']],
                'files' => ['bytes' => $snapshot['used_bytes'] - $snapshot['images_bytes'], 'count' => $snapshot['files_count'] - $snapshot['images_count']],
            ],
            'modules' => $modules,
        ]);
    }
}
