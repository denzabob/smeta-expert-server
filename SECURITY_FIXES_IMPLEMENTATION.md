# 🚀 Практическое руководство по исправлению уязвимостей авторизации

**Приоритет:** КРИТИЧЕСКИЙ  
**Время реализации:** 4-6 часов  
**Сложность:** Средняя  

---

## 1️⃣ Добавить Rate Limiting для /api/login

### 📍 Проблема
Без rate limiting attacker может перебирать пароли неограниченно.

### 🔧 Решение

#### file: `server/app/Http/Controllers/Api/AuthController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use App\Services\GeoIpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;  // ← ADD
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // ✅ NEW: Rate limiting by IP
        $throttleKey = 'login:' . $request->ip();
        
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'message' => "Слишком много попыток входа. Повторите через {$seconds} секунд.",
                'retry_after' => $seconds,
            ], 429);
        }

        if (!Auth::attempt($request->only('email', 'password'))) {
            // ✅ NEW: Record failed attempt (15 minutes throttle)
            RateLimiter::hit($throttleKey, 900);
            
            return response()->json([
                'message' => 'Неверный email или пароль',
            ], 401);
        }

        // ✅ Clear throttle on success
        RateLimiter::clear($throttleKey);

        // ... rest of the code ...
    }
}
```

### ✅ Проверка
```bash
# 1. Открыть Postman/cURL
# 2. Сделать 6 POST запросов к /api/login за короткое время
# 3. 6-й запрос должен вернуть 429 Too Many Requests

curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@test.com","password":"wrong"}'

# Ожидаемый ответ на 6-й попытке:
# {
#   "message": "Слишком много попыток входа. Повторите через 900 секунд.",
#   "retry_after": 900
# }
```

---

## 2️⃣ Защитить Cookies (HttpOnly, Secure, SameSite)

### 📍 Проблема
XSS может украсть device_id и device_secret из cookies, получив доступ на 30 дней.

### 🔧 Решение

#### file: `server/app/Http/Controllers/Api/PinAuthController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class PinAuthController extends Controller
{
    /**
     * ✅ NEW METHOD: Create secure trusted device cookies
     */
    protected function issueTrustedDeviceCookies($user, Request $request): array
    {
        $deviceId = (string) \Illuminate\Support\Str::uuid();
        $deviceSecret = \Illuminate\Support\Str::random(32);
        $deviceSecretHash = hash_hmac('sha256', $deviceSecret, config('app.key'));

        // Создать TrustedDevice запись в БД
        $user->trustedDevices()->create([
            'device_id' => $deviceId,
            'device_secret_hash' => $deviceSecretHash,
            'user_agent' => $request->header('User-Agent'),
            'ip_first' => $this->maskIpFirstOctets($request->ip()),
            'ip_last' => $request->ip(),  // Сохранить последние 2 октета для логирования
        ]);

        // ✅ SECURE COOKIES with HttpOnly, Secure, SameSite
        return [
            'tdid_cookie' => Cookie::make(
                name: 'tdid',
                value: $deviceId,
                minutes: 43200,  // 30 дней
                path: '/',
                domain: null,
                secure: env('SESSION_SECURE_COOKIES', false),  // true в production
                httpOnly: true,  // ✅ Защита от XSS
                raw: false,
                sameSite: 'lax'    // ✅ Защита от CSRF
            ),
            'tds_cookie' => Cookie::make(
                name: 'tds',
                value: $deviceSecret,
                minutes: 43200,
                path: '/',
                domain: null,
                secure: env('SESSION_SECURE_COOKIES', false),
                httpOnly: true,  // ✅ Защита от XSS
                raw: false,
                sameSite: 'lax'    // ✅ Защита от CSRF
            ),
        ];
    }

    private function maskIpFirstOctets(string $ip): string
    {
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            return '***.' . $parts[1] . '.*.*';  // Маскировка IP
        }
        return '***';
    }

    /**
     * POST /api/auth/pin/set
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

        if (!Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Неверный пароль'], 422);
        }

        $user->setPin($request->input('pin'));

        $response = [
            'message' => 'PIN-код установлен',
            'pin_enabled' => true,
        ];

        // ✅ Use new secure cookie method
        if ($request->boolean('trust_device', true)) {
            $cookieData = $this->issueTrustedDeviceCookies($user, $request);
            $response['device_trusted'] = true;

            return response()->json($response)
                ->withCookie($cookieData['tdid_cookie'])
                ->withCookie($cookieData['tds_cookie']);
        }

        return response()->json($response);
    }
}
```

### ✅ Проверка в DevTools
```javascript
// 1. Открыть Chrome DevTools → Application → Cookies
// 2. Проверить cookies после установки PIN:
// 
// Cookie 'tdid':
// ✓ HttpOnly: YES
// ✓ Secure: YES (в production)
// ✓ SameSite: Lax
// ✓ Max-Age: 2592000 (30 дней)
//
// Cookie 'tds':
// ✓ HttpOnly: YES
// ✓ Secure: YES
// ✓ SameSite: Lax
// ✓ Max-Age: 2592000

// 3. Проверить, что JavaScript НЕ может прочитать:
console.log(document.cookie);  // Должно быть пусто!
// Ожидаемый результат: ""
```

---

## 3️⃣ Настроить Email для восстановления пароля

### 📍 Проблема
Endpoint `/api/forgot-password` возвращает 200, но письма НЕ отправляются.

### 🔧 Решение

#### Step 1: Обновить `.env`

file: `server/.env`

```env
# ===== MAIL CONFIGURATION =====
MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io          # или ваш SMTP сервер
MAIL_PORT=587
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@smeta-expert.com
MAIL_FROM_NAME="Smeta Expert"
```

#### Step 2: Проверить миграцию

```bash
# Убедиться, что таблица password_reset_tokens существует
php artisan migrate:status | grep password_reset

# Если не создана:
php artisan make:migration create_password_reset_tokens_table

# Содержание миграции (должно быть):
php artisan migrate
```

#### Step 3: Создать Mail класс (если не существует)

file: `server/app/Mail/ResetPasswordNotification.php`

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $resetUrl,
        public string $userName = 'User'
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Восстановление пароля – Smeta Expert',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reset-password',
        );
    }
}
```

#### Step 4: Создать email template

file: `server/resources/views/emails/reset-password.blade.php`

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Восстановление пароля</title>
</head>
<body style="font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px;">
        <h2 style="color: #333;">Восстановление пароля</h2>
        
        <p>Привет, {{ $userName }}!</p>
        
        <p>Мы получили запрос на восстановление пароля для вашей учетной записи.</p>
        
        <p>Нажмите на кнопку ниже, чтобы восстановить пароль:</p>
        
        <a href="{{ $resetUrl }}" 
           style="display: inline-block; padding: 12px 24px; background: #007BFF; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0;">
            Восстановить пароль
        </a>
        
        <p>Или скопируйте эту ссылку в браузер:</p>
        <p style="word-break: break-all; color: #666;">{{ $resetUrl }}</p>
        
        <p style="color: #999; font-size: 12px; margin-top: 30px;">
            Ссылка действительна в течение 60 минут.<br>
            Если это не вы отправили запрос, проигнорируйте это письмо.
        </p>
    </div>
</body>
</html>
```

#### Step 5: Проверить конфиг

file: `server/config/mail.php` (проверить, что правильные значения)

```php
<?php

return [
    'default' => env('MAIL_DRIVER', 'log'),

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', 'smtp.mailtrap.io'),
            'port' => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
        ],
        // ...
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],
];
```

#### Step 6: Тестировать

```bash
# 1. Запустить tinker
php artisan tinker

# 2. Отправить тестовое письмо
Mail::raw('Test message', function($message) {
    $message->to('your-email@gmail.com');
    $message->subject('Test Email');
});

# Ожидаемый результат: (string) Mail was sent

# 3. Проверить входящие в Mailtrap / Gmail
# 4. Если письмо пришло — конфиг правильный
```

### ✅ Проверка в production

```bash
# 1. Открыть LoginView → "Забыли пароль"
# 2. Ввести email
# 3. Нажать "Отправить ссылку"
# 4. Проверить email (может быть в спаме)
# 5. Кликнуть ссылку
# 6. Ввести новый пароль
# 7. Проверить, что пароль изменился
```

---

## 4️⃣ Сузить CSRF исключения

### 📍 Проблема
`'api/chrome/*'` исключены из CSRF полностью, включая /api/chrome/auth/token (выдача токена).

### 🔧 Решение

#### file: `server/app/Http/Middleware/VerifyCsrfToken.php`

```php
<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/materials/fetch',  // ✅ Оставить, если нужно
        
        // ❌ УДАЛИТЬ ШИРОКОЕ ИСКЛЮЧЕНИЕ:
        // 'api/chrome/*',
        
        // ✅ ВМЕСТО ЭТОГО, специфицировать нужные endpoints:
        // (если они действительно нужны публично)
        // '/api/chrome/auth/token',      // Может быть публичным - требует отдельной защиты
    ];

    /**
     * Indicates whether the XSRF token should be read from the request body.
     *
     * @var bool
     */
    protected $addHttpCookie = true;
}
```

### ⚠️ Важно: Защита для /api/chrome/auth/token

Так как Chrome Extension получает токен через `issueToken()` (public endpoint), нужна альтернативная защита:

#### file: `server/app/Http/Controllers/Api/ChromeExtensionController.php`

```php
public function issueToken(Request $request): JsonResponse
{
    // ✅ Альтернативная защита: Rate limiting + IP check
    $throttleKey = 'chrome-token:' . $request->ip();
    
    if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
        return response()->json([
            'message' => 'Too many attempts. Try again later.',
        ], 429);
    }

    $request->validate([
        'email' => 'required|email',
        'password' => 'required|string',
    ]);

    $user = User::where('email', $request->email)->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        RateLimiter::hit($throttleKey, 900);  // Блокировка на 15 минут
        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    RateLimiter::clear($throttleKey);

    // ... создать токен ...
}
```

### ✅ Проверка
```bash
# 1. Попытаться POST запрос с CSRF токеном:
curl -X POST http://localhost/api/chrome/auth/token \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $(curl -s http://localhost/api/csrf-token | jq -r '.token')" \
  -d '{"email":"test@test.com","password":"test"}'

# 2. Ожидаемый результат:
# - Если CSRF TOKEN пропущен, должна быть ошибка (если поле не исключено)
# - Если Rate limiting сработал, должна быть 429
```

---

## 5️⃣ Добавить Логирование попыток входа

### 📍 Проблема
Невозможно отследить попытки входа, смену пароля, отзыв устройств.

### 🔧 Решение

#### file: `server/app/Http/Controllers/Api/AuthController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\AdminAuditLog;  // ← Уже существует в проекте!

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Rate limiting...
        $throttleKey = 'login:' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            // ✅ Логировать неудачную попытку
            AdminAuditLog::create([
                'user_id' => null,
                'action' => 'auth.login_failed_rate_limit',
                'ip_address' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
                'metadata' => [
                    'email' => $request->email,
                    'reason' => 'too_many_attempts',
                ],
            ]);

            return response()->json(['message' => 'Too many attempts'], 429);
        }

        if (!Auth::attempt($request->only('email', 'password'))) {
            RateLimiter::hit($throttleKey, 900);

            // ✅ Логировать неудачную попытку входа
            AdminAuditLog::create([
                'user_id' => null,
                'action' => 'auth.login_failed_invalid_credentials',
                'ip_address' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
                'metadata' => [
                    'email' => $request->email,
                ],
            ]);

            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        RateLimiter::clear($throttleKey);
        $user = Auth::user();

        // ... остальной код ...

        // ✅ Логировать успешный вход
        AdminAuditLog::create([
            'user_id' => $user->id,
            'action' => 'auth.login_success',
            'ip_address' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'metadata' => [
                'email' => $user->email,
                'has_trusted_device' => $hasTrustedDevice,
                'pin_enabled' => $user->pin_enabled,
            ],
        ]);

        return response()->json($responseData);
    }

    public function changePassword(Request $request)
    {
        // ... валидация ...

        $user = $request->user();

        // Verify current password
        if (!Auth::validate(['email' => $user->email, 'password' => $request->input('current_password')])) {
            // ✅ Логировать неудачную попытку смены пароля
            AdminAuditLog::create([
                'user_id' => $user->id,
                'action' => 'auth.change_password_failed', 
                'ip_address' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
                'metadata' => ['reason' => 'invalid_current_password'],
            ]);

            return response()->json(['message' => 'Current password is incorrect'], 401);
        }

        // Update password
        // ✅ Отозвать все токены
        $user->update(['password' => Hash::make($request->input('new_password'))]);
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->trustedDevices()->update(['revoked_at' => now()]);

        // ✅ Логировать успешную смену пароля
        AdminAuditLog::create([
            'user_id' => $user->id,
            'action' => 'auth.change_password_success',
            'ip_address' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'metadata' => [
                'sessions_terminated' => 'all',
                'tokens_revoked' => 'all',
                'devices_revoked' => 'all',
            ],
        ]);

        return response()->json(['message' => 'Password changed successfully']);
    }
}
```

#### file: `server/app/Http/Controllers/Api/PinAuthController.php`

```php
class PinAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate(['pin' => 'required|string|size:4']);

        $device = TrustedDevice::findActiveByDeviceId($request->cookie('tdid'));

        if (!$device) {
            // ✅ Логировать попытку входа с недействительным устройством
            AdminAuditLog::create([
                'user_id' => null,
                'action' => 'auth.pin_login_failed_invalid_device',
                'ip_address' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
                'metadata' => ['device_id' => $request->cookie('tdid')],
            ]);

            return response()->json(['message' => 'Device not trusted'], 403);
        }

        $user = $device->user;

        if (!$user->verifyPin($request->input('pin'))) {
            $user->recordFailedPinAttempt();

            // ✅ Логировать неудачную попытку PIN
            AdminAuditLog::create([
                'user_id' => $user->id,
                'action' => 'auth.pin_login_failed_wrong_pin',
                'ip_address' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
                'metadata' => [
                    'device_id' => $device->device_id,
                    'attempts_remaining' => max(0, 5 - $user->pin_attempts),
                ],
            ]);

            return response()->json(['message' => 'Wrong PIN'], 401);
        }

        // ✅ Успешный PIN login
        Auth::login($user);

        AdminAuditLog::create([
            'user_id' => $user->id,
            'action' => 'auth.pin_login_success',
            'ip_address' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'metadata' => ['device_id' => $device->device_id],
        ]);

        return response()->json($user);
    }
}
```

### ✅ Проверка

```bash
# 1. Открыть базу данных (adminer/phpMyAdmin)
# 2. Перейти в таблицу admin_audit_logs
# 3. Выполнить несколько операций входа
# 4. Проверить, что логи zapisываются:

SELECT * FROM admin_audit_logs 
WHERE action LIKE 'auth.%' 
ORDER BY created_at DESC 
LIMIT 20;

# Ожидаемый результат:
# - auth.login_success
# - auth.login_failed_invalid_credentials
# - auth.pin_login_success
# - auth.change_password_success
```

---

## 📋 Чеклист реализации

- [ ] Task 1: Rate limiting `/api/login` — OK?
- [ ] Task 2: Cookie security (HttpOnly, Secure, SameSite) — OK?
- [ ] Task 3: Email configuration + template — OK?
- [ ] Task 4: CSRF whitelist narrowed — OK?
- [ ] Task 5: Audit logging added — OK?
- [ ] Task 6: Testing completed — OK?
- [ ] Task 7: Code review passed — OK?
- [ ] Task 8: Deployed to staging — OK?
- [ ] Task 9: Security tests passed — OK?
- [ ] Task 10: Deployed to production — OK?

---

## 🧪 Тестовый сценарий

```bash
# 1. Тест Rate Limiting
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@test.com","password":"wrong"}' \
  # Повторить 6 раз → 6-й ответ должен быть 429

# 2. Тест Cookies Security
# DevTools → Application → Cookies → проверить HttpOnly, Secure, SameSite

# 3. Тест Email Восстановления
# POST /api/forgot-password → Проверить email → Кликнуть link → Изменить пароль

# 4. Тест Аудита
# SELECT * FROM admin_audit_logs WHERE created_at > NOW() - INTERVAL 1 MINUTE

# 5. Тест CSV Export (для аудитов)
# GET /api/admin/audit-logs/export?date_from=2026-01-01
```

---

## 🔗 Связанные файлы

- [SECURITY_ANALYSIS_AUTH_SYSTEM.md](SECURITY_ANALYSIS_AUTH_SYSTEM.md) — Полный анализ системы
- [AUTH_MODULE_ANALYSIS.md](AUTH_MODULE_ANALYSIS.md) — Старый анализ (архивный)
- `.github/instructions/backend.instructions.md` — Backend лучшие практики
- `server/config/auth.php` — Конфигурация аутентификации
- `server/config/session.php` — Конфигурация сессий
- `server/config/mail.php` — Конфигурация email

---

**Last updated:** 7 апреля 2026  
**Status:** Ready for implementation ✅
