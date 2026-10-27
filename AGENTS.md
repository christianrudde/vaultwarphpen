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
- Differential tests (`tests/Differential`) send the same requests to Vaultwarden and to us and
  compare the answers. They are skipped unless `VW_REFERENCE_URL` and `VW_SUBJECT_URL` are set;
  `.github/scripts/differential.sh <vaultwarden binary>` starts both servers and runs them. Write
  one for every route you implement. The script raises the login and unauthenticated rate limits,
  since every flow logs in; `Exchange::jsonString()` throws, so a broken setup fails loudly.
- Uploads: Vaultwarden takes files up to 525 MB, so PHP needs `upload_max_filesize=525M` and a
  `post_max_size` above it (or `0`). PHP cuts uploaded file names at the last slash, which encrypted
  names contain; read them with `getClientOriginalPath()` (see `App\Http\Data\UploadData`).
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
- `php artisan parity:sync` copies Vaultwarden's SQL migrations to `database/vaultwarden/<dialect>`,
  its Handlebars templates to `resources/vaultwarden/templates` and `global_domains.json` to
  `resources/vaultwarden/static`. Never edit those copies.
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
- Vaultwarden's settings are not Laravel configuration. `App\Config\Config` reads them like Vaultwarden:
  environment variables (raw, with `<NAME>_FILE`), overridden by `config.json` in the data folder,
  completed with the defaults of `App\Config\Settings` (a port of `make_config!`, checked against the
  matrix) and validated by `App\Config\ConfigValidator`. Use `app(Config::class)->string('domain')` etc.
  Relative paths are relative to the base path, where Vaultwarden uses its working directory.
- `DATABASE_URL` configures the default `vaultwarden` connection (`App\Database\DatabaseUrl`), with
  Vaultwarden's session settings (UTC, no SQLite foreign key enforcement, `DATABASE_CONN_INIT`). The
  `sqlite`, `mysql` and `pgsql` connections are only for tests and tools that name them.
- The project is licensed AGPL-3.0-only, like Vaultwarden, from which it is derived.

## HTTP conventions

- Routes live in `routes/vaultwarden.php`, mounted below `DOMAIN`'s path like Vaultwarden's mount points,
  and are named after their Rust handler (`core::now`). One controller per Rust module
  (`src/api/core/mod.rs` → `CoreController`), methods named after the handlers.
- No Laravel middleware groups: the only global middleware are `Cors` and `AppHeaders`, ports of
  Vaultwarden's response fairings. Laravel's input trimming, empty-string-to-null and CORS are off.
- Errors: throw `App\Http\ApiError` for `err!`, `err_code!`, `err_json!` and friends. Everything else is
  rendered by `App\Http\ErrorRenderer` like Rocket's catchers (API 404 JSON, the 404 page, Rocket's
  default catcher). Database errors become `{"message":"Db",...}` like Vaultwarden's `?` conversions.
- JSON: use `App\Http\Json`. Values built with `json!()` in Rust have their keys sorted (serde_json runs
  without `preserve_order`), so pass them through `Json::sorted()`; derived structs keep field order.
- Request guards like Rocket's (`App\Auth\Headers`, ...) are resolved by type-hinting them in a
  controller method; failures throw `App\Http\Unauthorized` (401, reason logged).
- Models (`app/Models`) extend `App\Models\Model`: `save()` writes every column with an upsert like
  Diesel, bytes go through the `Binary` cast, timestamps through `NaiveDateTime`. `toApi()` is Rust's
  `to_json()`.
- Tokens: `App\Auth\Jwt` with the key file `App\Auth\RsaKeys` shares with Vaultwarden. Claims are arrays
  in the field order of the Rust struct. `.github/scripts/token-parity.sh` checks interop in CI.
