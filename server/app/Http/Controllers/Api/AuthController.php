<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle a login request to the application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = Auth::user();

        // Regenerate session for security
        $request->session()->regenerate();

        // Single-session: инвалидировать остальные сеансы
        $currentSessionId = $request->session()->getId();
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
        $user->update(['current_session_id' => $currentSessionId]);

        // Проверяем, есть ли доверенное устройство для этого браузера
        $deviceId = $request->cookie('tdid');
        $hasTrustedDevice = false;

        if ($deviceId) {
            $device = TrustedDevice::findActiveByDeviceId($deviceId);
            if ($device && $device->user_id === $user->id) {
                $hasTrustedDevice = true;
                $device->update([
                    'last_used_at' => now(),
                    'ip_last' => $request->ip(),
                ]);
            }
        }

        $responseData = $user->toArray();
        $responseData['pin_enabled'] = (bool) $user->pin_enabled;
        $responseData['has_trusted_device'] = $hasTrustedDevice;
        $responseData['should_offer_pin_setup'] = $user->pin_enabled && !$hasTrustedDevice;
        $responseData['should_offer_pin_enable'] = !$user->pin_enabled;

        return response()->json($responseData);
    }

    /**
     * Log the user out of the application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
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
        return response()->json($request->user());
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
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Auth::validate(['email' => $user->email, 'password' => $request->input('current_password')])) {
            return response()->json(['message' => 'Текущий пароль неверен'], 422);
        }

        $user->password = bcrypt($request->input('password'));
        $user->save();

        return response()->json(['message' => 'Пароль успешно изменён']);
    }

    /**
     * Change password with session invalidation and trusted device revocation.
     *
     * POST /api/auth/password/change
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8',
            'new_password_confirmation' => 'required|string|same:new_password',
        ]);

        $user = $request->user();

        // Verify current password
        if (!Auth::validate(['email' => $user->email, 'password' => $request->input('current_password')])) {
            return response()->json(['message' => 'Текущий пароль неверен'], 401);
        }

        // Ensure new password differs from current
        if (Auth::validate(['email' => $user->email, 'password' => $request->input('new_password')])) {
            return response()->json(['message' => 'Новый пароль должен отличаться от текущего'], 422);
        }

        $user->password = bcrypt($request->input('new_password'));
        $user->save();

        // Invalidate all other sessions (keep current active)
        $currentSessionId = $request->session()->getId();
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
        $user->update(['current_session_id' => $currentSessionId]);

        // Revoke trusted devices on other browsers (keep current device if present)
        $currentDeviceId = $request->cookie('tdid');
        $query = $user->activeTrustedDevices();
        if ($currentDeviceId) {
            $query->where('device_id', '!=', $currentDeviceId);
        }
        $query->update(['revoked_at' => now()]);

        return response()->json(['message' => 'Пароль успешно изменён']);
    }
}
