# Deploy Policy

## Source Of Truth

The only valid change flow for this project is:

`local workstation -> git push -> VPS git pull --ff-only`

Production files on the VPS are deployment output, not an editing surface.

## VPS Rules

The VPS checkout must stay a clean Git working tree.

Allowed on VPS:

- `git pull --ff-only`
- `docker compose up -d --build`
- `./deploy-app`
- database migrations
- Laravel cache rebuilds
- health checks

Not allowed on VPS:

- manual source edits inside `/opt/smeta-expert-server`
- changing tracked or untracked repository files as part of deploy
- ad hoc Nginx or script edits that are not committed to Git

Emergency diagnostics are the only exception. Any emergency change must be moved back into Git immediately after the incident.

## Frontend Build Policy

This repository follows the production rule that frontend artifacts are not part of Git history.

- `client/dist/` is a generated artifact and is ignored by Git
- local frontend builds are for development verification only
- deploy scripts must not depend on `npm run build` writing into the VPS checkout
- production frontend build runs inside the `spa` Docker image

Recommended production strategy:

1. Build the frontend inside a Docker image build stage.
2. Copy the built assets into the runtime image.
3. Expose the SPA container on `127.0.0.1:8011`.
4. Let host Nginx proxy `/` to the SPA container and `/api/*` + `/sanctum/*` to the backend.
5. Deploy on VPS with `./deploy-app`.

This keeps the VPS checkout clean and makes deploy reproducible.

## Infrastructure Ownership

Nginx configs and deploy scripts must be treated in one of these two ways:

- stored in this repository and updated through commits
- stored as separate infrastructure code outside this repository

Do not hand-edit the same production config repeatedly on the VPS. That creates drift from Git and makes the next deploy nondeterministic.

## Minimal Deploy Sequence

1. Make and test code changes locally.
2. Commit and push to GitHub.
3. On VPS, run `./deploy-app`.

If a deploy step dirties `git status` on VPS, that step is wrong and must be moved out of the VPS checkout.

## Routing Contract

- SPA: `/`
- API: `/api/*`
- Sanctum: `/sanctum/*`
- Temporary compatibility shim: `/api/sanctum/* -> /sanctum/*` on host Nginx until all clients are fixed
- Canonical public verification host: `https://verify.prismcore.ru`
- Temporary legacy compatibility on `prismcore.ru`: proxy `/v/*` to the backend instead of the SPA

## Evidence Feature Rollout

The evidence pipeline is protected by 7 feature flags defined in `config/smeta.php`. All flags default to `false`. A missing `.env` entry is equivalent to `false` — no evidence code activates unless explicitly enabled.

No database migration is required when toggling these flags. After changing any flag, run `php artisan config:clear` inside the app container.

### Flags

| Env variable | Controls | Default |
|---|---|---|
| `EVIDENCE_PIPELINE_V2` | V2 evidence pipeline dispatcher (replaces legacy observation flow) | `false` |
| `EVIDENCE_FACADE_ENABLED` | Facade cost driver evidence collection | `false` |
| `EVIDENCE_OPERATIONS_ENABLED` | Operation cost driver evidence collection | `false` |
| `EVIDENCE_LABOR_WORK_ENABLED` | Labor work cost driver evidence collection | `false` |
| `EVIDENCE_EXPENSES_ENABLED` | Expense cost driver evidence collection | `false` |
| `EVIDENCE_EXPENSES_DOCUMENT_ENABLED` | Expense document attachment upload | `false` |
| `EVIDENCE_CHROME_REVISION_ENABLED` | Chrome extension revision panel endpoints | `false` |

### Activation Order

Enable flags in this order. Each step can be deployed and verified independently.

**Step 1 — Pipeline dispatcher**

```
EVIDENCE_PIPELINE_V2=true
```

Prerequisite: none. This is the master gate — all per-driver flags below are evaluated inside the V2 pipeline. Without this flag, the legacy observation flow runs instead.

Verify: start a revision run via `POST /api/projects/{project}/revisions/run`. Confirm a new row appears in the `revision_runs` table and items are processed through `EvidencePipelineService`.

**Step 2 — Cost driver evidence collection**

Enable individually or together based on rollout preference:

```
EVIDENCE_FACADE_ENABLED=true
EVIDENCE_OPERATIONS_ENABLED=true
EVIDENCE_LABOR_WORK_ENABLED=true
EVIDENCE_EXPENSES_ENABLED=true
```

Prerequisite: `EVIDENCE_PIPELINE_V2=true` must be set. These flags are mutually independent — enabling one does not affect the others.

Verify: trigger a revision for items of the enabled cost driver type. Confirm `evidence_artifacts` rows are created for those items.

**Step 3 — Expense document attachments**

```
EVIDENCE_EXPENSES_DOCUMENT_ENABLED=true
```

Prerequisite: `EVIDENCE_EXPENSES_ENABLED=true` must already be set. Enabling this flag while expenses are disabled is a no-op (the endpoint returns 403), not a crash, but illogical.

Verify: upload a document via `POST /api/revisions/run/{runId}/items/{itemId}/attach-document` for an expense item. Confirm response 200 and an `evidence_assets` row with `asset_type=document`.

**Step 4 — Chrome extension revision panel**

```
EVIDENCE_CHROME_REVISION_ENABLED=true
```

Prerequisite: `EVIDENCE_PIPELINE_V2=true` must be set. Enable this last — it exposes revision endpoints to the Chrome extension.

Warning: enabling this before `EVIDENCE_PIPELINE_V2` allows the extension to create `revision_runs` rows, but the processing job routes through the legacy observation path, producing silent data inconsistency.

Verify: from the Chrome extension, confirm `GET /api/chrome/revision-items` returns 200 (not 404) and `POST /api/chrome/revision-items/{itemId}/evidence` creates evidence artifacts.

### Rollback

Set any flag back to `false` and run `php artisan config:clear`. The corresponding evidence code path becomes inactive immediately. Existing data in `revision_runs`, `evidence_artifacts`, and `evidence_assets` is preserved but no longer generated.
