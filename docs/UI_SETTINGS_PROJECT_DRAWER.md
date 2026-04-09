# Настройки проекта (ProjectSettingsDrawer) — правый drawer

## Обзор

Компонент `ProjectSettingsDrawer` — это навигационный drawer (боковая панель), открывающийся с **правого края экрана**, который предоставляет быстрый доступ к специфичным для текущего проекта параметрам. Drawer имеет двухпанельную структуру (навигация слева, контент справа) и используется при редактировании проекта.

**Компонент:** `ProjectSettingsDrawer.vue`  
**Расположение:** v-navigation-drawer, location="right"  
**Ширина:** 1200px (desktop) / 100vw (mobile)  
**Модальность:** v-model управляемый, temporary drawer  
**Использование:** В `ProjectEditorView.vue` при клике на иконку настроек  

---

## Архитектура интерфейса

### Layout структура

```
Desktop:
┌────────────────────────────────────────────────────────────┐
│ Drawer Header (Settings Project)        [DL] [⋮] [✕]      │
├────────────────────────────────────────────────────────────┤
│  Левая панель (settings-sidebar)  │ Правая панель         │
│  - VList с секциями               │ (settings-content)    │
│  - Иконки + названия              │                       │
│  - Активирует контент кликом      │ Контент активной      │
│                                   │ секции с формами      │
│                                   │                       │
│                                   │ (scrollable)          │
└────────────────────────────────────────────────────────────┘


Mobile (compactLayout):
┌──────────────────────────────────────┐
│ Header                    [DL] [✕]  │
├──────────────────────────────────────┤
│            Левая навигация            │
│ (стак вертикально, 100% ширина)      │
├──────────────────────────────────────┤
│            Контент                    │
│ (scrollable, 100% ширина)             │
└──────────────────────────────────────┘
```

**CSS/Vue классы:**
- `settings-drawer` — корневой контейнер drawer'а
- `v-navigation-drawer` — встроенный компонент Vuetify
- `settings-layout` / `settings-layout--mobile` — flex контейнер содержимого
- `settings-sidebar` — левая навигационная панель
- `settings-content` — правая область контента
- `settings-content-scroll` — скроллируемая область контента

---

## Header (Заголовок и действия)

```
┌─────────────────────────────────────────────────────┐
│ Настройки проекта        [LoadDefaults] [More] [✕] │
└─────────────────────────────────────────────────────┘
```

**v-card-title** с классом `pa-4 border-b`:
- **Текст:** "Настройки проекта" (слева)
- **Действия** (справа):

### 1. Кнопка "Загрузить дефолты"
- **Иконка:** `mdi-download`
- **Функция:** Загружает значения из личных настроек пользователя
- **Props:**
  - `@click="loadUserDefaults"`
  - `:loading="isLoadingDefaults"`
  - `v-tooltip` с текстом подсказки
- **Поведение:**
  - Нормо-часы и цены операций НЕ затрагиваются
  - Загружаются только основные параметры (регион, коэффициенты, материалы)
  - После загрузки форма помечается как dirty (enable save)

### 2. Кнопка закрытия
- **Иконка:** `mdi-close`
- **Функция:** Закрывает drawer (`handleCloseSettingsDrawer`)
- **Поведение:** 
  - Если есть unsaved changes, может быть запрос подтверждения
  - Отправляет событие `update:modelValue` с `false`

---

## Левая панель навигации (5 секций)

### Структура секций

```typescript
const settingsSections = [
  { title: 'Основное', icon: 'mdi-folder-settings' },           // 0
  { title: 'Коэффициенты', icon: 'mdi-tune' },                  // 1
  { title: 'Материалы', icon: 'mdi-package-variant' },           // 2
  { title: 'Отходы', icon: 'mdi-recycle' },                      // 3
  { title: 'Справочные блоки', icon: 'mdi-text-box-outline' }    // 4
]

// Отличие от UserSettings: нет раздела "Безопасность"
```

**v-list** компонент:
- `:model-value="activeSettingsSection"` — текущая активная секция
- `@update:model-value="activeSettingsSection = $event"` — смена секции при клике
- `class="py-0"` — убирает padding сверху/снизу

**v-list-item** для каждой секции:
- `:value="idx"` — индекс секции
- `@click="activeSettingsSection = idx"` — переключение при клике
- Template `#prepend` — слот для иконки
- `v-list-item-title` — текст названия

---

## Правая панель данных (5 разделов)

### 0. Section: "Основное" 📁
**Icon:** `mdi-folder-settings`  
**Условие отображения:** `activeSettingsSection === 0`

#### Подраздел: Основное
**Заголовок:** "Основное"  
**Подсказка:** "Базовые сведения о проекте (дела), объекте и эксперте"

**Элементы управления (v-card variant="outlined"):**
```
Row 1 (2 col):
  ├─ № дела (v-text-field)
  │  - v-model: projectData.number
  │  - label: "№ дела"
  │  - type: text
  │
  └─ ФИО эксперта (v-text-field)
     - v-model: projectData.expert_name
     - label: "ФИО эксперта"
     - type: text

Row 2 (full):
  └─ Адрес объекта (v-text-field)
     - v-model: projectData.address
     - label: "Адрес объекта"
     - type: text
```

#### Подраздел: Методика и регион
**Заголовок:** "Методика и регион"  
**Подсказка:** "Влияет на расчёт ставок по профилям нормируемых работ"

**Элементы управления (v-card variant="outlined"):**
```
Row 1 (col md=6):
  └─ Регион (v-autocomplete)
     - v-model: projectData.region_id
     - :items: regions
     - item-title: "name"
     - item-value: "id"
     - label: "Регион"
     - clearable: true
     - density: "compact"
     - hint: "Используется для расчёта ставок по профилям"
     - :menu-props: { maxHeight: 300 }
```

**Условное предупреждение:** 
```
v-if="!projectData.region_id"
text-warning text-caption mt-2
[⚠️] Регион не выбран. Ставки будут расчитаны по умолчанию.
```

---

### 1. Section: "Коэффициенты" ⚙️
**Icon:** `mdi-tune`  
**Условие отображения:** `activeSettingsSection === 1`

#### Подраздел: Коэффициенты
**Заголовок:** "Коэффициенты"  
**Подсказка:** "Применяются при расчёте стоимости материалов"

**Элементы управления (v-card variant="outlined"):**
```
Row 1 (2 col md=6):
  ├─ Коэффициент обрезков (v-text-field)
  │  - v-model.number: projectData.waste_coefficient
  │  - label: "Коэффициент обрезков"
  │  - type: "number"
  │  - min: 1
  │  - step: 0.01
  │  - hint: "1.00 = без изменения"
  │  - persistent-hint: true
  │
  └─ Ремонтный коэффициент (v-text-field)
     - v-model.number: projectData.repair_coefficient
     - label: "Ремонтный коэффициент"
     - type: "number"
     - min: 1
     - step: 0.01
     - hint: "1.00 = без изменения"
     - persistent-hint: true
```

**Divider:** `v-divider class="my-4"`

**Toggle режима расчёта:**
```
Mode switch (custom flex layout):
  ├─ Label (left): "Расчёт по листам"
  ├─ v-switch (center)
  │  - v-model: projectData.use_area_calc_mode
  │  - hide-details: true
  │  - density: "compact"
  │  - color: "primary"
  │
  └─ Label (right): "Расчёт по площади"

Hint (text-caption text-grey mt-2):
  "Влияет на таблицу материалов и итоговую стоимость"
```

**Визуальное выделение активного режима:**
```css
.mode-switch-label {
  opacity: 0.6; /* inactive */
}
.mode-switch-label--active {
  opacity: 1;   /* active */
  font-weight: 600;
}
```

---

### 2. Section: "Материалы" 📦
**Icon:** `mdi-package-variant`  
**Условие отображения:** `activeSettingsSection === 2`

#### Подраздел: Материалы по умолчанию
**Заголовок:** "Материалы по умолчанию"  
**Подсказка:** "Подставляются при добавлении новых позиций"

**Элементы управления (v-card variant="outlined"):**
```
Row 1 (2 col md=6):
  ├─ Плитный материал (v-autocomplete)
  │  - v-model: projectData.default_plate_material_id
  │  - :items: materials.filter(m => m.type === 'plate')
  │  - item-title: "name"
  │  - item-value: "id"
  │  - label: "Плитный материал"
  │  - clearable: true
  │  - density: "compact"
  │
  └─ Кромочный материал (v-autocomplete)
     - v-model: projectData.default_edge_material_id
     - :items: materials.filter(m => m.type === 'edge')
     - item-title: "name"
     - item-value: "id"
     - label: "Кромочный материал"
     - clearable: true
     - density: "compact"
```

---

### 3. Section: "Отходы" ♻️
**Icon:** `mdi-recycle`  
**Условие отображения:** `activeSettingsSection === 3`

#### Подраздел: Коэффициенты отходов
**Заголовок:** "Коэффициенты отходов"  
**Подсказка:** "Детальные настройки отходов по материалам"

**Структура:** Три категории (аналогично UserSettings), но для PROJECT:

#### 3.1 Плитные материалы (Plate)
```
Row (flex align-center gap-3):
  ├─ Label (min-width: 80px): "Плитные"
  ├─ Коэффициент (v-text-field)
  │  - v-model.number: projectData.waste_plate_coefficient
  │  - type: "number"
  │  - min: 1
  │  - step: 0.01
  │  - density: "compact"
  │  - hide-details: true
  │  - max-width: 100px
  │  - placeholder: "1.00"
  │  - hint: "1.00 = без изменения"
  │  - persistent-hint: true
  │
  ├─ Применять (v-switch)
  │  - v-model: projectData.apply_waste_to_plate
  │  - color: primary / green
  │  - label: "Применять"
  │  - density: "compact"
  │  - hide-details: true
  │
  ├─ В отчёте (v-switch)
  │  - v-model: projectData.show_waste_plate_description
  │  - label: "В отчёте"
  │  - density: "compact"
  │  - :disabled: !plateDesc.title && !plateDesc.text
  │  - hide-details: true
  │
  ├─ Flex spacer
  │
  └─ Кнопка редактирования
     - v-btn (size="small" variant="outlined")
     - @click: showDescriptionDialog = true
     - title: "Редактировать описание"
     - Icon: mdi-pencil
     - Text: "Описание"
```

#### 3.2 Кромка (Edge)
Аналогичная структура для `waste_edge_coefficient`

#### 3.3 Операции (Operations)
Аналогичная структура для `waste_operations_coefficient`

**Диалоговое окно для описания отходов:**
```
v-dialog (v-model="showDescriptionDialog" max-width="500")
  └─ v-card
     ├─ title: "Редактировать описание для [Тип]"
     ├─ v-card-text
     │  ├─ Заголовок (v-text-field)
     │  │  - v-model: descriptionForm.title
     │  │  - label: "Заголовок"
     │  │  - class: "mb-4"
     │  │
     │  └─ Текст описания (v-textarea)
     │     - v-model: descriptionForm.text
     │     - label: "Попись описания"
     │     - rows: 6
     │
     └─ v-card-actions
        ├─ v-spacer
        ├─ Cancel (v-btn variant="text")
        └─ Save (v-btn color="primary" variant="flat")
```

**Функция получения типа:** `getCoefficientTypeLabel()`
- Возвращает локализованное имя коэффициента ('Плитные', 'Кромка' или 'Операции')

---

### 4. Section: "Справочные блоки" 📝
**Icon:** `mdi-text-box-outline`  
**Условие отображения:** `activeSettingsSection === 4`

#### Подраздел: Справочные блоки проекта
**Заголовок:** "Справочные блоки"  
**Подсказка:** "Кастомные текстовые блоки для отчёта проекта"

**Элементы управления:**

```
v-for="(block, idx) in projectData.text_blocks"

Row (flex align-center):
  ├─ Block number / drag handle (optional)
  ├─ Заголовок (v-text-field)
  │  - v-model: block.title
  │  - label: "Заголовок блока"
  │  - placeholder: "Например: Условия доставки"
  │  - density: "compact"
  │
  ├─ Текст (v-textarea)
  │  - v-model: block.text
  │  - label: "Текст блока"
  │  - rows: 3
  │  - density: "compact"
  │  - placeholder: "Введите текст..."
  │
  ├─ Чекбокс включения (v-checkbox)
  │  - v-model: block.enabled
  │  - label: "Включить"
  │  - density: "compact"
  │
  └─ Кнопка удаления
     - v-btn (icon size="small" variant="text")
     - @click: projectData.text_blocks.splice(idx, 1)
     - Icon: mdi-delete
```

**Кнопка добавления нового блока:**
```
v-btn (color="primary" variant="outlined")
  @click: addTextBlock()
  class: "mt-4"
  Icon: mdi-plus
  Text: "Добавить справочный блок"
```

**Метод добавления:**
```javascript
const addTextBlock = () => {
  projectData.value.text_blocks.push({ 
    title: '', 
    text: '', 
    enabled: true 
  })
}
```

---

## Типы данных и интерфейсы

```typescript
interface Region {
  id: number
  name: string
}

interface Material {
  id: number
  name: string
  type: 'plate' | 'edge'
}

interface TextBlock {
  title: string
  text: string
  enabled?: boolean
}

interface CoefficientDescription {
  title: string
  text: string
}

interface ProjectSettings {
  // === Section 0: Основное ===
  number: string                          // № дела
  expert_name: string                     // ФИО эксперта
  address: string                         // Адрес объекта
  region_id: number | null                // Регион

  // === Section 1: Коэффициенты ===
  waste_coefficient: number               // Коэффициент обрезков
  repair_coefficient: number              // Ремонтный коэффициент
  use_area_calc_mode: boolean             // Режим расчёта (листы vs площадь)

  // === Section 2: Материалы ===
  default_plate_material_id: number | null  // Плитный материал по-умолчанию
  default_edge_material_id: number | null   // Кромочный материал по-умолчанию

  // === Section 3: Отходы ===
  waste_plate_coefficient: number | null    // Коэффициент отходов плиты
  waste_edge_coefficient: number | null     // Коэффициент отходов кромки
  waste_operations_coefficient: number | null // Коэффициент отходов операций

  apply_waste_to_plate: boolean            // Применять коэффициент плиты
  apply_waste_to_edge: boolean             // Применять коэффициент кромки
  apply_waste_to_operations: boolean       // Применять коэффициент операций

  waste_plate_description: CoefficientDescription | null
  waste_edge_description: CoefficientDescription | null
  waste_operations_description: CoefficientDescription | null

  show_waste_plate_description: boolean    // Показывать в отчёте (плита)
  show_waste_edge_description: boolean     // Показывать в отчёте (кромка)
  show_waste_operations_description: boolean // Показывать в отчёте (операции)

  // === Section 4: Справочные блоки ===
  text_blocks: TextBlock[]                 // Пользовательские текстовые блоки
}
```

---

## Responsive поведение

### Desktop (md+)
- Drawer ширина: 1200px
- Drawer позицирует справа, не блокирует основной контент
- Боковой сайдбар всегда видимый (600-700px)
- Контент справа занимает оставшееся место (500-600px)
- Scrim (полупрозрачный оверлей) скрыт

### Tablet (sm)
- Drawer ширина: 95vw (минус padding)
- Боковой сайдбар и контент на мобильный стак
- Scrim активен
- Может требоваться горизонтальный скроль

### Mobile (xs)
- Drawer ширина: 100vw (fullscreen)
- Класс `settings-layout--mobile` активирует vertical flex
- Боковая навигация скрывается/открывается через бутерброд-меню (опционально)
- Scrim = true (затемнение фона)
- Temporary drawer (закрывается при клике вне)

**CSS переменная:** `--settings-drawer-content-width`
```vue
:style="{
  maxWidth: compactLayout ? '100vw' : '95vw',
  '--settings-drawer-content-width': compactLayout ? '100vw' : 'min(1200px, 95vw)'
}"
```

---

## API взаимодействие

### Endpoints

**GET `/api/projects/{id}`** — получить данные проекта
```
Response: { ...ProjectSettings, other_fields: ... }
```

**PUT `/api/projects/{id}`** — сохранить изменения проекта
```
Request: Partial<ProjectSettings>
Response: { ...ProjectSettings, other_fields: ... }
```

**GET `/api/materials`** — получить все материалы
```
Response: Material[]
```

**GET `/api/regions`** — получить регионы
```
Response: { data: Region[] }
```

**GET `/api/user/settings`** — загрузить личные настройки (функция loadUserDefaults)
```
Response: UserSettings
// Копирует в projectData:
// - region_id
// - waste_coefficient
// - repair_coefficient
// - default_plate_material_id
// - default_edge_material_id
// - use_area_calc_mode
// И НЕ копирует:
// - Нормо-часы
// - Цены операций
```

---

## Состояния и флаги

```typescript
// Управление drawer'ом
modelValue: boolean              // Props: управляет открытием/закрытием
compactLayout: boolean           // Computed: <= tablet size?

// Активная секция
activeSettingsSection: number    // 0-4: текущий активный раздел

// Состояние загрузки
loading: boolean                 // При загрузке данных
isLoadingDefaults: boolean       // При загрузке личных настроек

// Состояние сохранения
saving: boolean                  // При отправке PUT-запроса

// Dirty-tracking
isDirty: computed                // Есть ли несохранённые изменения
originalData: string             // JSON строка оригинальных данных

// Диалоги
showDescriptionDialog: boolean   // Диалог редактирования описания отходов
editingCoefficientType: string   // Какой коэффициент редактируется

// Форма данных
projectData: ref<ProjectSettings> // Основной объект формы
descriptionForm: ref<CoefficientDescription> // Временная форма диалога
```

---

## Пользовательский опыт (UX)

### Открытие/закрытие
1. Пользователь кликает иконку "Настройки" в ProjectEditor
2. Drawer открывается с right side с анимацией слайда
3. На мобильных — fullscreen с затемнением фона
4. Пользователь может закрыть:
   - Кликом на кнопку ✕
   - Кликом на scrim (мобайл)
   - Esc-ключом (опционально)

### Навигация по секциям
- Левая панель всегда видима (на desktop)
- Клик на пункт меню мгновенно переключает контент справа
- Иконки помогают визуально быстро найти нужную секцию

### Загрузка дефолтов
1. Пользователь кликает [⬇ Download] в header
2. Система загружает личные настройки из `/api/user/settings`
3. Значения копируются в projectData (кроме нормо-часов и цен)
4. Форма помечается как dirty
5. Уведомление об успехе/ошибке

### Сохранение изменений
1. Пользователь изменяет любые значения
2. Форма помечается dirty (enable save button)
3. Кликает "Сохранить" в footer (если существует)
4. PUT-запрос отправляется на `/api/projects/{id}`
5. На успех:
   - Данные сохраняются в store
   - Уведомление "Изменения сохранены"
   - Форма больше не dirty
6. На ошибку:
   - Красное уведомление с текстом ошибки
   - Форма остаётся dirty (данные не потеряны)

### Отмена изменений
- Кнопка "Отменить" восстанавливает оригинальное состояние
- Работает только если форма dirty
- Подтверждение может быть запрошено если есть значительные изменения

---

## Валидация

**Минимум значений:**
- `waste_coefficient`, `repair_coefficient`: min="1"
- Это предотвращает отрицательные или нулевые коэффициенты

**Шаг значений:**
- Текстовые поля number: step="0.01"
- Точность до сотых для финансовых расчётов

**Обязательные поля:**
- Нет строго обязательных (все опциональные)
- Но есть подсказки/предупреждения (e.g. "Регион не выбран")

**Кастомная валидация:**
- Материалы фильтруются by type перед показом
- Пустые text_blocks игнорируются при сохранении

---

## CSS/UI Компоненты

**Используются элементы Vuetify 3:**
- `v-navigation-drawer` — drawer контейнер
- `v-card` — карточка контейнеры с outlined вариантом
- `v-card-title` — заголовок с border-b
- `v-card-text` — основной контент
- `v-list` / `v-list-item` — навигационное меню
- `*-field` — текстовые/числовые/autocomplete поля
- `v-textarea` — многострочный текст
- `v-switch` — переключатели
- `v-checkbox` — флажки
- `v-btn` — кнопки (icon, outlined, flat)
- `v-icon` — иконки Material Design
- `v-dialog` — модальные окна для описаний
- `v-divider` — разделители между секциями
- `v-tooltip` — подсказки над кнопками
- `v-skeleton-loader` — плейсхолдеры загрузки

---

## Отличия от UserSettingsView

| Аспект | UserSettings (левый сайдбар) | ProjectSettings (правый drawer) |
|--------|--------|---------|
| **Расположение** | Полноэкранная страница | Drawer с right side |
| **Область действия** | Глобальные дефолты пользователя | Специфичные для проекта |
| **Раздел 6** | Безопасность (UserSecurityPanel) | ✕ Нет |
| **Кнопка загрузки** | ✕ Нет | ✓ [Load Defaults] |
| **Применение** | К новым проектам | К текущему проекту |
| **API endpoint** | `/api/user/settings` | `/api/projects/{id}` |
| **Sticky header** | ✓ PageHeader | ✓ v-card-title |
| **Sticky footer** | ✓ с кнопками сохранения | ✓ с кнопками действий |
| **Responsive** | Вертикальный стак на мобайл | Drawer fullscreen на мобайл |

---

## Примечания для разработчика

1. **Двойное управление описаниями:**
   - Редактируются в отдельном диалоге (`showDescriptionDialog`)
   - Временная форма (`descriptionForm.value`)
   - Сохраняются назад в `projectData.waste_[type]_description`

2. **Условное отключение switch'ей:**
   - `show_waste_*_description` отключена если нет title И нет text
   - Предотвращает показ пустых описаний

3. **Ленивая загрузка:**
   - Материалы и регионы загружаются один раз при монтировании
   - ProjectData передаётся сверху (из ProjectEditorView)

4. **Компактный макет:**
   - `compactLayout` вычисляется через `$vuetify.display.mdAndUp`
   - На xs/sm — fullscreen drawer, вертикальный flex
   - На md+ — 1200px drawer, горизонтальный flex

5. **Оптимизация для больших проектов:**
   - Можно добавить виртуализацию для длинного списка text_blocks
   - Использовать `v-virtual-scroll` если > 50 блоков

---

## Иерархия событий

```
ProjectSettingsDrawer
├─ update:modelValue (boolean) → контролирует открытие/закрытие
├─ save:project (data) → отправляет изменения на сохранение
├─ load:defaults → запросить загрузку дефолтов
└─ section:changed (idx) → уведомить об смене секции

ProjectEditorView (родитель)
├─ :modelValue="showSettingsDrawer" → контролирует drawer
├─ @update:modelValue="showSettingsDrawer = $event" → слушает закрытие
├─ :projectData="currentProject" → передаёт данные
└─ @save:project="saveProject($event)" → сохраняет изменения
```

---

## Примеры использования

### Встраивание в ProjectEditor

```vue
<ProjectSettingsDrawer
  :modelValue="showSettingsDrawer"
  @update:modelValue="showSettingsDrawer = $event"
  :projectData="currentProject"
  :regions="regions"
  :materials="materials"
  @save:project="handleSaveProject"
  @load:defaults="handleLoadDefaults"
/>
```

### Сохранение с обработкой ошибок

```javascript
const handleSaveProject = async (data) => {
  try {
    saving.value = true
    const response = await api.put(`/api/projects/${projectId}`, data)
    showNotification('Изменения сохранены', 'success')
    originalData.value = JSON.stringify(data)
  } catch (error) {
    showNotification(
      error.response?.data?.message || 'Ошибка сохранения',
      'error'
    )
  } finally {
    saving.value = false
  }
}
```

---

## Доступность (A11y)

- **Семантика:** label для всех полей через v-text-field/v-select label prop
- **Навигация:** доступна через Tab-клавишу, Enter для активации
- **ARIA-labels:** автоматически генерируются Vuetify
- **Контрастность:** используются стандартные цвета с хорошим контрастом
- **Фокус:** видимый focus ring при навигации клавиатурой
- **Скрин-ридеры:** все иконки имеют контекстный текст
