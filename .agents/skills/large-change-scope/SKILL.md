---
name: large-change-scope
description: "Use before implementing Prism work that crosses database, backend services, API, frontend, PDF, browser extension, public SEO, or other high-risk layers; enforce analysis-first bounded blocks, explicit scope and targeted validation."
---

# Large change scope

Применяй этот Skill перед реализацией cross-layer или high-risk задачи.

## Planning gate

Сначала:

1. Изучи текущую архитектуру, entry points и execution paths.
2. Определи domain entities, lifecycle, persistence и зависимости.
3. Перечисли требуемые schema changes и migration risks.
4. Зафиксируй API/public contracts и legacy behavior.
5. Определи UI/PDF/extension impact, если применимо.
6. Опиши rollout и backward compatibility.
7. Раздели работу на bounded blocks.

До выбора блока не начинай implementation. Каждый block обязан иметь один goal, exact in-scope files/modules, dependencies, explicit out-of-scope, compatibility expectation, acceptance criteria и targeted validation.

## Implementation rules

- Реализуй только выбранный block.
- Меняй только необходимые файлы; избегай broad refactors и больших rewrite.
- Предпочитай additive migrations, adapters и bridges.
- Не удаляй legacy paths без явного scope.
- Не меняй public contracts молча.
- Для одного bounded block используй только одного write-capable implementer.
- После изменения выполни targeted checks и сообщи Run, Not run и Manual checks.

Если обнаружена скрытая зависимость или конфликт между планом и repository, остановись и сообщи parent agent; не расширяй scope молча.
