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
