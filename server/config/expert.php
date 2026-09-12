<?php
return [
    'material_max_kib' => (int) env('EXPERT_MATERIAL_MAX_KIB', 51200),
    'material_extensions' => ['pdf', 'docx', 'xlsx', 'jpg', 'jpeg', 'png', 'webp'],
    'material_mime_types' => [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg', 'image/png', 'image/webp',
    ],
    'storage_cleanup_retry_delays_seconds' => [60, 300, 1800, 7200, 86400],
];
