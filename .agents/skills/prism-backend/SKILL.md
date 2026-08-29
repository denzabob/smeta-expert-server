---
name: prism-backend
description: "Project-specific Laravel/PHP backend engineering rules for Prism. Use for controllers, services, domain/application layers, models, API contracts, validation, middleware, jobs, commands, transactions, error handling, persistence impact, and backward-compatible backend changes."
---

# Prism Backend

Применяй этот Skill для Laravel/PHP backend в Prism. Это repository-specific contract и workflow, а не общий учебник Laravel.

## Canonical repository areas

Ориентируйся на текущую реализацию в:

- `server/app/**`;
- `server/routes/**`;
- `server/tests/**`;
- `server/database/**`;
- domain-specific README и docs.

## Анализ до backend edit

Перед изменением:

1. Найди реальный entry point и execution path от route/command/job до результата.
2. Определи границу controller, service, domain/application layer и model.
3. Найди API/data contracts, validation rules, error responses и legacy behavior.
4. Определи persistence impact; для schema/migration задач добавь `prism-database`.
5. Найди targeted tests и существующие integration/feature checks.
6. Оцени backward compatibility для клиентов, jobs, imports, extension flows и сохранённых данных.

Зафиксируй факты, допущения и неизвестные до редактирования.

## Boundaries and implementation

- Держи controllers тонкими: transport concerns, authorization, validation orchestration и вызов существующего service/domain flow.
- Размещай business rules в существующих services/domain classes; не создавай параллельную abstraction без необходимости.
- Используй explicit validation и стабильные error responses; не меняй payload semantics молча.
- Для multi-step persistence сохраняй atomicity и idempotency; транзакцию ограничивай необходимым scope.
- Сохраняй backward-compatible defaults, legacy routes и integration contracts, если изменение не входит в явный scope.
- Для schema/persistence details следуй `prism-database`, а не дублируй его правила.

## Guardrails

Не:

- переносить business logic в controllers;
- создавать новую архитектурную прослойку только ради локального feature fix;
- делать broad refactor одновременно с исправлением поведения;
- менять legacy flow, API payload или error semantics без явного scope;
- обходить существующие authorization, validation, transaction или persistence boundaries.

## Cross-skill routing

- Для Laravel-specific framework knowledge используй `laravel-expert` только если он реально доступен в текущем Codex environment.
- Для service boundaries, integration patterns или backend architecture используй `backend-architect` только если он реально доступен в текущем Codex environment.
- Для измеряемых bottleneck используй `performance-optimizer` только если он реально доступен в текущем Codex environment.
- Для review-only проверки используй `code-reviewer` только если он реально доступен в текущем Codex environment.
- Для каждой backend-задачи добавляй `prism-testing` и выбирай targeted checks по фактическому diff.

Не активируй все Skills механически.

## Validation и отчёт

Используй `prism-testing` для выбора минимальных targeted checks по фактическому diff. В отчёте разделяй `Run`, `Not run` и `Manual checks`; не называй API, transaction, persistence или backward compatibility проверенными без фактической проверки.

