# 🏗️ Диаграммы и схемы архитектуры системы авторизации

---

## 1. Общая архитектура системы

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            SMETA EXPERT SERVER                              │
│                         Authorization System v2.0                            │
└─────────────────────────────────────────────────────────────────────────────┘

                         ┌─────────────────────────────┐
                         │   Frontend (Vue 3 + SPA)    │
                         │                             │
                         │  • LoginView.vue            │
                         │  • auth.ts (Pinia Store)    │
                         │  • AuthLogin.vue            │
                         │  • AuthForgot.vue           │
                         │  • AuthReset.vue            │
                         │  • AuthPinLogin.vue         │
                         └────────────────┬────────────┘
                                          │
                    ┌─────────────────────┼─────────────────────┐
                    │                     │                     │
                    ▼                     ▼                     ▼
        ┌──────────────────┐   ┌──────────────────┐   ┌──────────────────┐
        │  Session Auth    │   │   PIN Auth       │   │  Token Auth      │
        │  (Web UI)        │   │  (Trusted Dev)   │   │  (Chrome Ext)    │
        │                  │   │                  │   │                  │
        │ POST /api/login  │   │ POST /api/auth/  │   │ POST /api/chrome/│
        │                  │   │      pin/login   │   │   auth/token     │
        └────────┬─────────┘   └────────┬─────────┘   └────────┬─────────┘
                 │                      │                      │
                 │ Session Cookie       │ Device Cookies       │ API Token
                 │ + current_session_id │ (tdid + tds)         │ (Sanctum PAT)
                 │                      │                      │
                 └──────────────────────┼──────────────────────┘
                                        │
                    ┌───────────────────┴───────────────────┐
                    │                                       │
                    ▼                                       ▼
        ┌──────────────────────────┐          ┌──────────────────────────┐
        │  Laravel Backend         │          │  Database (MariaDB)      │
        │                          │          │                          │
        │ • AuthController         │          │ ┌──────────────────────┐ │
        │ • PinAuthController      │◄────────►│ │ users                │ │
        │ • ChromeExtController    │          │ │ • PIN fields         │ │
        │ • PasswordResetController│          │ │ • session fields     │ │
        │ • PhoneAuthController    │          │ │ • auth_status        │ │
        │                          │          │ └──────────────────────┘ │
        │ • Middleware:            │          │                          │
        │   - EnforceSingleSession │◄────────►│ ┌──────────────────────┐ │
        │   - VerifyCsrfToken      │          │ │ trusted_devices      │ │
        │   - Authenticate         │          │ │ • device_secret_hash │ │
        │                          │          │ │ • last_used_at       │ │
        │ • Services:              │          │ └──────────────────────┘ │
        │   - GeoIpService         │          │                          │
        │   - RateLimiter (Redis)  │          │ ┌──────────────────────┐ │
        │                          │          │ │ password_reset_tokens│ │
        │                          │          │ │ • token              │ │
        │                          │          │ │ • created_at         │ │
        │                          │          │ └──────────────────────┘ │
        │                          │          │                          │
        │                          │          │ ┌──────────────────────┐ │
        │                          │◄────────►│ │ personal_access_     │ │
        │                          │          │ │ tokens (Sanctum)     │ │
        │                          │          │ └──────────────────────┘ │
        │                          │          │                          │
        │                          │          │ ┌──────────────────────┐ │
        │                          │◄────────►│ │ admin_audit_logs     │ │
        │                          │          │ │ • action             │ │
        │                          │          │ │ • user_id            │ │
        │                          │          │ │ • metadata           │ │
        │                          │          │ └──────────────────────┘ │
        └──────────────────────────┘          └──────────────────────────┘

                         ┌─────────────────────────────┐
                         │   External Services         │
                         │                             │
                         │  • Email (SMTP)             │
                         │    └─ Password Reset Link   │
                         │                             │
                         │  • SMS-RU (CallCheck)       │
                         │    └─ Phone OTP Verify      │
                         │                             │
                         │  • GeoIP Service            │
                         │    └─ Russia IP Check       │
                         └─────────────────────────────┘
```

---

## 2. Поток аутентификации: Session-based (Web UI)

```
STEP 1: User enters email + password
┌────────────────────────────────────────────────────┐
│ Frontend: AuthLogin.vue                            │
│ ┌────────────────────────────────────────────────┐ │
│ │ Email: [________________]                      │ │
│ │ Password: [________________]                   │ │
│ │                                                │ │
│ │ [Войти] (disabled until form valid)           │ │
│ └────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────┘
                        │
                        │ POST /api/login
                        │ {email, password}
                        │ + X-CSRF-Token header
                        ▼
┌────────────────────────────────────────────────────┐
│ Backend: AuthController::login()                   │
│                                                    │
│ 1. Validate email + password format               │
│ 2. ✅ NEW: Check rate limiting (5 attempts/15min) │
│ 3. Auth::attempt($email, $password)               │
│    └─ Query: SELECT * FROM users WHERE email     │
│    └─ Hash::check($input_pwd, user.password)     │
│                                                    │
│ 4. If failed:                                     │
│    └─ RateLimiter::hit() → 429 after 5 attempts  │
│    └─ Return 401 "Invalid credentials"           │
│                                                    │
│ 5. Check account status:                         │
│    └─ if trashed(): return 403 "account deleted" │
│    └─ if isBlocked(): return 403 "account blocked"│
│                                                    │
│ 6. SECURITY: Regenerate session                  │
│    └─ $session->regenerate()                     │
│    └─ LARAVEL_SESSION cookie renewed             │
│                                                    │
│ 7. SINGLE-SESSION: Invalidate other sessions    │
│    └─ DELETE FROM sessions                       │
│       WHERE user_id = ? AND id != current_id    │
│    └─ user.current_session_id = new_id          │
│                                                    │
│ 8. Check trusted device (from cookie "tdid"):    │
│    └─ $device = TrustedDevice::find($tdid)       │
│    └─ if exists && verifySecret(): trusted=true  │
│    └─ else: trusted=false, offer PIN setup       │
│                                                    │
│ 9. ✅ Log successful login to admin_audit_logs   │
│    └─ action: 'auth.login_success'               │
│    └─ ip_address, user_agent, metadata           │
│                                                    │
│ 10. Return 200 + user data                       │
│     {                                            │
│       user: {id, name, email, ...},             │
│       pin_enabled: bool,                         │
│       has_trusted_device: bool,                  │
│       should_offer_pin_setup: bool               │
│     }                                            │
└────────────────────────────────────────────────────┘
                        │
                        │ Set LARAVEL_SESSION cookie
                        │ (HttpOnly, Secure: prod, SameSite: Lax)
                        │
                        ▼
┌────────────────────────────────────────────────────┐
│ Frontend: LoginView.vue                            │
│                                                    │
│ 1. await authStore.checkAuth(true)               │
│ 2. if pin_enabled && !has_trusted_device:        │
│    └─ Show PinSetupDialog                        │
│ 3. else if pin_enabled && has_trusted_device:    │
│    └─ Show PIN input (fast login next time)      │
│ 4. else:                                         │
│    └─ Navigate to dashboard                      │
│                                                    │
│ Now LARAVEL_SESSION cookie is set ✓              │
│ All subsequent requests include it automatically │
│ (withCredentials: true in axios.ts)              │
└────────────────────────────────────────────────────┘
```

---

## 3. Поток аутентификации: PIN-based (Trusted Device)

```
CONDITION: Browser has valid "tdid" + "tds" cookies (trusted device)
           AND user.pin_enabled = true

STEP 1: Check if device is trusted
┌────────────────────────────────────────────────────┐
│ Frontend: onMounted() - before showing login form  │
│                                                    │
│ 1. Read cookies: tdid, tds                        │
│ 2. GET /api/auth/pin/status                       │
│    ?tdid=UUID&tds=SECRET (via query params!)      │
│    └─ Note: Cookies NOT sent automatically        │
│              because HttpOnly blocks JS access    │
└────────────────────────────────────────────────────┘
                        │
                        │ GET /api/auth/pin/status
                        ▼
┌────────────────────────────────────────────────────┐
│ Backend: PinAuthController::status()               │
│                                                    │
│ 1. $device = TrustedDevice::find($tdid)           │
│ 2. if !device: return false                       │
│ 3. if !$device->verifySecret($tds):               │
│    └─ return false (secret doesn't match)         │
│                                                    │
│ 4. $user = $device->user                          │
│ 5. if $user->trashed(): return false              │
│ 6. if $user->isBlocked(): return false            │
│                                                    │
│ 7. if $user->pin_enabled:                         │
│    └─ return {                                    │
│         pin_enabled: true,                        │
│         user_name: $user->name,                   │
│         user_email: $user->email,                 │
│         device_trusted: true                      │
│       }                                           │
│    else:                                          │
│       return {pin_enabled: false}                 │
└────────────────────────────────────────────────────┘
                        │
                        ▼
┌────────────────────────────────────────────────────┐
│ Frontend: Show PIN input                           │
│ ┌────────────────────────────────────────────────┐ │
│ │ Welcome back, John!                            │ │
│ │ john@example.com                               │ │
│ │                                                │ │
│ │ Enter your 4-digit PIN:                        │ │
│ │ [_] [_] [_] [_]   (PinInput component)         │ │
│ │                                                │ │
│ │ [Забыли код?] [Сменить аккаунт]               │ │
│ └────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────┘
        │
        │ User enters 4 digits
        │
        ▼
┌────────────────────────────────────────────────────┐
│ Frontend: onPinEntered()                            │
│                                                    │
│ 1. POST /api/auth/pin/login                       │
│    {                                              │
│      pin: "1234"                                  │
│    }                                              │
│ 2. Cookies (tdid, tds) sent automatically         │
│    because: backend sets them as:                 │
│    - HttpOnly: server-only, JS can't read        │
│    - But browser auto-sends in HTTP requests      │
│    - CORS: withCredentials: true                  │
└────────────────────────────────────────────────────┘
                        │
                        │ POST /api/auth/pin/login
                        ▼
┌────────────────────────────────────────────────────┐
│ Backend: PinAuthController::login()                │
│                                                    │
│ 1. Read $tdid from cookie                         │
│ 2. Read $tds from cookie                          │
│ 3. if !$tdid || !$tds: return 403 "Not trusted"  │
│                                                    │
│ 4. $device = TrustedDevice::findActive($tdid)     │
│ 5. if !$device->verifySecret($tds):               │
│    └─ return 403 "Invalid device"                 │
│                                                    │
│ 6. $user = $device->user                          │
│ 7. Check PIN lock:                                │
│    └─ if $user->isPinLocked():                    │
│       return 429 "Try again in X minutes"         │
│                                                    │
│ 8. ✅ NEW: Rate limiting per device               │
│    └─ $key = "pin-login:{$tdid}:{$ip}"            │
│    └─ if RateLimiter::tooManyAttempts($key, 5):  │
│       return 429 "Too many attempts"              │
│                                                    │
│ 9. Verify PIN:                                    │
│    └─ if !Hash::check($pin, $user->pin_hash):     │
│       └─ RateLimiter::hit($key, 300)              │
│       └─ $user->recordFailedPinAttempt()          │
│       └─ if $user->pin_attempts >= 10:            │
│          └─ $device->revoke()  ◄──── Device block│
│          └─ return 403 "Device revoked"           │
│       └─ return 401 "Wrong PIN"                   │
│                                                    │
│ 10. ✅ PIN correct, reset counters                │
│     └─ RateLimiter::clear($key)                   │
│     └─ $user->resetPinAttempts()                  │
│     └─ $device->update([last_used_at = now()])   │
│                                                    │
│ 11. CREATE SESSION                                │
│     └─ Auth::login($user)                         │
│     └─ $session->regenerate()                     │
│                                                    │
│ 12. SINGLE-SESSION                                │
│     └─ DELETE FROM sessions WHERE user_id = ?    │
│        AND id != current_session_id               │
│     └─ user.current_session_id = new_id          │
│                                                    │
│ 13. ✅ Log successful PIN login                   │
│     └─ AdminAuditLog('auth.pin_login_success')   │
│                                                    │
│ 14. Return 200 + user data + new LARAVEL_SESSION │
└────────────────────────────────────────────────────┘
                        │
                        ▼
┌────────────────────────────────────────────────────┐
│ Frontend: onPinLoginSuccess()                      │
│                                                    │
│ 1. await authStore.checkAuth(true)               │
│ 2. navigate('/dashboard')                         │
│                                                    │
│ ✓ User logged in via PIN (fast path!)             │
└────────────────────────────────────────────────────┘
```

---

## 4. Поток восстановления пароля

```
SCENARIO: User forgot password

STEP 1: User enters email
┌────────────────────────────────────────────────────┐
│ Frontend: AuthForgot.vue                           │
│ ┌────────────────────────────────────────────────┐ │
│ │ Email: [________________]                      │ │
│ │                                                │ │
│ │ [Отправить ссылку]                             │ │
│ └────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────┘
                        │
                        │ POST /api/forgot-password
                        │ {email: "john@example.com"}
                        ▼
┌────────────────────────────────────────────────────┐
│ Backend: PasswordResetController::sendResetLink() │
│                                                    │
│ 1. Validate email format                          │
│                                                    │
│ 2. ✅ ANTI-ENUMERATION:                           │
│    └─ Always return same message (even if not    │
│       registered)                                 │
│    └─ This prevents attackers from finding       │
│       registered emails via timing attack         │
│                                                    │
│ 3. Configure reset URL to point to SPA frontend:  │
│    └─ Password::createUrlUsing(function(...) {    │
│       return '{FRONTEND_URL}/reset-password       │
│               ?token={TOKEN}                      │
│               &email={EMAIL}'                     │
│     })                                            │
│                                                    │
│ 4. Send reset link via Laravel Password broker:   │
│    └─ Password::sendResetLink($email)             │
│    └─ This creates password_reset_token in DB    │
│    └─ Sends email with reset link                │
│    └─ Token expires in 60 minutes                 │
│    └─ Rate limit: 1 request per 60 seconds       │
│                                                    │
│ 5. Return 200:                                    │
│    {                                              │
│      message: "Если этот email зарегистрирован,  │
│                мы отправили ссылку..."            │
│    }                                              │
└────────────────────────────────────────────────────┘
                        │
                        │ ✅ Send email via SMTP
                        │ (if configured in .env)
                        ▼
┌────────────────────────────────────────────────────┐
│ Email Service (SMTP)                               │
│                                                    │
│ From: noreply@app.com                              │
│ To: john@example.com                               │
│ Subject: Восстановление пароля                    │
│                                                    │
│ Dear John,                                         │
│                                                    │
│ Click here to reset your password:                │
│ https://app.com/reset-password                     │
│   ?token=ENCRYPTED_TOKEN                          │
│   &email=john@example.com                         │
│                                                    │
│ This link expires in 60 minutes.                  │
│ If you didn't request this, ignore this email.    │
│                                                    │
│ Best regards,                                      │
│ Smeta Expert Team                                  │
└────────────────────────────────────────────────────┘
                        │
                        │ User clicks link in email
                        ▼
┌────────────────────────────────────────────────────┐
│ Frontend: ResetPasswordView.vue                     │
│                                                    │
│ URL: /reset-password?token=ABC...&email=john@...  │
│                                                    │
│ 1. Extract token + email from query params        │
│ 2. Show password reset form (new password only)   │
│ ┌────────────────────────────────────────────────┐ │
│ │ Новый пароль:                                  │ │
│ │ [________________]                              │ │
│ │                                                │ │
│ │ Подтвердите пароль:                            │ │
│ │ [________________]                              │ │
│ │                                                │ │
│ │ [Сохранить пароль]                             │ │
│ └────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────┘
                        │
                        │ User enters new password
                        │
                        ▼
┌────────────────────────────────────────────────────┐
│ Frontend: onSubmit() - AuthReset.vue               │
│                                                    │
│ Validation:                                        │
│ • password: required, min 8 chars                  │
│ • password_confirmation: must match                │
│                                                    │
│ POST /api/reset-password                          │
│ {                                                 │
│   token: "ENCRYPTED_TOKEN",                       │
│   email: "john@example.com",                       │
│   password: "newSecurePassword123!",              │
│   password_confirmation: "newSecurePassword123!"  │
│ }                                                 │
└────────────────────────────────────────────────────┘
                        │
                        │ POST /api/reset-password
                        ▼
┌────────────────────────────────────────────────────┐
│ Backend: PasswordResetController::resetPassword()  │
│                                                    │
│ 1. Validate input (token, email, password)        │
│                                                    │
│ 2. Call Laravel Password::reset():                │
│    └─ This verifies:                              │
│       • Token exists in password_reset_tokens    │
│       • Token belongs to this email              │
│       • Token is not expired (< 60 minutes)      │
│       • Token hasn't been used already           │
│                                                    │
│ 3. If valid, execute callback:                    │
│    └─ $user->forceFill([                          │
│       'password' => Hash::make($password),        │
│       'remember_token' => Str::random(60)         │
│     ])->save()                                     │
│    └─ event(new PasswordReset($user))            │
│       (triggers listeners for logging/audit)      │
│                                                    │
│ 4. ✅ Token is automatically deleted from DB      │
│                                                    │
│ 5. ✅ NEW: Revoke all tokens + sessions + devices │
│    └─ $user->tokens()->delete()  ◄── Personal AT │
│    └─ DB::table('sessions')                       │
│       ->where('user_id', $user->id)->delete()    │
│    └─ $user->trustedDevices()                     │
│       ->update(['revoked_at' => now()])          │
│                                                    │
│ 6. Return 200 / 422:                              │
│    ✓ Password::PASSWORD_RESET                    │
│      → {message: "Пароль успешно изменён"}       │
│    ❌ Password::INVALID_TOKEN                     │
│      → "Недействительный или истекший токен"     │
│    ❌ Password::INVALID_USER                      │
│      → "Пользователь не найден"                  │
│    ❌ Password::RESET_THROTTLED                   │
│      → "Слишком много попыток"                   │
└────────────────────────────────────────────────────┘
                        │
                        ▼
┌────────────────────────────────────────────────────┐
│ Frontend: Show success message                     │
│                                                    │
│ ✓ "Пароль успешно изменён"                        │
│                                                    │
│ Redirect to login after 2 seconds                  │
│ (All old sessions/tokens/devices revoked!)        │
└────────────────────────────────────────────────────┘
```

---

## 5. Диаграмма состояний сессии (Single-Session Policy)

```
                     ┌──────────────────────────┐
                     │   User Logs In Via       │
                     │   Email + Password       │
                     └────────────┬─────────────┘
                                  │
                                  ▼
                    ┌─────────────────────────────────┐
                    │ NEW SESSION CREATED              │
                    │ current_session_id = UUID1       │
                    │ LARAVEL_SESSION = UUID1          │
                    │ Status: ACTIVE ✓                 │
                    └────────────────┬──────────────────┘
                                     │
                 Old Sessions        │        Token Present
                 AUTO-DELETE         │        in Cookie
                 ────────────────────┼────────────────────
                                     ▼
                    ┌─────────────────────────────────┐
                    │ User Makes API Request           │
                    │ GET /api/me                      │
                    │ Headers: {                       │
                    │   Cookie: LARAVEL_SESSION=UUID1  │
                    │ }                                │
                    └────────────────┬──────────────────┘
                                     │
                                     ▼
                    ┌─────────────────────────────────┐
                    │ Middleware: EnforceSingleSession │
                    │                                 │
                    │ 1. Get current session ID       │
                    │    from request →  UUID1         │
                    │ 2. Get user.current_session_id  │
                    │    from DB → UUID1               │
                    │ 3. Compare: UUID1 == UUID1?     │
                    │    ✓ YES → ALLOW                │
                    │    ❌ NO → REJECT (401)          │
                    └────────────────┬──────────────────┘
                        ✓ Match        │        ❌ No Match
                                       │
                    ┌──────────────────┼──────────────┐
                    ▼                  ▼              ▼
         ┌─────────────────┐  ┌──────────────────┐   │
         │ Process Request │  │ Kill Session     │   │
         │ Continue...     │  │ Status: EXPIRED  │   │
         └─────────────────┘  └──────────────────┘   │
                                                     │
                                                     ▼
                                        ┌──────────────────────┐
                                        │ Return 401           │
                                        │ "Session terminated" │
                                        └──────────────────────┘


SCENARIO: User logs in from 2nd device simultaneously

Device 1: POST /api/login                Device 2: POST /api/login
          ↓                                         ↓
    Session created                           Session created
    UUID1 in DB                               UUID2 in DB
    current_session_id = UUID1                current_session_id = UUID2
    ↓                                         ↓
    Backend: DELETE all other sessions
    WHERE user_id = X AND id != UUID2
    ↓
    UUID1 is DELETED! 🔴
    ↓
Device 1: GET /api/me (with UUID1 cookie)
          ↓
Middleware checks:
- session_id from cookie = UUID1
- user.current_session_id from DB = UUID2
- UUID1 != UUID2 → 401 REJECTED
          ↓
Status: USER IS LOGGED OUT FROM DEVICE 1 ✓
Message: "Session terminated due to single-session policy"
```

---

## 6. Таблица состояний аутентификации

| Состояние | Пользователь | Устройство | Сессия | PIN | Действие |
|-----------|--------------|-----------|--------|-----|----------|
| 1️⃣ Новый пользователь | ✓ Зарегистрирован | ❌ Не доверено | ✓ Активна | ❌ Не установлен | Требуется email+password |
| 2️⃣ Первый вход | ✓ Активен | ❌ Не доверено | ✓ Активна | ❌ Не установлен | Предложить установку PIN |
| 3️⃣ PIN установлен | ✓ Активен | ❌ Не доверено | ✓ Активна | ✓ Активен | Требуется PIN при следующем входе |
| 4️⃣ PIN активен, dev trusted | ✓ Активен | ✓ Доверено (30 дней) | ✓ Активна | ✓ Активен | Быстрый вход по PIN |
| 5️⃣ Chrome token выдан | ✓ Активен | ✓ Chrome Ext | ✓ Активна (PAT) | ✓ Активен | Доступ через Chrome Extension |
| 6️⃣ Пользователь заблокирован | ❌ Заблокирован | ✓/❌ | ❌ Убита | ⚠️ Заморожен | Запрос 403 на любой попытке |
| 7️⃣ Пароль сброшен | ✓ Активен | ❌ Все revoked | ❌ Убиты все | ✓ Остается | Требуется переустановка PIN |
| 8️⃣ PIN забыт | ✓ Активен | ✓/❌ | ✓ Активна | ❌ Не работает | Требуется email+password или device revoke |
| 9️⃣ Device revoked | ✓ Активен | ❌ Отозвано | ✓ Новая | ⚠️ Не работает | Требуется email+password |

---

## 7. Матрица рисков безопасности

```
┌──────────────────────────────────────────────────────────────────────────┐
│                     SECURITY RISK MATRIX                                 │
│                                                                          │
│  SEVERITY (Vertical) vs LIKELIHOOD (Horizontal)                         │
├──────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  🔴                          1️⃣ Rate Limit              5️⃣ Token         │
│ CRITICAL                     Missing Login              Revocation       │
│  Impact                                                                 │
│   ├─  🔴                         2️⃣ Cookies            6️⃣ CSRF           │
│   │   Account                    HttpOnly               Exemption        │
│  HIGH │   Compromise      3️⃣ Email Not                                 │
│   ├─  │                  Setup                                         │
│   │   🟡                  (Blocker)                                    │
│   │  MEDIUM                                                            │
│   ├─  │                  4️⃣ PIN Hash          7️⃣ Logging              │
│   │   🟠                 Algorithm            Audit                    │
│  LOW  │                                                                 │
│   └─                                                                    │
│       └─────────────────────────────────────────────────────────────┘
│       LOW              MEDIUM            HIGH         VERY HIGH
│                         LIKELIHOOD
│
│ Key:
│ 1️⃣ No rate limiting on /api/login → brute force attacks
│ 2️⃣ Device cookies lack HttpOnly, Secure, SameSite → XSS theft
│ 3️⃣ Email not configured → password reset broken
│ 4️⃣ PIN uses bcrypt without proper params → weak hashing
│ 5️⃣ Chrome tokens not revoked on password change → old token valid
│ 6️⃣ CSRF exemptions too broad (api/chrome/*) → token hijacking
│ 7️⃣ No audit logging → can't track who changed what
│
└──────────────────────────────────────────────────────────────────────────┘
```

---

## 8. Архитектура Rate Limiting

```
Redis / In-Memory Store
│
├─ login:{IP}
│  ├─ Counter: 0 → 5 (attempts)
│  ├─ TTL: 900 seconds (15 minutes)
│  ├─ On hit: RateLimiter::hit() increments
│  └─ Action: return 429 if >= 5
│
├─ pin-login:{DEVICE_ID}:{IP}
│  ├─ Counter: 0 → 5 (attempts per 5 min)
│  ├─ TTL: 300 seconds
│  ├─ On hit: RateLimiter::hit() increments
│  └─ Action: lock PIN for 15 min if >= 10
│
├─ forgot-password:{EMAIL}
│  ├─ Counter: 0 → 1 (requests)
│  ├─ TTL: 60 seconds
│  └─ User can request only once per minute
│
└─ chrome-token:{IP}
   ├─ Counter: 0 → 3 (token requests per 15 min)
   ├─ TTL: 900 seconds
   └─ Action: return 429 if >= 3


Middleware Chain for Request:
1. Route → App\Http\Middleware\Authenticate
2. If auth required, redirect to login (non-JSON) or return 401
3. → App\Http\Middleware\EnforceSingleSession
   └─ Compare current_session_id with user.current_session_id
4. → App\Http\Middleware\VerifyCsrfToken
   └─ Check X-CSRF-Token header (except in $except array)
5. → Controller Action
   └─ AuthController::login()
   └─ RateLimiter::tooManyAttempts() — check rate limit
   └─ Auth::attempt() — validation
   └─ RateLimiter::hit() — on failure
   └─ RateLimiter::clear() — on success
```

---

## 9. Жизненный цикл токена сброса пароля

```
TIME: 0 min
├─ User clicks "Forgot password"
├─ POST /api/forgot-password {email}
├─ Backend generates:
│  ├─ token = hash_token()  (random 64 chars)
│  ├─ email = "john@example.com"
│  ├─ created_at = NOW()
│  └─ INSERT INTO password_reset_tokens
└─ Email sent to user



TIME: 5 min
├─ User receives email
├─ Email contains:
│  ├─ reset_url = {FRONTEND_URL}/reset-password
│  │   ?token={ENCRYPTED_TOKEN}
│  │   &email={EMAIL}
│  └─ Valid until: 60 minutes from creation
└─ User clicks link



TIME: 30 min (still valid, expires in 30 min)
├─ User enters new password
├─ POST /api/reset-password
│  ├─ token (from URL param)
│  ├─ email (from URL param)
│  ├─ password (new)
│  └─ password_confirmation
├─ Backend:
│  ├─ Query password_reset_tokens
│  │  WHERE email = ? AND created_at > NOW() - 60 min
│  ├─ Find token matching {token} param
│  ├─ If found && user exists:
│  │  ├─ Hash new password
│  │  ├─ Update users.password
│  │  ├─ Delete token from password_reset_tokens ✓
│  │  ├─ Fire PasswordReset event
│  │  └─ Return 200 "Success"
│  └─ else:
│     └─ Return 422 / 401 "Invalid / expired"
└─ Session ended (user must re-login with new password)



TIME: 65 min (EXPIRED, cannot be used)
├─ User tries to use old token from email
├─ POST /api/reset-password
│  ├─ token = {OLD_TOKEN}
│  ├─ Backend query:
│  │  WHERE email = ? AND created_at > NOW() - 60 min
│  └─ NO RESULTS (token_created_at is > 60 min ago)
├─ Return 422 "Недействительный или истекший токен"
└─ User must request new reset link ("Забыли пароль" again)


SECURITY PROPERTIES:
✅ Single-use: Token deleted after successful reset
✅ Time-limited: Only valid for 60 minutes
✅ Email-linked: Token bound to specific email
✅ User-linked: Can only be used by account owner
✅ Rate-limited: Can request max 1 per 60 seconds
✅ Anti-enumeration: Same response for valid/invalid emails
```

---

## 10. Уровни доступа к API

```
┌──────────────────────────────────────────────────────┐
│                  API Access Levels                    │
├──────────────────────────────────────────────────────┤
│                                                      │
│ 🔓 PUBLIC (No Auth Required)                        │
│ ├─ POST /api/login                                  │
│ ├─ POST /api/forgot-password                        │
│ ├─ POST /api/reset-password                         │
│ ├─ GET /api/auth/pin/status                         │
│ ├─ POST /api/auth/pin/login                         │
│ ├─ POST /api/chrome/auth/token   ⚠️ NEEDS RATE LIM │
│ └─ POST /api/register                               │
│                                                      │
│ 🔐 AUTHENTICATED (LARAVEL_SESSION Cookie)           │
│ ├─ GET /api/me                                      │
│ ├─ POST /api/logout                                 │
│ ├─ PUT /api/me (update profile)                     │
│ ├─ POST /api/auth/password/change                   │
│ ├─ POST /api/auth/pin/set                           │
│ ├─ POST /api/auth/pin/disable                       │
│ ├─ GET /api/auth/pin/status (if logged in)          │
│ ├─ GET /api/auth/trusted-devices                    │
│ ├─ POST /api/auth/trusted-devices/{id}/revoke       │
│ ├─ POST /api/chrome/auth/token/session              │
│ ├─ GET /api/chrome/auth/status                      │
│ ├─ POST /api/chrome/auth/revoke                     │
│ ├─ POST /api/auth/sessions/terminate-others         │
│ └─ All other /api/* endpoints (projects, materials)│
│                                                      │
│ 🔑 TOKEN-BASED (Sanctum API Token: chrome-ext)     │
│ ├─ GET /api/chrome/me                               │
│ ├─ POST /api/chrome/templates                       │
│ ├─ All material parsing endpoints                   │
│ └─ Specific Chrome Extension endpoints              │
│                                                      │
│ 🎖️ ADMIN-ONLY                                       │
│ ├─ GET /api/admin/audit-logs                        │
│ ├─ POST /api/admin/users/{id}/block                 │
│ ├─ ... (other admin endpoints)                      │
│                                                      │
└──────────────────────────────────────────────────────┘
```

---

## 11. Таблица проверки безопасности

| № | Проверка | Статус | Fix Priority | Где | Примечание |
|---|----------|--------|--------------|-----|-----------|
| 1 | Rate limiting на login | ❌ | 🔴 Critical | AuthController | Нет защиты от перебора |
| 2 | Cookie HttpOnly на tdid | ❌ | 🔴 Critical | PinAuthController | XSS может украсть |
| 3 | Email восстановления | ❌ | 🔴 Critical | PasswordReset | Письма не отправляются |
| 4 | CSRF исключения узкие | ❌ | 🔴 High | VerifyCsrfToken | api/chrome/* слишком широко |
| 5 | Логирование попыток входа | ❌ | 🟡 Medium | AuthController | Нет audit trail |
| 6 | Отзыв токенов при смене пароля | ⚠️ | 🟡 Medium | AuthController | Частично реализовано |
| 7 | Уведомление о новом браузере | ❌ | 🟡 Medium | Frontend | Нет email уведомления |
| 8 | PIN хеширование алгоритм | ⚠️ | 🟡 Medium | User model | Bcrypt может быть слаб |
| 9 | Проверка pwned passwords | ❌ | 🟠 Low | PasswordValidator | Нет проверки словаря |
| 10 | Phone OTP реализация | ⚠️ | 🟠 Low | PhoneAuthController | В разработке |

---

**Diagram Version:** 1.0  
**Last Updated:** 7 апреля 2026  
**Maintained by:** GitHub Copilot
