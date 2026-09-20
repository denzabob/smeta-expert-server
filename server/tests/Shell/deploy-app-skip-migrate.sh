#!/bin/sh
set -eu

test_dir=$(mktemp -d)
trap 'rm -rf "$test_dir"' EXIT HUP INT TERM
mkdir "$test_dir/bin"
tr -d '\r' > "$test_dir/deploy-app"
chmod +x "$test_dir/deploy-app"
printf 'MIGRATION_DB_USERNAME=test\nMIGRATION_DB_PASSWORD=test\n' > "$test_dir/.deploy.env"
printf '#!/bin/sh\nexit 0\n' > "$test_dir/backup-db.sh"
chmod +x "$test_dir/backup-db.sh"

cat > "$test_dir/bin/docker" <<'MOCK'
#!/bin/sh
printf '%s\n' "$*" >> "$CALLS"
case "$*" in
  *'exec -T db '*) echo 3 ;;
  *'sha256sum /var/www/html/vendor/composer/installed.php'*) echo 'same-lock-runtime  /var/www/html/vendor/composer/installed.php' ;;
  *'inspect -f '*) echo healthy ;;
esac
MOCK
printf '#!/bin/sh\nexit 0\n' > "$test_dir/bin/git"
printf '#!/bin/sh\nexit 0\n' > "$test_dir/bin/sleep"
chmod +x "$test_dir/bin/docker" "$test_dir/bin/git" "$test_dir/bin/sleep"
export CALLS="$test_dir/calls"
PATH="$test_dir/bin:$PATH"
export PATH
cd "$test_dir"

./deploy-app --skip-migrate > "$test_dir/skip-output"
grep -q 'exec -T app composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction' "$CALLS"
grep -q 'exec -T worker composer check-platform-reqs --no-dev' "$CALLS"
grep -q 'restart app worker' "$CALLS"
grep -q 'Migrations skipped by request' "$test_dir/skip-output"
if grep -q 'artisan migrate --force' "$CALLS"; then
  echo 'skip mode called migrate' >&2
  exit 1
fi

: > "$CALLS"
./deploy-app > "$test_dir/default-output"
grep -q 'artisan migrate --force' "$CALLS"
if grep -q 'Migrations skipped by request' "$test_dir/default-output"; then
  echo 'default mode skipped migrations' >&2
  exit 1
fi

echo 'deploy-app skip/default migration dry-run: PASS'
