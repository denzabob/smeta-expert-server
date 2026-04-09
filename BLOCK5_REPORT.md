# Block 5 — Recovery Gaps, Actionable Completion, and Account Control Surface

**Status:** ✅ Complete — 54/54 tests passing (159 assertions)  
**Block 4 regression:** ✅ 47/47 tests still passing (131 assertions)  
**Total test suite:** 101 tests, 290 assertions — all green

---

## 1. Changed Files

| File | Change |
|---|---|
| `app/Services/Auth/AuthMethodProfileService.php` | Extended `profile()` return with 6 new fields; updated `recommendedActions()` to be truly actionable; added private `recoveryMethods()`, `blockedActions()`, `prerequisiteActions()` |
| `app/Models/StepUpChallenge.php` | Added `'revoke_all_devices'` to `SCOPES` const |
| `app/Services/AuthAuditService.php` | Added 8 new audit event methods |
| `app/Http/Controllers/Api/AccountControlController.php` | **NEW** — session and trusted-device control surface |
| `app/Http/Controllers/Api/BootstrapController.php` | **NEW** — Yandex-only phone bootstrap flow |
| `routes/api.php` | Added 8 new routes under `auth:sanctum` |
| `tests/Feature/AccountControlTest.php` | **NEW** — 18 tests for session/device management |
| `tests/Feature/YandexBootstrapTest.php` | **NEW** — 10 tests for bootstrap flow |
| `tests/Feature/RecommendedActionsTest.php` | **NEW** — 11 tests for actionable recommendations |
| `tests/Feature/RecoveryPolicyTest.php` | **NEW** — 15 tests for recovery policy matrix |

---

## 2. What Was Implemented

### 2.1 Actionable Recommendations (no phantom actions)

`AuthMethodProfileService::recommendedActions()` was rewritten to enforce a strict invariant:  
**An action is only recommended if the user can complete it right now with their currently available factors.**

Previously, `set_password` could be recommended for a Yandex-only user who has no phone and no password — but they cannot complete set_password because it requires a step-up challenge that can only be satisfied by phone OTP or password. This was a phantom recommendation.

Now:
- `set_password` → only if `$canStepUp` is true (user has phone OTP or existing password)
- `enable_quick_pin` → only if `$canStepUp` is true
- `bootstrap_add_phone` → only for Yandex-only users with no phone (the real escape path)
- `add_phone` → only for password users with no phone (they can authenticate, so phone link is actionable)

### 2.2 Recovery Policy Matrix (new `auth-status` fields)

`GET /api/security/auth-status` returns 6 new fields:

| Field | Type | Description |
|---|---|---|
| `can_self_recover` | `bool` | At least one recovery path is available |
| `recovery_methods` | `string[]` | Active recovery paths: `phone_otp`, `password_reset`, `yandex_oauth` |
| `can_manage_sessions` | `bool` | Always `true` for authenticated users |
| `can_manage_trusted_devices` | `bool` | Always `true` for authenticated users |
| `blocked_actions` | `string[]` | Actions user wants but cannot currently complete |
| `prerequisite_actions` | `object` | Map: `blocked_action → required_step` |

**Recovery method conditions:**
- `phone_otp` → user has verified phone (`phone_verified_at` is not null)
- `password_reset` → user has password AND non-null email
- `yandex_oauth` → user has an active Yandex social account

**Blocked action logic (Yandex-only trap):**
- `set_password` is blocked when no step-up factor exists
- `enable_quick_pin` is blocked when no step-up factor exists
- Prerequisite for both: `bootstrap_add_phone`

### 2.3 Yandex Bootstrap Escape Flow

Yandex-only users (no password, no phone) are in a "trapped" state — they can log in via Yandex OAuth but cannot complete any security setup because they have no step-up factor.

**Bootstrap flow** provides a narrowly scoped phone-link path that does NOT require step-up:

```
POST /api/security/bootstrap/phone/initiate
  → validates: user has Yandex, has no existing phone
  → sends OTP via VerificationCodeService (purpose: 'bootstrap_phone')
  → stores a 10-minute challenge

POST /api/security/bootstrap/phone/verify
  → validates OTP
  → sets user.phone + user.phone_verified_at
  → fires audit: auth.method_yandex_bootstrap_completed
  → returns updated recommended_actions (set_password now appears)
```

Security notes:
- Bootstrap requires active Sanctum session (cannot be called unauthenticated)
- Non-Yandex users receive 403
- Users with existing phone receive 409
- Bootstrap is a one-time escape; once phone is set, set_password becomes a normal actionable step-up flow

### 2.4 Account Control Surface

New endpoints for session and trusted-device management:

#### Sessions

| Method | Route | Description |
|---|---|---|
| `GET` | `/api/security/sessions` | List all DB sessions for user; marks current session |
| `DELETE` | `/api/security/sessions/others` | Revoke all sessions except current; no step-up required |
| `DELETE` | `/api/security/sessions/{id}` | Revoke a specific session; ownership enforced; cannot revoke current |

**Notes:**
- Session listing reads from `sessions` table (`SESSION_DRIVER=database`)
- `revoke_others` does NOT update `current_session_id` to avoid invalidating subsequent `EnforceSingleSession` checks
- Each session entry includes: `id`, `current` (bool), `created_at`, `last_active_at`, `ip`, `device`

#### Trusted Devices

| Method | Route | Description | Step-up |
|---|---|---|---|
| `GET` | `/api/security/trusted-devices` | List non-revoked devices; marks current via `tdid` cookie | No |
| `DELETE` | `/api/security/trusted-devices/{id}` | Revoke single device | No |
| `DELETE` | `/api/security/trusted-devices` | Revoke ALL devices | Yes — scope: `revoke_all_devices` |

Bulk revocation requires a valid step-up token with scope `revoke_all_devices`. Without it, returns 422 (missing token) or 401 (invalid token).

---

## 3. Auth-Status API Contract (full — Block 4 + 5)

`GET /api/security/auth-status` returns (abridged — new fields marked ⭐):

```json
{
  "has_password": true,
  "has_phone": true,
  "phone_verified": true,
  "has_email": true,
  "email_verified": true,
  "has_yandex": false,
  "pin_enabled": false,
  "recommended_actions": ["enable_quick_pin"],

  "can_self_recover": true,
  "recovery_methods": ["phone_otp", "password_reset"],
  "can_manage_sessions": true,
  "can_manage_trusted_devices": true,
  "blocked_actions": [],
  "prerequisite_actions": {}
}
```

**Fields marked ⭐ were added in Block 5.**

---

## 4. Recommended Actions Logic (full decision tree)

```
hasYandex && !hasPhone && !hasPassword
  → bootstrap_add_phone
  (set_password, enable_quick_pin are BLOCKED — not recommended)

!emailVerified
  → verify_email

!phoneVerified && hasPhone
  → verify_phone

!hasPhone && hasPassword
  → add_phone   (actionable: user can authenticate)

!hasPhone && !hasPassword && hasYandex
  → bootstrap_add_phone   (already covered above)

!hasPassword && canStepUp
  → set_password

!pinEnabled && canStepUp
  → enable_quick_pin
```

`canStepUp` = `hasPassword || phoneVerified`

---

## 5. Recovery / Control Policy Matrix

| User State | can_self_recover | recovery_methods | blocked_actions |
|---|---|---|---|
| Yandex-only, no phone, no password | ✅ | `yandex_oauth` | `set_password`, `enable_quick_pin` |
| Phone verified, no password | ✅ | `phone_otp` | — |
| Password + email, no phone | ✅ | `password_reset` | — |
| Phone + password + email + Yandex | ✅ | `phone_otp`, `password_reset`, `yandex_oauth` | — |
| Email only (no phone, no password, no Yandex) | ❌ | `[]` | `set_password`, `enable_quick_pin` |
| Unverified phone only | ❌ | `[]` | `set_password`, `enable_quick_pin` |

---

## 6. Step-Up Token: `revoke_all_devices` Scope

Added to `StepUpChallenge::SCOPES`. Flow:

```
POST /api/security/step-up/initiate  { scope: "revoke_all_devices" }
  → user goes through challenge (password or phone OTP)
POST /api/security/step-up/complete  (existing)
  → returns step_up_token (raw 64-char token; SHA-256 hash stored in DB)

DELETE /api/security/trusted-devices  { step_up_token: "<raw token>" }
  → StepUpService::findValidToken() hashes input, queries DB
  → validates scope === "revoke_all_devices"
  → revokes all non-revoked devices
  → consumes token (single-use)
```

---

## 7. Audit Events Added

| Method | Event Name |
|---|---|
| `sessionRevoked()` | `auth.session_revoked` |
| `sessionsRevokedOther()` | `auth.sessions_revoked_other` |
| `trustedDeviceRevoked()` | `auth.trusted_device_revoked` |
| `trustedDevicesRevokedAll()` | `auth.trusted_devices_revoked_all` |
| `methodPhoneLinkStarted()` | `auth.method_phone_link_started` |
| `methodPhoneVerified()` | `auth.method_phone_verified` |
| `yandexBootstrapCompleted()` | `auth.method_yandex_bootstrap_completed` |
| `recoveryPathBlocked()` | `auth.recovery_path_blocked` |

---

## 8. Security Decisions

1. **Bootstrap does not require step-up.** The bootstrap phone-link flow is allowed from an authenticated Yandex session without a separate step-up challenge. Rationale: bootstrap IS the prerequisite — the user has already authenticated via Yandex OAuth (possession factor). Making bootstrap itself require step-up would create a circular dependency.

2. **Revoke-others does not require step-up.** Cleaning up your own sessions is a defensive, low-risk action. We do not update `current_session_id` on revoke-others because doing so would force the current session to re-validate against `EnforceSingleSession`, breaking stateless test invocations and creating unnecessary friction.

3. **Bulk device revocation requires step-up.** Revoking all trusted devices is a high-impact action (removes all known-device login paths). Scope `revoke_all_devices` was added specifically for this.

4. **Phone-only recovery does not include unverified phones.** Only `phone_verified_at IS NOT NULL` counts as a recovery method. An unverified phone number could be spoofed.

5. **Password reset requires non-null email.** A password is stored as a hash; reset requires an email delivery channel. A user with password but `email=NULL` cannot reset via email and therefore does not have `password_reset` in `recovery_methods`.

---

## 9. Manual Test Checklist

### Bootstrap flow (Yandex-only user)
- [ ] Log in via Yandex OAuth (fresh account, no phone, no password)
- [ ] `GET /api/security/auth-status` → `recommended_actions` contains `bootstrap_add_phone`, does NOT contain `set_password`
- [ ] `POST /api/security/bootstrap/phone/initiate` with a phone number → 200 + "code sent"
- [ ] Calling initiate again immediately → 422 (phone already pending or user already has phone)
- [ ] `POST /api/security/bootstrap/phone/verify` with wrong code → 422
- [ ] `POST /api/security/bootstrap/phone/verify` with correct code → 200
- [ ] After verify: `GET /api/security/auth-status` → `recommended_actions` now contains `set_password`, no longer contains `bootstrap_add_phone`
- [ ] Non-Yandex user calling bootstrap/phone/initiate → 403
- [ ] User with existing phone calling bootstrap/phone/initiate → 409

### Session management
- [ ] `GET /api/security/sessions` → returns array of session objects with `id`, `current`, `ip`, `device`, `last_active_at`
- [ ] One session marked `current: true` (the current browser session)
- [ ] `DELETE /api/security/sessions/others` → 200 + `revoked_count`; other sessions gone; current session still works
- [ ] `DELETE /api/security/sessions/{id}` with another user's session ID → 404
- [ ] `DELETE /api/security/sessions/{current_id}` → 422 "cannot revoke current session"
- [ ] Unauthenticated calls to any session endpoint → 401

### Trusted device management
- [ ] `GET /api/security/trusted-devices` → lists only non-revoked devices; current device marked
- [ ] `DELETE /api/security/trusted-devices/{id}` → 200; `GET` no longer returns that device
- [ ] `DELETE /api/security/trusted-devices/{other_users_device_id}` → 404
- [ ] `DELETE /api/security/trusted-devices` without `step_up_token` → 422
- [ ] `DELETE /api/security/trusted-devices` with invalid token → 401 with `error: step_up_required`
- [ ] `DELETE /api/security/trusted-devices` with valid revoke_all_devices token → 200; all devices gone; token consumed (second use → 401)

### Recovery policy
- [ ] Fully verified user (phone + email + Yandex): `recovery_methods` contains all three; `can_self_recover: true`
- [ ] Yandex-only user: `recovery_methods: ["yandex_oauth"]`; `blocked_actions: ["set_password", "enable_quick_pin"]`; `prerequisite_actions.set_password = "bootstrap_add_phone"`
- [ ] Email-only user (no phone, no password, no Yandex): `can_self_recover: false`; `recovery_methods: []`

---

## 10. Automated Tests Added

| Suite | Tests | Covers |
|---|---|---|
| `YandexBootstrapTest` | 10 | Bootstrap initiate/verify; invalid code; non-Yandex blocked; existing phone blocked; state updates |
| `AccountControlTest` | 18 | Session list (structure, current marker); revoke-others; revoke-single (ownership, 404, 422-current); device list (empty, active, revoked); device revoke (single, ownership); device revoke-all (step-up required, invalid token, valid token, token reuse) |
| `RecommendedActionsTest` | 11 | Yandex-only sees bootstrap not set_password; password-only sees add_phone; phone-only sees set_password; unverified phone sees verify_phone; fully verified → nothing critical; no duplicate actions; ordering invariant |
| `RecoveryPolicyTest` | 15 | can_self_recover per archetype; recovery_methods completeness; unverified phone excluded; blocked_actions and prerequisite_actions for Yandex-only trap; password user no blocked actions; fully verified no prerequisites; control surface always available; response contract completeness |

---

## 11. Known Risks and Edge Cases

1. **Session driver must be `database`.** `AccountControlController::listSessions()` queries `DB::table('sessions')`. If the session driver is changed to `file` or `redis`, the sessions endpoint returns empty results. The current config (`SESSION_DRIVER=database`) is correct.

2. **current session marker in tests.** `actingAs()` in PHPUnit does not write a real row to the `sessions` table. The `current: true` marker will never appear when testing via `actingAs()`. Tests verify structure and at-most-one-current constraint instead.

3. **Bootstrap OTP expiry.** The OTP challenge for bootstrap uses `VerificationCodeService` with purpose `bootstrap_phone`. The TTL is 10 minutes (same as other OTP flows). If the user requests the OTP but doesn't verify within 10 minutes, they must start over.

4. **Concurrent Yandex + bootstrap flow.** If a user logs in via Yandex on two devices simultaneously, both can call `bootstrap/phone/initiate`. The second call will likely fail with "phone already pending" (depending on how `VerificationCodeService` handles existing pending codes). This is acceptable — only one bootstrap can be in progress at a time.

5. **Revoke-all-devices token single-use enforcement.** The step-up token is consumed on use. A second call with the same raw token will return 401. This is by design (prevents replay attacks).

---

## 12. Definition of Done

- [x] `AuthMethodProfileService.profile()` returns all 6 new Block 5 fields
- [x] `recommended_actions` only contains actions the user can actually complete
- [x] `bootstrap_add_phone` is the only recommendation for Yandex-only trapped users
- [x] `blocked_actions` + `prerequisite_actions` accurately describe the Yandex trap
- [x] `BootstrapController` — initiate + verify endpoints functional with proper guards
- [x] `AccountControlController` — all 6 session/device endpoints functional
- [x] `revoke_all_devices` step-up scope wired end-to-end
- [x] All new routes registered in `api.php` with per-route throttle
- [x] 8 new audit events recorded
- [x] 54 Block 5 tests passing / 47 Block 4 tests still passing (101 total, 290 assertions)
- [x] No regressions
