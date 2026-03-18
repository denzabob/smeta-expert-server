# SMS.ru Official PHP Library Integration

Copy official SMS.ru files into this directory:

- sms.ru.php
- callback.php (optional, for reference only)

This project uses Laravel webhook route for callbacks:

- POST /api/auth/phone/call/webhook

Supported payloads:

- Native callcheck webhook fields: check_id + check_status
- Official callback batch format: data[] entries (line-based), with plain "100" ACK

## Enable official library in backend

Set these env vars in server/.env:

- SMSRU_OFFICIAL_LIBRARY_ENABLED=true
- SMSRU_OFFICIAL_LIBRARY_PATH=/var/www/html/integrations/smsru/official/sms.ru.php
- SMSRU_OFFICIAL_LIBRARY_CLASS=SMSRU

Then clear config cache:

- php artisan config:clear

## Notes

- If official library methods are unavailable for callcheck in your version, code automatically falls back to direct HTTP API calls.
- In test mode (`VERIFICATION_TEST_MODE=true`), call number is taken from:
  - SMSRU_CALLCHECK_TEST_PHONE
  - SMSRU_CALLCHECK_TEST_PHONE_PRETTY
