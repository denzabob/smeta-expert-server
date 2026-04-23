# UI Foundation Standard

Документ фиксирует минимальный foundation-слой для Vue 3 + Vuetify 3 интерфейса. Цель - единая строгая enterprise-система без скрытого изменения бизнес-логики, API payload, enum values и пользовательских workflows.

Для локальной визуальной приемки защищенных pilot-экранов используется только dev-only режим из `client/docs/dev-visual-acceptance.md`. Он не является частью production auth flow и не должен использоваться для проверки бизнес-логики.

## 1. Page Container

**Компонент:** `PageContainer`

**Назначение:** единая оболочка обычной страницы внутри app shell.

**Когда использовать:** для registry pages, settings pages, admin pages, catalog/list pages, pricing overview pages.

**Когда не использовать:** внутри fullscreen workspace/editor, nested drawer/dialog content, auth hero pages, внутренних карточек.

**Правила:**
- Верхний уровень обычной страницы начинается с `PageContainer`.
- Ширина задается через `maxWidth`, а не локальными wrapper-классами.
- Page padding не дублируется через локальные `pa-*` на root-элементе.
- Для плотных enterprise pages сохраняется компактный вертикальный ритм.

**Anti-patterns:**
- Локальный page wrapper с собственным max-width при наличии `PageContainer`.
- Вложенный `PageContainer` внутри `SectionCard`.
- Использование page container для drawer/dialog body.

## 2. Page Header

**Компонент:** `PageHeader`

**Назначение:** единая точка заголовка страницы, подзаголовка и page-level actions.

**Когда использовать:** на всех обычных страницах, где есть рабочая область или реестр.

**Когда не использовать:** в карточках, drawer, modal, строках таблицы, внутренних формах.

**Правила:**
- Один `PageHeader` на страницу.
- `title` короткий и предметный.
- `subtitle` объясняет назначение или состояние, не повторяет заголовок.
- Primary action размещается в `actions`; вторичные действия идут после primary.
- Back/navigation action должен быть вторичным, кроме dedicated detail page.

**Anti-patterns:**
- Несколько H1/header-блоков на одной обычной странице.
- Технические заголовки вроде `pricing`, `strict`, `source_kind` без пользовательского смысла.
- Page actions внутри первого `SectionCard`, если они управляют всей страницей.

## 3. Section Card

**Компонент:** `SectionCard`

**Назначение:** основной контейнер смысловой секции страницы.

**Когда использовать:** таблица реестра, группа настроек, список сущностей, основной рабочий блок.

**Когда не использовать:** для каждого маленького поля формы, для декоративного обрамления, как wrapper внутри другого card без доменной причины.

**Правила:**
- Один section card должен соответствовать одной смысловой задаче.
- Header используется для title/subtitle секции, actions - для действий этой секции.
- Внутри section card не создавать mini-design-system локальными `.card-*` классами.
- Для внутренних групп использовать `AppFormSection`, `AppDetailMetaGrid` или простые semantic blocks.

**Anti-patterns:**
- Card in card ради визуального слоя.
- Локальные hero/metric cards, которые спорят с page header.
- Смешение `v-card-title`, кастомных `.section-title` и локальных заголовков без единого правила.

## 4. Table Toolbar

**Компоненты:** `TableToolbar`, `AppDataTableShell`

**Назначение:** единый layout для search, filters и actions над таблицами.

**Когда использовать:** над `v-data-table`, list registry, server table, import table.

**Когда не использовать:** для page actions, drawer header actions, inline field groups.

**Правила:**
- Search слева, filters рядом, actions справа.
- Search имеет стабильную ширину и не ломает actions на desktop.
- На mobile элементы переносятся без overlap.
- Bulk actions должны быть отдельным selected-state toolbar, а не смешиваться с обычными filters.

**Anti-patterns:**
- Несколько toolbar-полос над одной таблицей без причины.
- Inline `style="max-width"` в каждом экране вместо общего wrapper/variant.
- Фильтры внутри произвольной карточки, если они управляют таблицей ниже.

## 5. Search / Filter / Action Layout

**Назначение:** единая композиция управления списками.

**Когда использовать:** registry pages, admin lists, catalog filters, import lists.

**Когда не использовать:** внутри сложных форм, где controls являются полями данных.

**Правила:**
- Search placeholder должен говорить, где искать.
- Filters должны иметь понятные labels.
- Action group справа: create/import/export/refresh.
- Destructive actions не размещаются в общей toolbar без подтверждения.
- Dense controls используют Vuetify compact defaults.

**Anti-patterns:**
- Перемешивание search, create и bulk actions без визуальной группы.
- Кнопки с одинаковой визуальной важностью для primary и secondary действий.
- Непредсказуемое исчезновение filters на mobile без альтернативы.

## 6. Status Chip

**Компонент:** `StatusChip`

**Назначение:** единое отображение статусов, режимов и кратких business states.

**Когда использовать:** таблицы, detail summary, cards, revision/evidence/pricing statuses.

**Когда не использовать:** для обычных категорий, декоративных тегов, длинных описаний.

**Правила:**
- UI label можно менять, backend enum/payload менять нельзя.
- `success` - подтверждено/активно/готово.
- `warning` - требует внимания/устарело/неполно.
- `error` - ошибка/отклонено/блокирует.
- `info` - системное/автоматическое/в процессе.
- `grey` - нет данных/неактивно/не применимо.
- В таблицах использовать `small` или `x-small`.

**Anti-patterns:**
- Локальные badge-классы на каждый экран.
- Цвет как украшение, а не смысловой статус.
- Длинный текст внутри chip.

## 7. Row Actions

**Компонент:** `AppRowActions`

**Назначение:** единый набор действий строки таблицы.

**Когда использовать:** edit/delete/open/details/evidence/actions в строках таблиц.

**Когда не использовать:** page-level actions, form submit actions, destructive confirmation body.

**Правила:**
- Icon actions должны иметь tooltip и `aria-label`.
- Click должен останавливать row navigation там, где строка кликабельна.
- Основное row action может быть текстовой кнопкой только если это главный сценарий.
- Loading/disabled передаются на конкретное действие.

**Anti-patterns:**
- Иконки без tooltip/aria-label.
- Разные размеры action buttons в одной таблице.
- Delete рядом с primary action без visual distinction.

## 8. Right Detail Drawer

**Компонент:** `AppDetailDrawer`

**Назначение:** единая правая панель деталей без перехода со страницы.

**Когда использовать:** details/source/import item/user inspector/operation details, если пользователь сравнивает список и объект.

**Когда не использовать:** длинные multi-step forms, destructive confirmation, fullscreen editor.

**Правила:**
- Desktop width обычно 400-480px, для таблиц/импортов допустимо шире.
- Mobile - fullscreen или почти fullscreen.
- Header: title, subtitle/meta, close, optional header actions.
- Body scrolls, header/footer stay predictable.
- Первый экран drawer показывает summary и primary decision; подробности ниже.

**Anti-patterns:**
- Несколько конкурирующих headers внутри drawer.
- Перегруженный первый экран техническими полями.
- Локальные drawer class systems без reuse.

## 9. Form Section

**Компонент:** `AppFormSection`

**Назначение:** смысловая группа полей в форме.

**Когда использовать:** settings, dialogs с несколькими смысловыми блоками, pricing/source/profile forms.

**Когда не использовать:** вокруг одиночного поля, внутри компактной таблицы, для декоративного разделения.

**Правила:**
- Section title короткий, description объясняет влияние.
- Fields используют Vuetify defaults и общий vertical rhythm.
- Validation показывается у поля; общая ошибка формы - alert сверху секции или формы.
- Actions секции не должны конфликтовать с footer actions формы.

**Anti-patterns:**
- Каждый field в отдельной card-like surface.
- Технические названия полей вместо пользовательских labels.
- Локальные padding/radius/color при наличии tokens.

## 10. Empty / Loading / Error States

**Компоненты:** `EmptyState`, `AppStateBlock`, `AppDataTableShell`

**Назначение:** единые состояния отсутствия данных, загрузки и ошибок.

**Когда использовать:** таблицы, списки, panels, non-table content.

**Когда не использовать:** вместо validation errors конкретного поля или вместо confirmation dialog.

**Правила:**
- Initial loading: skeleton или compact loader рядом с местом данных.
- Refresh loading: `loading` на конкретной таблице/кнопке.
- Empty state отвечает: что произошло, почему это нормально, что можно сделать.
- Error state должен быть рядом с местом проблемы.
- Snackbar используется для результата действия, не для постоянной ошибки экрана.

**Anti-patterns:**
- Просто пустой блок без текста.
- Глобальный snackbar вместо inline error.
- Несколько разных empty state стилей на одном типе экрана.

## 11. Action Footer

**Компонент:** `AppActionFooter`

**Назначение:** единый footer для save/cancel/dirty/loading actions.

**Когда использовать:** settings shell, long form, drawer form, dialog with persistent actions.

**Когда не использовать:** простая одношаговая confirmation modal, table row actions.

**Правила:**
- Status слева, actions справа.
- Primary save справа.
- Cancel/text action перед primary.
- Dirty/saving status должен быть явным.
- Sticky footer допустим для long scroll forms.

**Anti-patterns:**
- Save button в нескольких местах одной формы.
- Destructive action рядом с save без отделения.
- Footer, который перекрывает последний input на mobile.

## 12. Tabs

**Компонент:** `AppTabs`

**Назначение:** единый wrapper для разделов одной страницы.

**Когда использовать:** admin system tabs, settings sub-sections, detail card sub-tabs.

**Когда не использовать:** как replacement для sidebar/main navigation, wizard steps без явного progress, filters where chips are better.

**Правила:**
- Tabs переключают разделы одного контекста.
- Labels короткие.
- Route-bound tabs синхронизируются с route name/query аккуратно.
- Mobile tabs должны скроллиться горизонтально без overlap.

**Anti-patterns:**
- Tabs как главное меню приложения.
- Смешение русских и английских labels без доменной причины.
- Слишком много tabs в одной строке без группировки.

## 13. General Foundation Rules

- Новые business screens должны сначала искать существующий foundation component.
- Не делать global CSS sweep без pilot и visual smoke.
- Не менять backend enum/API terms под видом UI стандартизации.
- Не трогать high-risk screens до успешного registry/detail/settings pilot.
- Любая миграция должна сохранять props/events/data flow существующего экрана.
