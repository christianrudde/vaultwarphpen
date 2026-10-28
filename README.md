# Vaultwarphpen

A PHP port of [Vaultwarden](https://github.com/dani-garcia/vaultwarden), the Bitwarden-compatible server
written in Rust. Vaultwarphpen is a modified version of Vaultwarden: its Rust source, translated into PHP
on Laravel since October 2026, following Vaultwarden commit
[`0f44744`](https://github.com/dani-garcia/vaultwarden/commit/0f44744ef86a2a7bea615af1c024044ce5736187)
(release 1.37.4). [NOTICE.md](NOTICE.md) names everything it is based on.

It is meant to run on an ordinary PHP host, without Docker, and to be a drop-in replacement for
Vaultwarden: the same HTTP API for the official Bitwarden clients, the same database and the same
environment variables, so an installation can move from Vaultwarden to Vaultwarphpen and back.

> [!WARNING]
> Vaultwarphpen is in early development and has not been audited. Do not trust it with real passwords
> yet; try it with test accounts or with a copy of your data.

## Why a PHP port

At Lumundi, we run our company software exclusively in PHP, on managed servers. We liked Vaultwarden's
approach so much, a lean self-hosted server for the official Bitwarden clients, that we decided to port
it to PHP with the help of AI (see [Credits](#credits)).

## Status

[PARITY.md](PARITY.md) lists every route, setting, scheduled job, table, template and static asset of the
pinned Vaultwarden version and whether it is ported. In short:

- **Ported:** the database schema for SQLite, MySQL/MariaDB and PostgreSQL, interchangeable with
  Vaultwarden's; registration and login with the master password; two-step login (authenticator,
  email, WebAuthn, YubiKey, Duo, recovery code); account management, devices and login with a
  device; folders and Sends; ciphers and attachments; the organization API (members, collections,
  groups, policies, account recovery, import and export, API keys, the event log); emergency access;
  mails over SMTP or sendmail; Vaultwarden's scheduled jobs; the admin panel; the web vault (from
  Vaultwarden's [builds](docs/installation.md#web-vault)); SSO with OpenID Connect; live
  notifications over WebSocket, served by an optional daemon ([installation](docs/installation.md#live-notifications)),
  and mobile push through Bitwarden's relay.
- **Not yet:** website icons.

Every ported route is compared with Vaultwarden itself: the differential tests send the same requests
to both servers and compare the answers.

## Requirements

- PHP 8.5 or newer with the `openssl`, `sodium` and `mbstring` extensions and `pdo_sqlite`, `pdo_mysql`
  or `pdo_pgsql` for the database
- SQLite, MySQL/MariaDB or PostgreSQL
- A web server that passes requests to PHP (Apache, nginx, Caddy, ...) and serves HTTPS

## Getting started

To try it locally, install it:

```bash
composer setup
```

This installs the dependencies, creates `.env` and the application key, and creates the database
(SQLite at `data/db.sqlite3` by default). Then start PHP's built-in server and point a Bitwarden client
at `http://127.0.0.1:8000`:

```bash
php -S 127.0.0.1:8000 -t public public/index.php
```

For a real installation, read the documentation:

- [Installation](docs/installation.md): web server, PHP settings, updates
- [Configuration](docs/configuration.md): Vaultwarden's settings and where Vaultwarphpen differs
- [Moving from Vaultwarden](docs/migrating-from-vaultwarden.md): taking over an existing database and
  data folder, and going back

## Reporting problems

Please report problems with Vaultwarphpen in [this repository's issues](https://github.com/christianrudde/vaultwarphpen/issues),
not to Vaultwarden: its maintainers have nothing to do with this port. Security issues go through the
private channel described in [SECURITY.md](SECURITY.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). [AGENTS.md](AGENTS.md) describes the commands, the parity
workflow and the conventions of the code.

## Credits

Vaultwarphpen exists because of the work of others. [NOTICE.md](NOTICE.md) lists all of it in detail:

- **[Vaultwarden](https://github.com/dani-garcia/vaultwarden)** by Daniel García and the
  [Vaultwarden contributors](https://github.com/dani-garcia/vaultwarden/graphs/contributors) (AGPL-3.0):
  the behaviour Vaultwarphpen ports, its database migrations, templates and domain lists, and the
  reference every test compares against.
- **[Bitwarden](https://github.com/bitwarden)** by Bitwarden, Inc.: the server whose API Vaultwarden
  reimplements (AGPL-3.0), and the clients Vaultwarphpen serves (GPL-3.0).
- **[Rocket](https://rocket.rs)** (MIT), whose default error pages Vaultwarphpen copies, and the Rust
  crates whose behaviour it reproduces: Diesel, serde_json, chrono, jsonwebtoken, governor,
  data-encoding and url.
- **[Laravel](https://laravel.com)** (MIT) and the PHP packages it runs on.

Every commit so far was written with [Claude Code](https://claude.com/claude-code), Anthropic's AI
coding assistant; the commits say so in a `Co-Authored-By` trailer.

## License

Copyright (C) 2026 Lumundi Versand GmbH and the Vaultwarphpen contributors.

Vaultwarphpen is free software under the [GNU Affero General Public License, version 3](LICENSE.txt)
(AGPL-3.0-only), like Vaultwarden. If you run a modified version for others, the AGPL asks you to offer
them its source code.

Bitwarden is a trademark of Bitwarden, Inc. Vaultwarphpen is not affiliated with, associated with or
endorsed by Bitwarden, Inc. or the Vaultwarden project.
