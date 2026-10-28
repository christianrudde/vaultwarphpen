#!/usr/bin/env bash
#
# Starts Vaultwarden and this implementation side by side, each with a data
# folder of its own and the same (dummy) web vault, and runs the Differential
# test suite against both.
#
# Usage: differential.sh <vaultwarden binary> [phpunit arguments...]
# Ports 8000-8003 (Vaultwarden), 8080-8083 (PHP), 2501-2502 (SMTP sinks) and 2503 (fake Yubico
# and Duo services) must be free.

set -euo pipefail

binary="$(realpath "$1")"
shift
work="$(mktemp -d)"
mkdir -p "${work}/reference" "${work}/subject" "${work}/shared" "${work}/features-reference" "${work}/features-subject" \
    "${work}/mail-reference" "${work}/mail-subject" "${work}/web-vault/app" "${work}/web-vault/images"
# A few files for tests/Differential/WebVaultTest, without a version file.
echo '<!DOCTYPE html><title>Web vault</title>' > "${work}/web-vault/index.html"
echo 'console.log("web vault");' > "${work}/web-vault/app/main.js"
echo 'body{margin:0}' > "${work}/web-vault/app/STYLE.CSS"
echo 'data' > "${work}/web-vault/app/data.unknown"
echo '<svg xmlns="http://www.w3.org/2000/svg"/>' > "${work}/web-vault/images/icon.svg"
echo '<!DOCTYPE html><title>Connector</title>' > "${work}/web-vault/webauthn-connector.html"
echo 'secret' > "${work}/web-vault/.hidden"
export WEB_VAULT_FOLDER="${work}/web-vault/"
# Every flow logs in; Vaultwarden's limits (10 logins, then one a minute) would cut the suite short.
export LOGIN_RATELIMIT_MAX_BURST=10000 UNAUTHENTICATED_RATELIMIT_MAX_BURST=10000 ADMIN_RATELIMIT_MAX_BURST=10000
# The admin panel: a plain token on the default pair, an Argon2id PHC string of another one
# on the mail pair (tests/Differential/AdminTest). The pair with groups and events has none.
admin_token=differential-admin-token
mail_admin_token='$argon2id$v=19$m=19456,t=2,p=1$1u9NMozy1wIOfBlcUShBoA$QV3xAUsbGY31IC8a82I+DXWOcb6PBY7xD3/yupWvJ3k'

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

DATA_FOLDER="${work}/reference" ADMIN_TOKEN="${admin_token}" ROCKET_PORT=8000 "${binary}" > "${work}/reference.log" 2>&1 &
pids+=($!)
# A second pair on one database: Vaultwarden creates it, we use it too.
DATA_FOLDER="${work}/shared" ROCKET_PORT=8001 "${binary}" > "${work}/shared-reference.log" 2>&1 &
pids+=($!)
# A third pair with the optional features groups and events, which change what the clients see,
# and YubiKey and Duo two-factor login against fake services over HTTPS. Their certificate
# authority is the only one these servers trust.
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj '/CN=Differential CA' -addext 'basicConstraints=critical,CA:TRUE' \
    -addext 'keyUsage=critical,keyCertSign' -keyout "${work}/ca.key" -out "${work}/ca.pem" 2> /dev/null
openssl req -newkey rsa:2048 -nodes -subj '/CN=127.0.0.1' -keyout "${work}/services.key" -out "${work}/services.csr" 2> /dev/null
printf 'subjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n' \
    > "${work}/services.ext"
openssl x509 -req -in "${work}/services.csr" -CA "${work}/ca.pem" -CAkey "${work}/ca.key" -CAcreateserial -days 2 \
    -extfile "${work}/services.ext" -out "${work}/services.pem" 2> /dev/null
duo_ikey=DIDIFFERENTIALGLOBAL
features_env=(ORG_GROUPS_ENABLED=true ORG_EVENTS_ENABLED=true SSL_CERT_FILE="${work}/ca.pem" HTTP_REQUEST_BLOCK_NON_GLOBAL_IPS=false
    YUBICO_CLIENT_ID=1 YUBICO_SECRET_KEY=MDEyMzQ1Njc4OWFiY2RlZmdoaWo= YUBICO_SERVER=https://127.0.0.1:2503/wsapi/2.0/verify
    DUO_IKEY="${duo_ikey}" DUO_SKEY="$(php -r "echo substr(hash('sha256', 'duo:${duo_ikey}'), 0, 40);")" DUO_HOST=127.0.0.1:2503)
env YUBICO_SECRET_KEY=MDEyMzQ1Njc4OWFiY2RlZmdoaWo= php .github/scripts/fake-services.php 2503 "${work}/services.pem" "${work}/services.key" \
    "${work}/services-state" > "${work}/services.log" 2>&1 &
pids+=($!)
env DATA_FOLDER="${work}/features-reference" "${features_env[@]}" ROCKET_PORT=8002 "${binary}" > "${work}/features-reference.log" 2>&1 &
pids+=($!)
# A fourth pair sends mail, each server to an SMTP sink of its own that keeps the messages.
php .github/scripts/smtp-sink.php 2501 "${work}/mail-reference-inbox" > "${work}/sink-reference.log" 2>&1 &
pids+=($!)
php .github/scripts/smtp-sink.php 2502 "${work}/mail-subject-inbox" > "${work}/sink-subject.log" 2>&1 &
pids+=($!)
mail_env=(SMTP_HOST=127.0.0.1 SMTP_SECURITY=off SMTP_FROM=vaultwarden@example.com SMTP_TIMEOUT=5
    EMAIL_2FA_ENFORCE_ON_VERIFIED_INVITE=true EMAIL_2FA_AUTO_FALLBACK=true ADMIN_TOKEN="${mail_admin_token}")
env DATA_FOLDER="${work}/mail-reference" "${mail_env[@]}" SMTP_PORT=2501 ROCKET_PORT=8003 "${binary}" > "${work}/mail-reference.log" 2>&1 &
pids+=($!)

# php -S rather than artisan serve, which drops most environment variables.
# Laravel's router script expects to run in the public folder.
export DATA_FOLDER="${work}/subject"
php artisan vaultwarden:migrate --no-ansi > /dev/null
DATA_FOLDER="${work}/features-subject" php artisan vaultwarden:migrate --no-ansi > /dev/null
DATA_FOLDER="${work}/mail-subject" php artisan vaultwarden:migrate --no-ansi > /dev/null
# Each PHP server has a cache of its own: the rate limits are per server, like Vaultwarden's.
(cd public && ADMIN_TOKEN="${admin_token}" CACHE_PATH="${work}/subject-cache" exec php -d upload_max_filesize=525M -d post_max_size=0 -S 127.0.0.1:8080 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) \
    > "${work}/subject.log" 2>&1 &
pids+=($!)
(cd public && DATA_FOLDER="${work}/shared" CACHE_PATH="${work}/shared-cache" exec php -d upload_max_filesize=525M -d post_max_size=0 -S 127.0.0.1:8081 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) \
    > "${work}/shared-subject.log" 2>&1 &
pids+=($!)
(cd public && exec env DATA_FOLDER="${work}/features-subject" "${features_env[@]}" CACHE_PATH="${work}/features-cache" \
    php -d upload_max_filesize=525M -d post_max_size=0 -S 127.0.0.1:8082 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) \
    > "${work}/features-subject.log" 2>&1 &
pids+=($!)
(cd public && exec env DATA_FOLDER="${work}/mail-subject" "${mail_env[@]}" SMTP_PORT=2502 CACHE_PATH="${work}/mail-cache" \
    php -d upload_max_filesize=525M -d post_max_size=0 -S 127.0.0.1:8083 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) \
    > "${work}/mail-subject.log" 2>&1 &
pids+=($!)

for attempt in $(seq 30); do
    curl -sS --cacert "${work}/ca.pem" -o /dev/null https://127.0.0.1:2503/ 2> /dev/null && break
    sleep 0.5
done
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
    VW_RSA_KEY="${work}/subject/rsa_key.pem" VW_SERVICES_CA="${work}/ca.pem" VW_SERVICES_HOST=127.0.0.1:2503 \
    vendor/bin/phpunit --testsuite Differential "$@" || status=$?
if [[ "${status}" != 0 ]]; then
    for log in "${work}"/*.log; do
        echo "--- ${log}"
        tail -30 "${log}"
    done
fi
exit "${status}"
