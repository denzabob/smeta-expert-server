<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use App\Services\AuthAuditService;
use App\Services\GeoIpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function __construct(private readonly AuthAuditService $audit) {}

    /**
     * Handle a login request to the application.
     *
     * Rate-limited by email+IP compound key (5 attempts / 60 s).
     * On success, clears the limiter. On failure, increments it.
     * Returns generic 401 on credential failure to avoid user-enumeration.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // ── Rate limiting ──────────────────────────────────────────────────
        // Key includes hashed email + IP so one attacker cannot lock out all
        // users from a shared IP, and cannot enumerate registered emails.
        $email = strtolower(trim((string) $request->input('email')));
        $rateLimitKey = 'login:' . hash('sha256', $email) . ':' . $request->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $this->audit->loginFailedRateLimit(null, $request, ['retry_after' => $seconds]);
            return response()->json([
                'message'     => 'Слишком много попыток входа. Подождите перед следующей попыткой.',
                'retry_after' => $seconds,
            ], 429);
        }

        // ── Credential verification ────────────────────────────────────────
        if (!Auth::attempt(['email' => $email, 'password' => $request->input('password')])) {
            RateLimiter::hit($rateLimitKey, 60); // 60-second decay
            $this->audit->loginFailedInvalidCredentials(null, $request);
            return response()->json(['message' => 'Неверные учётные данные'], 401);
        }

        $user = Auth::user();

        // ── Account-state guards ───────────────────────────────────────────
        if ($user->trashed()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            RateLimiter::hit($rateLimitKey, 60);
            $this->audit->loginFailedInvalidCredentials($user->id, $request, ['reason' => 'account_deleted']);
            return response()->json([
                'message' => 'Ваша учётная запись удалена. Обратитесь к администратору для восстановления.',
                'error'   => 'account_deleted',
            ], 403);
        }

        if ($user->isBlocked()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $this->audit->loginFailedInvalidCredentials($user->id, $request, ['reason' => 'account_blocked']);
            return response()->json([
                'message' => 'Ваша учётная запись заблокирована.' . ($user->blocked_reason ? ' Причина: ' . $user->blocked_reason : ''),
                'error'   => 'account_blocked',
            ], 403);
        }

        // ── Success ─────────────────────────────────────────────────────────
        RateLimiter::clear($rateLimitKey);

        // Regenerate session for security
        $request->session()->regenerate();

        // Single-session: invalidate all other sessions
        $currentSessionId = $request->session()->getId();
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
        $user->update(['current_session_id' => $currentSessionId]);

        // Check for trusted device cookie
        $deviceId = $request->cookie('tdid');
        $hasTrustedDevice = false;

        if ($deviceId) {
            $device = TrustedDevice::findActiveByDeviceId($deviceId);
            if ($device && $device->user_id === $user->id) {
                $hasTrustedDevice = true;
                $device->update([
                    'last_used_at' => now(),
                    'ip_last'      => $request->ip(),
                ]);
            }
        }

        $this->audit->loginSuccess($user->id, $request);

        $responseData = $user->toArray();
        $responseData['pin_enabled']            = (bool) $user->pin_enabled;
        $responseData['has_trusted_device']     = $hasTrustedDevice;
        $responseData['should_offer_pin_setup'] = $user->pin_enabled && !$hasTrustedDevice;
        $responseData['should_offer_pin_enable'] = !$user->pin_enabled;

        return response()->json($responseData);
    }

    /**
     * Log the user out of the application.
     */
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /**
     * Get the authenticated User.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        $user = $request->user();
        $clientIp = GeoIpService::getClientIp($request);
        $isRussiaIp = GeoIpService::isRussiaIp($clientIp);
        
        return response()->json(array_merge($user->toArray(), [
            'role' => $user->role,
            'is_admin' => $user->isAdmin(),
            'is_russia_ip' => $isRussiaIp,
            'client_ip' => $clientIp,
        ]));
    }

    /**
     * Update the authenticated User's profile.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
        ]);

        $user = $request->user();
        
        if ($request->has('name')) {
            $user->name = $request->input('name');
        }
        
        $user->save();

        return response()->json($user);
    }

    /**
     * Update the authenticated User's password.
     *
     * PUT /api/me/password — revokes other sessions, all Sanctum tokens,
     * and trustedDevices on other browsers. Keeps the current session/device.
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->input('current_password'), $user->password)) {
            return response()->json(['message' => 'Текущий пароль неверен'], 422);
        }

        $user->password = bcrypt($request->input('password'));
        $user->save();

        // Revoke other sessions
        $currentSessionId = $request->session()->getId();
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
        $user->update(['current_session_id' => $currentSessionId]);

        // Revoke ALL Sanctum tokens (chrome extension tokens)
        $tokenCount = $user->tokens()->count();
        $user->tokens()->delete();

        // Revoke trusted devices on other browsers (keep current device if present)
        $currentDeviceId = $request->cookie('tdid');
        $query = $user->activeTrustedDevices();
        if ($currentDeviceId) {
            $query->where('device_id', '!=', $currentDeviceId);
        }
        $deviceCount = $query->count();
        $query->update(['revoked_at' => now()]);

        $this->audit->passwordChanged($user->id, $request, [
            'sessions_revoked' => true,
            'tokens_revoked'   => $tokenCount,
            'devices_revoked'  => $deviceCount,
        ]);

        return response()->json(['message' => 'Пароль успешно изменён']);
    }

    /**
     * Change password with session invalidation and trusted device revocation.
     *
     * POST /api/auth/password/change — revokes other sessions, all Sanctum tokens,
     * and trusted devices on other browsers. Keeps the current session/device.
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password'          => 'required|string',
            'new_password'              => 'required|string|min:8',
            'new_password_confirmation' => 'required|string|same:new_password',
        ]);

        $user = $request->user();

        // Verify current password
        if (!Hash::check($request->input('current_password'), $user->password)) {
            return response()->json(['message' => 'Текущий пароль неверен'], 401);
        }

        // Ensure new password differs from current
        if (Hash::check($request->input('new_password'), $user->password)) {
            return response()->json(['message' => 'Новый пароль должен отличаться от текущего'], 422);
        }

        $user->password = bcrypt($request->input('new_password'));
        $user->save();

        // Revoke other sessions (keep current)
        $currentSessionId = $request->session()->getId();
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
        $user->update(['current_session_id' => $currentSessionId]);

        // Revoke ALL Sanctum tokens (chrome extension tokens cannot survive a password change)
        $tokenCount = $user->tokens()->count();
        $user->tokens()->delete();

        // Revoke trusted devices on other browsers (keep current device if present)
        $currentDeviceId = $request->cookie('tdid');
        $query = $user->activeTrustedDevices();
        if ($currentDeviceId) {
            $query->where('device_id', '!=', $currentDeviceId);
        }
        $deviceCount = $query->count();
        $query->update(['revoked_at' => now()]);

        $this->audit->passwordChanged($user->id, $request, [
            'sessions_revoked' => true,
            'tokens_revoked'   => $tokenCount,
            'devices_revoked'  => $deviceCount,
        ]);

        return response()->json(['message' => 'Пароль успешно изменён']);
    }
}
