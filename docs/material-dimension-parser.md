# Material Dimension Parser Architecture

## Overview

`App\Services\MaterialDimensionParser` is the single entry point for automatic dimension extraction.

Pipeline order:
1. Normalize raw input text (`DimensionTextNormalizer`)
2. Apply built-in strategies (deterministic order)
3. Apply managed DB rules (`material_dimension_rules`)
4. Return structured parse result
5. Log failed parse case (`material_dimension_parse_failures`) with deduplication

## Structured Result

`DimensionParseResult::toArray()` returns:
- `success`
- `length_mm`
- `width_mm`
- `thickness_mm`
- `confidence`
- `source`
- `rule_type`
- `strategy_name`
- `normalized_text`
- `error_reason`
- `rule_id`
- `meta`

## Built-in Strategies

Current built-in strategies:
- `plate_lxwxt`
- `plate_lxw_plus_thickness`
- `plate_lxw`
- `edge_width_thickness`

To add a new built-in strategy:
1. Implement `App\Services\MaterialDimensions\Contracts\DimensionParseStrategy`
2. Return `DimensionParseResult` on match, otherwise `null`
3. Inject and register it in `MaterialDimensionParser` constructor in required order
4. Add unit tests for the new strategy

## Managed Rules (DB)

Table: `material_dimension_rules`

Each active rule supports:
- `is_active`, `priority`
- scope by `material_type` and optional `source`
- `rule_type` (currently `regex`)
- `config` JSON

Regex config shape example:

```json
{
  "pattern": "\\b(\\d{4})\\s*x\\s*(\\d{4})\\s*x\\s*(\\d{2})\\b",
  "flags": "u",
  "use_normalized_text": true,
  "captures": {
    "length_mm": 1,
    "width_mm": 2,
    "thickness_mm": 3
  },
  "fixed": {
    "thickness_mm": 16
  }
}
```

Validation is enforced by `UpsertMaterialDimensionRuleRequest`:
- regex pattern syntax
- required capture/fixed mappings
- plate/edge require both length and width mapping

## Failed Parse Cases

Table: `material_dimension_parse_failures`

Stored fields include:
- `raw_text`, `normalized_text`
- `material_type`, `source`
- `parse_error_reason`
- `occurrences` (deduplicated by fingerprint)
- optional resolved dimensions and resolution metadata

Admin API:
- `GET /api/admin/material-dimension-failures`
- `GET /api/admin/material-dimension-failures/{id}`
- `PATCH /api/admin/material-dimension-failures/{id}`

## Admin APIs

Managed rules API:
- `GET /api/admin/material-dimension-rules`
- `POST /api/admin/material-dimension-rules`
- `GET /api/admin/material-dimension-rules/{id}`
- `PUT/PATCH /api/admin/material-dimension-rules/{id}`
- `DELETE /api/admin/material-dimension-rules/{id}`

All admin endpoints are protected by policies (`user_id = 1`).

## Integration Points

Central parser is used by:
- `MaterialController` (store/update)
- `ChromeExtractService` and chrome preview validation
- `MaterialNormalizer` (`materials:normalize` command + `NormalizeMaterial` job)

Manual values always have priority via `MaterialDimensionParser::mergeWithManual()`.
