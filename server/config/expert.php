<?php
return [
    'chat' => [
        'history_limit' => (int) env('EXPERT_CHAT_HISTORY_LIMIT', 20),
        'idempotency_lock_seconds' => (int) env('EXPERT_CHAT_IDEMPOTENCY_LOCK_SECONDS', 900),
        'idempotency_wait_seconds' => (int) env('EXPERT_CHAT_IDEMPOTENCY_WAIT_SECONDS', 5),
    ],
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
