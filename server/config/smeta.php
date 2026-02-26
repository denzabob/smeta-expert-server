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
];
