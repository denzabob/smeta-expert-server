# Анализ модуля аутентификации, регистрации и восстановления пароля

**Smeta Expert Server** — подробная документация системы аутентификации  
**Дата анализа:** 14 марта 2026 г.  
**Статус:** ✅ Production Ready (частично)

---

## 📋 Оглавление

1. [Общая архитектура](#общая-архитектура)
2. [Backend (Laravel)](#backend-laravel)
3. [Frontend (Vue 3)](#frontend-vue-3)
4. [Конфигурация](#конфигурация)
5. [База данных](#база-данных)
6. [Матрица реализации](#матрица-реализации)
7. [Выводы и рекомендации](#выводы-и-рекомендации)

---

## Общая архитектура

### Типы аутентификации

| Тип | Технология | Для кого | Endpoint |
|-----|------------|----------|----------|
| **Session-based** | Laravel Sanctum + Cookie | Web-пользователи | `/api/login` |
| **PIN-based** | Кастомная реализация | Доверенные устройства | `/api/auth/pin/login` |
| **Token-based** | Sanctum Personal Access Token | Chrome Extension | `/api/chrome/auth/token` |

### Схема потока аутентификации

```
┌─────────────────────────────────────────────────────────────────┐
│                    Web-пользователь                              │
│                                                                  │
│  ┌──────────────┐    ┌──────────────┐    ┌─────────────────┐   │
│  │   LoginView  │───▶│  AuthLogin   │───▶│  authStore.ts   │   │
│  │   (modes)    │    │   (form)     │    │  (Pinia store)  │   │
│  └──────────────┘    └──────────────┘    └────────┬────────┘   │
│                                                    │             │
└────────────────────────────────────────────────────┼─────────────┘
                                                     │
                                                     ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Laravel Backend                               │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │              AuthController.php                          │   │
│  │  • login()  • logout()  • me()  • changePassword()      │   │
│  └──────────────────────────────────────────────────────────┘   │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │              PinAuthController.php                       │   │
│  │  • status()  • login()  • set()  • trustedDevices()     │   │
│  └──────────────────────────────────────────────────────────┘   │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │           ChromeExtensionController.php                  │   │
│  │  • issueToken()  • tokenStatus()  • me()                │   │
│  └──────────────────────────────────────────────────────────┘   │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │              Middleware                                  │   │
│  │  • auth:sanctum  • EnforceSingleSession  • CSRF          │   │
│  └──────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│                    MariaDB Database                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐  │
│  │    users     │  │    sessions  │  │ personal_access_     │  │
│  │  + PIN fields│  │  (database)  │  │ tokens (Sanctum)     │  │
│  └──────────────┘  └──────────────┘  └──────────────────────┘  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │              trusted_devices                             │   │
│  │  device_id (UUID)  •  device_secret_hash  •  user_id     │   │
│  └──────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

---

## Backend (Laravel)

### 1. Контроллеры аутентификации

#### AuthController.php
**Путь:** `server/app/Http/Controllers/Api/AuthController.php`

**Назначение:** Основной контроллер управления сессиями и профилем пользователя.

| Метод | HTTP | Endpoint | Описание |
|-------|------|----------|----------|
| `login()` | POST | `/api/login` | Вход по email/паролю |
| `logout()` | POST | `/api/logout` | Выход, инвалидация сессии |
| `me()` | GET | `/api/me` | Информация о пользователе |
| `updateProfile()` | PUT | `/api/me` | Обновление имени |
| `updatePassword()` | PUT | `/api/me/password` | Обновление пароля |
| `changePassword()` | POST | `/api/auth/password/change` | Смена пароля с инвалидацией сессий |

**Ключевые особенности:**

```php
// Single-session enforcement
$user->current_session_id = $request->session()->getId();
// При новом входе все старые сессии инвалидируются

// Проверка доверенного устройства
$trustedDevice = TrustedDevice::findActiveByDeviceId($deviceId);
$response->cookie('tdid', $deviceId, 43200, ...); // 30 дней
```

**Возвращаемые данные:**
```json
{
  "user": { "id": 1, "name": "...", "email": "..." },
  "pin_enabled": true,
  "has_trusted_device": false,
  "should_offer_pin_setup": true
}
```

---

#### PinAuthController.php
**Путь:** `server/app/Http/Controllers/Api/PinAuthController.php`

**Назначение:** Управление PIN-кодом и доверенными устройствами.

| Метод | HTTP | Endpoint | Auth | Описание |
|-------|------|----------|------|----------|
| `status()` | GET | `/api/auth/pin/status` | ❌ | Статус PIN (публичный) |
| `login()` | POST | `/api/auth/pin/login` | ❌ | Вход по PIN |
| `set()` | POST | `/api/auth/pin/set` | ✅ | Установить PIN |
| `disable()` | POST | `/api/auth/pin/disable` | ✅ | Отключить PIN |
| `trustedDevices()` | GET | `/api/auth/trusted-devices` | ✅ | Список устройств |
| `revokeDevice($id)` | POST | `/api/auth/trusted-devices/{id}/revoke` | ✅ | Отозвать устройство |
| `forgetDevice()` | POST | `/api/auth/trusted-device/forget` | ❌ | «Сменить аккаунт» |
| `terminateSessions()` | POST | `/api/auth/terminate-sessions` | ✅ | Завершить все сессии |
| `sessions()` | GET | `/api/auth/sessions` | ✅ | Список сессий |
| `terminateOtherSessions()` | POST | `/api/auth/sessions/terminate-others` | ✅ | Завершить другие |

**Rate limiting:**
```php
// 5 попыток PIN за 5 минут
RateLimiter::tooManyAttempts('pin-login:'.$deviceId, 5)

// Блокировка после 5 неудачных попыток на 15 минут
$user->pin_locked_until = now()->addMinutes(15);

// Отзыв устройства после 10 неудачных попыток
$trustedDevice->revoke();
```

**Парсинг User-Agent:**
```php
// Определение типа устройства
$parser = new DeviceParser($userAgent);
$deviceType = $parser->getDeviceType(); // mobile, tablet, desktop
$browser = $parser->getBrowser();
$os = $parser->getOS();
```

---

#### ChromeExtensionController.php
**Путь:** `server/app/Http/Controllers/Api/ChromeExtensionController.php`

**Назначение:** Аутентификация Chrome-расширения через токены Sanctum.

| Метод | HTTP | Endpoint | Auth | Описание |
|-------|------|----------|------|----------|
| `issueToken()` | POST | `/api/chrome/auth/token` | ❌ | Токен по email/паролю |
| `issueTokenFromSession()` | POST | `/api/chrome/auth/token/session` | ✅ | Токен из сессии |
| `tokenStatus()` | GET | `/api/chrome/auth/status` | ✅ | Статус токена |
| `revokeToken()` | POST | `/api/chrome/auth/revoke` | ✅ | Отозвать токен |
| `me()` | GET | `/api/chrome/me` | ✅ | Информация о пользователе |

**Особенности:**
- Использует Sanctum Personal Access Tokens
- Токены с именем `chrome-extension`
- Отдельные middleware (без stateful/session)
- Бессрочные токены (expiration = null)

---

### 2. Middleware

#### Authenticate.php
**Путь:** `server/app/Http/Middleware/Authenticate.php`

```php
protected function redirectTo($request): ?string
{
    // Возвращает null для JSON-ответов (401 вместо редиректа)
    return $request->expectsJson() ? null : route('login');
}
```

---

#### EnforceSingleSession.php
**Путь:** `server/app/Http/Middleware/EnforceSingleSession.php`

```php
public function handle(Request $request, Closure $next)
{
    $user = $request->user();
    $currentSessionId = $request->session()->getId();
    
    if ($user && $user->current_session_id !== $currentSessionId) {
        // Сессия не совпадает — logout
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        return response()->json([
            'message' => 'Session terminated due to single-session policy'
        ], 401);
    }
    
    return $next($request);
}
```

---

#### VerifyCsrfToken.php
**Путь:** `server/app/Http/Middleware/VerifyCsrfToken.php`

**Исключения из CSRF защиты:**
```php
protected $except = [
    '/api/materials/fetch',
    'api/chrome/*'
];
```

---

### 3. Модели

#### User.php
**Путь:** `server/app/Models/User.php`

**Поля таблицы `users`:**
```php
fillable: ['name', 'email', 'password']
hidden: ['password', 'remember_token', 'pin_hash', 'current_session_id']
```

**PIN-поля (из миграции):**
| Поле | Тип | Описание |
|------|-----|----------|
| `pin_enabled` | boolean | Флаг включения PIN |
| `pin_hash` | string | Хэш PIN-кода |
| `pin_changed_at` | timestamp | Дата изменения PIN |
| `pin_attempts` | tinyInteger | Счётчик неудачных попыток |
| `pin_locked_until` | timestamp | Время разблокировки |
| `current_session_id` | string | ID текущей сессии |

**Связи:**
```php
public function settings(): HasOne          // UserSettings
public function trustedDevices(): HasMany   // TrustedDevice
public function activeTrustedDevices(): HasMany
```

**Методы PIN:**
```php
setPin(string $pin): void           // Установить PIN (хэширует)
verifyPin(string $pin): bool        // Проверить PIN
isPinLocked(): bool                 // Проверка блокировки
recordFailedPinAttempt(): void      // Запись неудачной попытки
resetPinAttempts(): void            // Сброс счётчика
disablePin(): void                  // Отключить PIN
```

---

#### TrustedDevice.php
**Путь:** `server/app/Models/TrustedDevice.php`

**Поля таблицы `trusted_devices`:**
| Поле | Тип | Описание |
|------|-----|----------|
| `id` | bigint | Primary key |
| `user_id` | bigint | Foreign key → users |
| `device_id` | uuid | Уникальный ID устройства |
| `device_secret_hash` | string | Хэш секрета устройства |
| `user_agent` | string(512) | User-Agent браузера |
| `ip_first` | string(45) | Первые 2 октета IP |
| `ip_last` | string(45) | Последние 2 октета IP |
| `last_used_at` | timestamp | Последнее использование |
| `revoked_at` | timestamp | Время отзыва |

**Методы:**
```php
isRevoked(): bool                 // Проверка отзыва
revoke(): void                    // Отозвать устройство
verifySecret(string $secret): bool // Проверить секрет
createForUser(User $user): self   // Создать устройство
findActiveByDeviceId(string $id): ?self
getDeviceLabelAttribute(): string // "Chrome on Windows"
```

---

#### UserSettings.php
**Путь:** `server/app/Models/UserSettings.php`

**Назначение:** Настройки пользователя (регион, коэффициенты, текстовые блоки).

**Связи:**
```php
public function user(): BelongsTo           // User
public function region(): BelongsTo         // Region
public function defaultPlateMaterial(): BelongsTo
public function defaultEdgeMaterial(): BelongsTo
```

---

### 4. Маршруты

#### routes/api.php
**Путь:** `server/routes/api.php`

**Публичные маршруты (без auth):**
```php
// Базовая аутентификация
Route::post('login', [AuthController::class, 'login']);

// Chrome Extension (отдельный стек)
Route::withoutMiddleware([...])->group(function () {
    Route::post('chrome/auth/token', [ChromeExtensionController::class, 'issueToken']);
});

// PIN (публичные endpoints)
Route::post('auth/pin/login', [PinAuthController::class, 'login']);
Route::get('auth/pin/status', [PinAuthController::class, 'status']);
Route::post('auth/trusted-device/forget', [PinAuthController::class, 'forgetDevice']);
```

**Защищённые маршруты (`auth:sanctum`):**
```php
Route::middleware('auth:sanctum')->group(function () {
    // Профиль и сессия
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::put('me', [AuthController::class, 'updateProfile']);
    Route::put('me/password', [AuthController::class, 'updatePassword']);
    Route::post('auth/password/change', [AuthController::class, 'changePassword']);
    
    // PIN и доверенные устройства
    Route::post('auth/pin/set', [PinAuthController::class, 'set']);
    Route::post('auth/pin/disable', [PinAuthController::class, 'disable']);
    Route::get('auth/trusted-devices', [PinAuthController::class, 'trustedDevices']);
    Route::post('auth/trusted-devices/{id}/revoke', ...);
    
    // Управление сессиями
    Route::post('auth/terminate-sessions', ...);
    Route::get('auth/sessions', ...);
    Route::post('auth/sessions/terminate-others', ...);
    
    // Chrome Extension
    Route::get('chrome/auth/status', ...);
    Route::get('chrome/me', ...);
    Route::post('chrome/auth/revoke', ...);
});
```

---

#### routes/web.php
**Путь:** `server/routes/web.php`

**Публичные маршруты для верификации смет:**
```php
Route::get('/v/{publicId}', [PublicVerificationController::class, 'show']);
Route::get('/v/{publicId}/pdf', [PublicVerificationController::class, 'pdf']);
Route::get('/public/price-file/{versionId}/{documentToken}', ...);
```

---

### 5. Конфигурация

#### config/auth.php
**Путь:** `server/config/auth.php`

```php
'defaults' => [
    'guard' => 'web',
    'passwords' => 'users',
],

'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
],

'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => User::class,
    ],
],

'passwords' => [
    'users' => [
        'table' => 'password_reset_tokens',
        'expire' => 60,      // минут
        'throttle' => 60,    // секунд
    ],
],
```

---

#### config/sanctum.php
**Путь:** `server/config/sanctum.php`

```php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost,localhost:5173')),

'guard' => ['web'],

'expiration' => null,  // Бессрочные токены

'token_prefix' => '',

'middleware' => [
    'authenticate_session' => AuthenticateSession::class,
    'encrypt_cookies' => EncryptCookies::class,
    'validate_csrf_token' => VerifyCsrfToken::class,
],
```

---

#### config/mail.php
**Путь:** `server/config/mail.php`

```php
'default' => env('MAIL_MAILER', 'log'),  // ⚠️ По умолчанию логирование!

'mailers' => [
    'smtp' => [
        'transport' => 'smtp',
        'host' => env('MAIL_HOST', '127.0.0.1'),
        'port' => env('MAIL_PORT', 2525),
        'encryption' => env('MAIL_ENCRYPTION', 'tls'),
        'username' => env('MAIL_USERNAME'),
        'password' => env('MAIL_PASSWORD'),
    ],
    'log' => ['transport' => 'log'],
],

'from' => [
    'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
    'name' => env('MAIL_FROM_NAME', 'Example'),
],
```

**⚠️ Важно:** В проекте **НЕТ** активных Mail классов для отправки писем сброса пароля.

---

#### config/session.php
**Путь:** `server/config/session.php`

```php
'driver' => env('SESSION_DRIVER', 'database'),

'lifetime' => (int) env('SESSION_LIFETIME', 120),  // 2 часа

'expire_on_close' => false,

'encrypt' => false,

'table' => env('SESSION_TABLE', 'sessions'),

'cookie' => env('SESSION_COOKIE', 'laravel-session'),

'secure' => env('SESSION_SECURE_COOKIE'),

'http_only' => true,

'same_site' => 'lax',
```

---

### 6. Миграции

#### 2026_02_11_000001_add_pin_fields_to_users_table.php
**Путь:** `server/database/migrations/2026_02_11_000001_add_pin_fields_to_users_table.php`

```php
Schema::table('users', function (Blueprint $table) {
    $table->boolean('pin_enabled')->default(false);
    $table->string('pin_hash')->nullable();
    $table->timestamp('pin_changed_at')->nullable();
    $table->unsignedTinyInteger('pin_attempts')->default(0);
    $table->timestamp('pin_locked_until')->nullable();
    $table->string('current_session_id')->nullable();
});
```

---

#### 2026_02_11_000002_create_trusted_devices_table.php
**Путь:** `server/database/migrations/2026_02_11_000002_create_trusted_devices_table.php`

```php
Schema::create('trusted_devices', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->uuid('device_id')->unique();
    $table->string('device_secret_hash');
    $table->string('user_agent', 512);
    $table->string('ip_first', 45);
    $table->string('ip_last', 45);
    $table->timestamp('last_used_at')->nullable();
    $table->timestamp('revoked_at')->nullable();
    
    $table->index(['user_id', 'revoked_at']);
    $table->index('device_id');
});
```

---

#### 2026_02_24_000001_extend_collect_profiles_for_chrome_ext.php
**Путь:** `server/database/migrations/2026_02_24_000001_extend_collect_profiles_for_chrome_ext.php`

```php
// Создание таблицы personal_access_tokens для Sanctum
Schema::create('personal_access_tokens', function (Blueprint $table) {
    $table->id();
    $table->morphs('tokenable');
    $table->string('name');
    $table->string('token', 64)->unique();
    $table->text('abilities')->nullable();
    $table->timestamp('last_used_at')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();
});
```

---

#### 2026_02_11_100000_create_notifications_tables.php
**Путь:** `server/database/migrations/2026_02_11_100000_create_notifications_tables.php`

```php
// Таблица notifications
Schema::create('notifications', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('type');
    $table->morphs('notifiable');
    $table->text('data');
    $table->timestamp('read_at')->nullable();
    $table->timestamps();
});

// Таблица user_notifications
Schema::create('user_notifications', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->foreignId('notification_id')->constrained()->onDelete('cascade');
    $table->string('channel');
    $table->boolean('sent')->default(false);
    $table->timestamps();
});
```

---

## Frontend (Vue 3)

### 1. Страницы и компоненты

#### LoginView.vue
**Путь:** `client/src/views/LoginView.vue`

**Назначение:** Основная страница входа с поддержкой нескольких режимов.

**Режимы работы:**
```typescript
type LoginMode = 'login' | 'pin' | 'forgot' | 'forgot-pin' | 'register'
```

**Структура компонента:**
```vue
<template>
  <div class="login-container">
    <!-- Форма входа -->
    <AuthLogin v-if="mode === 'login'" @login-success="onLoginSuccess" />
    
    <!-- PIN вход -->
    <AuthPinLogin v-else-if="mode === 'pin'" @login-success="onLoginSuccess" />
    
    <!-- Восстановление пароля -->
    <AuthForgot v-else-if="mode === 'forgot'" />
    
    <!-- Восстановление PIN -->
    <AuthForgot v-else-if="mode === 'forgot-pin'" />
    
    <!-- Регистрация -->
    <AuthRegister v-else-if="mode === 'register'" />
    
    <!-- Диалог настройки PIN -->
    <PinSetupDialog v-model="showPinSetup" />
  </div>
</template>
```

**Логика инициализации:**
```typescript
onMounted(async () => {
  // Проверка статуса PIN при загрузке
  const status = await pinApi.getStatus()
  
  if (status.trusted_device_present && !status.pin_enabled) {
    // Устройство доверено, но PIN не настроен
    mode.value = 'login'
  } else if (status.pin_enabled) {
    // PIN включён — показываем PIN вход
    mode.value = 'pin'
  }
})

onLoginSuccess(data) {
  // Обработка успешного входа
  if (data.should_offer_pin_setup) {
    showPinSetup.value = true  // Показываем диалог настройки PIN
  } else {
    // Редирект на главную
    router.push({ name: 'projects' })
  }
}
```

---

#### AuthLogin.vue
**Путь:** `client/src/components/auth/AuthLogin.vue`

**Назначение:** Компонент формы входа.

**Поля формы:**
```typescript
const form = ref({
  email: '',
  password: '',
})

const rules = {
  email: [(v: string) => !!v || 'Введите email', (v: string) => /.+@.+\..+/.test(v) || 'Некорректный email'],
  password: [(v: string) => !!v || 'Введите пароль'],
}
```

**Метод отправки:**
```typescript
async function submit() {
  loading.value = true
  error.value = null
  
  try {
    await ensureCsrfCookie()
    const response = await axios.post('/api/login', form.value)
    
    // Успех — вызываем checkAuth для обновления состояния
    await authStore.checkAuth(true)
    
    // Emit события родителю
    emit('login-success', response.data)
  } catch (e) {
    error.value = 'Неверный email или пароль'
  } finally {
    loading.value = false
  }
}
```

---

#### AuthRegister.vue
**Путь:** `client/src/components/auth/AuthRegister.vue`

**Назначение:** Компонент регистрации.

**⚠️ Статус:** Функция **отключена** — показывает сообщение «Функция находится в разработке».

**Поля формы:**
```typescript
const form = ref({
  name: '',
  email: '',
  password: '',
  passwordConfirm: '',
  acceptTerms: false,
})
```

**Валидация:**
```typescript
const rules = {
  name: [(v: string) => !!v || 'Введите имя'],
  email: [
    (v: string) => !!v || 'Введите email',
    (v: string) => /.+@.+\..+/.test(v) || 'Некорректный email',
  ],
  password: [
    (v: string) => !!v || 'Введите пароль',
    (v: string) => v.length >= 8 || 'Минимум 8 символов',
  ],
  passwordConfirm: [
    (v: string) => v === form.value.password || 'Пароли не совпадают',
  ],
  acceptTerms: [(v: boolean) => v || 'Необходимо согласие'],
}
```

---

#### AuthForgot.vue
**Путь:** `client/src/components/auth/AuthForgot.vue`

**Назначение:** Компонент восстановления пароля (запрос ссылки).

**⚠️ Статус:** Frontend готов, но **backend endpoint не реализован**.

**Поля формы:**
```typescript
const form = ref({ email: '' })

async function submit() {
  try {
    await ensureCsrfCookie()
    await axios.post('/api/forgot-password', { email: form.value.email })
    
    // Всегда показываем нейтральное сообщение (anti-enumeration)
    success.value = true
  } catch (e) {
    // Игнорируем ошибку для anti-enumeration
    success.value = true
  }
}
```

---

#### AuthReset.vue
**Путь:** `client/src/components/auth/AuthReset.vue`

**Назначение:** Компонент сброса пароля по токену.

**⚠️ Статус:** Frontend готов, но **backend endpoint не реализован**.

**Параметры из query строки:**
```typescript
const route = useRoute()
const token = route.query.token
const email = route.query.email
```

**Поля формы:**
```typescript
const form = ref({
  password: '',
  passwordConfirm: '',
})

async function submit() {
  try {
    await axios.post('/api/reset-password', {
      token,
      email,
      password: form.value.password,
      password_confirmation: form.value.passwordConfirm,
    })
    
    // Редирект на страницу входа
    router.push('/login?message=password-reset')
  } catch (e) {
    error.value = 'Ошибка сброса пароля'
  }
}
```

---

#### AuthPinLogin.vue
**Путь:** `client/src/components/auth/AuthPinLogin.vue`

**Назначение:** Компонент входа по PIN.

**Особенности:**
- Использует компонент `PinInput` (4 цифры)
- Проверка блокировки PIN (429 Too Many Requests)
- Отслеживание оставшихся попыток

**Логика отправки:**
```typescript
async function submit(pin: string) {
  try {
    const response = await pinApi.loginByPin(pin)
    emit('login-success', response.data)
  } catch (e) {
    if (e.response?.status === 429) {
      // Блокировка PIN
      const retryAfter = e.response.headers['retry-after']
      lockMessage.value = `PIN заблокирован на ${retryAfter} мин.`
    } else {
      attemptsLeft.value--
      if (attemptsLeft.value === 0) {
        // Устройство отозвано
        emit('device-revoked')
      }
    }
  }
}
```

---

#### PinSetupDialog.vue
**Путь:** `client/src/components/auth/PinSetupDialog.vue`

**Назначение:** Диалог настройки PIN после первого входа.

**Шаги диалога:**
1. `enter` — ввод нового PIN (4 цифры)
2. `confirm` — подтверждение PIN (повторный ввод)
3. `password` — ввод текущего пароля + опция «Доверять устройству»

**Логика:**
```typescript
async function submitPassword() {
  await pinApi.setPin({
    pin: newPin.value,
    password: password.value,
    trust_device: trustDevice.value,  // Чекбокс
  })
  
  emit('done')
}
```

---

#### PinInput.vue
**Путь:** `client/src/components/auth/PinInput.vue`

**Назначение:** Компонент ввода 4-значного PIN.

**Особенности:**
- 4 отдельных input поля
- Автопереход между полями при вводе
- Поддержка paste (вставка 4 цифр)
- Обработка backspace и стрелок
- Анимация ошибки (shake)
- Тёмная тема

**События:**
```typescript
emit('complete', pin: string)  // Все 4 цифры введены
emit('error')                   // Ошибка валидации
```

---

#### ResetPasswordView.vue
**Путь:** `client/src/views/ResetPasswordView.vue`

**Назначение:** Страница сброса пароля.

**Использование:**
```vue
<template>
  <div class="reset-password-page">
    <AuthReset />
  </div>
</template>
```

---

### 2. Pinia Stores

#### auth.ts
**Путь:** `client/src/stores/auth.ts`

**Назначение:** Pinia store для управления состоянием аутентификации.

**State:**
```typescript
state: () => ({
  user: null as User | null,
  isAuthenticated: false,
  authChecked: false,
})
```

**Actions:**
```typescript
async function checkAuth(force = false) {
  if (this.authChecked && !force && this.user) {
    return this.user
  }
  
  try {
    await ensureCsrfCookie()
    const response = await axios.get('/api/me', { timeout: 5000 })
    
    this.user = response.data
    this.isAuthenticated = true
    this.authChecked = true
    
    return this.user
  } catch (e) {
    this.user = null
    this.isAuthenticated = false
    this.authChecked = true
    
    return null
  }
}

async function logout(options = { redirect: true }) {
  try {
    await axios.post('/api/logout')
  } catch (e) {
    // Игнорируем ошибки
  }
  
  this.user = null
  this.isAuthenticated = false
  this.authChecked = false
  
  if (options.redirect) {
    router.push({ name: 'login', query: { message: 'logged-out' } })
  }
}
```

---

### 3. Router Guards

#### index.ts
**Путь:** `client/src/router/index.ts`

**Публичные маршруты (`requiresAuth: false`):**
```typescript
{
  path: '/login',
  name: 'login',
  component: LoginView,
  meta: { requiresAuth: false },
}
{
  path: '/reset-password',
  name: 'reset-password',
  component: ResetPasswordView,
  meta: { requiresAuth: false },
}
```

**Защищённые маршруты (`requiresAuth: true`):**
```typescript
{
  path: '/',
  component: AppShell,
  meta: { requiresAuth: true },
  children: [
    { path: '', redirect: '/projects' },
    { path: 'projects', name: 'projects', component: ProjectsView },
    { path: 'materials', name: 'materials', component: MaterialsView },
    // ... другие маршруты
  ],
}
```

**Admin маршруты (`requiresAdmin: true`):**
```typescript
{
  path: '/admin',
  component: AdminShell,
  meta: { requiresAdmin: true },
  children: [
    { path: '', name: 'admin-dashboard', component: AdminDashboard },
  ],
}
```

**Guard логика:**
```typescript
router.beforeEach(async (to, from, next) => {
  const authStore = useAuthStore()
  
  // Проверка admin
  const isAdminUser = () => {
    const user = authStore.user
    const role = String(user?.role ?? '').toLowerCase()
    return Number(user?.id) === 1 || role === 'admin' || role === 'superadmin'
  }
  
  // Пропуск auth маршрутов
  if (to.name === 'login' || to.name === 'reset-password') {
    if (!authStore.authChecked) {
      await authStore.checkAuth()
    }
    if (authStore.isAuthenticated) {
      return next({ name: 'projects' })
    }
    return next()
  }
  
  // Проверка авторизации
  if (to.meta.requiresAuth) {
    if (!authStore.authChecked) {
      await authStore.checkAuth()
    }
    if (!authStore.isAuthenticated) {
      return next({
        name: 'login',
        query: { intended: to.fullPath },
      })
    }
  }
  
  // Проверка admin
  if (to.meta.requiresAdmin && !isAdminUser()) {
    return next({ name: 'projects' })
  }
  
  next()
})
```

---

### 4. API клиенты

#### axios.ts
**Путь:** `client/src/api/axios.ts`

**Назначение:** Настройка Axios с CSRF и interceptors.

**Конфигурация:**
```typescript
const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/',
  withCredentials: true,  // Куки
  timeout: 30000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
})
```

**CSRF клиент:**
```typescript
const csrfClient = axios.create({
  withCredentials: true,
  timeout: 5000,
})

export function ensureCsrfCookie(timeout = 5000) {
  return csrfClient.get('/sanctum/csrf-cookie', { timeout })
}
```

**Interceptors:**
```typescript
// Request interceptor
apiClient.interceptors.request.use(
  (config) => {
    console.log(`[API] ${config.method?.toUpperCase()} ${config.url}`)
    return config
  },
  (error) => Promise.reject(error)
)

// Response interceptor
apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 419) {
      // CSRF токен истёк — пробуем обновить
      await ensureCsrfCookie()
      return apiClient.request(error.config)
    }
    
    if (error.response?.status === 401) {
      // Исключаем auth endpoints
      const excludedUrls = [
        '/api/login',
        '/api/logout',
        '/api/me',
        '/api/auth/pin/',
      ]
      
      if (!excludedUrls.some(url => error.config.url.includes(url))) {
        // Редирект на login
        const intended = router.currentRoute.value.fullPath
        router.push({
          name: 'login',
          query: {
            message: 'session-terminated',
            intended,
          },
        })
      }
    }
    
    return Promise.reject(error)
  }
)
```

---

#### auth.ts
**Путь:** `client/src/api/auth.ts`

**Назначение:** API клиент для расширенных auth операций.

**Интерфейсы:**
```typescript
interface ChangePasswordPayload {
  current_password: string
  new_password: string
  new_password_confirmation: string
}

interface SessionInfo {
  id: string
  ip_address: string
  user_agent: string
  last_activity: string
  is_current: boolean
}

interface SessionsResponse {
  current: SessionInfo
  others: SessionInfo[]
}

interface ChromeTokenResponse {
  token: string
  expires_at: string | null
}
```

**Методы:**
```typescript
export const authApi = {
  async changePassword(payload: ChangePasswordPayload) {
    const response = await apiClient.post('/api/auth/password/change', payload)
    return response.data
  },
  
  async getSessions() {
    const response = await apiClient.get('/api/auth/sessions')
    return response.data as SessionsResponse
  },
  
  async terminateOtherSessions() {
    const response = await apiClient.post('/api/auth/sessions/terminate-others')
    return response.data
  },
  
  async issueChromeToken(email: string, password: string) {
    const response = await apiClient.post('/api/chrome/auth/token', {
      email,
      password,
    })
    return response.data as ChromeTokenResponse
  },
  
  async issueChromeTokenFromSession() {
    const response = await apiClient.post('/api/chrome/auth/token/session')
    return response.data as ChromeTokenResponse
  },
  
  async getChromeTokenStatus() {
    const response = await apiClient.get('/api/chrome/auth/status')
    return response.data
  },
  
  async revokeChromeToken() {
    const response = await apiClient.post('/api/chrome/auth/revoke')
    return response.data
  },
}
```

---

#### pin.ts
**Путь:** `client/src/api/pin.ts`

**Назначение:** API клиент для PIN операций.

**Интерфейсы:**
```typescript
interface PinStatus {
  pin_enabled: boolean
  trusted_device_present: boolean
  requires_password_login: boolean
  user_name: string | null
  user_email: string | null
}

interface TrustedDevice {
  id: number
  device_id: string
  device_label: string
  user_agent: string
  ip_first: string
  ip_last: string
  last_used_at: string
  is_current: boolean
}
```

**Методы:**
```typescript
export const pinApi = {
  async getStatus() {
    const response = await apiClient.get('/api/auth/pin/status')
    return response.data as PinStatus
  },
  
  async loginByPin(pin: string) {
    const response = await apiClient.post('/api/auth/pin/login', { pin })
    return response.data
  },
  
  async setPin(payload: {
    pin: string
    password: string
    trust_device?: boolean
  }) {
    const response = await apiClient.post('/api/auth/pin/set', payload)
    return response.data
  },
  
  async disablePin(password: string) {
    const response = await apiClient.post('/api/auth/pin/disable', { password })
    return response.data
  },
  
  async getTrustedDevices() {
    const response = await apiClient.get('/api/auth/trusted-devices')
    return response.data as TrustedDevice[]
  },
  
  async revokeDevice(id: number) {
    const response = await apiClient.post(`/api/auth/trusted-devices/${id}/revoke`)
    return response.data
  },
  
  async forgetDevice() {
    const response = await apiClient.post('/api/auth/trusted-device/forget')
    return response.data
  },
  
  async terminateSessions() {
    const response = await apiClient.post('/api/auth/terminate-sessions')
    return response.data
  },
}
```

---

## Конфигурация

### Настройки Sanctum

**Файл:** `server/config/sanctum.php`

| Параметр | Значение | Описание |
|----------|----------|----------|
| `stateful` | `localhost,localhost:5173` | Домены для stateful auth |
| `guard` | `['web']` | Guard для токенов |
| `expiration` | `null` | Бессрочные токены |
| `token_prefix` | `''` | Префикс токенов |
| `middleware.authenticate_session` | `AuthenticateSession::class` | Проверка сессии |
| `middleware.encrypt_cookies` | `EncryptCookies::class` | Шифрование кук |
| `middleware.validate_csrf_token` | `VerifyCsrfToken::class` | CSRF защита |

---

### Настройки почты

**Файл:** `server/config/mail.php`

| Параметр | Значение | Описание |
|----------|----------|----------|
| `default` | `log` | ⚠️ Логирование (не отправляет) |
| `mailers.smtp.host` | `127.0.0.1` | SMTP сервер |
| `mailers.smtp.port` | `2525` | SMTP порт |
| `mailers.smtp.encryption` | `tls` | Шифрование |
| `from.address` | `hello@example.com` | Адрес отправителя |
| `from.name` | `Example` | Имя отправителя |

**⚠️ Важно:** Для включения отправки писем необходимо:
1. Изменить `MAIL_MAILER=log` на `MAIL_MAILER=smtp` в `.env`
2. Настроить SMTP сервер
3. Создать Mail классы для уведомлений

---

### Настройки сессий

**Файл:** `server/config/session.php`

| Параметр | Значение | Описание |
|----------|----------|----------|
| `driver` | `database` | Хранение в БД |
| `lifetime` | `120` | Время жизни (минуты) |
| `expire_on_close` | `false` | Не истекать при закрытии |
| `table` | `sessions` | Таблица в БД |
| `cookie` | `laravel-session` | Имя cookie |
| `secure` | `env('SESSION_SECURE_COOKIE')` | HTTPS только |
| `http_only` | `true` | Недоступны из JS |
| `same_site` | `lax` | CSRF защита |

---

## База данных

### Схема таблиц

```
┌─────────────────────────────────────────────────────────────────┐
│                         users                                   │
├─────────────┬───────────────────────────────────────────────────┤
│ id          | bigint, primary key                               │
│ name        | string                                            │
│ email       | string, unique                                    │
│ password    | string (bcrypt)                                   │
│ pin_enabled | boolean, default false                            │
│ pin_hash    | string, nullable                                  │
│ pin_changed_at | timestamp, nullable                            │
│ pin_attempts | tinyint, default 0                              │
│ pin_locked_until | timestamp, nullable                          │
│ current_session_id | string, nullable                           │
│ remember_token | string(100), nullable                          │
│ created_at  | timestamp                                         │
│ updated_at  | timestamp                                         │
└─────────────┴───────────────────────────────────────────────────┘
                            │
                            │ 1
                            │
                            │
                            │ *
┌─────────────────────────────────────────────────────────────────┐
│                     trusted_devices                             │
├─────────────┬───────────────────────────────────────────────────┤
│ id          | bigint, primary key                               │
│ user_id     | bigint, foreign key → users.id                    │
│ device_id   | uuid, unique                                      │
│ device_secret_hash | string                                     │
│ user_agent  | string(512)                                       │
│ ip_first    | string(45)                                        │
│ ip_last     | string(45)                                        │
│ last_used_at | timestamp, nullable                              │
│ revoked_at  | timestamp, nullable                               │
│ created_at  | timestamp                                         │
│ updated_at  | timestamp                                         │
└─────────────┴───────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                     sessions                                    │
├─────────────┬───────────────────────────────────────────────────┤
│ id          | string, primary key                               │
│ user_id     | bigint, nullable, foreign key → users.id          │
│ ip_address  | string(45), nullable                              │
│ user_agent  | text, nullable                                    │
│ payload     | text                                              │
│ last_activity | int                                             │
└─────────────┴───────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                  personal_access_tokens                         │
├─────────────┬───────────────────────────────────────────────────┤
│ id          | bigint, primary key                               │
│ tokenable_type | string                                         │
│ tokenable_id | bigint                                           │
│ name        | string                                            │
│ token       | string(64), unique                                │
│ abilities   | text, nullable                                    │
│ last_used_at | timestamp, nullable                              │
│ expires_at  | timestamp, nullable                               │
│ created_at  | timestamp                                         │
│ updated_at  | timestamp                                         │
└─────────────┴───────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                  password_reset_tokens                          │
├─────────────┬───────────────────────────────────────────────────┤
│ email       | string, primary key                               │
│ token       | string                                            │
│ created_at  | timestamp                                         │
└─────────────┴───────────────────────────────────────────────────┘
```

---

## Матрица реализации

| Функция | Backend | Frontend | Статус | Примечания |
|---------|---------|----------|--------|------------|
| **Вход по email/паролю** | ✅ | ✅ | ✅ Полностью | `AuthController::login`, `AuthLogin.vue` |
| **Выход** | ✅ | ✅ | ✅ Полностью | `AuthController::logout`, `authStore.logout` |
| **Single-session** | ✅ | ✅ | ✅ Полностью | `EnforceSingleSession` middleware |
| **PIN-вход** | ✅ | ✅ | ✅ Полностью | `PinAuthController`, `AuthPinLogin.vue` |
| **Доверенные устройства** | ✅ | ✅ | ✅ Полностью | `TrustedDevice` model, cookie `tdid` |
| **Смена пароля** | ✅ | ✅ | ✅ Полностью | Требует текущий пароль |
| **Управление сессиями** | ✅ | ✅ | ✅ Полностью | Просмотр, завершение других |
| **Chrome extension auth** | ✅ | ✅ | ✅ Полностью | Token-based через Sanctum |
| **Регистрация** | ❌ | ⚠️ | ❌ Не реализована | Frontend отключен |
| **Восстановление пароля (forgot)** | ❌ | ⚠️ | ❌ Не реализована | Нет endpoint `/api/forgot-password` |
| **Сброс пароля (reset)** | ❌ | ⚠️ | ❌ Не реализована | Нет endpoint `/api/reset-password` |
| **Email уведомления** | ❌ | N/A | ❌ Не реализованы | Нет Mail классов |
| **Подтверждение email** | ❌ | ❌ | ❌ Не реализовано | Нет `MustVerifyEmail` |

**Условные обозначения:**
- ✅ — реализовано и работает
- ⚠️ — frontend готов, backend отсутствует
- ❌ — не реализовано

---

## Выводы и рекомендации

### ✅ Реализованные функции

1. **Базовая аутентификация** (email/пароль)
   - Session-based через Laravel Sanctum
   - CSRF защита
   - Single-session политика

2. **PIN-аутентификация**
   - 4-значный PIN-код
   - Rate limiting (5 попыток за 5 минут)
   - Блокировка на 15 минут после 5 неудачных попыток
   - Отзыв устройства после 10 неудачных попыток

3. **Доверенные устройства**
   - Cookie `tdid` (device_id) и `tds` (device_secret)
   - Срок жизни 30 дней
   - Парсинг User-Agent для определения устройства
   - Управление через API (просмотр, отзыв)

4. **Управление сессиями**
   - Просмотр активных сессий
   - Завершение других сессий
   - Завершение всех сессий

5. **Chrome Extension**
   - Token-based auth через Sanctum
   - Бессрочные токены
   - Отдельные middleware (без stateful/session)

---

### ❌ Нереализованные функции

1. **Регистрация пользователей**
   - **Проблема:** Frontend компонент `AuthRegister.vue` существует, но отключен
   - **Требуется:**
     - Создать endpoint `POST /api/register`
     - Добавить валидацию (уникальность email)
     - Отправить welcome email (опционально)

2. **Восстановление пароля**
   - **Проблема:** Frontend компоненты готовы, backend endpoints отсутствуют
   - **Требуется:**
     - Создать endpoint `POST /api/forgot-password`
     - Создать endpoint `POST /api/reset-password`
     - Реализовать `ResetPasswordNotification`
     - Создать Mail template для письма
     - Настроить SMTP сервер

3. **Email уведомления**
   - **Проблема:** Нет Mail классов, notification классов
   - **Требуется:**
     - `app/Mail/PasswordResetMail.php`
     - `app/Notifications/ResetPasswordNotification.php`
     - `resources/views/emails/password-reset.blade.php`

4. **Подтверждение email**
   - **Проблема:** Не реализовано
   - **Требуется:**
     - Добавить `MustVerifyEmail` к модели User
     - Создать endpoint `POST /api/email/verification-notification`
     - Создать endpoint `POST /api/email/verify`
     - Создать `EmailVerificationNotification`

---

### 🔧 Архитектурные особенности

1. **Гибридная аутентификация**
   - Session-based для web-пользователей
   - Token-based для Chrome Extension
   - PIN-based для доверенных устройств

2. **Single-session политика**
   - При новом входе все старые сессии инвалидируются
   - Middleware `EnforceSingleSession` проверяет `current_session_id`
   - Возвращает 401 при несоответствии

3. **Отсутствие стандартных Laravel фич**
   - Нет `ForgotPasswordController`
   - Нет `ResetPasswordNotification`
   - Нет `MustVerifyEmail`
   - Кастомная реализация через `PinAuthController`

4. **Безопасность**
   - Rate limiting для PIN (5 попыток за 5 минут)
   - Блокировка PIN на 15 минут
   - Отзыв устройства после 10 неудачных попыток
   - CSRF защита с исключениями
   - Шифрование кук
   - SameSite=lax для CSRF защиты

---

### 📋 Рекомендации по доработке

#### Приоритет 1 (Критично)

1. **Реализовать восстановление пароля**
   ```bash
   php artisan make:notification ResetPasswordNotification
   php artisan make:mail PasswordResetMail
   ```
   
   Endpoint'ы:
   ```php
   Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink']);
   Route::post('reset-password', [PasswordResetController::class, 'reset']);
   ```

2. **Настроить SMTP**
   ```env
   MAIL_MAILER=smtp
   MAIL_HOST=smtp.example.com
   MAIL_PORT=587
   MAIL_USERNAME=noreply@example.com
   MAIL_PASSWORD=secret
   MAIL_ENCRYPTION=tls
   MAIL_FROM_ADDRESS=noreply@example.com
   MAIL_FROM_NAME="${APP_NAME}"
   ```

#### Приоритет 2 (Важно)

3. **Включить регистрацию**
   ```php
   Route::post('register', [AuthController::class, 'register']);
   ```
   
   Валидация:
   ```php
   $request->validate([
     'name' => 'required|string|max:255',
     'email' => 'required|email|unique:users',
     'password' => 'required|min:8|confirmed',
   ]);
   ```

4. **Добавить подтверждение email**
   ```php
   class User extends Authenticatable implements MustVerifyEmail
   ```

#### Приоритет 3 (Опционально)

5. **Двухфакторная аутентификация (2FA)**
   - TOTP (Google Authenticator)
   - SMS уведомления
   - Backup коды

6. **OAuth провайдеры**
   - Google
   - Яндекс
   - VK

7. **Аудит безопасности**
   - Логирование входов/выходов
   - Уведомления о новых устройствах
   - История сессий

---

### 📊 Статистика кода

| Компонент | Файлов | Строк кода |
|-----------|--------|------------|
| **Backend Controllers** | 3 | ~600 |
| **Backend Middleware** | 3 | ~100 |
| **Backend Models** | 3 | ~250 |
| **Backend Migrations** | 5 | ~200 |
| **Frontend Views** | 2 | ~300 |
| **Frontend Components** | 7 | ~800 |
| **Frontend Stores** | 1 | ~100 |
| **Frontend API** | 3 | ~200 |
| **Итого** | **27** | **~2550** |

---

## Приложения

### A. Список файлов

#### Backend
```
server/
├── app/Http/Controllers/Api/
│   ├── AuthController.php
│   ├── PinAuthController.php
│   └── ChromeExtensionController.php
├── app/Http/Middleware/
│   ├── Authenticate.php
│   ├── EnforceSingleSession.php
│   └── VerifyCsrfToken.php
├── app/Models/
│   ├── User.php
│   ├── TrustedDevice.php
│   └── UserSettings.php
├── config/
│   ├── auth.php
│   ├── sanctum.php
│   ├── mail.php
│   └── session.php
├── database/migrations/
│   ├── 2026_02_11_000001_add_pin_fields_to_users_table.php
│   ├── 2026_02_11_000002_create_trusted_devices_table.php
│   ├── 2026_02_24_000001_extend_collect_profiles_for_chrome_ext.php
│   └── 2026_02_11_100000_create_notifications_tables.php
└── routes/
    ├── api.php
    └── web.php
```

#### Frontend
```
client/src/
├── views/
│   ├── LoginView.vue
│   └── ResetPasswordView.vue
├── components/auth/
│   ├── AuthLogin.vue
│   ├── AuthRegister.vue
│   ├── AuthForgot.vue
│   ├── AuthReset.vue
│   ├── AuthPinLogin.vue
│   ├── PinSetupDialog.vue
│   └── PinInput.vue
├── stores/
│   └── auth.ts
├── router/
│   └── index.ts
└── api/
    ├── axios.ts
    ├── auth.ts
    └── pin.ts
```

---

### B. Диаграмма последовательности входа

```
Пользователь          Frontend            Backend             Database
    │                    │                    │                    │
    │  1. Ввод данных    │                    │                    │
    │───────────────────▶│                    │                    │
    │                    │                    │                    │
    │                    │  2. GET /sanctum/csrf-cookie           │
    │                    │───────────────────▶│                    │
    │                    │                    │                    │
    │                    │  3. Cookie: XSRF-TOKEN                 │
    │                    │◀───────────────────│                    │
    │                    │                    │                    │
    │  4. POST /api/login (email, password)   │                    │
    │───────────────────▶│                    │                    │
    │                    │                    │                    │
    │                    │  5. POST /api/login │                    │
    │                    │───────────────────▶│                    │
    │                    │                    │                    │
    │                    │                    │  6. SELECT * FROM users
    │                    │                    │───────────────────▶│
    │                    │                    │                    │
    │                    │                    │  7. user record    │
    │                    │                    │◀───────────────────│
    │                    │                    │                    │
    │                    │                    │  8. UPDATE users SET
    │                    │                    │     current_session_id
    │                    │                    │───────────────────▶│
    │                    │                    │                    │
    │                    │  9. Response + Cookie: laravel-session  │
    │                    │◀───────────────────│                    │
    │                    │                    │                    │
    │  10. Успешный вход │                    │                    │
    │◀───────────────────│                    │                    │
    │                    │                    │                    │
    │                    │  11. GET /api/me   │                    │
    │                    │───────────────────▶│                    │
    │                    │                    │                    │
    │                    │  12. User data     │                    │
    │                    │◀───────────────────│                    │
    │                    │                    │                    │
    │  13. Редирект на /projects             │                    │
    │◀───────────────────│                    │                    │
    │                    │                    │                    │
```

---

**Документ подготовлен:** 14 марта 2026 г.  
**Автор:** Qwen Code AI Assistant  
**Статус:** ✅ Готово к использованию
