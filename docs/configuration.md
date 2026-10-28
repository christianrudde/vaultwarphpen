# Configuration

Vaultwarphpen takes Vaultwarden's settings, under the same names, with the same defaults and the same
validation. Vaultwarden's documentation therefore applies:

- [`.env.template`](https://github.com/dani-garcia/vaultwarden/blob/0f44744ef86a2a7bea615af1c024044ce5736187/.env.template)
  of the pinned commit lists every setting with its description
- the [Vaultwarden wiki](https://github.com/dani-garcia/vaultwarden/wiki) explains them in depth

This page covers where the settings come from and where Vaultwarphpen differs.

## Where settings come from

Like Vaultwarden, in this order:

1. **Environment variables**, either real ones (set by the web server, PHP-FPM or the shell) or lines
   in `.env`. Real environment variables win over `.env`.
2. **`<NAME>_FILE`** instead of `<NAME>` reads the value from a file, for secrets such as
   `ADMIN_TOKEN_FILE` or `DATABASE_URL_FILE`.
3. **`config.json`** in the data folder (or at `CONFIG_FILE`) overrides the environment. Vaultwarden's
   admin panel writes it; Vaultwarphpen reads an existing one, but has no admin panel yet.
4. **Defaults** for everything not set.

Invalid values and combinations are refused with Vaultwarden's error messages.

## Laravel's settings

The upper part of `.env` belongs to Laravel. Leave it as `.env.example` has it, except:

- `APP_KEY`: created by `php artisan key:generate`
- `APP_ENV=production` and `APP_DEBUG=false` on a server
- `LOG_CHANNEL` and `LOG_STACK` if you want the log somewhere other than `storage/logs/laravel.log`

Keep the cache, session and queue drivers on `file` and `sync`. Laravel's `DB_*` variables are not
used; the database is configured with Vaultwarden's `DATABASE_URL`.

## Which settings take effect

Every setting is read and validated, but many belong to features that are not ported yet; those have
no effect so far. The `config:` items in [PARITY.md](../PARITY.md) show the state of each one.

## Differences from Vaultwarden

- **Relative paths** (`DATA_FOLDER`, `DATABASE_URL` with SQLite, ...) are relative to the installation
  folder. Vaultwarden takes them relative to its working directory.
- **Rocket's settings** (`ROCKET_ADDRESS`, `ROCKET_PORT`, `ROCKET_TLS` and the other `ROCKET_*`
  variables) do not exist: the web server and PHP take over their jobs. Upload limits are PHP's
  ([Installation](installation.md#php-settings)).
- **The database pool settings** `DATABASE_MAX_CONNS`, `DATABASE_MIN_CONNS`, `DATABASE_IDLE_TIMEOUT`,
  `DATABASE_TIMEOUT` and `DB_CONNECTION_RETRIES` are validated but have no effect: PHP opens one
  connection per request.
- **`LOG_LEVEL`** is read by Laravel as well. Laravel knows neither `trace`, `warn` nor `off`; until
  Vaultwarden's logging is ported, use `debug`, `info` or `error`, which both understand.
- **Scheduled jobs** run in a process of their own (`php artisan vaultwarden:schedule`) or from cron
  (`--once`), not inside the web server ([Installation](installation.md#scheduled-jobs)).
  `JOB_POLL_INTERVAL_MS` sets how often the process checks; with cron, cron decides, but `0` still
  turns all jobs off.
- **Rate limits** live in Laravel's cache (`storage/framework/cache`) instead of in memory, so all PHP
  workers share them. Vaultwarden keeps them per process.
- **The server's name.** Where Vaultwarden names itself to the clients (the `server` object of
  `/api/config`), Vaultwarphpen names itself and links to its own repository, so that users find the
  source of the server they use and report problems in the right place. This route is not ported yet.
- **Development routes.** Vaultwarden's debug builds serve static files from the source tree at
  `/vw_static/`; release builds do not, and neither does Vaultwarphpen.
- **Caches.** Do not cache Laravel's configuration or routes (see
  [Installation](installation.md#things-not-to-do)).
