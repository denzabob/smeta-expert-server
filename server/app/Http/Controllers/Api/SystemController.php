<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParsingSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class SystemController extends Controller
{
    /**
     * Get parser system status and health metrics
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function parserStatus()
    {
        // Check if scheduler is running (Laravel schedule:work process)
        $schedulerRunning = $this->isSchedulerRunning();
        
        // Get active sessions count
        $activeSessions = ParsingSession::whereIn('status', ['pending', 'running'])
            ->count();
        
        // Get running sessions with PIDs
        $runningSessions = ParsingSession::where('status', 'running')
            ->whereNotNull('pid')
            ->count();
        
        // Check for zombie processes
        $zombieSessions = ParsingSession::where('status', 'running')
            ->whereNotNull('pid')
            ->where(function ($query) {
                $query->where('last_heartbeat', '<', now()->subMinutes(config('parser.heartbeat_timeout', 15)))
                      ->orWhereNull('last_heartbeat');
            })
            ->count();
        
        // Calculate overall health score (0-100)
        $healthScore = $this->calculateHealthScore();
        
        // Get recent failures (last 24h)
        $recentFailures = ParsingSession::where('status', 'failed')
            ->where('started_at', '>=', now()->subDay())
            ->count();
        
        // Database size
        $dbSize = $this->getDatabaseSize();
        
        // Total logs count
        $totalLogs = DB::table('parsing_logs')->count();
        
        // Last cleanup/pruning
        $lastCleanup = Cache::get('parser:last_cleanup');
        $lastPruning = Cache::get('parser:last_pruning');
        
        return response()->json([
            'scheduler' => [
                'running' => $schedulerRunning,
                'status' => $schedulerRunning ? 'active' : 'stopped',
            ],
            'sessions' => [
                'active' => $activeSessions,
                'running' => $runningSessions,
                'zombies' => $zombieSessions,
            ],
            'health' => [
                'score' => $healthScore,
                'status' => $this->getHealthStatus($healthScore),
                'recent_failures' => $recentFailures,
            ],
            'database' => [
                'size' => $dbSize,
                'size_formatted' => $this->formatBytes($dbSize),
                'total_logs' => $totalLogs,
            ],
            'maintenance' => [
                'last_cleanup' => $lastCleanup,
                'last_pruning' => $lastPruning,
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }
    
    /**
     * Check if Laravel scheduler is running
     */
    private function isSchedulerRunning(): bool
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: check for php artisan schedule:work
            $process = new Process(['tasklist', '/FI', 'IMAGENAME eq php.exe', '/FO', 'CSV']);
            $process->run();
            
            if ($process->isSuccessful()) {
                $output = $process->getOutput();
                return str_contains($output, 'schedule:work') || str_contains($output, 'artisan');
            }
        } else {
            // Linux: check for artisan schedule:work
            $process = new Process(['ps', 'aux']);
            $process->run();
            
            if ($process->isSuccessful()) {
                $output = $process->getOutput();
                return str_contains($output, 'schedule:work');
            }
        }
        
        return false;
    }
    
    /**
     * Calculate overall health score based on recent performance
     */
    private function calculateHealthScore(): int
    {
        $last24h = now()->subDay();
        
        $total = ParsingSession::where('started_at', '>=', $last24h)->count();
        
        if ($total === 0) {
            return 100; // No sessions = no problems
        }
        
        $successful = ParsingSession::where('started_at', '>=', $last24h)
            ->where('status', 'success')
            ->count();
        
        $failed = ParsingSession::where('started_at', '>=', $last24h)
            ->where('status', 'failed')
            ->count();
        
        $zombies = ParsingSession::where('started_at', '>=', $last24h)
            ->where('status', 'running')
            ->whereNotNull('pid')
            ->where('last_heartbeat', '<', now()->subMinutes(config('parser.heartbeat_timeout', 15)))
            ->count();
        
        // Score calculation:
        // - Successful sessions: +points
        // - Failed sessions: -points
        // - Zombie sessions: -heavy penalty
        $score = (($successful / $total) * 100) - (($failed / $total) * 20) - (($zombies / $total) * 40);
        
        return max(0, min(100, (int) round($score)));
    }
    
    /**
     * Get health status label
     */
    private function getHealthStatus(int $score): string
    {
        if ($score >= 90) return 'excellent';
        if ($score >= 70) return 'good';
        if ($score >= 50) return 'fair';
        if ($score >= 30) return 'poor';
        return 'critical';
    }
    
    /**
     * Get database size in bytes
     */
    private function getDatabaseSize(): int
    {
        try {
            $dbName = config('database.connections.mysql.database');
            
            $result = DB::selectOne("
                SELECT SUM(data_length + index_length) as size
                FROM information_schema.TABLES
                WHERE table_schema = ?
            ", [$dbName]);
            
            return (int) ($result->size ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
