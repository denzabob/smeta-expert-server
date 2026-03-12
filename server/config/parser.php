<?php

return array (
  'callback_url' => 'http://web/api/internal/parser/callback',
  'callback_token' => 'test-secret-parser-token',
  'hmac_secret' => env('PARSER_HMAC_SECRET', 'default-hmac-secret'),
  'python_path' => 'python3',
  'timeout' => '3600',
  'log_buffer_size' => '1',
  'limit' => '10',
  'request_delay' => '0.5',
  'queue' => 'default',
  'allowed_ips' => 
  array (
    0 => '127.0.0.1',
    1 => '::1',
  ),
  'heartbeat_timeout' => 10,
  'log_retention_days' => 14,
  'max_logs_per_session' => 100,
  'auto_cleanup' => true,
  'screenshot_process_timeout_seconds' => (int) env('SCREENSHOT_PROCESS_TIMEOUT_SECONDS', 60),
  'screenshot_navigation_timeout_ms' => (int) env('SCREENSHOT_NAVIGATION_TIMEOUT_MS', 20000),
  'screenshot_total_timeout_seconds' => (int) env('SCREENSHOT_TOTAL_TIMEOUT_SECONDS', 45),
  'screenshot_max_parallel' => (int) env('SCREENSHOT_MAX_PARALLEL', 3),
  'screenshot_slot_wait_seconds' => (int) env('SCREENSHOT_SLOT_WAIT_SECONDS', 30),
  'suppliers' => 
  array (
    'skm_mebel' => 'СКМ Мебель',
    'template' => 'Template Supplier',
  ),
);
