---
name: prism-git-workflow
description: "Project-specific safe Git workflow for Prism development across workstations and GitHub-to-VPS deployment. Use for status checks, branching, commits, pushes, synchronization, release preparation, and deployment safety."
---

# Prism Git Workflow

Применяй этот Skill для Git workflow Prism, синхронизации нескольких рабочих машин и подготовки deployment. GitHub `main` является source of truth для application code.

## Workflow topology

```text
Laptop ----\
            -> GitHub main -> VPS
Desktop ---/
```

Локальная машина не становится отдельным source of truth. Production application code обновляется только из проверенного Git/GitHub commit.

## Before work

Выполни и зафиксируй:

```text
git status
git branch --show-current
git fetch origin
```

Перед destructive sync проверь, что uncommitted changes сохранены или явно признаны ненужными. Не используй force/reset для потери данных без явного scope.

## Before commit and push

Перед commit выполни:

```text
git status
git diff
git diff --check
```

Запусти targeted validation через `prism-testing`. Один bounded logical block должен иметь один понятный semantic commit; не включай unrelated files, dumps, logs или debug artifacts.

Перед push проверь текущую ветку, ожидаемый diff и `git log -1 --oneline`.

## Deployment

Перед deployment:

- проверь expected commit и clean working tree;
- fetch и сверь `origin/main`;
- обновляй только ожидаемую revision;
- выполни required build/migrations/checks и smoke test.

Не редактируй application code вручную на production и не деплой изменения, которых нет в проверенном Git commit.

## Multi-workstation sync

При переходе между ноутбуком и ПК:

1. Заверши или закоммить изменения на активной машине.
2. Выполни push.
3. На другой машине выполни fetch и проверь local changes.
4. Синхронизируйся с `origin/main`.
5. Отдельно актуализируй machine-specific `.env`, Docker data/DB только если это требуется.

Не используй копирование рабочей папки как основной способ синхронизации кода.

## Routing и отчёт

- Для targeted checks используй `prism-testing`.
- Для cross-layer или high-risk work сначала используй `large-change-scope`.
- Для GitHub/remote-provider actions используй соответствующий инструмент только если он реально доступен; не считай внешний Git provider гарантированным.

Отчёт разделяй на фактически выполненные команды, непроведённые проверки и оставшиеся manual/deployment checks.

