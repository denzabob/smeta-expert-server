Смысл:



не менять публичные auth endpoints без явного указания;

не ослаблять anti-enumeration;

не логировать секреты, токены, PIN, reset codes;

все auth changes должны сопровождаться targeted tests;

любые cookie changes должны явно проверяться на HttpOnly, Secure, SameSite;

любые password/reset/login changes должны проверять revocation policy;

любые CSRF exemptions должны быть узкими и документированными;

любые email auth flows должны использовать TTL, throttling, hashed storage for codes/tokens where applicable;

не вводить новый auth factor без product requirement.

