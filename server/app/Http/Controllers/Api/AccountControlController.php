<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use App\Services\Auth\StepUpService;
use App\Services\Auth\StepUpTokenInvalidException;
use App\Services\AuthAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Account Control Surface — sessions and trusted devices.
 *
 * All routes require auth:sanctum.
 *
 * Route map:
 *   GET    /api/security/sessions                 → listSessions()
 *   DELETE /api/security/sessions/others          → revokeOtherSessions()
 *   DELETE /api/security/sessions/{id}            → revokeSession()
 *   GET    /api/security/trusted-devices          → listDevices()
 *   DELETE /api/security/trusted-devices          → revokeAllDevices()   [needs step-up]
 *   DELETE /api/security/trusted-devices/{id}     → revokeDevice()
 *
 * Step-up policy:
 *   Sessions:       NO step-up required. Revoking your own sessions is always safe and beneficial.
 *   Devices (one):  NO step-up required. Revoking one trusted device is low-risk.
 *   Devices (ALL):  YES step-up required (scope: revoke_all_devices).
 *                   Bulk device revocation disables PIN for all devices and is high-impact.
 */
class AccountControlController extends Controller
{
    public function __construct(
        private readonly AuthAuditService $audit,
        private readonly StepUpService    $stepUpService,
    ) {}

    // ─── Sessions ────────────────────────────────────────────────────────────

    /**
     * GET /api/security/sessions
     *
     * List all active sessions for the authenticated user.
     * Returns current session and all other sessions with safe metadata.
     */
    public function listSessions(Request $request): JsonResponse
    {
        $user             = $request->user();
        $currentSessionId = $request->session()->getId();

        $rows = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get();

        $sessions = $rows->map(function ($row) use ($currentSessionId) {
            $parsed = $this->parseUserAgent($row->user_agent ?? '');
            return [
                'id'             => $row->id,
                'current'        => $row->id === $currentSessionId,
                'created_at'     => null, // not stored in sessions table
                'last_active_at' => $row->last_activity ? date('c', $row->last_activity) : null,
                'ip'             => $row->ip_address,
                'device'         => $parsed['browser'] . ' / ' . $parsed['platform_label'],
            ];
        });

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * DELETE /api/security/sessions/others
     *
     * Revoke all sessions except the current one.
     * No step-up required — cleaning up your own access is always safe.
     */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $user             = $request->user();
        $currentSessionId = $request->session()->getId();

        $revokedCount = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        $this->audit->sessionsRevokedOther($user->id, $request, $revokedCount);

        return response()->json([
            'message'       => 'Все другие сеансы завершены.',
            'revoked_count' => $revokedCount,
        ]);
    }

    /**
     * DELETE /api/security/sessions/{id}
     *
     * Revoke a specific session (cannot revoke the current one).
     */
    public function revokeSession(Request $request, string $id): JsonResponse
    {
        $user             = $request->user();
        $currentSessionId = $request->session()->getId();

        if ($id === $currentSessionId) {
            return response()->json([
                'message' => 'Нельзя завершить текущий сеанс. Используйте выход из системы.',
                'error'   => 'cannot_revoke_current_session',
            ], 422);
        }

        $deleted = DB::table('sessions')
            ->where('id', $id)
            ->where('user_id', $user->id) // ensure ownership
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Сеанс не найден.'], 404);
        }

        $this->audit->sessionRevoked($user->id, $request, ['session_id_prefix' => substr($id, 0, 8)]);

        return response()->json(['message' => 'Сеанс завершён.']);
    }

    // ─── Trusted devices ─────────────────────────────────────────────────────

    /**
     * GET /api/security/trusted-devices
     *
     * List trusted devices with safe metadata.
     */
    public function listDevices(Request $request): JsonResponse
    {
        $user    = $request->user();
        $current = $request->cookie('tdid');

        $devices = $user->activeTrustedDevices()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn (TrustedDevice $d) => [
                'id'          => $d->id,
                'current'     => $d->device_id === $current,
                'created_at'  => $d->created_at?->toIso8601String(),
                'last_used_at' => $d->last_used_at?->toIso8601String(),
                'ip_last'     => $d->ip_last,
                'device'      => $d->device_label,
            ]);

        return response()->json(['trusted_devices' => $devices]);
    }

    /**
     * DELETE /api/security/trusted-devices/{id}
     *
     * Revoke a specific trusted device.
     * No step-up required — users can remove their own devices freely.
     */
    public function revokeDevice(Request $request, int $id): JsonResponse
    {
        $device = $request->user()
            ->activeTrustedDevices()
            ->find($id);

        if (!$device) {
            return response()->json(['message' => 'Устройство не найдено.'], 404);
        }

        $device->revoke();

        $this->audit->trustedDeviceRevoked($request->user()->id, $device->id, $request);

        return response()->json(['message' => 'Устройство отозвано.']);
    }

    /**
     * DELETE /api/security/trusted-devices
     *
     * Revoke ALL trusted devices.
     * REQUIRES step-up token with scope=revoke_all_devices.
     * This is a high-impact action: disables PIN for all devices.
     */
    public function revokeAllDevices(Request $request): JsonResponse
    {
        $request->validate([
            'step_up_token' => ['required', 'string'],
        ]);

        $user = $request->user();

        try {
            $challenge = $this->stepUpService->validateToken(
                $request->input('step_up_token'),
                $user,
                'revoke_all_devices',
            );
        } catch (StepUpTokenInvalidException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'step_up_required'], 401);
        }

        $revokedCount = $user->activeTrustedDevices()->count();
        $user->activeTrustedDevices()->update(['revoked_at' => now()]);

        $this->stepUpService->consumeToken($challenge);

        $this->audit->trustedDevicesRevokedAll($user->id, $request, $revokedCount);

        return response()->json([
            'message'       => 'Все доверенные устройства отозваны.',
            'revoked_count' => $revokedCount,
        ]);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * Minimal User-Agent parsing for session metadata display.
     */
    private function parseUserAgent(string $ua): array
    {
        $platform = 'unknown';
        $platformLabel = 'Unknown';
        if (preg_match('/Windows/i', $ua)) {
            $platform = 'windows';
            $platformLabel = 'Windows';
        } elseif (preg_match('/Macintosh/i', $ua)) {
            $platform = 'mac';
            $platformLabel = 'macOS';
        } elseif (preg_match('/Android/i', $ua)) {
            $platform = 'android';
            $platformLabel = 'Android';
        } elseif (preg_match('/iPhone|iPad/i', $ua)) {
            $platform = 'ios';
            $platformLabel = 'iOS';
        } elseif (preg_match('/Linux/i', $ua)) {
            $platform = 'linux';
            $platformLabel = 'Linux';
        }

        $browser = 'Unknown';
        if (preg_match('/Edg(e|\/)/i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/OPR|Opera/i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/YaBrowser/i', $ua)) {
            $browser = 'Yandex';
        } elseif (preg_match('/Chrome/i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Firefox/i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Safari/i', $ua)) {
            $browser = 'Safari';
        }

        return [
            'platform'       => $platform,
            'platform_label' => $platformLabel,
            'browser'        => $browser,
        ];
    }
}
