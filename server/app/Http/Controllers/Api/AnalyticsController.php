<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParsingSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * Get chart data for analytics
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function chart(Request $request)
    {
        $validated = $request->validate([
            'period' => 'sometimes|in:7d,30d,90d',
            'supplier' => 'sometimes|string',
            'type' => 'sometimes|in:processed,errors,sessions',
        ]);
        
        $period = $validated['period'] ?? '7d';
        $supplier = $validated['supplier'] ?? null;
        $type = $validated['type'] ?? 'processed';
        
        $days = match($period) {
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            default => 7,
        };
        
        $startDate = now()->subDays($days)->startOfDay();
        
        switch ($type) {
            case 'processed':
                $data = $this->getProcessedItemsChart($startDate, $supplier);
                break;
            case 'errors':
                $data = $this->getErrorTrendsChart($startDate, $supplier);
                break;
            case 'sessions':
                $data = $this->getSessionsChart($startDate, $supplier);
                break;
            default:
                $data = [];
        }
        
        return response()->json([
            'type' => $type,
            'period' => $period,
            'supplier' => $supplier,
            'data' => $data,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
    
    /**
     * Get session statistics
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function stats(Request $request)
    {
        $validated = $request->validate([
            'period' => 'sometimes|in:24h,7d,30d',
            'supplier' => 'sometimes|string',
        ]);
        
        $period = $validated['period'] ?? '24h';
        $supplier = $validated['supplier'] ?? null;
        
        $startDate = match($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDay(),
        };
        
        $query = ParsingSession::where('started_at', '>=', $startDate);
        
        if ($supplier) {
            $query->where('supplier_name', $supplier);
        }
        
        $totalSessions = (clone $query)->count();
        $completedSessions = (clone $query)->where('status', 'completed')->count();
        $failedSessions = (clone $query)->where('status', 'failed')->count();
        $runningSessions = (clone $query)->where('status', 'running')->count();
        
        $totalProcessed = (clone $query)->sum('pages_processed');
        $totalSuccess = (clone $query)->sum('items_updated');
        $totalErrors = (clone $query)->sum('errors_count');
        
        // Average runtime for completed sessions
        $avgRuntime = (clone $query)
            ->whereNotNull('finished_at')
            ->get()
            ->avg(function ($session) {
                return $session->finished_at->diffInSeconds($session->started_at);
            });
        
        // Success rate
        $successRate = $totalProcessed > 0 
            ? round(($totalSuccess / $totalProcessed) * 100, 1) 
            : 0;
        
        return response()->json([
            'period' => $period,
            'supplier' => $supplier,
            'sessions' => [
                'total' => $totalSessions,
                'completed' => $completedSessions,
                'failed' => $failedSessions,
                'running' => $runningSessions,
                'completion_rate' => $totalSessions > 0 
                    ? round(($completedSessions / $totalSessions) * 100, 1) 
                    : 0,
            ],
            'items' => [
                'processed' => $totalProcessed,
                'success' => $totalSuccess,
                'errors' => $totalErrors,
                'success_rate' => $successRate,
            ],
            'performance' => [
                'avg_runtime_seconds' => $avgRuntime ? round($avgRuntime) : null,
                'avg_runtime_formatted' => $avgRuntime ? $this->formatDuration($avgRuntime) : null,
                'avg_speed_items_per_sec' => $avgRuntime && $totalProcessed > 0 
                    ? round($totalProcessed / ($avgRuntime * $totalSessions), 2) 
                    : null,
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }
    
    /**
     * Get processed items chart data (daily aggregation)
     */
    private function getProcessedItemsChart($startDate, $supplier = null)
    {
        $query = ParsingSession::select(
                DB::raw('DATE(started_at) as date'),
                DB::raw('SUM(pages_processed) as total_processed'),
                DB::raw('SUM(items_updated) as total_success'),
                DB::raw('SUM(errors_count) as total_errors')
            )
            ->where('started_at', '>=', $startDate)
            ->groupBy(DB::raw('DATE(started_at)'))
            ->orderBy('date', 'asc');
        
        if ($supplier) {
            $query->where('supplier_name', $supplier);
        }
        
        $results = $query->get();
        
        return $results->map(function ($row) {
            return [
                'date' => $row->date,
                'processed' => (int) $row->total_processed,
                'success' => (int) $row->total_success,
                'errors' => (int) $row->total_errors,
            ];
        });
    }
    
    /**
     * Get error trends chart data
     */
    private function getErrorTrendsChart($startDate, $supplier = null)
    {
        $query = ParsingSession::select(
                DB::raw('DATE(started_at) as date'),
                DB::raw('COUNT(*) as total_sessions'),
                DB::raw('SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_sessions'),
                DB::raw('SUM(errors_count) as total_errors'),
                DB::raw('AVG(CASE WHEN pages_processed > 0 THEN (errors_count / pages_processed) * 100 ELSE 0 END) as error_rate')
            )
            ->where('started_at', '>=', $startDate)
            ->groupBy(DB::raw('DATE(started_at)'))
            ->orderBy('date', 'asc');
        
        if ($supplier) {
            $query->where('supplier_name', $supplier);
        }
        
        $results = $query->get();
        
        return $results->map(function ($row) {
            return [
                'date' => $row->date,
                'total_errors' => (int) $row->total_errors,
                'failed_sessions' => (int) $row->failed_sessions,
                'error_rate' => round((float) $row->error_rate, 2),
            ];
        });
    }
    
    /**
     * Get sessions chart data
     */
    private function getSessionsChart($startDate, $supplier = null)
    {
        $query = ParsingSession::select(
                DB::raw('DATE(started_at) as date'),
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed'),
                DB::raw('SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed'),
                DB::raw('SUM(CASE WHEN status = "running" THEN 1 ELSE 0 END) as running')
            )
            ->where('started_at', '>=', $startDate)
            ->groupBy(DB::raw('DATE(started_at)'))
            ->orderBy('date', 'asc');
        
        if ($supplier) {
            $query->where('supplier_name', $supplier);
        }
        
        $results = $query->get();
        
        return $results->map(function ($row) {
            return [
                'date' => $row->date,
                'total' => (int) $row->total,
                'completed' => (int) $row->completed,
                'failed' => (int) $row->failed,
                'running' => (int) $row->running,
            ];
        });
    }
    
    /**
     * Format duration in seconds to human readable
     */
    private function formatDuration(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $secs);
        } elseif ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $secs);
        } else {
            return sprintf('%ds', $secs);
        }
    }
}
