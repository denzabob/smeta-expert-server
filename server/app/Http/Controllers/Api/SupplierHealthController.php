<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParsingSession;
use Illuminate\Support\Facades\DB;

class SupplierHealthController extends Controller
{
    /**
     * Get health metrics for all suppliers
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        // Get all unique suppliers from config
        $suppliers = $this->getConfiguredSuppliers();
        
        $healthData = [];
        
        foreach ($suppliers as $supplierKey => $supplierName) {
            $healthData[] = $this->calculateSupplierHealth($supplierKey, $supplierName);
        }
        
        return response()->json([
            'suppliers' => $healthData,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
    
    /**
     * Get health metrics for a specific supplier
     * 
     * @param string $supplier
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(string $supplier)
    {
        $suppliers = $this->getConfiguredSuppliers();
        
        if (!isset($suppliers[$supplier])) {
            return response()->json([
                'error' => 'Supplier not found',
            ], 404);
        }
        
        $health = $this->calculateSupplierHealth($supplier, $suppliers[$supplier]);
        
        return response()->json($health);
    }
    
    /**
     * Get configured suppliers from config
     */
    private function getConfiguredSuppliers(): array
    {
        // Get suppliers from parser config
        $suppliers = config('parser.suppliers', []);
        
        // Default suppliers if config is empty
        if (empty($suppliers)) {
            return [
                'skm_mebel' => 'СКМ Мебель',
                'template' => 'Template Supplier',
            ];
        }
        
        return $suppliers;
    }
    
    /**
     * Calculate health metrics for a supplier
     */
    private function calculateSupplierHealth(string $key, string $name): array
    {
        $last24h = now()->subDay();
        $last7d = now()->subDays(7);
        
        // Get last session
        $lastSession = ParsingSession::where('supplier_name', $key)
            ->orderBy('started_at', 'desc')
            ->first();
        
        // Get current running session
        $runningSession = ParsingSession::where('supplier_name', $key)
            ->where('status', 'running')
            ->whereNotNull('pid')
            ->first();
        
        // Calculate success rate (last 24h)
        $total24h = ParsingSession::where('supplier_name', $key)
            ->where('started_at', '>=', $last24h)
            ->count();
        
        $successful24h = ParsingSession::where('supplier_name', $key)
            ->where('started_at', '>=', $last24h)
            ->where('status', 'success')
            ->count();
        
        $successRate = $total24h > 0 ? round(($successful24h / $total24h) * 100, 1) : 0;
        
        // Health score (0-100)
        $healthScore = $this->calculateHealthScore($key, $last24h);
        
        // Total sessions (last 7 days)
        $totalSessions = ParsingSession::where('supplier_name', $key)
            ->where('started_at', '>=', $last7d)
            ->count();
        
        // Average runtime (last 10 sessions)
        $avgRuntime = ParsingSession::where('supplier_name', $key)
            ->whereNotNull('finished_at')
            ->orderBy('started_at', 'desc')
            ->limit(10)
            ->get()
            ->avg(function ($session) {
                return $session->finished_at->diffInSeconds($session->started_at);
            });
        
        // Total items processed (last 24h)
        $itemsProcessed = ParsingSession::where('supplier_name', $key)
            ->where('started_at', '>=', $last24h)
            ->sum('items_updated');
        
        return [
            'supplier' => $key,
            'name' => $name,
            'active' => true, // Все настроенные поставщики активны
            'health_score' => $healthScore,
            'status' => $this->getSupplierStatus($runningSession, $lastSession),
            'last_sync' => $lastSession?->started_at?->toIso8601String(),
            'success_rate_24h' => $successRate,
            'current_pid' => $runningSession?->pid,
            'is_running' => $runningSession !== null,
            'metrics' => [
                'total_sessions_7d' => $totalSessions,
                'successful_24h' => $successful24h,
                'total_24h' => $total24h,
                'items_processed_24h' => $itemsProcessed,
                'avg_runtime_seconds' => $avgRuntime ? round($avgRuntime) : null,
            ],
            'last_session' => $lastSession ? [
                'id' => $lastSession->id,
                'status' => $lastSession->status,
                'started_at' => $lastSession->started_at->toIso8601String(),
                'finished_at' => $lastSession->finished_at?->toIso8601String(),
                'pages_processed' => $lastSession->pages_processed,
                'items_updated' => $lastSession->items_updated,
                'errors_count' => $lastSession->errors_count,
            ] : null,
        ];
    }
    
    /**
     * Calculate health score for supplier
     */
    private function calculateHealthScore(string $supplier, $since): int
    {
        $total = ParsingSession::where('supplier_name', $supplier)
            ->where('started_at', '>=', $since)
            ->count();
        
        if ($total === 0) {
            return 100; // No sessions = no problems
        }
        
        $successful = ParsingSession::where('supplier_name', $supplier)
            ->where('started_at', '>=', $since)
            ->where('status', 'success')
            ->count();
        
        $failed = ParsingSession::where('supplier_name', $supplier)
            ->where('started_at', '>=', $since)
            ->where('status', 'failed')
            ->count();
        
        // Check for stale running sessions
        $stale = ParsingSession::where('supplier_name', $supplier)
            ->where('status', 'running')
            ->where('last_heartbeat', '<', now()->subMinutes(config('parser.heartbeat_timeout', 15)))
            ->count();
        
        // Score: success rate - penalties
        $successRate = ($successful / $total) * 100;
        $failurePenalty = ($failed / $total) * 30;
        $stalePenalty = ($stale / $total) * 50;
        
        $score = $successRate - $failurePenalty - $stalePenalty;
        
        return max(0, min(100, (int) round($score)));
    }
    
    /**
     * Get supplier status
     */
    private function getSupplierStatus($runningSession, $lastSession): string
    {
        if ($runningSession) {
            // Check if stale
            if ($runningSession->last_heartbeat && 
                $runningSession->last_heartbeat < now()->subMinutes(config('parser.heartbeat_timeout', 15))) {
                return 'stale';
            }
            return 'running';
        }
        
        if (!$lastSession) {
            return 'idle';
        }
        
        return $lastSession->status === 'success' ? 'success' : $lastSession->status;
    }
}
