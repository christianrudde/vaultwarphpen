# Vaultwarphpen

A feature-equivalent PHP implementation of [Vaultwarden](https://github.com/dani-garcia/vaultwarden),
the Bitwarden-compatible server, built on Laravel. It is meant to run on an ordinary PHP host without
Docker, and to be a drop-in replacement: same API, same database, same environment variables.

**Status:** early development. The database schema and the template engine are in place; the API is not
implemented yet. [PARITY.md](PARITY.md) tracks every route, setting, job, table, template and asset of
the pinned Vaultwarden version.

## Requirements

- PHP 8.5+ with the `pdo_sqlite`, `pdo_mysql` or `pdo_pgsql`, `sodium` and `openssl` extensions
- SQLite, MySQL/MariaDB or PostgreSQL

## Setup

```bash
composer setup
```

This installs the dependencies, creates `.env` and the application key, and creates the database
(SQLite at `data/db.sqlite3` by default).

## Development

See [AGENTS.md](AGENTS.md) for the commands and the parity workflow.

## License

AGPL-3.0-only, like Vaultwarden. Not affiliated with Bitwarden, Inc. or the Vaultwarden project.
