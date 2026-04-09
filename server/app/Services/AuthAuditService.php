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

    // ─── Registration / verification lifecycle ──────────────────────────────

    public function registrationCreated(?int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.registration_created', $userId, $request, 'success', $meta);
    }

    public function verificationSent(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.verification_sent', $userId, $request, 'success', $meta);
    }

    public function verificationResent(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.verification_resent', $userId, $request, 'success', $meta);
    }

    public function emailVerified(int $userId, Request $request): void
    {
        $this->write('auth.email_verified', $userId, $request, 'success');
    }

    public function loginBlockedUnverifiedEmail(int $userId, Request $request): void
    {
        $this->write('auth.login_blocked_unverified_email', $userId, $request, 'blocked');
    }

    // ─── Email security notifications ────────────────────────────────────────

    public function passwordChangedEmailSent(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.password_changed_email_sent', $userId, $request, 'success', $meta);
    }

    public function newLoginAlertSent(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.new_login_alert_sent', $userId, $request, 'success', $meta);
    }

    // ─── Auth-method lifecycle ──────────────────────────────────────────────

    public function methodEmailLinked(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.method_email_linked', $userId, $request, 'success', $meta);
    }

    public function methodEmailVerified(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.method_email_verified', $userId, $request, 'success', $meta);
    }

    public function methodPasswordSet(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.method_password_set', $userId, $request, 'success', $meta);
    }

    public function methodYandexLinked(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.method_yandex_linked', $userId, $request, 'success', $meta);
    }

    public function methodQuickPinEnabled(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.method_quick_pin_enabled', $userId, $request, 'success', $meta);
    }

    public function methodQuickPinDisabled(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.method_quick_pin_disabled', $userId, $request, 'success', $meta);
    }

    // ─── Step-up ────────────────────────────────────────────────────────────

    public function stepUpChallengeStarted(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.step_up_challenge_started', $userId, $request, 'started', $meta);
    }

    public function stepUpChallengeCompleted(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.step_up_challenge_completed', $userId, $request, 'success', $meta);
    }

    public function stepUpChallengeFailed(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.step_up_challenge_failed', $userId, $request, 'failed', $meta);
    }

    public function stepUpRequiredActionBlocked(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.step_up_required_action_blocked', $userId, $request, 'blocked', $meta);
    }

    // Block 6A — Email OTP step-up events

    public function stepUpEmailOtpSent(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.step_up_email_otp_sent', $userId, $request, 'success', $meta);
    }

    public function stepUpEmailOtpVerified(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.step_up_email_otp_verified', $userId, $request, 'success', $meta);
    }

    public function stepUpEmailOtpFailed(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.step_up_email_otp_failed', $userId, $request, 'failed', $meta);
    }

    // ─── Sessions / tokens ──────────────────────────────────────────────────

    public function sessionsRevoked(int $userId, Request $request, int $revokedCount): void
    {
        $this->write('auth.sessions_revoked', $userId, $request, 'success', [
            'revoked_count' => $revokedCount,
        ]);
    }

    public function sessionRevoked(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.session_revoked', $userId, $request, 'success', $meta);
    }

    public function sessionsRevokedOther(int $userId, Request $request, int $revokedCount): void
    {
        $this->write('auth.sessions_revoked_other', $userId, $request, 'success', [
            'revoked_count' => $revokedCount,
        ]);
    }

    public function trustedDevicesRevokedAll(int $userId, Request $request, int $revokedCount): void
    {
        $this->write('auth.trusted_devices_revoked_all', $userId, $request, 'success', [
            'revoked_count' => $revokedCount,
        ]);
    }

    public function tokensRevoked(int $userId, Request $request, int $revokedCount): void
    {
        $this->write('auth.tokens_revoked', $userId, $request, 'success', [
            'revoked_count' => $revokedCount,
        ]);
    }

    // ─── Phone method lifecycle ──────────────────────────────────────────────

    public function methodPhoneLinkStarted(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.method_phone_link_started', $userId, $request, 'started', $meta);
    }

    public function methodPhoneVerified(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.method_phone_verified', $userId, $request, 'success', $meta);
    }

    public function methodYandexBootstrapCompleted(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.method_yandex_bootstrap_completed', $userId, $request, 'success', $meta);
    }

    public function recoveryPathBlocked(int $userId, Request $request, array $meta = []): void
    {
        $this->write('auth.recovery_path_blocked', $userId, $request, 'blocked', $meta);
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
