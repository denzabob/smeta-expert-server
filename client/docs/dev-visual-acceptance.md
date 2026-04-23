# Dev Visual Acceptance

This mode exists only to unblock local UI smoke checks for protected pilot screens.

## Enable

Run the frontend with an explicit development flag:

```bash
VITE_ENABLE_DEV_VISUAL_AUTH=true npm run dev -- --host 127.0.0.1 --port 5173
```

On Windows PowerShell:

```powershell
$env:VITE_ENABLE_DEV_VISUAL_AUTH='true'; npm run dev -- --host 127.0.0.1 --port 5173
```

## URLs

- `http://127.0.0.1:5173/projects`
- `http://127.0.0.1:5173/price-imports`
- `http://127.0.0.1:5173/settings`

## Guard Rails

- The mode is active only when `import.meta.env.DEV` is true.
- It also requires `VITE_ENABLE_DEV_VISUAL_AUTH=true`.
- Production auth flow is not changed.
- Demo API responses are read-only and limited to pilot visual acceptance endpoints.

## Scope

Use this mode for visual smoke only: layout, spacing, table/list shells, drawers, empty/loading/error states, chips and action bars.

Do not use it to validate business logic, payloads, permissions, destructive flows, pricing, evidence, reports, or editor workflows.
