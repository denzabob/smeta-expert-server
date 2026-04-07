<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Structured audit logging for auth-sensitive events.
 *
 * Writes to the 'auth-audit' log channel (daily rotating, 90-day retention).
 * Never logs plaintext passwords, PINs, tokens, or secrets.
 */
class AuthAuditService
{
    // ─── Login ──────────────────────────────────────────────────────────────

    public function loginSuccess(int $userId, Request $request): void
    {
        $this->write('auth.login_success', $userId, $request, 'success');
    }

    public function loginFailedInvalidCredentials(?int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.login_failed_invalid_credentials', $userId, $request, 'failed', $meta);
    }

    public function loginFailedRateLimit(?int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.login_failed_rate_limit', $userId, $request, 'blocked', $meta);
    }

    // ─── PIN ────────────────────────────────────────────────────────────────

    public function pinLoginSuccess(int $userId, Request $request): void
    {
        $this->write('auth.pin_login_success', $userId, $request, 'success');
    }

    public function pinLoginFailed(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.pin_login_failed', $userId, $request, 'failed', $meta);
    }

    // ─── Trusted devices ────────────────────────────────────────────────────

    public function trustedDeviceRevoked(int $userId, int $deviceId, Request $request): void
    {
        $this->write('auth.trusted_device_revoked', $userId, $request, 'success', [
            'device_id' => $deviceId,
        ]);
    }

    public function allDevicesRevoked(int $userId, Request $request, int $revokedCount): void
    {
        $this->write('auth.all_devices_revoked', $userId, $request, 'success', [
            'revoked_count' => $revokedCount,
        ]);
    }

    // ─── Password ───────────────────────────────────────────────────────────

    public function passwordChanged(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.password_changed', $userId, $request, 'success', $meta);
    }

    public function passwordResetRequested(Request $request): void
    {
        // Intentionally omit userId to avoid confirming existence of the email.
        $this->write('auth.password_reset_requested', null, $request, 'success');
    }

    public function passwordResetCompleted(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.password_reset_completed', $userId, $request, 'success', $meta);
    }

    // ─── Sessions / tokens ──────────────────────────────────────────────────

    public function sessionsRevoked(int $userId, Request $request, int $revokedCount): void
    {
        $this->write('auth.sessions_revoked', $userId, $request, 'success', [
            'revoked_count' => $revokedCount,
        ]);
    }

    public function tokensRevoked(int $userId, Request $request, int $revokedCount): void
    {
        $this->write('auth.tokens_revoked', $userId, $request, 'success', [
            'revoked_count' => $revokedCount,
        ]);
    }

    // ─── Internal ───────────────────────────────────────────────────────────

    private function write(
        string $action,
        ?int $userId,
        Request $request,
        string $result,
        array $meta = []
    ): void {
        Log::channel('auth-audit')->info($action, [
            'action'     => $action,
            'result'     => $result,
            'user_id'    => $userId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'meta'       => $meta ?: null,
        ]);
    }
}
