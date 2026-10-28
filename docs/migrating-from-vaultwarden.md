# Moving from Vaultwarden

Vaultwarphpen uses Vaultwarden's database, data folder and settings unchanged. An existing installation
can therefore move over, and back again.

> [!WARNING]
> Vaultwarphpen does not have all of Vaultwarden's features yet: two-step login, emergency access, the
> admin panel, the web vault, push notifications and mails are missing (see the
> [README](../README.md#status)). Users who rely on them cannot use it yet. Try the move with a copy of
> your data first.

## What carries over

| What | Where | Why it matters |
|---|---|---|
| Database | `data/db.sqlite3`, or the MySQL/PostgreSQL database in `DATABASE_URL` | all accounts, vaults and organizations |
| RSA key | `data/rsa_key.pem` | signs the login tokens; with the same key, clients stay logged in |
| Attachments | `data/attachments/` | files attached to vault items |
| Sends | `data/sends/` | files shared with Send |
| `config.json` | `data/config.json` | settings saved in Vaultwarden's admin panel |
| Settings | Vaultwarden's environment variables | same names, see [Configuration](configuration.md) |

With Docker, the data folder is the volume mounted at `/data`.

## Moving over

1. **Stop Vaultwarden**, so that nothing changes while you copy.
2. **Back up** the database and the data folder. With SQLite, copy `db.sqlite3` together with any
   `db.sqlite3-wal` and `db.sqlite3-shm` files.
3. **Install Vaultwarphpen** as described in [Installation](installation.md), without creating a new
   database yet.
4. **Bring the data folder:** copy it to Vaultwarphpen's `data` folder, or set `DATA_FOLDER` to where it
   is. PHP must be able to write to it.
5. **Bring the settings:** copy Vaultwarden's environment variables into `.env` (or the server's
   environment). Keep `DOMAIN` as it was, so that the clients do not notice the change. Leave out the
   `ROCKET_*` settings.
6. **Apply missing migrations:**

   ```bash
   php artisan vaultwarden:migrate
   ```

   If your Vaultwarden was older than the version Vaultwarphpen follows, this brings the schema up to
   date, exactly as a Vaultwarden update would. Vaultwarphpen books the migrations in Diesel's own
   table, so Vaultwarden sees them as applied.
7. **Switch the web server** to Vaultwarphpen and log in with a client.

Vaultwarphpen follows the Vaultwarden commit pinned in
[`parity/reference.json`](../parity/reference.json). A database from a newer Vaultwarden may contain
migrations Vaultwarphpen does not know; move over from that version or an older one.

## Going back

Stop Vaultwarphpen and start Vaultwarden with the same database, data folder and settings. Use a
Vaultwarden that knows at least the migrations Vaultwarphpen applied, that is the pinned version or a
newer one. Nothing needs converting: the CI checks in both directions that the schemas Vaultwarden and
Vaultwarphpen create are the same.
