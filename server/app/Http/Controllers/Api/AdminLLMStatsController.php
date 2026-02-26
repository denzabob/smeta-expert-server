<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiLog;
use App\Models\User;
use App\Services\LLM\ProviderRegistry;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Контроллер для статистики LLM использования (админка)
 * 
 * Доступ: только для user_id = 1 (admin)
 */
class AdminLLMStatsController extends Controller
{
    /**
     * Получить общую статистику использования LLM
     * 
     * GET /api/admin/llm-stats
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $period = $request->query('period', '7d'); // 7d, 30d, 90d
        $from = $this->getPeriodStart($period);

        // Общая статистика
        $totals = $this->getTotals($from);
        
        // Статистика по провайдерам
        $byProvider = $this->getByProvider($from);
        
        // Статистика по пользователям (топ 10)
        $byUser = $this->getByUser($from);
        
        // Активность по дням
        $dailyActivity = $this->getDailyActivity($from);
        
        // Активность по часам (для текущего дня)
        $hourlyActivity = $this->getHourlyActivity();
        
        // Ошибки по типам
        $errorsByType = $this->getErrorsByType($from);

        return response()->json([
            'period' => $period,
            'from' => $from->toIso8601String(),
            'totals' => $totals,
            'by_provider' => $byProvider,
            'by_user' => $byUser,
            'daily_activity' => $dailyActivity,
            'hourly_activity' => $hourlyActivity,
            'errors_by_type' => $errorsByType,
        ]);
    }

    /**
     * Получить детальную статистику по пользователям
     * 
     * GET /api/admin/llm-stats/users
     */
    public function users(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $period = $request->query('period', '30d');
        $from = $this->getPeriodStart($period);

        $users = AiLog::query()
            ->select([
                'user_id',
                DB::raw('COUNT(*) as total_requests'),
                DB::raw('SUM(CASE WHEN is_successful THEN 1 ELSE 0 END) as successful_requests'),
                DB::raw('SUM(COALESCE(cost_usd, 0)) as total_cost'),
                DB::raw('SUM(COALESCE(prompt_tokens, 0) + COALESCE(completion_tokens, 0)) as total_tokens'),
                DB::raw('AVG(latency_ms) as avg_latency'),
                DB::raw('MAX(created_at) as last_used_at'),
            ])
            ->where('created_at', '>=', $from)
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->orderByDesc('total_requests')
            ->limit(50)
            ->get();

        // Добавляем информацию о пользователях
        $userIds = $users->pluck('user_id')->toArray();
        $userNames = User::whereIn('id', $userIds)->pluck('name', 'id');

        $users = $users->map(function ($row) use ($userNames) {
            return [
                'user_id' => $row->user_id,
                'user_name' => $userNames[$row->user_id] ?? 'Unknown',
                'total_requests' => (int) $row->total_requests,
                'successful_requests' => (int) $row->successful_requests,
                'success_rate' => $row->total_requests > 0
                    ? round($row->successful_requests / $row->total_requests * 100, 1)
                    : 0,
                'total_cost' => round((float) $row->total_cost, 4),
                'total_tokens' => (int) $row->total_tokens,
                'avg_latency_ms' => (int) round($row->avg_latency),
                'last_used_at' => $row->last_used_at,
            ];
        });

        return response()->json([
            'period' => $period,
            'users' => $users,
        ]);
    }

    /**
     * Получить детальную статистику по провайдерам
     * 
     * GET /api/admin/llm-stats/providers
     */
    public function providers(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $period = $request->query('period', '7d');
        $from = $this->getPeriodStart($period);

        $providers = AiLog::query()
            ->select([
                'provider_name',
                DB::raw('COUNT(*) as total_requests'),
                DB::raw('SUM(CASE WHEN is_successful THEN 1 ELSE 0 END) as successful_requests'),
                DB::raw('SUM(COALESCE(cost_usd, 0)) as total_cost'),
                DB::raw('SUM(COALESCE(prompt_tokens, 0)) as prompt_tokens'),
                DB::raw('SUM(COALESCE(completion_tokens, 0)) as completion_tokens'),
                DB::raw('AVG(latency_ms) as avg_latency'),
                DB::raw('MIN(latency_ms) as min_latency'),
                DB::raw('MAX(latency_ms) as max_latency'),
                DB::raw('SUM(CASE WHEN fallback_used THEN 1 ELSE 0 END) as fallback_count'),
            ])
            ->where('created_at', '>=', $from)
            ->whereNotNull('provider_name')
            ->groupBy('provider_name')
            ->get();

        // Добавляем метаданные провайдеров
        $providersMeta = ProviderRegistry::all();

        $providers = $providers->map(function ($row) use ($providersMeta) {
            $meta = $providersMeta[$row->provider_name] ?? [];
            return [
                'provider' => $row->provider_name,
                'name' => $meta['name'] ?? $row->provider_name,
                'icon' => $meta['icon'] ?? 'mdi-robot',
                'total_requests' => (int) $row->total_requests,
                'successful_requests' => (int) $row->successful_requests,
                'success_rate' => $row->total_requests > 0
                    ? round($row->successful_requests / $row->total_requests * 100, 1)
                    : 0,
                'total_cost' => round((float) $row->total_cost, 4),
                'prompt_tokens' => (int) $row->prompt_tokens,
                'completion_tokens' => (int) $row->completion_tokens,
                'total_tokens' => (int) $row->prompt_tokens + (int) $row->completion_tokens,
                'avg_latency_ms' => (int) round($row->avg_latency),
                'min_latency_ms' => (int) $row->min_latency,
                'max_latency_ms' => (int) $row->max_latency,
                'fallback_count' => (int) $row->fallback_count,
            ];
        });

        return response()->json([
            'period' => $period,
            'providers' => $providers,
        ]);
    }

    /**
     * Получить данные для графика активности
     * 
     * GET /api/admin/llm-stats/activity
     */
    public function activity(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $period = $request->query('period', '7d');
        $granularity = $request->query('granularity', 'day'); // hour, day
        $from = $this->getPeriodStart($period);

        if ($granularity === 'hour') {
            $data = $this->getHourlyActivityDetailed($from);
        } else {
            $data = $this->getDailyActivityDetailed($from);
        }

        return response()->json([
            'period' => $period,
            'granularity' => $granularity,
            'data' => $data,
        ]);
    }

    /**
     * Получить начало периода
     */
    private function getPeriodStart(string $period): Carbon
    {
        return match ($period) {
            '24h' => Carbon::now()->subHours(24),
            '7d' => Carbon::now()->subDays(7),
            '30d' => Carbon::now()->subDays(30),
            '90d' => Carbon::now()->subDays(90),
            default => Carbon::now()->subDays(7),
        };
    }

    /**
     * Получить общие показатели
     */
    private function getTotals(Carbon $from): array
    {
        $stats = AiLog::query()
            ->where('created_at', '>=', $from)
            ->selectRaw('
                COUNT(*) as total_requests,
                SUM(CASE WHEN is_successful THEN 1 ELSE 0 END) as successful_requests,
                SUM(COALESCE(cost_usd, 0)) as total_cost,
                SUM(COALESCE(prompt_tokens, 0) + COALESCE(completion_tokens, 0)) as total_tokens,
                AVG(latency_ms) as avg_latency,
                COUNT(DISTINCT user_id) as unique_users
            ')
            ->first();

        return [
            'total_requests' => (int) $stats->total_requests,
            'successful_requests' => (int) $stats->successful_requests,
            'failed_requests' => (int) $stats->total_requests - (int) $stats->successful_requests,
            'success_rate' => $stats->total_requests > 0
                ? round($stats->successful_requests / $stats->total_requests * 100, 1)
                : 0,
            'total_cost' => round((float) $stats->total_cost, 4),
            'total_tokens' => (int) $stats->total_tokens,
            'avg_latency_ms' => (int) round($stats->avg_latency ?? 0),
            'unique_users' => (int) $stats->unique_users,
        ];
    }

    /**
     * Статистика по провайдерам
     */
    private function getByProvider(Carbon $from): array
    {
        return AiLog::query()
            ->select([
                'provider_name',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(COALESCE(cost_usd, 0)) as cost'),
                DB::raw('AVG(latency_ms) as avg_latency'),
            ])
            ->where('created_at', '>=', $from)
            ->whereNotNull('provider_name')
            ->groupBy('provider_name')
            ->get()
            ->mapWithKeys(function ($row) {
                return [
                    $row->provider_name => [
                        'count' => (int) $row->count,
                        'cost' => round((float) $row->cost, 4),
                        'avg_latency_ms' => (int) round($row->avg_latency),
                    ],
                ];
            })
            ->toArray();
    }

    /**
     * Топ пользователей по использованию
     */
    private function getByUser(Carbon $from): array
    {
        $users = AiLog::query()
            ->select([
                'user_id',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(COALESCE(cost_usd, 0)) as cost'),
            ])
            ->where('created_at', '>=', $from)
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $userIds = $users->pluck('user_id')->toArray();
        $userNames = User::whereIn('id', $userIds)->pluck('name', 'id');

        return $users->map(function ($row) use ($userNames) {
            return [
                'user_id' => $row->user_id,
                'name' => $userNames[$row->user_id] ?? 'Unknown',
                'count' => (int) $row->count,
                'cost' => round((float) $row->cost, 4),
            ];
        })->toArray();
    }

    /**
     * Активность по дням
     */
    private function getDailyActivity(Carbon $from): array
    {
        return AiLog::query()
            ->select([
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(CASE WHEN is_successful THEN 1 ELSE 0 END) as successful'),
            ])
            ->where('created_at', '>=', $from)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get()
            ->map(function ($row) {
                return [
                    'date' => $row->date,
                    'count' => (int) $row->count,
                    'successful' => (int) $row->successful,
                    'failed' => (int) $row->count - (int) $row->successful,
                ];
            })
            ->toArray();
    }

    /**
     * Детальная активность по дням с разбивкой по провайдерам
     */
    private function getDailyActivityDetailed(Carbon $from): array
    {
        $data = AiLog::query()
            ->select([
                DB::raw('DATE(created_at) as date'),
                'provider_name',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(CASE WHEN is_successful THEN 1 ELSE 0 END) as successful'),
                DB::raw('SUM(COALESCE(cost_usd, 0)) as cost'),
            ])
            ->where('created_at', '>=', $from)
            ->whereNotNull('provider_name')
            ->groupBy(DB::raw('DATE(created_at)'), 'provider_name')
            ->orderBy('date')
            ->get();

        // Группируем по датам
        $grouped = [];
        foreach ($data as $row) {
            $date = $row->date;
            if (!isset($grouped[$date])) {
                $grouped[$date] = [
                    'date' => $date,
                    'total' => 0,
                    'successful' => 0,
                    'cost' => 0,
                    'providers' => [],
                ];
            }
            $grouped[$date]['total'] += (int) $row->count;
            $grouped[$date]['successful'] += (int) $row->successful;
            $grouped[$date]['cost'] += (float) $row->cost;
            $grouped[$date]['providers'][$row->provider_name] = [
                'count' => (int) $row->count,
                'successful' => (int) $row->successful,
                'cost' => round((float) $row->cost, 4),
            ];
        }

        return array_values($grouped);
    }

    /**
     * Активность по часам (сегодня)
     */
    private function getHourlyActivity(): array
    {
        $today = Carbon::today();

        return AiLog::query()
            ->select([
                DB::raw('EXTRACT(HOUR FROM created_at) as hour'),
                DB::raw('COUNT(*) as count'),
            ])
            ->where('created_at', '>=', $today)
            ->groupBy(DB::raw('EXTRACT(HOUR FROM created_at)'))
            ->orderBy('hour')
            ->get()
            ->map(function ($row) {
                return [
                    'hour' => (int) $row->hour,
                    'count' => (int) $row->count,
                ];
            })
            ->toArray();
    }

    /**
     * Детальная активность по часам
     */
    private function getHourlyActivityDetailed(Carbon $from): array
    {
        return AiLog::query()
            ->select([
                DB::raw('DATE(created_at) as date'),
                DB::raw('EXTRACT(HOUR FROM created_at) as hour'),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(CASE WHEN is_successful THEN 1 ELSE 0 END) as successful'),
            ])
            ->where('created_at', '>=', $from)
            ->groupBy(DB::raw('DATE(created_at)'), DB::raw('EXTRACT(HOUR FROM created_at)'))
            ->orderBy('date')
            ->orderBy('hour')
            ->get()
            ->map(function ($row) {
                return [
                    'date' => $row->date,
                    'hour' => (int) $row->hour,
                    'count' => (int) $row->count,
                    'successful' => (int) $row->successful,
                ];
            })
            ->toArray();
    }

    /**
     * Ошибки по типам
     */
    private function getErrorsByType(Carbon $from): array
    {
        return AiLog::query()
            ->select([
                'error_type',
                DB::raw('COUNT(*) as count'),
            ])
            ->where('created_at', '>=', $from)
            ->where('is_successful', false)
            ->whereNotNull('error_type')
            ->groupBy('error_type')
            ->orderByDesc('count')
            ->get()
            ->map(function ($row) {
                return [
                    'type' => $row->error_type,
                    'count' => (int) $row->count,
                ];
            })
            ->toArray();
    }

    /**
     * Проверить доступ администратора (user_id = 1)
     */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (!$user || (int) $user->id !== 1) {
            abort(403, 'Access denied. Admin only.');
        }
    }
}
