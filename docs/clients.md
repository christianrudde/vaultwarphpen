# Testing with the official clients

The differential tests compare Vaultwarphpen's HTTP answers with Vaultwarden's, with keys made up for
the purpose. The tests in [`tests/Clients`](../tests/Clients) go further: they drive the official
Bitwarden clients against a running server, so every key is made and every item encrypted and
decrypted by the clients' own cryptography, as it is for users.

## The clients

- **The web vault** that Vaultwarphpen serves: the build of Vaultwarden's Docker image that
  `parity/reference.json` pins (`web_vault`, see [Installation](installation.md#web-vault)), driven by
  [Playwright](https://playwright.dev) in headless Chromium.
- **The Bitwarden CLI** from npm, [`@bitwarden/cli`](https://www.npmjs.com/package/@bitwarden/cli), in the
  release of the web vault.

Both versions, and Playwright's, are pinned in `tests/Clients/package.json` and `package-lock.json`. The
CLI pins its own dependencies exactly; where one of them has a known advisory, `overrides` in
`package.json` takes the release that fixes it (`npm audit` stays clean, and so do Dependabot's alerts).
Drop an override once the CLI brings that release itself.

## What they do

One account goes through all of it, in this order:

1. **Web vault:** creates the account; a folder; a login with a website, notes and an attachment (which
   it downloads and compares), a secure note and a card; edits the login's password and moves a note
   to the trash.
2. Creates an organization and moves a login into its default collection, encrypted with the
   organization's key.
3. Creates a text Send and opens it in another browser, without an account.
4. Logs in on another browser with a login request (login with a device), which the first one approves
   from its device list.
5. Switches the key derivation from PBKDF2 to Argon2id, then changes the master password and rotates the
   account key, which encrypts the items, folders, attachments and Sends again; logs in after each and
   checks that everything still decrypts.
6. Turns on two-step login with an authenticator app, taking the secret from the page and computing
   the codes in the test (RFC 6238), logs out and logs in again with a code.
7. **CLI:** `bw config server`, `bw login` with the master password and a code, `bw sync`; lists the
   folders, items, trash, organizations and collections and compares the decrypted items with what the
   web vault entered (password history included); downloads the attachment. The CLI derives its keys
   with Argon2id here.
8. Creates an item with `bw create item`. The open web vault shows it at once, through the
   notifications hub, and again after a reload and unlock.
9. Lists the Send of the web vault and opens it with `bw receive`; sends a file and a text behind a
   password with `bw send`, which another browser opens and downloads.

Not covered yet: SSO, other two-step methods than the authenticator, organization members and groups,
emergency access, and import and export.

## How it runs

[`.github/scripts/clients.sh`](../.github/scripts/clients.sh) starts the server with an empty SQLite
database and the web vault, and runs the tests:

- Vaultwarphpen on PHP's built-in server (port 8100), with the notifications daemon (port 8101).
- In front of it, an HTTPS proxy ([`tests/Clients/proxy.mjs`](../tests/Clients/proxy.mjs), port 8443)
  with a certificate from an authority the script makes. The clients talk to nothing but HTTPS, and
  `DOMAIN` is the proxy's address. WebSocket requests go to the daemon, as a reverse proxy in
  production would send them.
- The browser accepts the certificate; the CLI trusts the authority through `NODE_EXTRA_CA_CERTS`.
- Each run uses a new data folder, and the server's default settings, rate limits included.

`clients.sh --vaultwarden <binary>` runs the same tests against Vaultwarden, which serves the same web
vault and its hubs itself. The [Clients](../.github/workflows/clients.yml) workflow runs the tests on
every push; the `clients` job of the [Reference](../.github/workflows/reference.yml) workflow runs them
against the Vaultwarden it builds. A failure in both is the tests', a failure only in the first is ours.
When a run fails, the workflow keeps Playwright's report and a trace of the browser as the
`clients-report` artifact; open the trace with `npx playwright show-trace`.

## Running them locally

You need PHP 8.5, Composer's dependencies and a `.env` (see [Installation](installation.md)), Node 22 and
the web vault:

```bash
php artisan vaultwarden:web-vault
```

Then install the clients and Playwright's Chromium once:

```bash
cd tests/Clients && npm ci --ignore-scripts && npx playwright install chromium && cd ../..
```

and run the tests from the repository's root. `PHP` names another PHP binary than `php` (with Herd,
`$HOME/Library/Application Support/Herd/bin/php86`); arguments after the options go to Playwright:

```bash
.github/scripts/clients.sh
```

Ports 8100, 8101 and 8443 must be free. For Vaultwarden, pass its binary:

```bash
.github/scripts/clients.sh --vaultwarden /path/to/vaultwarden
```

To work on the tests, `--serve` starts the servers and keeps them running; it prints the environment
for `npx playwright test` in `tests/Clients` (add `--ui` or `--headed` to watch). The rate limits are
Vaultwarden's, so many runs against one server need higher ones, for example
`LOGIN_RATELIMIT_MAX_BURST=1000 UNAUTHENTICATED_RATELIMIT_MAX_BURST=1000`. The tests create a new
account each time.
