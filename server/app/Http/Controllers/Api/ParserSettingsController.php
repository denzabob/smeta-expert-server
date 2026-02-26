<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ParserSettingsController extends Controller
{
    /**
     * Get parser settings
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $settings = [
            'callback' => [
                'token' => $this->maskToken(config('parser.callback_token')),
                'token_masked' => true,
            ],
            'allowed_ips' => config('parser.allowed_ips', []),
            'logs' => [
                'retention_days' => config('parser.log_retention_days', 30),
                'max_per_session' => config('parser.max_logs_per_session', 100),
            ],
            'heartbeat' => [
                'timeout_minutes' => config('parser.heartbeat_timeout', 15),
            ],
            'cleanup' => [
                'enabled' => config('parser.auto_cleanup', true),
                'schedule' => 'daily',
            ],
            'system' => [
                'total_sessions' => \App\Models\ParsingSession::count(),
                'total_logs' => \Illuminate\Support\Facades\DB::table('parsing_logs')->count(),
                'database_size' => $this->getDatabaseSize(),
                'last_cleanup' => Cache::get('parser:last_cleanup'),
                'last_pruning' => Cache::get('parser:last_pruning'),
            ],
        ];
        
        return response()->json($settings);
    }
    
    /**
     * Update parser settings
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'logs.retention_days' => 'sometimes|integer|min:7|max:90',
            'logs.max_per_session' => 'sometimes|integer|min:50|max:500',
            'heartbeat.timeout_minutes' => 'sometimes|integer|min:5|max:60',
            'cleanup.enabled' => 'sometimes|boolean',
        ]);
        
        // Update config file
        $configPath = config_path('parser.php');
        
        if (!file_exists($configPath)) {
            return response()->json([
                'error' => 'Configuration file not found',
            ], 500);
        }
        
        $config = include $configPath;
        
        // Update values
        if (isset($validated['logs']['retention_days'])) {
            $config['log_retention_days'] = $validated['logs']['retention_days'];
        }
        
        if (isset($validated['logs']['max_per_session'])) {
            $config['max_logs_per_session'] = $validated['logs']['max_per_session'];
        }
        
        if (isset($validated['heartbeat']['timeout_minutes'])) {
            $config['heartbeat_timeout'] = $validated['heartbeat']['timeout_minutes'];
        }
        
        if (isset($validated['cleanup']['enabled'])) {
            $config['auto_cleanup'] = $validated['cleanup']['enabled'];
        }
        
        // Write config file
        $this->writeConfigFile($configPath, $config);
        
        // Clear config cache
        \Illuminate\Support\Facades\Artisan::call('config:clear');
        
        return response()->json([
            'message' => 'Settings updated successfully',
            'settings' => $this->index()->getData(),
        ]);
    }
    
    /**
     * Regenerate callback token
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function regenerateToken()
    {
        $newToken = Str::random(64);
        
        // Update config file
        $configPath = config_path('parser.php');
        $config = include $configPath;
        $config['callback_token'] = $newToken;
        
        $this->writeConfigFile($configPath, $config);
        
        // Clear config cache
        \Illuminate\Support\Facades\Artisan::call('config:clear');
        
        return response()->json([
            'message' => 'Token regenerated successfully',
            'token' => $newToken,
            'warning' => 'Update Python parser configuration with new token',
        ]);
    }
    
    /**
     * Get allowed IPs
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllowedIps()
    {
        return response()->json([
            'allowed_ips' => config('parser.allowed_ips', []),
        ]);
    }
    
    /**
     * Update allowed IPs
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateAllowedIps(Request $request)
    {
        $validated = $request->validate([
            'allowed_ips' => 'required|array',
            'allowed_ips.*' => 'required|string|ip',
        ]);
        
        // Update config file
        $configPath = config_path('parser.php');
        $config = include $configPath;
        $config['allowed_ips'] = $validated['allowed_ips'];
        
        $this->writeConfigFile($configPath, $config);
        
        // Clear config cache
        \Illuminate\Support\Facades\Artisan::call('config:clear');
        
        return response()->json([
            'message' => 'IP whitelist updated successfully',
            'allowed_ips' => $validated['allowed_ips'],
        ]);
    }
    
    /**
     * Trigger manual cleanup
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function cleanup()
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('parser:cleanup-zombies');
            
            Cache::put('parser:last_cleanup', now(), now()->addDays(7));
            
            return response()->json([
                'message' => 'Cleanup completed successfully',
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Cleanup failed: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Trigger manual pruning
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function prune()
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('parser:prune-logs');
            
            Cache::put('parser:last_pruning', now(), now()->addDays(7));
            
            return response()->json([
                'message' => 'Pruning completed successfully',
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Pruning failed: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Clear all logs (danger zone)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function clearAllLogs(Request $request)
    {
        $validated = $request->validate([
            'confirmation' => 'required|string|in:DELETE ALL LOGS',
        ]);
        
        try {
            $deleted = \Illuminate\Support\Facades\DB::table('parsing_logs')->delete();
            
            return response()->json([
                'message' => 'All logs cleared successfully',
                'deleted_count' => $deleted,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to clear logs: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Mask token for display
     */
    private function maskToken(?string $token): ?string
    {
        if (!$token || strlen($token) < 8) {
            return null;
        }
        
        return substr($token, 0, 4) . str_repeat('*', strlen($token) - 8) . substr($token, -4);
    }
    
    /**
     * Get database size
     */
    private function getDatabaseSize(): string
    {
        try {
            $dbName = config('database.connections.mysql.database');
            
            $result = \Illuminate\Support\Facades\DB::selectOne("
                SELECT SUM(data_length + index_length) as size
                FROM information_schema.TABLES
                WHERE table_schema = ?
            ", [$dbName]);
            
            $bytes = (int) ($result->size ?? 0);
            
            return $this->formatBytes($bytes);
        } catch (\Exception $e) {
            return 'Unknown';
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
    
    /**
     * Write config array to file
     */
    private function writeConfigFile(string $path, array $config): void
    {
        $export = var_export($config, true);
        $content = "<?php\n\nreturn " . $export . ";\n";
        
        file_put_contents($path, $content);
    }
}
