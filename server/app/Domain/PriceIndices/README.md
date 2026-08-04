# Price Indices domain

This domain contains the isolated backend boundary for the "PRIZMA Price Indices" application.

Current stage: `skeleton`.

Allowed shared dependencies are authentication through Sanctum, the shared `User` identity, and the `admin` and `superadmin` roles.

The domain must not depend on estimate projects, project positions or revisions, estimate calculators, reports, material/operation/labor catalogs, existing price tables, or estimate snapshots.

No statistical datasets, persistence models, imports, XLSX parsing, or index calculation logic are implemented at this stage.
