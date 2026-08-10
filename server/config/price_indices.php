<?php

return [
    'enabled' => env('PRICE_INDICES_ENABLED', false),
    'admin_only' => env('PRICE_INDICES_ADMIN_ONLY', true),

    'source_files' => [
        'max_upload_bytes' => 67_108_864,
        'max_download_bytes' => 67_108_864,
        'storage_disk' => 'local',
        'temp_directory' => 'price-indices/tmp',
    ],

    'xlsx' => [
        'max_zip_entries' => 5_000,
        'max_single_entry_uncompressed_bytes' => 67_108_864,
        'max_total_uncompressed_bytes' => 536_870_912,
        'max_compression_ratio' => 200,
        'allowed_mime_types' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream',
        ],
    ],

    'imports' => [
        'chunk_rows' => (int) env('PRICE_INDICES_IMPORT_CHUNK_ROWS', 2_000),
        'importers' => [
            'producer_price_indices_by_product' => [
                'code' => 'producer_price_indices_by_product',
                'version' => '1.0.0',
            ],
        ],
    ],
];
