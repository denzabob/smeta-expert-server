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
  'suppliers' => 
  array (
    'skm_mebel' => 'СКМ Мебель',
    'template' => 'Template Supplier',
  ),
);
