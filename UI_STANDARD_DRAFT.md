# UI Standard Draft

## 1. Назначение стандарта

Этот документ задает черновой единый UI-стандарт для frontend части `client`: Vue 3 + Vuetify 3 SaaS-интерфейс с плотной enterprise-подачей и MD3-inspired семантическими ролями.

Цель стандарта - не украшение отдельных экранов, а общий язык интерфейса: одинаковые страницы должны выглядеть и вести себя одинаково, а сложные бизнес-экраны должны сохранять скорость чтения, плотность и предсказуемость.

## 2. Наблюдения из текущего проекта

### Observed

- Frontend находится в `client/src`.
- Vuetify подключен через `client/src/plugins/vuetify.ts`.
- Тема и Vuetify defaults заданы в `client/src/plugins/theme.ts`.
- Глобальный stylesheet дизайн-системы уже есть: `client/src/styles/design-system.scss`.
- Есть базовые layout-компоненты: `PageContainer`, `PageHeader`, `SectionCard`, `TableToolbar`, `EmptyState`, `ButtonGroup`.
- В приложении есть несколько shell-подходов: основной `AppShell`, админский `AdminLayout`, parser layout `ParserLayout`, отдельный workspace внутри `ProjectEditorView`.
- `PageContainer` используется примерно на 30 экранах, `PageHeader` - примерно на 27, `SectionCard` - примерно на 30 местах.
- В проекте много локальных scoped-стилей для таблиц, карточек, панелей, drawer, empty state, chips и форм.

### Inferred

- Дизайн-система уже начала формироваться, но стандартизация применена неравномерно.
- Основной риск будущего UI-рефакторинга - не визуальная сложность, а смешение глобальных правил, Vuetify defaults и локальных overrides в бизнес-экранах.
- Без общего набора wrappers для таблиц, detail panels, dialogs и status chips локальная косметика будет продолжать расходиться.

## 3. Visual Principles

- Стиль: строгий современный enterprise UI, без декоративной рыхлости.
- Плотность: по умолчанию компактная или comfortable, особенно для таблиц, pricing, evidence, admin, settings и редактора сметы.
- Иерархия: один главный заголовок страницы, один основной рабочий контейнер, дальше секции по смыслу.
- Поверхности: глубина через тональные surface-роли и границы, не через тяжелые тени.
- Цвет: только семантические Vuetify/theme tokens и `--ds-*` / `--md-sys-*`; raw hex допустим только при явной необходимости и должен быть вынесен в tokens.
- UI language: бизнес-ориентированный русский текст; технические термины оставлять только там, где они являются предметом работы.
- Поведение: hover, focus, loading, disabled, error должны быть предсказуемы и одинаковы для одного типа элементов.

## 4. Spacing Rules

- Базовая шкала: использовать существующие `--ds-space-*`.
- Page padding: через `PageContainer`, без локального дублирования `pa-*` на верхнем уровне страницы.
- Стандартный вертикальный ритм страницы: header -> 16-24px -> основной блок.
- Секции внутри карточки: 12-16px gap.
- Формы: использовать `md3-form-stack`, `form-stack` или будущий `AppFormSection`, а не случайные `mb-*` между полями.
- Toolbar/filter row: единый gap 8-12px, перенос на мобильном, без inline `style="max-width"` там, где можно задать prop/wrapper.
- Не вкладывать card в card без доменной причины; для внутренних групп использовать section block/surface.

## 5. Typography Rules

- Главный заголовок страницы: только `PageHeader`.
- Заголовок секции: только через `SectionCard` header или общий `AppSectionTitle`.
- Не смешивать `text-h6`, локальные `.section-title`, `.drawer-title`, `.fp-pricing-hero__title` без стандарта.
- Табличные данные: 13-14px, tabular nums для денег, дат, процентов и количеств.
- Secondary text: `--ds-text-secondary`; tertiary/help text: `--ds-text-tertiary`.
- Не использовать отрицательный `letter-spacing` в новых локальных компонентах; текущие случаи считать legacy debt.
- Labels/status/kickers: короткие, не декоративные, без капса там, где это не помогает сканированию.

## 6. Surface / Card Rules

- `SectionCard` - базовый контейнер смысловой секции.
- `v-card` внутри `SectionCard` использовать только для повторяющихся отдельных сущностей или независимых блоков.
- Для внутренних подблоков использовать общий будущий `AppSurface` / `AppInfoBlock`, а не локальные `.detail-card`, `.meta-cell`, `.position-form-section`, `.fp-pricing-metric`.
- Радиусы и границы брать из `--ds-radius-*`, `--md-sys-shape-*`, `--ds-border-color`.
- Новые тени не добавлять, кроме menu/dialog/dropdown, где тень уже стандартизована.
- Hero-like blocks внутри операционных экранов не должны конкурировать с `PageHeader`.

## 7. Page Header Rules

- Каждый обычный экран внутри приложения должен начинаться с `PageContainer` + `PageHeader`.
- `PageHeader.title` - короткое имя рабочей области.
- `PageHeader.subtitle` - состояние или назначение, не маркетинговый текст.
- Actions справа: только основные действия страницы; вторичные действия через text/tonal или меню.
- Back action: слева или первым вторичным действием, не смешивать с primary CTA.
- Не создавать локальные topbar/header внутри страницы, если экран не является workspace/editor.

## 8. Toolbar / Filters Rules

- Для таблиц использовать `TableToolbar`.
- Search слева, фильтры рядом, actions справа.
- Фильтры не должны быть спрятаны в случайный card, если они управляют таблицей ниже.
- Bulk-action toolbar должен иметь отдельный selected state: выбранное количество, доступные действия, clear selection.
- Не использовать разные названия классов для одинаковой сущности: `table-toolbar`, `filters-bar`, `positions-unified-toolbar` должны быть сведены к общей модели.

## 9. Table Rules

- Для реестров использовать `v-data-table` с едиными row height, header style, hover, selected.
- Действия в строке: иконки с tooltip/aria-label; текстовая кнопка допускается только для главного row action.
- Статусы в таблицах: через единый status chip helper/component.
- Денежные значения, даты, счетчики: правое выравнивание или стабильная numeric presentation по типу таблицы.
- Empty state таблицы должен быть внутри `#no-data` и использовать общий `EmptyState`.
- Loading: `:loading` таблицы для обновления данных, skeleton только при первой загрузке пустого экрана.
- Не делать локальные table wrappers с собственной геометрией без причины; если нужен sticky/scroll, вынести в `AppDataTableShell`.

## 10. Form Rules

- Формы делить на короткие смысловые секции: identity, pricing, evidence, settings, advanced.
- Поля: Vuetify defaults уже задают compact outlined; не переопределять локально без причины.
- Ошибки: через `error-messages`; общая ошибка формы - `v-alert type="error" density="compact"`.
- Подсказки: короткие, возле поля, не абзацы.
- Action row: cancel/text слева или справа по контексту, primary save справа, destructive отдельно и визуально отделено.
- Dirty state: для settings/editor показывать sticky footer/action bar.

## 11. Dialog / Modal Rules

- Dialog anatomy: header, divider, body, actions.
- Заголовок диалога должен описывать действие: "Добавить источник", "Изменить операцию", "Архивировать проект".
- Actions: `Отмена` text, primary flat/tonal, destructive error.
- Scroll: body scrollable, actions fixed для длинных форм.
- Snackbar внутри dialog избегать; лучше alert/status внутри body, кроме компактных подтверждений.
- Fullscreen dialog на мобильном разрешен для сложных flows.
- Confirmation dialogs должны явно говорить, что изменится, и не использовать технические payload/status имена.

## 12. Detail Panel Rules

- Правый detail panel/drawer должен иметь общий контракт:
  - фиксированная ширина desktop 400-480px;
  - fullscreen или bottom/full dialog на mobile;
  - header с title, subtitle/meta, close;
  - body с секциями;
  - optional sticky action/footer.
- Не перегружать первый экран drawer: сначала summary/primary decision, затем details/accordion.
- Detail cards/meta cells должны быть едиными, а не локальными `.meta-cell`, `.detail-card`, `.calculation-block`.
- Если panel влияет на выбранную строку, selection state в таблице должен быть видимым.

## 13. Tabs Rules

- Top-level tabs использовать для разделов одной страницы, не для навигации между разными сущностями.
- Внутренние settings tabs/sidebar лучше через `SettingsShell` или будущий `AppSectionNav`.
- Мобильные tabs должны быть horizontally scrollable, с видимым active state.
- Названия вкладок короткие: "LLM", "Пользователи", "Логи"; без смешения русского и английского, если нет доменной причины.

## 14. Buttons Hierarchy

- Primary action: `color="primary" variant="flat"`.
- Secondary action: `variant="tonal"` или `outlined`.
- Tertiary/navigation: `variant="text"`.
- Destructive: `color="error"`; destructive primary только в confirmation dialog.
- Icon actions: обязательно tooltip или `aria-label`, особенно в таблицах.
- Button groups: через `ButtonGroup` или будущий `AppActionGroup`.
- Не смешивать primary и secondary цвет как просто разные красивые акценты; цвет должен означать роль действия.

## 15. Chips / Status Rules

- Status chip должен кодировать бизнес-состояние, а не декоративную категорию.
- Размер в таблицах: `x-small` или `small`; в summary: `small`.
- Цветовые роли:
  - success: подтверждено, активно, успешно;
  - warning: требует внимания, устарело, неполные данные;
  - error: ошибка, отклонено, блокирующее состояние;
  - info: системное/автоматическое;
  - neutral/grey: нет данных, pending, не применимо.
- Не создавать локальные badge-классы на каждый экран; нужен `StatusChip`.
- Текст chip должен быть коротким: "Активен", "Устарело", "Нет цены", "Ожидание".

## 16. Loading / Empty / Error States

- Initial loading page: skeleton.
- Refresh loading: progress в конкретном компоненте или `:loading`.
- Empty state: общий `EmptyState`, один icon, title, description, optional action.
- Error state: `v-alert type="error"` рядом с местом проблемы; глобальный snackbar - только для результата действия.
- Disabled state должен объясняться title/tooltip, если причина неочевидна.
- Long-running action должен блокировать повторное действие через `:loading` и `:disabled`.

## 17. Microcopy Rules

- Говорить языком пользователя: "Источник цены добавлен", не "price source created".
- Избегать технических слов в UI: `strict`, `pricing`, `source_kind`, `capture_method`, `LLM` - только там, где пользователь реально работает с этими понятиями.
- Кнопки - глагол + объект: "Добавить источник", "Сохранить правило", "Архивировать проект".
- Empty state должен отвечать: что произошло, почему это нормально, что можно сделать дальше.
- Ошибки должны быть полезными: "Не удалось загрузить источники цены" лучше, чем "Ошибка".
- Подтверждение destructive action должно называть последствия и объект.

## 18. Что считать регрессией

- Потеря плотности в реестрах, редакторе сметы, pricing/evidence и admin.
- Вложенные карточки, которые визуально дробят один рабочий блок.
- Появление raw hex/rgb в новых screen-level стилях без токена.
- Разные формы одного и того же паттерна: таблица с фильтрами, detail drawer, status chip, empty state.
- Английские технические подписи в пользовательских рабочих экранах без доменной причины.
- Скрытые изменения бизнес-поведения под видом UI-рефакторинга.
