<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class PinAuthController extends Controller
{
    /**
     * GET /api/auth/pin/status
     *
     * Статус PIN для текущего браузера (вызывается на странице логина, без auth).
     * Проверяет cookie tdid + tds → есть ли доверенное устройство.
     */
    public function status(Request $request): JsonResponse
    {
        $deviceId = $request->cookie('tdid');
        $deviceSecret = $request->cookie('tds');

        if (!$deviceId || !$deviceSecret) {
            return response()->json([
                'pin_enabled' => false,
                'trusted_device_present' => false,
                'requires_password_login' => true,
                'user_name' => null,
                'user_email' => null,
            ]);
        }

        $device = TrustedDevice::findActiveByDeviceId($deviceId);

        if (!$device || !$device->verifySecret($deviceSecret)) {
            return response()->json([
                'pin_enabled' => false,
                'trusted_device_present' => false,
                'requires_password_login' => true,
                'user_name' => null,
                'user_email' => null,
            ]);
        }

        $user = $device->user;

        return response()->json([
            'pin_enabled' => (bool) $user->pin_enabled,
            'trusted_device_present' => true,
            'requires_password_login' => !$user->pin_enabled,
            'user_name' => $user->name,
            'user_email' => $user->email,
        ]);
    }

    /**
     * POST /api/auth/pin/login
     *
     * Вход по PIN (только для доверенных устройств).
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'pin' => 'required|string|size:4',
        ]);

        $deviceId = $request->cookie('tdid');
        $deviceSecret = $request->cookie('tds');

        if (!$deviceId || !$deviceSecret) {
            return response()->json(['message' => 'Устройство не является доверенным'], 403);
        }

        $device = TrustedDevice::findActiveByDeviceId($deviceId);

        if (!$device || !$device->verifySecret($deviceSecret)) {
            return response()->json(['message' => 'Устройство не является доверенным'], 403);
        }

        $user = $device->user;

        // Проверка блокировки PIN
        if ($user->isPinLocked()) {
            $minutes = now()->diffInMinutes($user->pin_locked_until);
            return response()->json([
                'message' => "PIN-вход заблокирован. Попробуйте через {$minutes} мин.",
                'locked_until' => $user->pin_locked_until->toIso8601String(),
            ], 429);
        }

        // Rate limiting: 5 попыток за 5 минут на device_id + ip
        $rateLimitKey = "pin-login:{$deviceId}:{$request->ip()}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return response()->json([
                'message' => "Слишком много попыток. Повторите через {$seconds} сек.",
            ], 429);
        }

        if (!$user->pin_enabled) {
            return response()->json(['message' => 'PIN-вход не включён'], 403);
        }

        // Проверка PIN
        if (!$user->verifyPin($request->input('pin'))) {
            RateLimiter::hit($rateLimitKey, 300); // 5 минут
            $user->recordFailedPinAttempt();

            // Если слишком много попыток — отзываем устройство
            if ($user->pin_attempts >= 10) {
                $device->revoke();
                return response()->json([
                    'message' => 'Устройство отозвано из-за множества неудачных попыток. Войдите по паролю.',
                    'device_revoked' => true,
                ], 403);
            }

            $remaining = max(0, 5 - $user->pin_attempts);
            return response()->json([
                'message' => 'Неверный PIN-код',
                'attempts_remaining' => $remaining,
            ], 401);
        }

        // Успешный вход по PIN
        RateLimiter::clear($rateLimitKey);
        $user->resetPinAttempts();

        // Обновить last_used_at и ip
        $device->update([
            'last_used_at' => now(),
            'ip_last' => $request->ip(),
        ]);

        // Создать сессию
        Auth::login($user);
        $request->session()->regenerate();

        // Single-session: инвалидировать остальные сеансы
        $this->enforceSingleSession($user, $request->session()->getId());

        return response()->json($user);
    }

    /**
     * POST /api/auth/pin/set
     *
     * Установить или изменить PIN (требует пароль).
     */
    public function set(Request $request): JsonResponse
    {
        $request->validate([
            'pin' => 'required|string|size:4|regex:/^\d{4}$/',
            'pin_confirm' => 'required|string|same:pin',
            'password' => 'required|string',
            'trust_device' => 'boolean',
        ]);

        $user = $request->user();

        // Проверка пароля
        if (!Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Неверный пароль'], 422);
        }

        // Установить PIN
        $user->setPin($request->input('pin'));

        $response = [
            'message' => 'PIN-код установлен',
            'pin_enabled' => true,
        ];

        // Доверить устройство если запрошено
        if ($request->boolean('trust_device', true)) {
            $cookieData = $this->trustCurrentDevice($user, $request);
            $response['device_trusted'] = true;

            return response()->json($response)
                ->withCookie($cookieData['tdid_cookie'])
                ->withCookie($cookieData['tds_cookie']);
        }

        return response()->json($response);
    }

    /**
     * POST /api/auth/pin/disable
     *
     * Отключить PIN (требует пароль).
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        if (!Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Неверный пароль'], 422);
        }

        $user->disablePin();

        // Отозвать все доверенные устройства
        $user->activeTrustedDevices()->update(['revoked_at' => now()]);

        return response()->json(['message' => 'PIN-код отключён', 'pin_enabled' => false]);
    }

    /**
     * GET /api/auth/trusted-devices
     *
     * Список доверенных устройств текущего пользователя.
     */
    public function trustedDevices(Request $request): JsonResponse
    {
        $devices = $request->user()
            ->activeTrustedDevices()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn (TrustedDevice $d) => [
                'id' => $d->id,
                'device_label' => $d->device_label,
                'ip_last' => $d->ip_last,
                'last_used_at' => $d->last_used_at?->toIso8601String(),
                'created_at' => $d->created_at?->toIso8601String(),
                'is_current' => $d->device_id === $request->cookie('tdid'),
            ]);

        return response()->json($devices);
    }

    /**
     * POST /api/auth/trusted-devices/{id}/revoke
     *
     * Отозвать доверенное устройство.
     */
    public function revokeDevice(Request $request, int $id): JsonResponse
    {
        $device = $request->user()
            ->activeTrustedDevices()
            ->findOrFail($id);

        $device->revoke();

        return response()->json(['message' => 'Устройство отозвано']);
    }

    /**
     * POST /api/auth/trusted-device/forget
     *
     * «Сменить аккаунт» — удалить метку доверенного устройства в браузере + отозвать.
     */
    public function forgetDevice(Request $request): JsonResponse
    {
        $deviceId = $request->cookie('tdid');

        if ($deviceId) {
            $device = TrustedDevice::findActiveByDeviceId($deviceId);
            if ($device) {
                $device->revoke();
            }
        }

        // Удаляем cookies
        return response()->json(['message' => 'Устройство забыто'])
            ->withCookie(cookie()->forget('tdid'))
            ->withCookie(cookie()->forget('tds'));
    }

    /**
     * POST /api/auth/terminate-sessions
     *
     * Завершить все сеансы (кроме текущего).
     */
    public function terminateSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentSessionId = $request->session()->getId();

        // Удалить все сессии кроме текущей
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        $user->update(['current_session_id' => $currentSessionId]);

        return response()->json(['message' => 'Все другие сеансы завершены']);
    }

    /**
     * GET /api/auth/sessions
     *
     * Список активных сессий пользователя.
     * current — сессия текущего запроса, others — остальные.
     */
    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentSessionId = $request->session()->getId();

        $rows = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get();

        $mapSession = function ($row) use ($currentSessionId) {
            $ua = $row->user_agent ?? '';
            $parsed = $this->parseUserAgent($ua);

            return [
                'id'             => $row->id,
                'is_current'     => $row->id === $currentSessionId,
                'platform'       => $parsed['platform'],
                'client'         => $parsed['client'],
                'device_name'    => $parsed['device_name'],
                'browser'        => $parsed['browser'],
                'ip_address'     => $row->ip_address,
                'last_active_at' => $row->last_activity
                    ? date('c', $row->last_activity)
                    : null,
                'city'           => null, // geo не реализован
                'country'        => null,
            ];
        };

        $current = null;
        $others = [];

        foreach ($rows as $row) {
            $mapped = $mapSession($row);
            if ($row->id === $currentSessionId) {
                $current = $mapped;
            } else {
                $others[] = $mapped;
            }
        }

        // Если текущая сессия не найдена в БД (race condition) — сконструировать из запроса
        if (!$current) {
            $ua = $request->userAgent() ?? '';
            $parsed = $this->parseUserAgent($ua);
            $current = [
                'id'             => $currentSessionId,
                'is_current'     => true,
                'platform'       => $parsed['platform'],
                'client'         => $parsed['client'],
                'device_name'    => $parsed['device_name'],
                'browser'        => $parsed['browser'],
                'ip_address'     => $request->ip(),
                'last_active_at' => now()->toIso8601String(),
                'city'           => null,
                'country'        => null,
            ];
        }

        return response()->json([
            'current' => $current,
            'others'  => $others,
        ]);
    }

    /**
     * POST /api/auth/sessions/terminate-others
     *
     * Завершить все сеансы кроме текущего (дублирует terminate-sessions для нового URL).
     */
    public function terminateOtherSessions(Request $request): JsonResponse
    {
        return $this->terminateSessions($request);
    }

    /**
     * Парсит User-Agent и возвращает platform / client / device_name / browser.
     */
    private function parseUserAgent(string $ua): array
    {
        // Определяем платформу
        $platform = 'unknown';
        if (preg_match('/Windows/i', $ua))       $platform = 'windows';
        elseif (preg_match('/Macintosh/i', $ua))  $platform = 'mac';
        elseif (preg_match('/Android/i', $ua))    $platform = 'android';
        elseif (preg_match('/iPhone|iPad/i', $ua)) $platform = 'ios';
        elseif (preg_match('/Linux/i', $ua))       $platform = 'linux';

        // Определяем браузер
        $browser = 'Unknown';
        if (preg_match('/Edg(e|\/)/i', $ua))         $browser = 'Edge';
        elseif (preg_match('/OPR|Opera/i', $ua))      $browser = 'Opera';
        elseif (preg_match('/YaBrowser/i', $ua))       $browser = 'Yandex';
        elseif (preg_match('/Chrome/i', $ua))          $browser = 'Chrome';
        elseif (preg_match('/Firefox/i', $ua))         $browser = 'Firefox';
        elseif (preg_match('/Safari/i', $ua))          $browser = 'Safari';

        // Клиент
        $client = 'web';

        // Имя устройства
        $platformNames = [
            'windows' => 'Windows',
            'mac'     => 'macOS',
            'android' => 'Android',
            'ios'     => 'iOS',
            'linux'   => 'Linux',
            'unknown' => 'Unknown',
        ];
        $device_name = $platformNames[$platform] ?? 'Unknown';

        return [
            'platform'    => $platform,
            'client'      => $client,
            'device_name' => $device_name,
            'browser'     => $browser,
        ];
    }

    /**
     * Доверить текущее устройство и вернуть данные для cookies.
     */
    private function trustCurrentDevice(User $user, Request $request): array
    {
        // Отозвать существующее устройство с тем же device_id (если есть)
        $existingDeviceId = $request->cookie('tdid');
        if ($existingDeviceId) {
            TrustedDevice::where('device_id', $existingDeviceId)->update(['revoked_at' => now()]);
        }

        $result = TrustedDevice::createForUser(
            $user,
            $request->userAgent() ?? 'Unknown',
            $request->ip()
        );

        $cookieLifetime = 60 * 24 * 180; // 180 дней в минутах

        return [
            'tdid_cookie' => cookie('tdid', $result['device_id'], $cookieLifetime, '/', null, true, true, false, 'Lax'),
            'tds_cookie' => cookie('tds', $result['device_secret'], $cookieLifetime, '/', null, true, true, false, 'Lax'),
        ];
    }

    /**
     * Принудить single-session: инвалидировать все сеансы кроме текущего.
     */
    private function enforceSingleSession(User $user, string $currentSessionId): void
    {
        // Удалить все sessions кроме текущей
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        // Записать current_session_id
        $user->update(['current_session_id' => $currentSessionId]);
    }
}
