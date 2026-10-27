# Vaultwarphpen

A feature-equivalent PHP implementation of [Vaultwarden](https://github.com/dani-garcia/vaultwarden),
built on Laravel 13. The goal is a drop-in replacement: same HTTP API, same database, same
environment variables, so the official Bitwarden clients and existing Vaultwarden data keep working.

## Environment

- PHP 8.5 or newer is required. In Herd, `php` on the PATH is still 8.4, so run everything with
  Herd's PHP 8.6: `"$HOME/Library/Application Support/Herd/bin/php86"` (shown as `php86` below).
- Tests: `php86 artisan test`. To include MySQL, point `VW_TEST_MYSQL_DATABASE` (or
  `VW_TEST_PGSQL_DATABASE`) at an empty database that the tests may wipe, e.g.
  `VW_TEST_MYSQL_DATABASE=vaultwarphpen_test php86 artisan test`.
- Static analysis (PHPStan, max level, PHP 8.5): `php86 vendor/bin/phpstan analyse --memory-limit=1G`.
- Code style: `php86 vendor/bin/pint`.
- Database schema: `php86 artisan vaultwarden:migrate`.
- CI (GitHub Actions): `tests.yml` runs Pint, PHPStan and the tests on SQLite, MySQL and PostgreSQL.
  `reference.yml` builds the pinned Vaultwarden for Linux, and for macOS (arm64) only when started by
  hand (Actions → Reference → Run workflow), and keeps the binaries as workflow artifacts. Its databases job lets Vaultwarden create SQLite, MySQL and PostgreSQL databases
  and checks both directions with `.github/scripts/database-parity.sh`. It runs when the pin, the
  snapshots or the migrator change, or on demand.

## Parity workflow

Everything is measured against one pinned Vaultwarden commit.

- `parity/reference.json` pins the commit, release and web vault version.
- `php artisan parity:fetch` checks that commit out into `reference/vaultwarden` (git-ignored).
- `php artisan parity:sync` copies Vaultwarden's SQL migrations to `database/vaultwarden/<dialect>`
  and its Handlebars templates to `resources/vaultwarden/templates`. Never edit those copies.
- `php artisan parity:generate` extracts every route, setting, scheduled job, table, template and
  static asset into `parity/matrix.json` and writes `PARITY.md`. Status and notes survive.
- Status is `todo`, `partial`, `done` or `wontfix`. An item may only be `done` when a test carries
  `#[Parity('<id>')]` (a trailing `*` matches a prefix); `tests/Unit/Parity/ParityMatrixTest` enforces this.
- Set a status with `php artisan parity:report --mark='route:GET /api/sync' --status=done --note='...'`.
- Moving to a newer upstream: update `parity/reference.json`, then `parity:fetch`, `parity:sync`,
  `parity:generate`, and work through the added and removed items. Push, and commit the
  `parity-schema` artifact of the Reference run to `parity/schema`.

## Rules

- Port behaviour from the Rust source at the pinned commit. Each matrix item names its source
  location; read it before implementing.
- The schema comes only from Vaultwarden's own migrations, applied by `App\Database\VaultwardenMigrator`,
  which books them in Diesel's `__diesel_schema_migrations`. The database stays interchangeable with
  Vaultwarden in both directions. Do not add Laravel migrations or extra tables; cache, sessions and
  queues use file or sync drivers.
- Where `src/db/schema.rs` and the migrations disagree, the migrations win. The known cases are listed
  in `tests/Feature/Database/VaultwardenMigratorTest.php`.
- `parity/schema/<dialect>.json` describes the databases Vaultwarden itself creates (booked migrations,
  tables, columns, indexes, foreign keys; see `App\Database\SchemaSnapshot`). The Reference workflow
  writes them with `php artisan parity:schema` and fails when they differ from the committed ones;
  never edit them by hand, commit its `parity-schema` artifact instead. The migrator tests compare
  our databases against them.
- Keep Vaultwarden's environment variable names (`DATA_FOLDER`, `TEMPLATES_FOLDER`, ...);
  settings live in `config/vaultwarden.php`.
- The project is licensed AGPL-3.0-only, like Vaultwarden, from which it is derived.
