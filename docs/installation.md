# Installation

Vaultwarphpen is an ordinary Laravel application: PHP runs `public/index.php` for every request, and
the data lives in a database and a data folder. This guide sets it up on a server of your own or on a
hosting package that gives you PHP 8.5, Composer and a shell.

> [!WARNING]
> Vaultwarphpen is in early development and has not been audited. See the status in the
> [README](../README.md#status) before you put real data into it.

## Requirements

- PHP 8.5 or newer, as PHP-FPM or an Apache module, with the extensions `openssl`, `sodium` and
  `mbstring`, the ones Laravel needs (`ctype`, `filter`, `hash`, `session`, `tokenizer`) and one of
  `pdo_sqlite`, `pdo_mysql` or `pdo_pgsql`
- [Composer](https://getcomposer.org)
- SQLite, MySQL/MariaDB or PostgreSQL
- A web server with HTTPS. The Bitwarden clients need HTTPS for everything but `localhost`.

## Getting the code

```bash
git clone https://github.com/christianrudde/vaultwarphpen.git
cd vaultwarphpen
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

## Configuring it

Open `.env`. The upper part holds Laravel's own settings; for a server, set:

```dotenv
APP_ENV=production
APP_DEBUG=false
```

Vaultwarden's settings go into the same file (or into real environment variables) under Vaultwarden's
names. At least set the public address:

```dotenv
DOMAIN=https://vault.example.com
```

The database is SQLite in `data/db.sqlite3` unless you set `DATABASE_URL`, for example
`mysql://user:password@localhost:3306/vaultwarden` or `postgresql://user:password@localhost/vaultwarden`.
[Configuration](configuration.md) explains the settings and where they differ from Vaultwarden.

Then create the database schema:

```bash
php artisan vaultwarden:migrate
```

## Web vault

The Bitwarden web vault is not part of this repository. Vaultwarden's builds of it
([bw_web_builds](https://github.com/dani-garcia/bw_web_builds)) work unchanged. Take the version
Vaultwarphpen follows (`web_vault` in [`parity/reference.json`](../parity/reference.json)) and unpack it
in the installation folder; the archive holds the `web-vault` folder that `WEB_VAULT_FOLDER` names by
default:

```bash
curl -fsSL https://github.com/dani-garcia/bw_web_builds/releases/download/v2026.8.0/bw_web_v2026.8.0.tar.gz | tar -xz
```

PHP serves its files, with the same headers as Vaultwarden, and adds `/css/vaultwarden.css`, which hides
what the server does not offer (built from the templates `scss/vaultwarden.scss.hbs` and
`scss/user.vaultwarden.scss.hbs`, which you may override in `data/templates`). `WEB_VAULT_ENABLED=false`
leaves the web vault out, as in Vaultwarden.

## Permissions

The PHP user must be able to write to:

- `data/`: the database (with SQLite), the RSA key that signs the login tokens, attachments and Sends
- `storage/`: logs and the cache that holds the rate limits
- `bootstrap/cache/`

Nothing else needs to be writable. Keep `data/` out of the web root; the default layout does that,
because only `public/` is served.

## PHP settings

Vaultwarden accepts attachments and Sends up to 525 MB. Let PHP accept them as well:

```ini
upload_max_filesize = 525M
post_max_size = 0          ; or anything above 525M
max_input_time = 300       ; slow uploads of large files
expose_php = Off           ; Vaultwarden sends no X-Powered-By header
```

## Web server

The document root is the `public` folder. Serve Vaultwarphpen from the root of a (sub)domain of its own,
such as `vault.example.com`. A path in `DOMAIN` (`https://example.com/vault`) mounts every route below
it, as in Vaultwarden; the web server then has to pass `/vault/...` to `public/index.php` without
stripping the prefix.

### nginx with PHP-FPM

```nginx
server {
    listen 443 ssl;
    server_name vault.example.com;
    root /var/www/vaultwarphpen/public;

    # ssl_certificate ...;

    client_max_body_size 525M;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

### Apache

Point the document root at `public` and allow its `.htaccess`, which needs `mod_rewrite`:

```apache
<VirtualHost *:443>
    ServerName vault.example.com
    DocumentRoot /var/www/vaultwarphpen/public

    <Directory /var/www/vaultwarphpen/public>
        AllowOverride All
        Require all granted
    </Directory>

    # SSLEngine on ...
</VirtualHost>
```

The `.htaccess` also passes the `Authorization` header on to PHP, which the clients' tokens need.

### Behind a reverse proxy

If another proxy sits in front, set `IP_HEADER` and `IP_HEADER_TRUSTED_PROXIES` as for Vaultwarden, so
that the rate limits and logs see the client's address.

## Things not to do

- Do not run `php artisan optimize`, `config:cache` or `route:cache`. With a cached configuration
  Laravel stops reading `.env`, and the routes depend on `DOMAIN`.
- Do not change Laravel's cache, session or queue drivers to a database: Vaultwarphpen shares its
  database with Vaultwarden and adds no tables of its own.

## Scheduled jobs

Vaultwarden runs jobs on a schedule: it purges expired Sends, the trash (with `TRASH_AUTO_DELETE_DAYS`),
old events (with `EVENTS_DAYS_RETAIN`) and unfinished logins, mails users about two-step logins that
were not completed, and grants and reminds of emergency access requests. Vaultwarphpen runs them with
Artisan, as the user PHP runs as. Either keep the scheduler running as a service, like Vaultwarden's
own scheduler, which checks every `JOB_POLL_INTERVAL_MS` (30 seconds by default):

```bash
php artisan vaultwarden:schedule
```

for example with a systemd unit:

```ini
[Unit]
Description=Vaultwarphpen scheduled jobs
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/vaultwarphpen
ExecStart=/usr/bin/php artisan vaultwarden:schedule
Restart=always

[Install]
WantedBy=multi-user.target
```

It stops between two checks on `SIGTERM` or `SIGINT` (with PHP's `pcntl` extension). Or, where only
cron is available, check once a minute:

```cron
* * * * * cd /var/www/vaultwarphpen && php artisan vaultwarden:schedule --once
```

`--once` keeps the time of the previous run in Laravel's cache and skips a run while the previous one
is still busy. The first run, like the service's first check, only notes the time.

The schedules are Vaultwarden's settings (`SEND_PURGE_SCHEDULE`, `TRASH_PURGE_SCHEDULE`, ...): cron
expressions with seconds, always in UTC. A job runs at most once per check, so with cron at most once a
minute. A blank schedule turns a job off, `JOB_POLL_INTERVAL_MS=0` all of them. To run one job right
away, whatever its schedule:

```bash
php artisan vaultwarden:job trash_purge
```

## Updating

1. Back up the database and the `data` folder.
2. Fetch the new version and its dependencies:

   ```bash
   git pull
   composer install --no-dev --optimize-autoloader
   ```

3. Apply new schema migrations:

   ```bash
   php artisan vaultwarden:migrate
   ```

4. If `web_vault` in `parity/reference.json` changed, unpack that web vault as described
   [above](#web-vault), after removing the old `web-vault` folder.
5. Restart the scheduler service, if you use one, so that it loads the new code.

There are no releases yet; `main` is the current state.
