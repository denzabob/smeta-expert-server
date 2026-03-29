<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Smeta Calculation Engine Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the smeta calculation engine and versioning
    |
    */

    'calculation_engine_version' => env('CALCULATION_ENGINE_VERSION', '1.0.0'),
    
    /*
    |--------------------------------------------------------------------------
    | Snapshot Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for project snapshot/revision management
    |
    */
    
    'snapshot' => [
        // Maximum number of revisions per project (0 = unlimited)
        'max_revisions_per_project' => env('SMETA_MAX_REVISIONS', 0),
        
        // Automatically prune old revisions when limit is reached
        'auto_prune' => env('SMETA_AUTO_PRUNE_REVISIONS', false),
        
        // Compression for snapshot JSON (none, gzip)
        'compression' => env('SMETA_SNAPSHOT_COMPRESSION', 'none'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Evidence Pipeline
    |--------------------------------------------------------------------------
    |
    | Feature flags for staged rollout of the price evidence pipeline.
    |
    */
    'evidence' => [
        'pipeline_v2' => env('EVIDENCE_PIPELINE_V2', false),
        'facade_enabled' => env('EVIDENCE_FACADE_ENABLED', false),
        'chrome_revision_enabled' => env('EVIDENCE_CHROME_REVISION_ENABLED', false),
        'operations_enabled' => env('EVIDENCE_OPERATIONS_ENABLED', false),
        'labor_work_enabled' => env('EVIDENCE_LABOR_WORK_ENABLED', false),
        'expenses_enabled' => env('EVIDENCE_EXPENSES_ENABLED', false),
        'expenses_document_enabled' => env('EVIDENCE_EXPENSES_DOCUMENT_ENABLED', false),
        'generic_chrome_enabled' => env('EVIDENCE_GENERIC_CHROME_ENABLED', false),
    ],
];
