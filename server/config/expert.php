<?php
return [
    'chat' => [
        'history_limit' => (int) env('EXPERT_CHAT_HISTORY_LIMIT', 20),
        'idempotency_lock_seconds' => (int) env('EXPERT_CHAT_IDEMPOTENCY_LOCK_SECONDS', 900),
        'idempotency_wait_seconds' => (int) env('EXPERT_CHAT_IDEMPOTENCY_WAIT_SECONDS', 5),
    ],
    'material_context' => [
        'xlsx_enabled' => true,
        'max_materials_per_message' => (int) env('EXPERT_CHAT_MAX_MATERIALS_PER_MESSAGE', 5),
        'max_total_material_bytes' => (int) env('EXPERT_CHAT_MAX_TOTAL_MATERIAL_BYTES', 10 * 1024 * 1024),
        'max_material_bytes' => (int) env('EXPERT_CHAT_MAX_MATERIAL_BYTES', 5 * 1024 * 1024),
        'max_extracted_chars_per_material' => (int) env('EXPERT_CHAT_MAX_EXTRACTED_CHARS_PER_MATERIAL', 30000),
        'max_total_extracted_chars' => (int) env('EXPERT_CHAT_MAX_TOTAL_EXTRACTED_CHARS', 80000),
        'max_spreadsheet_uncompressed_bytes' => (int) env('EXPERT_CHAT_MAX_SPREADSHEET_UNCOMPRESSED_BYTES', 20 * 1024 * 1024),
        'max_spreadsheet_cells' => (int) env('EXPERT_CHAT_MAX_SPREADSHEET_CELLS', 5000),
        'max_docx_archive_entries' => (int) env('EXPERT_CHAT_MAX_DOCX_ARCHIVE_ENTRIES', 200),
        'max_docx_uncompressed_bytes' => (int) env('EXPERT_CHAT_MAX_DOCX_UNCOMPRESSED_BYTES', 20 * 1024 * 1024),
        'max_docx_xml_entry_bytes' => (int) env('EXPERT_CHAT_MAX_DOCX_XML_ENTRY_BYTES', 5 * 1024 * 1024),
        'max_xlsx_archive_entries' => (int) env('EXPERT_CHAT_MAX_XLSX_ARCHIVE_ENTRIES', 500),
        'max_xlsx_xml_entry_bytes' => (int) env('EXPERT_CHAT_MAX_XLSX_XML_ENTRY_BYTES', 5 * 1024 * 1024),
        'max_zip_compression_ratio' => (int) env('EXPERT_CHAT_MAX_ZIP_COMPRESSION_RATIO', 100),
    ],
    'material_max_kib' => (int) env('EXPERT_MATERIAL_MAX_KIB', 51200),
    'material_extensions' => ['txt', 'md', 'pdf', 'docx', 'xlsx', 'jpg', 'jpeg', 'png', 'webp'],
    'material_mime_types' => [
        'text/plain', 'text/markdown',
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg', 'image/png', 'image/webp',
    ],
    'storage_cleanup_retry_delays_seconds' => [60, 300, 1800, 7200, 86400],
];
