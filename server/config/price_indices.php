<?php

$publicUrl = rtrim((string) env('PRICE_INDICES_PUBLIC_URL', 'https://indices.prismcore.ru'), '/');

return [
    'enabled' => env('PRICE_INDICES_ENABLED', false),
    'admin_only' => env('PRICE_INDICES_ADMIN_ONLY', true),
    'public_url' => $publicUrl,
    'public_host' => (string) (parse_url($publicUrl, PHP_URL_HOST) ?: ''),
    'brand_url' => env('PRICE_INDICES_BRAND_URL'),
    'yandex_metrika_id' => env('PRICE_INDICES_YANDEX_METRIKA_ID', '111537697'),

    'public_calculation' => [
        'max_period_months' => 120,
        'max_amount' => '999999999999999.99',
        'throttle_per_minute' => 20,
    ],

    'source_files' => [
        'max_upload_bytes' => 67_108_864,
        'max_download_bytes' => 67_108_864,
        'storage_disk' => 'local',
        'temp_directory' => 'price-indices/tmp',
    ],

    'classifier_acquisition' => [
        'storage_disk' => 'price_indices_classifier_artifacts',
        'temp_directory' => '.tmp',
        'max_size_bytes' => (int) env('PRICE_INDICES_CLASSIFIER_MAX_SIZE_BYTES', 20_971_520),
        'timeout_seconds' => (int) env('PRICE_INDICES_CLASSIFIER_TIMEOUT_SECONDS', 60),
        'connect_timeout_seconds' => (int) env('PRICE_INDICES_CLASSIFIER_CONNECT_TIMEOUT_SECONDS', 10),
        'max_redirects' => (int) env('PRICE_INDICES_CLASSIFIER_MAX_REDIRECTS', 5),
        'descriptors' => [
            'okpd2' => [
                'code' => 'okpd2',
                'standard_code' => 'ОК 034-2014 (КПЕС 2008)',
                'name' => 'Общероссийский классификатор продукции по видам экономической деятельности',
                'issuing_authority' => 'Росстандарт',
                'official_distributor' => 'Росстат',
                'source_page_url' => 'https://rosstat.gov.ru/classification',
                'download_url' => 'https://rosstat.gov.ru/storage/mediabank/OKPD2.zip',
                'allowed_hosts' => ['rosstat.gov.ru'],
                'artifact_type' => 'zip',
                'original_filename' => 'OKPD2.zip',
                'allowed_mime_types' => [
                    'application/zip',
                    'application/x-zip-compressed',
                    'application/octet-stream',
                ],
            ],
        ],
    ],

    'classifier_parsers' => [
        'okpd2_rosstat_docx' => [
            'version' => 1,
            'parts' => [
                ['filename' => 'TIZ_OKPD2_1.docx', 'sections' => range('A', 'D')],
                ['filename' => 'TIZ_OKPD2_2.docx', 'sections' => range('E', 'U')],
            ],
            'minimum_digital_nodes' => 10_000,
            'outer_zip' => [
                'max_entries' => 8,
                'max_single_entry_uncompressed_bytes' => 20_971_520,
                'max_total_uncompressed_bytes' => 41_943_040,
                'max_compression_ratio' => 200,
            ],
            'docx_zip' => [
                'max_entries' => 256,
                'max_single_entry_uncompressed_bytes' => 67_108_864,
                'max_total_uncompressed_bytes' => 134_217_728,
                'max_compression_ratio' => 200,
            ],
            'max_document_xml_bytes' => 50_331_648,
            'max_control_xml_bytes' => 1_048_576,
        ],
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
        'db_batch_size' => 500,
        'preview_sample_limit' => 50,
        'preview_cache_ttl_hours' => 24,
        'preview_job_timeout' => 180,
        'preview_job_tries' => 1,
        'preview_lock_ttl' => 300,
        'preview_lock_wait_seconds' => 5,
        'job_timeout' => 3_600,
        'job_tries' => 1,
        'job_backoff' => 60,
        'max_sheets' => 64,
        'max_rows_per_sheet' => 30_000,
        'importers' => [
            'producer_price_indices_by_product' => [
                'code' => 'producer_price_indices_by_product',
                'version' => '1.0.0',
            ],
        ],
    ],

    'api' => [
        'imports_per_page' => 25,
        'issues_per_page' => 100,
        'observations_per_page' => 100,
        'max_page_size' => 500,
    ],
];
