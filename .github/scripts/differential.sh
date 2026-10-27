#!/usr/bin/env bash
#
# Starts Vaultwarden and this implementation side by side, each with a data
# folder of its own and the same (dummy) web vault, and runs the Differential
# test suite against both.
#
# Usage: differential.sh <vaultwarden binary> [phpunit arguments...]
# Ports 8000 (Vaultwarden) and 8080 (PHP) must be free.

set -euo pipefail

binary="$(realpath "$1")"
shift
work="$(mktemp -d)"
mkdir -p "${work}/reference" "${work}/subject" "${work}/web-vault"
echo '<!DOCTYPE html><title>Web vault</title>' > "${work}/web-vault/index.html"
export WEB_VAULT_FOLDER="${work}/web-vault/"

pids=()
trap 'kill "${pids[@]}" 2> /dev/null || true' EXIT

# One RSA key for both: unknown emails get KDF settings derived from it.
DATA_FOLDER="${work}/subject" php artisan parity:token encode admin key --no-ansi > /dev/null
cp "${work}/subject/rsa_key.pem" "${work}/reference/rsa_key.pem"

DATA_FOLDER="${work}/reference" ROCKET_PORT=8000 "${binary}" > "${work}/reference.log" 2>&1 &
pids+=($!)

# php -S rather than artisan serve, which drops most environment variables.
# Laravel's router script expects to run in the public folder.
export DATA_FOLDER="${work}/subject"
php artisan vaultwarden:migrate --no-ansi > /dev/null
(cd public && exec php -S 127.0.0.1:8080 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) \
    > "${work}/subject.log" 2>&1 &
pids+=($!)

for url in http://127.0.0.1:8000/alive http://127.0.0.1:8080/alive; do
    for attempt in $(seq 60); do
        curl -fsS "${url}" > /dev/null 2>&1 && break
        if [[ "${attempt}" == 60 ]]; then
            cat "${work}/reference.log" "${work}/subject.log"
            echo "::error::${url} did not answer."
            exit 1
        fi
        sleep 1
    done
done

status=0
VW_REFERENCE_URL=http://127.0.0.1:8000 VW_SUBJECT_URL=http://127.0.0.1:8080 \
    vendor/bin/phpunit --testsuite Differential "$@" || status=$?
if [[ "${status}" != 0 ]]; then
    echo '--- PHP server log'
    tail -50 "${work}/subject.log"
fi
exit "${status}"
