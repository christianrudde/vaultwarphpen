#!/usr/bin/env bash
#
# Starts Vaultwarden and this implementation side by side, each with a data
# folder of its own and the same (dummy) web vault, and runs the Differential
# test suite against both.
#
# Usage: differential.sh <vaultwarden binary> [phpunit arguments...]
# Ports 8000-8003 (Vaultwarden), 8080-8083 (PHP) and 2501-2502 (SMTP sinks) must be free.

set -euo pipefail

binary="$(realpath "$1")"
shift
work="$(mktemp -d)"
mkdir -p "${work}/reference" "${work}/subject" "${work}/shared" "${work}/features-reference" "${work}/features-subject" \
    "${work}/mail-reference" "${work}/mail-subject" "${work}/web-vault"
echo '<!DOCTYPE html><title>Web vault</title>' > "${work}/web-vault/index.html"
export WEB_VAULT_FOLDER="${work}/web-vault/"
# Every flow logs in; Vaultwarden's limits (10 logins, then one a minute) would cut the suite short.
export LOGIN_RATELIMIT_MAX_BURST=10000 UNAUTHENTICATED_RATELIMIT_MAX_BURST=10000

pids=()
trap 'kill "${pids[@]}" 2> /dev/null || true' EXIT

# One RSA key for both: unknown emails get KDF settings derived from it.
DATA_FOLDER="${work}/subject" php artisan parity:token encode admin key --no-ansi > /dev/null
cp "${work}/subject/rsa_key.pem" "${work}/reference/rsa_key.pem"
cp "${work}/subject/rsa_key.pem" "${work}/shared/rsa_key.pem"
cp "${work}/subject/rsa_key.pem" "${work}/features-reference/rsa_key.pem"
cp "${work}/subject/rsa_key.pem" "${work}/features-subject/rsa_key.pem"
cp "${work}/subject/rsa_key.pem" "${work}/mail-reference/rsa_key.pem"
cp "${work}/subject/rsa_key.pem" "${work}/mail-subject/rsa_key.pem"

DATA_FOLDER="${work}/reference" ROCKET_PORT=8000 "${binary}" > "${work}/reference.log" 2>&1 &
pids+=($!)
# A second pair on one database: Vaultwarden creates it, we use it too.
DATA_FOLDER="${work}/shared" ROCKET_PORT=8001 "${binary}" > "${work}/shared-reference.log" 2>&1 &
pids+=($!)
# A third pair with the optional features groups and events, which change what the clients see.
DATA_FOLDER="${work}/features-reference" ORG_GROUPS_ENABLED=true ORG_EVENTS_ENABLED=true ROCKET_PORT=8002 "${binary}" > "${work}/features-reference.log" 2>&1 &
pids+=($!)
# A fourth pair sends mail, each server to an SMTP sink of its own that keeps the messages.
php .github/scripts/smtp-sink.php 2501 "${work}/mail-reference-inbox" > "${work}/sink-reference.log" 2>&1 &
pids+=($!)
php .github/scripts/smtp-sink.php 2502 "${work}/mail-subject-inbox" > "${work}/sink-subject.log" 2>&1 &
pids+=($!)
mail_env=(SMTP_HOST=127.0.0.1 SMTP_SECURITY=off SMTP_FROM=vaultwarden@example.com SMTP_TIMEOUT=5)
env DATA_FOLDER="${work}/mail-reference" "${mail_env[@]}" SMTP_PORT=2501 ROCKET_PORT=8003 "${binary}" > "${work}/mail-reference.log" 2>&1 &
pids+=($!)

# php -S rather than artisan serve, which drops most environment variables.
# Laravel's router script expects to run in the public folder.
export DATA_FOLDER="${work}/subject"
php artisan vaultwarden:migrate --no-ansi > /dev/null
DATA_FOLDER="${work}/features-subject" php artisan vaultwarden:migrate --no-ansi > /dev/null
DATA_FOLDER="${work}/mail-subject" php artisan vaultwarden:migrate --no-ansi > /dev/null
# Each PHP server has a cache of its own: the rate limits are per server, like Vaultwarden's.
(cd public && CACHE_PATH="${work}/subject-cache" exec php -d upload_max_filesize=525M -d post_max_size=0 -S 127.0.0.1:8080 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) \
    > "${work}/subject.log" 2>&1 &
pids+=($!)
(cd public && DATA_FOLDER="${work}/shared" CACHE_PATH="${work}/shared-cache" exec php -d upload_max_filesize=525M -d post_max_size=0 -S 127.0.0.1:8081 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) \
    > "${work}/shared-subject.log" 2>&1 &
pids+=($!)
(cd public && DATA_FOLDER="${work}/features-subject" ORG_GROUPS_ENABLED=true ORG_EVENTS_ENABLED=true CACHE_PATH="${work}/features-cache" exec php -d upload_max_filesize=525M -d post_max_size=0 -S 127.0.0.1:8082 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) \
    > "${work}/features-subject.log" 2>&1 &
pids+=($!)
(cd public && exec env DATA_FOLDER="${work}/mail-subject" "${mail_env[@]}" SMTP_PORT=2502 CACHE_PATH="${work}/mail-cache" \
    php -d upload_max_filesize=525M -d post_max_size=0 -S 127.0.0.1:8083 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) \
    > "${work}/mail-subject.log" 2>&1 &
pids+=($!)

for url in http://127.0.0.1:{8000,8001,8002,8003,8080,8081,8082,8083}/alive; do
    for attempt in $(seq 60); do
        curl -fsS "${url}" > /dev/null 2>&1 && break
        if [[ "${attempt}" == 60 ]]; then
            cat "${work}"/*.log
            echo "::error::${url} did not answer."
            exit 1
        fi
        sleep 1
    done
done

status=0
VW_REFERENCE_URL=http://127.0.0.1:8000 VW_SUBJECT_URL=http://127.0.0.1:8080 \
    VW_SHARED_REFERENCE_URL=http://127.0.0.1:8001 VW_SHARED_SUBJECT_URL=http://127.0.0.1:8081 \
    VW_FEATURES_REFERENCE_URL=http://127.0.0.1:8002 VW_FEATURES_SUBJECT_URL=http://127.0.0.1:8082 \
    VW_MAIL_REFERENCE_URL=http://127.0.0.1:8003 VW_MAIL_SUBJECT_URL=http://127.0.0.1:8083 \
    VW_MAIL_REFERENCE_DIR="${work}/mail-reference-inbox" VW_MAIL_SUBJECT_DIR="${work}/mail-subject-inbox" \
    VW_RSA_KEY="${work}/subject/rsa_key.pem" \
    vendor/bin/phpunit --testsuite Differential "$@" || status=$?
if [[ "${status}" != 0 ]]; then
    for log in "${work}"/*.log; do
        echo "--- ${log}"
        tail -30 "${log}"
    done
fi
exit "${status}"
