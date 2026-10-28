#!/usr/bin/env bash
#
# Starts a server with an empty SQLite database and the web vault behind an
# HTTPS proxy, and runs the tests of tests/Clients against it, which drive the
# official Bitwarden clients: this implementation by default, Vaultwarden when
# its binary is given.
#
# Usage: clients.sh [--vaultwarden <binary>] [--serve] [playwright arguments...]
# --serve starts the servers and keeps them running, for `npx playwright test`
# by hand (with the environment it prints).
#
# Needs the web vault in WEB_VAULT_FOLDER (php artisan vaultwarden:web-vault) and
# `npm ci` and `npx playwright install chromium` in tests/Clients. PHP is `php`
# unless PHP names another. Ports 8100, 8101 and 8443 must be free.

set -euo pipefail

binary=
serve=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --vaultwarden) binary="$(realpath "$2")"; shift 2 ;;
        --serve) serve=true; shift ;;
        *) break ;;
    esac
done
php="${PHP:-php}"
root="$(pwd)"
work="$(mktemp -d)"
mkdir -p "${work}/data"

for port in 8100 8101 8443; do
    if (echo > "/dev/tcp/127.0.0.1/${port}") 2> /dev/null; then
        echo "::error::Port ${port} is in use."
        exit 1
    fi
done
pids=()
stop() {
    for pid in ${pids[@]+"${pids[@]}"}; do
        # PHP's server workers outlive their parent unless they are stopped first.
        pkill -P "${pid}" 2> /dev/null || true
        kill "${pid}" 2> /dev/null || true
    done
}
trap stop EXIT

# The clients accept nothing but HTTPS: a certificate for 127.0.0.1 from an authority of its own,
# which the CLI trusts through NODE_EXTRA_CA_CERTS.
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj '/CN=Clients CA' -addext 'basicConstraints=critical,CA:TRUE' \
    -addext 'keyUsage=critical,keyCertSign' -keyout "${work}/ca.key" -out "${work}/ca.pem" 2> /dev/null
openssl req -newkey rsa:2048 -nodes -subj '/CN=127.0.0.1' -keyout "${work}/server.key" -out "${work}/server.csr" 2> /dev/null
printf 'subjectAltName=IP:127.0.0.1,DNS:localhost\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n' \
    > "${work}/server.ext"
openssl x509 -req -in "${work}/server.csr" -CA "${work}/ca.pem" -CAkey "${work}/ca.key" -CAcreateserial -days 2 \
    -extfile "${work}/server.ext" -out "${work}/server.pem" 2> /dev/null

url=https://127.0.0.1:8443
export DATA_FOLDER="${work}/data" DOMAIN="${url}" WEB_VAULT_FOLDER="$(realpath "${WEB_VAULT_FOLDER:-web-vault}")/"
if [[ ! -f "${WEB_VAULT_FOLDER}index.html" ]]; then
    echo "::error::No web vault in ${WEB_VAULT_FOLDER}; install it with php artisan vaultwarden:web-vault."
    exit 1
fi

if [[ -n "${binary}" ]]; then
    ROCKET_ADDRESS=127.0.0.1 ROCKET_PORT=8100 "${binary}" > "${work}/server.log" 2>&1 &
    pids+=($!)
    hub=
else
    "${php}" artisan vaultwarden:migrate --no-ansi > /dev/null
    # php -S rather than artisan serve, which drops most environment variables. Laravel's router
    # script expects to run in the public folder. The web vault loads many files at once.
    (cd public && PHP_CLI_SERVER_WORKERS=4 CACHE_PATH="${work}/cache" exec "${php}" -d upload_max_filesize=525M -d post_max_size=0 \
        -S 127.0.0.1:8100 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) > "${work}/server.log" 2>&1 &
    pids+=($!)
    "${php}" artisan vaultwarden:notifications --listen=127.0.0.1:8101 --no-ansi > "${work}/notifications.log" 2>&1 &
    pids+=($!)
    hub=http://127.0.0.1:8101
fi
node tests/Clients/proxy.mjs 8443 "${work}/server.pem" "${work}/server.key" http://127.0.0.1:8100 ${hub:+"${hub}"} > "${work}/proxy.log" 2>&1 &
pids+=($!)

for attempt in $(seq 60); do
    curl -fsS --cacert "${work}/ca.pem" "${url}/alive" > /dev/null 2>&1 && break
    if [[ "${attempt}" == 60 ]]; then
        tail -30 "${work}"/*.log
        echo "::error::${url} did not answer."
        exit 1
    fi
    sleep 1
done

export VW_CLIENTS_URL="${url}" NODE_EXTRA_CA_CERTS="${work}/ca.pem"
if [[ "${serve}" == true ]]; then
    echo "Serving ${url} (logs in ${work}). In tests/Clients, run:"
    echo "VW_CLIENTS_URL=${url} NODE_EXTRA_CA_CERTS=${work}/ca.pem npx playwright test"
    wait
fi

status=0
(cd "${root}/tests/Clients" && npx playwright test "$@") || status=$?
if [[ "${status}" != 0 ]]; then
    for log in "${work}"/*.log; do
        echo "--- ${log}"
        tail -50 "${log}"
    done
fi
exit "${status}"
