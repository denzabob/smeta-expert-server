<?php

namespace App\Services\Billing;

class BillingCodes
{
    public const FEATURE_PROJECTS_CREATE = 'projects.create';

    public const FEATURE_PROJECTS_ARCHIVE = 'projects.archive';

    public const FEATURE_PROJECTS_ACTIVE = 'projects.active';

    public const FEATURE_PDF_SMETA = 'pdf.smeta';

    public const FEATURE_PDF_REVISION = 'pdf.revision';

    public const FEATURE_PDF_PRICE_JUSTIFICATION = 'pdf.price_justification';

    public const FEATURE_PDF_EVIDENCE = 'pdf.evidence';

    public const FEATURE_REVISIONS = 'revisions';

    public const FEATURE_REVISION_EVIDENCE = 'revision_evidence';

    public const FEATURE_EVIDENCE_RUNS = 'evidence.runs';

    public const FEATURE_EVIDENCE_REFRESH = 'evidence.refresh';

    public const FEATURE_EVIDENCE_FINALIZE = 'evidence.finalize';

    public const FEATURE_EVIDENCE_RESOLVE = 'evidence.resolve';

    public const FEATURE_EVIDENCE_MANUAL_UPLOAD = 'evidence.manual_upload';

    public const FEATURE_EVIDENCE_ASSETS = 'evidence.assets';

    public const FEATURE_CHROME_EXTRACT = 'chrome.extract';

    public const FEATURE_CHROME_EXTRACT_WITH_EVIDENCE = 'chrome.extract_with_evidence';

    public const FEATURE_EVIDENCE_CHROME_CAPTURE = 'evidence.chrome_capture';

    public const FEATURE_CHROME_REVISION_EVIDENCE = 'chrome.revision_evidence';

    public const FEATURE_PRICE_LISTS = 'price_lists';

    public const FEATURE_PRICE_IMPORT = 'price_import';

    public const FEATURE_PARSER = 'parser';

    public const FEATURE_AI_WORK_DECOMPOSE = 'ai.work_decompose';

    public const FEATURE_ADMIN_BILLING = 'admin.billing';

    public const METRIC_PROJECTS_CREATED = 'projects.created';

    public const METRIC_PROJECTS_ARCHIVED = 'projects.archived';

    public const METRIC_PROJECTS_ACTIVE_COUNT = 'projects.active.count';

    public const METRIC_PDF_SMETA_GENERATED = 'pdf.smeta.generated';

    public const METRIC_PDF_REVISION_GENERATED = 'pdf.revision.generated';

    public const METRIC_PDF_PRICE_JUSTIFICATION_GENERATED = 'pdf.price_justification.generated';

    public const METRIC_PDF_EVIDENCE_RUN_GENERATED = 'pdf.evidence_run.generated';

    public const METRIC_REVISIONS_CREATED = 'revisions.created';

    public const METRIC_REVISION_RUNS_STARTED = 'revision_runs.started';

    public const METRIC_REVISION_RUNS_FINALIZED = 'revision_runs.finalized';

    public const METRIC_EVIDENCE_RUNS_CREATED = 'evidence_runs.created';

    public const METRIC_EVIDENCE_RUNS_REFRESHED = 'evidence_runs.refreshed';

    public const METRIC_EVIDENCE_RUNS_FINALIZED = 'evidence_runs.finalized';

    public const METRIC_EVIDENCE_ITEMS_RESOLVED = 'evidence_items.resolved';

    public const METRIC_EVIDENCE_ITEMS_SKIPPED = 'evidence_items.skipped';

    public const METRIC_EVIDENCE_MANUAL_UPLOADS = 'evidence.manual_uploads';

    public const METRIC_EVIDENCE_ASSETS_UPLOADED = 'evidence_assets.uploaded';

    public const METRIC_STORAGE_BYTES_UPLOADED = 'storage.bytes_uploaded';

    public const METRIC_CHROME_EXTRACTS = 'chrome.extracts';

    public const METRIC_CHROME_EXTRACT_WITH_EVIDENCE = 'chrome.extract_with_evidence';

    public const METRIC_EVIDENCE_CHROME_CAPTURES = 'evidence.chrome_captures';

    public const METRIC_EVIDENCE_CHROME_ITEM_CAPTURES = 'evidence.chrome_item_captures';

    public const METRIC_PRICE_LISTS_UPLOADED = 'price_lists.uploaded';

    public const METRIC_PRICE_IMPORTS_CREATED = 'price_imports.created';

    public const METRIC_PARSER_SESSIONS_STARTED = 'parser.sessions.started';

    public const METRIC_AI_WORK_DECOMPOSE_REQUESTS = 'ai.work_decompose.requests';

    public static function features(): array
    {
        return [
            self::FEATURE_PROJECTS_CREATE,
            self::FEATURE_PROJECTS_ARCHIVE,
            self::FEATURE_PROJECTS_ACTIVE,
            self::FEATURE_PDF_SMETA,
            self::FEATURE_PDF_REVISION,
            self::FEATURE_PDF_PRICE_JUSTIFICATION,
            self::FEATURE_PDF_EVIDENCE,
            self::FEATURE_REVISIONS,
            self::FEATURE_REVISION_EVIDENCE,
            self::FEATURE_EVIDENCE_RUNS,
            self::FEATURE_EVIDENCE_REFRESH,
            self::FEATURE_EVIDENCE_FINALIZE,
            self::FEATURE_EVIDENCE_RESOLVE,
            self::FEATURE_EVIDENCE_MANUAL_UPLOAD,
            self::FEATURE_EVIDENCE_ASSETS,
            self::FEATURE_CHROME_EXTRACT,
            self::FEATURE_CHROME_EXTRACT_WITH_EVIDENCE,
            self::FEATURE_EVIDENCE_CHROME_CAPTURE,
            self::FEATURE_CHROME_REVISION_EVIDENCE,
            self::FEATURE_PRICE_LISTS,
            self::FEATURE_PRICE_IMPORT,
            self::FEATURE_PARSER,
            self::FEATURE_AI_WORK_DECOMPOSE,
            self::FEATURE_ADMIN_BILLING,
        ];
    }

    public static function metrics(): array
    {
        return [
            self::METRIC_PROJECTS_CREATED,
            self::METRIC_PROJECTS_ARCHIVED,
            self::METRIC_PROJECTS_ACTIVE_COUNT,
            self::METRIC_PDF_SMETA_GENERATED,
            self::METRIC_PDF_REVISION_GENERATED,
            self::METRIC_PDF_PRICE_JUSTIFICATION_GENERATED,
            self::METRIC_PDF_EVIDENCE_RUN_GENERATED,
            self::METRIC_REVISIONS_CREATED,
            self::METRIC_REVISION_RUNS_STARTED,
            self::METRIC_REVISION_RUNS_FINALIZED,
            self::METRIC_EVIDENCE_RUNS_CREATED,
            self::METRIC_EVIDENCE_RUNS_REFRESHED,
            self::METRIC_EVIDENCE_RUNS_FINALIZED,
            self::METRIC_EVIDENCE_ITEMS_RESOLVED,
            self::METRIC_EVIDENCE_ITEMS_SKIPPED,
            self::METRIC_EVIDENCE_MANUAL_UPLOADS,
            self::METRIC_EVIDENCE_ASSETS_UPLOADED,
            self::METRIC_STORAGE_BYTES_UPLOADED,
            self::METRIC_CHROME_EXTRACTS,
            self::METRIC_CHROME_EXTRACT_WITH_EVIDENCE,
            self::METRIC_EVIDENCE_CHROME_CAPTURES,
            self::METRIC_EVIDENCE_CHROME_ITEM_CAPTURES,
            self::METRIC_PRICE_LISTS_UPLOADED,
            self::METRIC_PRICE_IMPORTS_CREATED,
            self::METRIC_PARSER_SESSIONS_STARTED,
            self::METRIC_AI_WORK_DECOMPOSE_REQUESTS,
        ];
    }
}
