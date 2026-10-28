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
  `pdo_sqlite`, `pdo_mysql` or `pdo_pgsql`; `phar`, `zlib` and `allow_url_fopen` to download the web
  vault
- [Composer](https://getcomposer.org)
- SQLite, MySQL/MariaDB or PostgreSQL
- A web server with HTTPS. The Bitwarden clients need HTTPS for everything but `localhost`.

## Installing it

Composer installs Vaultwarphpen with everything it needs in one step, into a new folder:

```bash
composer create-project --no-dev christianrudde/vaultwarphpen vaultwarphpen dev-main
cd vaultwarphpen
```

`dev-main` is the current state of `main`; there are no releases yet. After the PHP dependencies,
Composer sets up:

1. `.env`, copied from `.env.example`, with a new application key (`php artisan key:generate`)
2. the database schema, in SQLite at `data/db.sqlite3` (`php artisan vaultwarden:migrate`)
3. the web vault in `web-vault/` (`php artisan vaultwarden:web-vault`, see [below](#web-vault))

If a step fails, Composer stops there and names it; the folder and what was done stay. Fix the cause
and run the remaining steps by hand, in the order above.

From a Git checkout, the same steps are:

```bash
git clone https://github.com/christianrudde/vaultwarphpen.git
cd vaultwarphpen
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
php artisan vaultwarden:migrate
php artisan vaultwarden:web-vault
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

The installation created the schema in SQLite. After setting `DATABASE_URL`, create it in that database
(and delete the unused `data/db.sqlite3`):

```bash
php artisan vaultwarden:migrate
```

## Web vault

The Bitwarden web vault is not part of this repository. Vaultwarphpen serves the one Vaultwarden's
Docker images ship: Vaultwarden's build of it ([bw_web_builds](https://github.com/dani-garcia/bw_web_builds)),
in the version and image that Vaultwarden's Dockerfile pins (`web_vault` and `web_vault_digest` in
[`parity/reference.json`](../parity/reference.json)). The installation puts it into `WEB_VAULT_FOLDER`
(`web-vault/` by default); to do that again, for example after an update:

```bash
php artisan vaultwarden:web-vault
```

If the folder holds that version already, the command leaves it alone (`--force` installs it anyway).
Otherwise it downloads about 75 MB:

1. an anonymous token and the image from the GitHub container registry
   (`ghcr.io/dani-garcia/bw_web_builds`), or, if anything fails there, from Docker Hub
   (`docker.io/vaultwarden/web-vault`),
2. checks the image and each of its parts against the pinned digest and refuses anything else,
3. unpacks the image's `web-vault` folder next to `WEB_VAULT_FOLDER`, then puts it in place of the
   old one.

PHP needs to write to the folder that holds `WEB_VAULT_FOLDER`. If the server cannot download the image,
run the command on another computer and copy the `web-vault` folder over, or take it from the image with
Docker:

```bash
docker create --name web-vault --platform linux/amd64 docker.io/vaultwarden/web-vault@sha256:632375471c7c06a799335cd74dfeaef63f92f45f9cd322c337ae7ad70081e48a
docker cp web-vault:/web-vault ./web-vault
docker rm web-vault
```

PHP serves its files, with the same headers as Vaultwarden, and adds `/css/vaultwarden.css`, which hides
what the server does not offer (built from the templates `scss/vaultwarden.scss.hbs` and
`scss/user.vaultwarden.scss.hbs`, which you may override in `data/templates`). `WEB_VAULT_ENABLED=false`
leaves the web vault out, as in Vaultwarden; `vaultwarden:web-vault` then installs nothing.

## Permissions

Run Composer and Artisan as the user PHP runs as, or give that user the files afterwards. It must be
able to write to:

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

## Live notifications

The Bitwarden clients keep a WebSocket open to `/notifications/hub`, through which Vaultwarden tells them
at once when something changed on another device; a device that logs in with the help of another one
waits on `/notifications/anonymous-hub` for the approval. PHP-FPM cannot keep such connections open, so
a small daemon serves both hubs:

```bash
php artisan vaultwarden:notifications
```

It listens on `127.0.0.1:3012` (`--listen=address:port` to change that) and needs PHP's `pcntl`
extension to stop cleanly. The web requests hand their notifications to it through the Unix socket
`notifications.sock` in the data folder; every message is signed with a key derived from the RSA key
both read. Where Unix sockets are not available, or the data folder's path is too long for one (about
100 characters), set a TCP address for both sides in `.env`:

```dotenv
NOTIFICATIONS_SOCKET=tcp://127.0.0.1:3013
```

Without the daemon, or with `ENABLE_WEBSOCKET=false` (the daemon then refuses to start), Vaultwarphpen
behaves like Vaultwarden with WebSockets turned off: the clients see changes at their next sync.

Run it as a service, as the user PHP runs as (so that it can read the RSA key and the web requests can
reach its socket), for example with systemd:

```ini
[Unit]
Description=Vaultwarphpen live notifications
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/vaultwarphpen
ExecStart=/usr/bin/php artisan vaultwarden:notifications
Restart=always

[Install]
WantedBy=multi-user.target
```

Then let the web server pass everything below `/notifications/` to the daemon, with the path unchanged
(below the path of `DOMAIN`, if it has one). With nginx, inside the `server` block:

```nginx
location /notifications/ {
    proxy_pass http://127.0.0.1:3012;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 1h;
}
```

With Apache 2.4.47 or newer, `mod_proxy` and `mod_proxy_http`:

```apache
ProxyPass /notifications/ http://127.0.0.1:3012/notifications/ upgrade=websocket
RequestHeader set X-Real-IP "%{REMOTE_ADDR}s"
```

The daemon reads the client's address from `IP_HEADER` like Vaultwarden (`X-Real-IP` from a local proxy
by default); the anonymous hub allows 25 connections per address. It is a single PHP process: it can
hold about a thousand connections at a time, as many as PHP's `stream_select()` allows.

### Push notifications for the mobile apps

The mobile apps are notified through Bitwarden's push relay, as with Vaultwarden. Request an
installation id and key at <https://bitwarden.com/host> and set:

```dotenv
PUSH_ENABLED=true
PUSH_INSTALLATION_ID=...
PUSH_INSTALLATION_KEY=...
# For installations registered in the EU region:
# PUSH_RELAY_URI=https://api.bitwarden.eu
# PUSH_IDENTITY_URI=https://identity.bitwarden.eu
```

This needs no daemon: the web requests send the notifications themselves, once their response is out.

## Updating

1. Back up the database and the `data` folder.
2. Fetch the new version and its dependencies. In a Git checkout:

   ```bash
   git pull
   composer install --no-dev --optimize-autoloader
   ```

   An installation made with `composer create-project` has nothing to pull from. Install the new version
   next to it without the setup steps, move the settings, the data and the web vault over, and swap the
   folders:

   ```bash
   composer create-project --no-dev --no-scripts christianrudde/vaultwarphpen vaultwarphpen-new dev-main
   rm -r vaultwarphpen-new/data
   mv vaultwarphpen/.env vaultwarphpen/data vaultwarphpen/web-vault vaultwarphpen-new/
   mv vaultwarphpen vaultwarphpen-old
   mv vaultwarphpen-new vaultwarphpen
   cd vaultwarphpen
   ```

3. Apply new schema migrations:

   ```bash
   php artisan vaultwarden:migrate
   ```

4. Install the web vault of the new version. Unless it pins another one, nothing happens:

   ```bash
   php artisan vaultwarden:web-vault
   ```

5. Restart the scheduler and the notifications daemon, if you use them, so that they load the new code.

There are no releases yet; `main` is the current state.
