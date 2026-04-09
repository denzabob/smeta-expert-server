# UI/UX анализ системы настроек — Личные vs Проектные

## Оглавление

1. [Архитектурный обзор](#архитектурный-обзор)
2. [Сравнительная таблица](#сравнительная-таблица)
3. [Иерархия и наследование данных](#иерархия-и-наследование-данных)
4. [User Flow диаграммы](#user-flow-диаграммы)
5. [UX паттерны и лучшие практики](#ux-паттерны-и-лучшие-практики)
6. [Проблемы и рекомендации](#проблемы-и-рекомендации)

---

## Архитектурный обзор

### Двухуровневая система настроек

```
УРОВЕНЬ 1: Личные настройки (UserSettingsView)
├─ Область действия: Глобальные дефолты пользователя
├─ Сохранение: /api/user/settings  
├─ Применение: Ко ВСЕМ новым проектам
├─ Интерфейс: Полноэкранная страница
├─ Структура: 2-панель (левый сайдбар + правый контент)
└─ Разделы: 6 (+ Безопасность)

                    ↓ наследование (load defaults)

УРОВЕНЬ 2: Проектные настройки (ProjectSettingsDrawer)
├─ Область действия: Специфичные для текущего проекта
├─ Сохранение: /api/projects/{id}
├─ Применение: ТОЛЬКО к текущему проекту
├─ Интерфейс: Right-side drawer (temporary modal)
├─ Структура: 2-панель (левый сайдбар + правый контент)
└─ Разделы: 5 (без Безопасности)
```

### Взаимодействие уровней

```
Пользователь устанавливает
личные дефолты
        ↓
  UserSettings.api
        ↓
    сохраняются
        ↓
При создании новых проектов
автоматически применяются
        ↓
Пользователь может вручную
загрузить дефолты в проект
       ↓
ProjectSettingsDrawer
[Load Defaults] button
        ↓
   Копируются значения
(except norms & prices)
        ↓
Форма становится "dirty"
        ↓
Пользователь сохраняет
изменения проекта
        ↓
/api/projects/{id} PUT
```

---

## Сравнительная таблица

| Критерий | Личные настройки | Проектные настройки |
|----------|-----------------|---------------------|
| **Путь в приложении** | `/user-settings` | Drawer в ProjectEditor |
| **Компонент** | `UserSettingsView.vue` | `ProjectSettingsDrawer.vue` |
| **Расположение UI** | Full-page | Right drawer |
| **Доступность** | Меню юзера, аккаунт | Иконка в ProjectEditor |
| **Модальность** | ✕ Постоянная страница | ✓ Temporary drawer |
| **Ширина контента** | ~100% страницы | 1200px (desktop) / 100vw (mobile) |
| **Левый сайдбар** | Всегда видим | Всегда видим* |
| **Скроллирование** | Контент скроллировится | Контент скроллировится |
| | | (*компактный на мобайл) |
| | | |
| **Раздел 0** | Регион и режим | Основное (дела, адрес) |
| **Раздел 1** | Коэффициенты | Коэффициенты |
| **Раздел 2** | Материалы | Материалы |
| **Раздел 3** | Отходы | Отходы |
| **Раздел 4** | Справочные блоки | Справочные блоки |
| **Раздел 5** | Безопасность | ✕ Нет |
| | | |
| **Header действия** | Save/Cancel buttons | [Load Defaults] + Close |
| **API endpoint** | `/api/user/settings` | `/api/projects/{id}` |
| **Тип запроса** | PUT (update all) | PUT (partial update) |
| **Валидация** | Базовая (типы) | Базовая (типы) |
| **Dirty-tracking** | JSON stringify сравнение | JSON stringify сравнение |
| **Оптимистичное обновления** | Нет | Нет |
| **Конфликты редактирования** | Последняя запись побеждает | Последняя запись побеждает |
| | | |
| **Text blocks** | ✓ Пользовательские | ✓ Пользовательские |
| **Description dialogs** | ✓ 3 (plate, edge, ops) | ✓ 3 (plate, edge, ops) |
| **Load defaults button** | ✕ Нет | ✓ Есть |
| **Скрывать элементы** | В личном UI | В проектном UI |

---

## Иерархия и наследование данных

### Преобразование данных между уровнями

```
UserSettings (полная структура)
    ├─ region_id ✓
    ├─ use_area_calc_mode ✓
    ├─ waste_coefficient ✓
    ├─ repair_coefficient ✓
    ├─ default_plate_material_id ✓
    ├─ default_edge_material_id ✓
    ├─ waste_plate_coefficient ✓
    ├─ waste_edge_coefficient ✓
    ├─ waste_operations_coefficient ✓
    ├─ apply_waste_to_plate ✓
    ├─ apply_waste_to_edge ✓
    ├─ apply_waste_to_operations ✓
    ├─ waste_*_description ✓
    ├─ show_waste_*_description ✓
    └─ text_blocks ✓

    [LOAD_DEFAULTS]
           ↓
    Копируются в ProjectSettings:
    ├─ region_id → projectData.region_id
    ├─ use_area_calc_mode → projectData.use_area_calc_mode
    ├─ waste_coefficient → projectData.waste_coefficient
    ├─ repair_coefficient → projectData.repair_coefficient
    ├─ default_plate_material_id → projectData.default_plate_material_id
    ├─ default_edge_material_id → projectData.default_edge_material_id
    ├─ waste_plate_coefficient → projectData.waste_plate_coefficient
    ├─ waste_edge_coefficient → projectData.waste_edge_coefficient
    ├─ waste_operations_coefficient → projectData.waste_operations_coefficient
    ├─ apply_waste_to_plate → projectData.apply_waste_to_plate
    ├─ apply_waste_to_edge → projectData.apply_waste_to_edge
    ├─ apply_waste_to_operations → projectData.apply_waste_to_operations
    ├─ waste_*_description → projectData.waste_*_description
    ├─ show_waste_*_description → projectData.show_waste_*_description
    └─ text_blocks → projectData.text_blocks

    НЕ копируются (задаются отдельно в проекте):
    ├─ number (№ дела) — уникален для проекта
    ├─ expert_name — уникален для проекта
    ├─ address — уникален для проекта
    ├─ Нормо-часы по профилям
    └─ Цены операций
```

### Логика создания нового проекта

```
1. Пользователь кликает "Создать проект"
2. Форма загружает UserSettings из /api/user/settings
3. Инициализирует ProjectSettings с этими значениями:
   {
     number: '',
     expert_name: '',
     address: '',
     region_id: user_settings.region_id,
     waste_coefficient: user_settings.waste_coefficient,
     repair_coefficient: user_settings.repair_coefficient,
     use_area_calc_mode: user_settings.use_area_calc_mode,
     default_plate_material_id: user_settings.default_plate_material_id,
     default_edge_material_id: user_settings.default_edge_material_id,
     waste_plate_coefficient: user_settings.waste_plate_coefficient,
     waste_edge_coefficient: user_settings.waste_edge_coefficient,
     waste_operations_coefficient: user_settings.waste_operations_coefficient,
     apply_waste_to_plate: user_settings.apply_waste_to_plate,
     apply_waste_to_edge: user_settings.apply_waste_to_edge,
     apply_waste_to_operations: user_settings.apply_waste_to_operations,
     waste_plate_description: user_settings.waste_plate_description,
     waste_edge_description: user_settings.waste_edge_description,
     waste_operations_description: user_settings.waste_operations_description,
     show_waste_plate_description: user_settings.show_waste_plate_description,
     show_waste_edge_description: user_settings.show_waste_edge_description,
     show_waste_operations_description: user_settings.show_waste_operations_description,
     text_blocks: [...user_settings.text_blocks], // глубокая копия
   }
4. Пользователь может переопределить любые значения
5. Сохранить проект
```

---

## User Flow диаграммы

### Flow 1: Первичная настройка персональных дефолтов

```
┌─────────────────────────────────────────────────┐
│ Новый пользователь регистрируется              │
│ Открывает "Личные настройки"                   │
└────────────────┬────────────────────────────────┘
                 │
                 ↓
┌─────────────────────────────────────────────────┐
│ UserSettingsView загружается                   │
│ ├─ GET /api/materials                          │
│ ├─ GET /api/regions                            │
│ └─ GET /api/user/settings                      │
│    (может быть пусто для нового пользователя)  │
└────────────────┬────────────────────────────────┘
                 │
                 ↓
┌─────────────────────────────────────────────────┐
│ Пользователь заполняет 6 разделов              │
│ ├─ Регион и режим расчёта                      │
│ ├─ Коэффициенты                                │
│ ├─ Материалы                                   │
│ ├─ Отходы                                      │
│ ├─ Справочные блоки                            │
│ └─ Безопасность (read-only)                    │
└────────────────┬────────────────────────────────┘
                 │
                 ↓
┌─────────────────────────────────────────────────┐
│ Кликает "Сохранить"                            │
│ PUT /api/user/settings { ...data }             │
│ ├─ Успех → зелёное уведомление                 │
│ └─ Ошибка → красное уведомление                │
└────────────────┬────────────────────────────────┘
                 │
                 ↓
┌─────────────────────────────────────────────────┐
│ Дефолты сохранены для всех новых проектов      │
└─────────────────────────────────────────────────┘
```

### Flow 2: Создание и настройка нового проекта

```
┌──────────────────────────────────────┐
│ Пользователь кликает                 │
│ "Создать новый проект"               │
└──────────────┬───────────────────────┘
               │
               ↓
┌──────────────────────────────────────┐
│ ProjectCreationForm                  │
│                                      │
│ Инициализирует ProjectSettings       │
│ с значениями из UserSettings         │
│ GET /api/user/settings               │
└──────────────┬───────────────────────┘
               │
               ↓
┌──────────────────────────────────────┐
│ Заполняет уникальные поля проекта:  │
│ ├─ № дела                            │
│ ├─ ФИО эксперта                      │
│ ├─ Адрес объекта                     │
│ └─ Если нужно: переопределяет        │
│    коэффициенты/материалы            │
└──────────────┬───────────────────────┘
               │
               ↓
┌──────────────────────────────────────┐
│ Кликает "Сохранить проект"           │
│ POST /api/projects { ...data }       │
│ Проект создан                        │
└──────────────┬───────────────────────┘
               │
               ↓
┌──────────────────────────────────────┐
│ ProjectEditorView открывается        │
│ Проект загружен                      │
└─────────────────────────────────────┘
```

### Flow 3: Редактирование проекта через drawer

```
┌──────────────────────────────────────┐
│ Пользователь в ProjectEditorView     │
│ Кликает иконку "Настройки"           │
└──────────────┬───────────────────────┘
               │
               ↓
┌──────────────────────────────────────┐
│ ProjectSettingsDrawer открывается    │
│ (right side, temporary)              │
│                                      │
│ Загружает текущие данные проекта     │
└──────────────┬───────────────────────┘
               │
       ┌───────┴────────┬────────────────────┐
       │                │                    │
       ↓                ↓                    ↓
   [Load Defaults]  [Edit Sections]   [Review]
       │                │                    │
       ├──────┬─────────┘                    │
       │      ↓                              │
       │  Пользователь                       │
       │  ├─ Кликает на раздел               │
       │  ├─ Вводит новые значения           │
       │  ├─ Форма становится dirty          │
       │  └─ Может открыть диалоги           │
       │     для описаний отходов            │
       │                                    │
       ↓                                    ↓
   Копирует из    ┌────────────────────────┐
   личных         │ Нажимает "Сохранить"   │
   дефолтов       │                        │
   ├─ Регион      │ PUT /api/projects/{id} │
   ├─ Коэффициенты│ { ...data }           │
   ├─ Материалы   │                        │
   ├─ Отходы      │ ├─ Успех:              │
   └─ Блоки       │ │  * Drawer закрывается│
                  │ │  * Уведомление       │
   Форма          │ │  * ProjectData       │
   становится     │ │    обновляется       │
   dirty          │ │                      │
                  │ └─ Ошибка:             │
                  │    * Красное сообщение │
                  │    * Форма остаётся    │
                  │      открытой          │
                  └────────────────────────┘
                             │
                             ↓
                  ┌────────────────────┐
                  │ Пользователь может:│
                  ├─ Сохранить снова   │
                  ├─ Отменить изменения│
                  ├─ Закрыть                │
                  └────────────────────┘
```

### Flow 4: Опции редактирования коэффициента отходов

```
Пользователь видит строку:
  [Плитные] [1.00] [Применять ✓] [В отчёте □] [Описание]
                                            │
                                            ↓
                               Кликает [Описание]
                                            │
                                            ↓
               v-dialog "Редактировать описание для Плитные"
                                   │
                    ┌──────────────┴──────────────┐
                    │                             │
                   ↓                              ↓
           [Заголовок]                   [Текст описания]
           v-text-field                  v-textarea
           "Обрезки плит"                "При расчёте..."
                    │                             │
                    └──────────────┬──────────────┘
                                   │
                         ┌─────────┴────────┐
                         │                  │
                        ↓                   ↓
                   [Закрыть]         [Сохранить]
                         │                  │
                         └─────────┬────────┘
                                   │
                    Данные возвращаются в форму
                    plateDesc.value = { title, text }
                    Форма становится dirty
```

---

## UX паттерны и лучшие практики

### 1. Паттерн "Settings as Master-Detail"

**Описание:** Двухпанельный интерфейс с навигацией слева и контентом справа.

**Преимущества:**
- ✓ Быстрое переключение между разделами
- ✓ Контекст остаётся видимым
- ✓ Масштабируется на разные экраны
- ✓ Знаком пользователям (используется везде: GitHub, Figma, etc.)

**Применение:** UserSettingsView, ProjectSettingsDrawer

**Рекомендация:** ✓ Хорошо использовано, продолжить в этом направлении

---

### 2. Паттерн "Drawer for Secondary Content"

**Описание:** ProjectSettingsDrawer — temporary modal drawer с right side расположением.

**Преимущества:**
- ✓ Не загромождает основной контент (остаётся видим ProjectEditor позади)
- ✓ Легко открыть/закрыть
- ✓ На мобайле становится fullscreen (не требует адаптации)
- ✓ Сохраняет контекст работы

**Применение:** ProjectSettingsDrawer

**Рекомендация:** ✓ Отличный выбор для настроек в контексте проекта

---

### 3. Паттерн "Dirty Form Tracking"

**Описание:** Система отслеживания несохранённых изменений через JSON сравнение.

**Реализация:**
```javascript
const isDirty = computed(() => {
  return JSON.stringify(currentState) !== JSON.stringify(originalState)
})
```

**Преимущества:**
- ✓ Автоматическое обнаружение изменений
- ✓ Кнопка сохранения активна только когда нужна
- ✓ Защита от случайного закрытия (можно добавить подтверждение)
- ✓ Простая реализация

**Рекомендация:** ✓ Хорошо работает, можно добавить:
- beforeunload warning если форма dirty
- Confirm dialog перед закрытием drawer'а

---

### 4. Паттерн "Inheritance & Defaults"

**Описание:** Двухуровневая система где личные дефолты наследуются проектами.

**Преимущества:**
- ✓ Снижает повторение ввода данных
- ✓ Обеспечивает консистентность
- ✓ [Load Defaults] позволяет быстро обновить проект
- ✓ Всё ещё можно переопределить вручную

**Рекомендация:** ✓ Эффективно использовано

---

### 5. Паттерн "Modal Dialogs for Complex Forms"

**Описание:** v-dialog для редактирования сложных вложенных объектов (описания отходов).

**Преимущества:**
- ✓ Не усложняет основную форму
- ✓ Фокусирует внимание на редактируемом элементе
- ✓ Можно отменить вне диалога

**Рекомендация:** ✓ Правильное использование для вложенных редакторов

---

## Проблемы и рекомендации

### ⚠️ Проблема 1: История изменений отсутствует

**Описание:** Нет способа посмотреть когда и что было изменено в настройках.

**Последствия:**
- ✗ Пользователь не знает что изменилось между версиями
- ✗ Администратор не может отследить изменения
- ✗ Нельзя вернуться на предыдущую версию

**Рекомендация:**
```
[ ] Добавить audit_logs таблицу
[ ] Логировать PUT запросы с before/after diff
[ ] Показать "Последнее изменение: 2 часа назад" в UI
[ ] Опционально: История версий с возвратом
```

---

### ⚠️ Проблема 2: Конфликты редактирования (race condition)

**Описание:** Если два экрана одновременно редактируют проект — последний PUT побеждает.

**Последствия:**
- ✗ Данные могут быть потеряны
- ✗ Неконсистентное состояние

**Пример:**
```
Tab 1 (ProjectEditor):
  user изменает число в табеле
  нажимает Save → PUT /api/projects/1

Tab 2 (ProjectSettingsDrawer):
  user изменает регион
  нажимает Save → PUT /api/projects/1
  
→ Изменение из Tab 1 теряется
```

**Рекомендация:**
```
[ ] Добавить field-level locking:
    PUT /api/projects/1/lock-field?field=region_id
    
[ ] Или использовать versioning (ETag):
    PUT /api/projects/1?version=42
    ← 409 Conflict если версия устарела
    
[ ] Или кэшировать на frontend:
    Store.setProjectVersion(42)
    При конфликте: merge или показать dialog
```

---

### ⚠️ Проблема 3: Валидация минимальна

**Описание:** В формах почти нет кастомной валидации.

**Примеры:**
- ✗ Можно установить коэффициент 0 (будет ошибка при расчёте)
- ✗ Материалы могут быть несовместимы
- ✗ Нет проверки на уникальность № дела

**Рекомендация:**
```typescript
// Добавить валидацию через vee-validate или ручную

const validateCoefficient = (value) => {
  if (value < 1) return "Минимум 1.0"
  if (value > 10) return "Максимум 10.0"
  if (!Number.isFinite(value)) return "Должно быть число"
  return true
}

const validateProjectNumber = async (value) => {
  if (!value) return "Должно быть заполнено"
  const exists = await api.get(`/api/projects/number/${value}`)
  if (exists.data.id !== currentProject.id) 
    return "Такой номер дела уже существует"
  return true
}
```

---

### ⚠️ Проблема 4: Нет подтверждения при закрытии unsaved changes

**Описание:** Пользователь может закрыть drawer/страницу и потерять данные.

**Последствия:**
- ✗ Пользователь забыл сохранить
- ✗ Данные потеряны, работа с нуля

**Рекомендация:**
```vue
<!-- UserSettingsView -->
<script>
const handleBeforeUnload = (e) => {
  if (isDirty.value) {
    e.preventDefault()
    e.returnValue = ''
    return ''
  }
}

onMounted(() => {
  window.addEventListener('beforeunload', handleBeforeUnload)
})

onUnmounted(() => {
  window.removeEventListener('beforeunload', handleBeforeUnload)
})
</script>

<!-- ProjectSettingsDrawer -->
<script>
const handleCloseSettingsDrawer = () => {
  if (isDirty.value) {
    const confirmed = confirm(
      'У вас есть несохранённые изменения. Вы уверены?'
    )
    if (!confirmed) return
  }
  handleDrawerUpdate(false)
}
</script>
```

---

### ⚠️ Проблема 5: Responsive behavior на tablet нечёткий

**Описание:** На tablet (md breakpoint) поведение может быть неучётным.

**Проблема в ProjectSettingsDrawer:**
```vue
:width="compactLayout ? '100vw' : 1200"
```

Между sm (100%) и md (1200px) есть скачок.

**Рекомендация:**
```vue
const drawerWidth = computed(() => {
  if (display.xs.value) return '100vw'
  if (display.sm.value) return '100vw'
  if (display.md.value) return '80vw'  // Мягче!
  if (display.lg.value) return 1200
  return 1400
})

:width="drawerWidth"
```

---

### ⚠️ Проблема 6: Нет индикатора сохранения

**Описание:** Пользователь не видит что происходит при сохранении.

**Рекомендация:**
```vue
<!-- Добавить прогресс бар/спиннер -->
<v-progress-linear 
  v-if="saving" 
  indeterminate 
  class="mb-4"
/>

<!-- Или inline loading на кнопке -->
<v-btn 
  color="primary" 
  :loading="saving"
  @click="onSave"
>
  Сохранить
</v-btn>
```

---

### ⚠️ Проблема 7: Копирование text_blocks может быть не глубоким

**Описание:** При copyFrom(UserSettings → ProjectSettings) text_blocks могут быть shallow copy.

**Проблема:**
```javascript
// Shallow copy — объекты shared!
text_blocks: user_settings.text_blocks
```

**Рекомендация:**
```javascript
// Deep copy
text_blocks: JSON.parse(JSON.stringify(user_settings.text_blocks))

// Или через structuredClone
text_blocks: structuredClone(user_settings.text_blocks)
```

---

### 💡 Рекомендация 1: Добавить "Advanced Options" toggle

**Проблема:** Много незнакомых пользователям опций (коэффициенты отходов).

**Решение:**
```vue
<v-switch v-model="showAdvanced" label="Показать расширенные опции" />

<div v-if="showAdvanced">
  <!-- Раздел "Отходы" и другие сложные настройки -->
</div>
```

**Результат:** 
- ✓ Упрощение интерфейса для новичков
- ✓ Опытные пользователи могут включить все опции

---

### 💡 Рекомендация 2: Search/Filter для разделов

**Проблема:** При 6 разделах пользователь может не найти нужный.

**Решение:**
```vue
<v-text-field 
  v-model="searchSections"
  label="Поиск раздела"
  prepend-inner-icon="mdi-magnify"
  clearable
  density="compact"
/>

<v-list-item 
  v-for="section in filteredSections"
  :key="section.title"
  v-show="section.title.toLowerCase().includes(searchSections.toLowerCase())"
/>
```

---

### 💡 Рекомендация 3: Keyboard shortcuts

**Проблема:** Пользователь хочет быстро сохранить (Ctrl+S).

**Решение:**
```javascript
import { onMounted } from 'vue'

onMounted(() => {
  window.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault()
      onSave()
    }
  })
})
```

---

### 💡 Рекомендация 4: Preview/Summary перед сохранением

**Проблема:** На большой форме легко допустить опечатку.

**Решение:**
```vue
<!-- Карточка "Итого" с важными значениями -->
<v-card class="mb-4" variant="outlined">
  <v-card-title>Итого настроек</v-card-title>
  <v-card-text>
    <v-list density="compact">
      <v-list-item 
        v-for="(val, key) in importantFields" 
        :key="key"
      >
        <strong>{{ key }}:</strong> {{ val }}
      </v-list-item>
    </v-list>
  </v-card-text>
</v-card>
```

---

### 💡 Рекомендация 5: Экспорт/Импорт настроек

**Проблема:** Пользователь хочет скопировать настройки между проектами.

**Решение:**
```vue
<v-btn icon variant="text" size="small" title="Экспортировать">
  <v-icon>mdi-download</v-icon>
</v-btn>

<!-- Скачивает JSON -->
const exportSettings = () => {
  const json = JSON.stringify(projectSettings, null, 2)
  const blob = new Blob([json], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `project-settings-${projectId}.json`
  a.click()
}
```

---

### 💡 Рекомендация 6: Batch operations для text_blocks

**Проблема:** Редактирование множества блоков неудобно один за одним.

**Решение:**
```vue
<!-- Режим редактирования таблицей -->
<v-data-table
  v-if="editTableMode"
  :items="text_blocks"
  :headers="[
    { title: 'Заголовок', key: 'title' },
    { title: 'Текст', key: 'text' },
    { title: 'Включен', key: 'enabled' }
  ]"
  item-value="title"
/>
```

---

## Матрица UX оценок

| Критерий | Личные настройки | Проектные настройки | Комментарий |
|----------|------------------|---------------------|------------|
| **Навигация** | 8/10 | 8/10 | Хороша, но можно добавить поиск |
| **Понятность** | 7/10 | 7/10 | Нужны подсказки/tooltips для advanced fields |
| **Отзывчивость** | 6/10 | 6/10 | Нужен индикатор сохранения |
| **Валидация** | 5/10 | 5/10 | Слаба, нужна кастомная валидация |
| **Ошибки** | 7/10 | 7/10 | Хорош snackbar, но нужны suggestions |
| **Мобайл** | 7/10 | 8/10 | ProjectSettings лучше на мобайле |
| **Доступность** | 7/10 | 7/10 | Хороша, нужны ARIA labels для complex controls |
| **Производительность** | 9/10 | 9/10 | Быстро, нет оптимизации нужна |
| **Консистентность** | 9/10 | 9/10 | Одинаковые паттерны везде |
| **ИТОГО** | **7.2/10** | **7.3/10** | Солидный уровень, есть куда расти |

---

## Заключение

### Сильные стороны ✓
1. **Двухпанельная архитектура** — снижает когнитивную нагрузку
2. **Двухуровневая система** (User → Project) — логична и эффективна
3. **Dirty-tracking** — защищает от потери данных
4. **Responsive** — работает на всех устройствах
5. **Консистентность** — одинаковые паттерны везде

### Слабые стороны ✗
1. **Минимальная валидация** — может привести к ошибкам расчёта
2. **Нет истории изменений** — невозможно отследить что изменилось
3. **Race conditions** — при параллельном редактировании
4. **Отсутствие подтверждений** — данные могут быть случайно потеряны
5. **Слабая индикация процесса** — не ясно когда идёт сохранение

### Приоритет улучшений
1. 🔴 **High:** Валидация + подтверждение closeconfirm
2. 🟠 **Medium:** История изменений + конфликт-обработка
3. 🟡 **Low:** Advanced options toggle + экспорт/импорт

---

## Ссылки на детальные описания

- [Личные настройки (UserSettingsView)](./UI_SETTINGS_PERSONAL_SIDEBAR.md)
- [Проектные настройки (ProjectSettingsDrawer)](./UI_SETTINGS_PROJECT_DRAWER.md)
