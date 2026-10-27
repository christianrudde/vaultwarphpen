#!/usr/bin/env bash
#
# Checks that Vaultwarden and we accept each other's tokens, using the admin
# session token, which needs no account:
#
#   1. We create the RSA key file; Vaultwarden must start with it.
#   2. A token Vaultwarden issues after the admin login decodes here.
#   3. A token we issue opens Vaultwarden's admin API; a tampered one does not.
#
# Usage: token-parity.sh <vaultwarden binary>

set -euo pipefail

binary="$(realpath "$1")"
work="$(mktemp -d)"
data="${work}/data"
mkdir -p "${data}"
export DATA_FOLDER="${data}"
failed=0

fail() {
    echo "::error::$1"
    failed=1
}

ours="$(php artisan parity:token encode admin admin_panel --no-ansi)"
grep -q 'BEGIN RSA PRIVATE KEY' "${data}/rsa_key.pem" || fail "We did not create the key file in Vaultwarden's format."

ADMIN_TOKEN=parity-admin-token WEB_VAULT_ENABLED=false ROCKET_PORT=8000 "${binary}" > "${work}/vaultwarden.log" 2>&1 &
pid=$!
trap 'kill "${pid}" 2> /dev/null || true' EXIT
for _ in $(seq 60); do
    curl -fsS http://127.0.0.1:8000/alive > /dev/null 2>&1 && break
    sleep 1
done

curl -sS -o /dev/null -c "${work}/cookies" --data-urlencode token=parity-admin-token http://127.0.0.1:8000/admin
theirs="$(awk '$6 == "VW_ADMIN" { print $7 }' "${work}/cookies")"
if [[ -z "${theirs}" ]]; then
    cat "${work}/vaultwarden.log"
    fail 'Vaultwarden issued no admin token.'
elif ! claims="$(php artisan parity:token decode admin "${theirs}" --no-ansi)"; then
    fail "Vaultwarden's admin token does not decode here: ${claims}"
else
    echo "Vaultwarden's token decodes here: ${claims}"
    [[ "${claims}" == *'"sub":"admin_panel"'* ]] || fail "Unexpected claims: ${claims}"
fi

status="$(curl -sS -o "${work}/users.json" -w '%{http_code}' -b "VW_ADMIN=${ours}" http://127.0.0.1:8000/admin/users)"
if [[ "${status}" == 200 ]]; then
    echo "Our token opens Vaultwarden's admin API: $(cat "${work}/users.json")"
else
    fail "Vaultwarden refused our admin token with status ${status}."
fi

status="$(curl -sS -o /dev/null -w '%{http_code}' -b "VW_ADMIN=${ours%?}x" http://127.0.0.1:8000/admin/users)"
[[ "${status}" != 200 ]] || fail 'Vaultwarden accepted a tampered token, so the check above proves nothing.'

exit "${failed}"
