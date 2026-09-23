# DB Admin

A small, self-hosted web UI for MariaDB and MySQL, built to sit next to a hosting control panel the way phpMyAdmin does: the panel signs the user in with a one-time link, and the session connects to the one server the app is configured for, as the one database user the link was issued for.

- Databases and tables with row counts, sizes, engine, collation and overhead
- Browse any table page by page: sort by any column, filter with conditions, search every text column; long and binary values are shown safely, and opened in full on click
- JSON and PHP-serialized values (WordPress options and meta) are decoded for reading; classes are never instantiated
- Edit, insert, copy and delete rows. Rows are always picked out by their primary or unique key, and every change touches one row per key at most
- Structure: columns, indexes, foreign keys, triggers and the `CREATE` statement
- Table operations: rename, empty, truncate, drop, optimize, analyze, check and repair, one table or several at once
- SQL console: runs several statements in order, stops at the first error, cuts long results off, and asks before anything that throws data away (`DROP`, `TRUNCATE`, `DELETE` or `UPDATE` without `WHERE`)
- Export a database or some of its tables as an SQL dump (optionally gzipped), a table's rows or a query's result as CSV. Exports stream, so their size is not limited by memory
- Import `.sql` and `.sql.gz` files of any size: uploaded in chunks, run in slices of a few seconds each, and resumable past a failed statement
- Read-only sessions, for support access
- The URL records the database, table, tab, page, sort and filters, so a reload lands on the same view and every tab can work in a database of its own
- No build step, no runtime dependencies: plain PHP 8.3+, mysqli, and a vendored copy of Alpine.js

## Requirements

- PHP 8.3 or newer with the `mysqli`, `mbstring`, `session` and `sodium` extensions
- MariaDB 10.6+ or MySQL 8.0+
- A web server that serves only the `public/` directory

## Install

Download the zip from the [latest release](https://github.com/wpsimply/db-admin/releases/latest). It holds only the files a server needs, inside a single `db-admin/` directory:

```sh
version=0.2.0
curl -fsSLO "https://github.com/wpsimply/db-admin/releases/download/v${version}/db-admin-${version}.zip"
curl -fsSLO "https://github.com/wpsimply/db-admin/releases/download/v${version}/db-admin-${version}.zip.sha256"
sha256sum -c "db-admin-${version}.zip.sha256"
unzip -q "db-admin-${version}.zip" -d /var/www
cd /var/www/db-admin && cp .env.example .env
```

Cloning the repository works too, but brings the tests and CI files along.

### With Composer

```sh
composer create-project wpsimply/db-admin /var/www/db-admin
```

This installs the runtime files and leaves out the tests, examples and CI files, like the release zip. It also copies `.env.example` to `.env` and sets the storage directories to `0700`.

To pin DB Admin in another project instead, `composer require wpsimply/db-admin`, and set `DB_ADMIN_HOME` in the real environment (the PHP-FPM pool's `env[...]`) to a directory outside `vendor/` that holds `.env`, `config.php` and `storage/`. `DB_ADMIN_HOME` can't be set in `.env`, because it decides where `.env` is read from.

### Permissions

Make the storage directories writable by the PHP-FPM pool user and nobody else:

```sh
chown -R www-data:www-data storage
chmod 700 storage/sessions storage/sso-tokens storage/imports
```

Point the web server at `public/`, and let it run `index.php`, `sso.php`, `api.php`, `export.php`, `import.php` and `logout.php`. Imports upload in 8 MB chunks, so allow request bodies of at least 9 MB, in the web server and in PHP's `post_max_size`. There are examples for nginx and PHP-FPM in [`examples/`](examples).

## Configure

Configuration comes from three layers, each overriding the one before:

1. the defaults in `src/Config.php`
2. `DB_ADMIN_*` environment variables, read from `.env` and the real environment (the real environment wins)
3. `config.php`, if present (copy `config.example.php`)

The settings that matter:

| Variable | Purpose |
| --- | --- |
| `DB_ADMIN_DB_HOST`, `DB_ADMIN_DB_PORT` | The server every session connects to. |
| `DB_ADMIN_DB_SOCKET` | A Unix socket to use instead of host and port. |
| `DB_ADMIN_DB_SSL`, `DB_ADMIN_DB_SSL_CA`, `DB_ADMIN_DB_SSL_VERIFY` | TLS to the server. |
| `DB_ADMIN_HIDDEN_DATABASES` | Databases never listed or opened, comma separated. Defaults to the system schemas. |
| `DB_ADMIN_TOKEN_DIR` | Where the control panel drops sign-on tokens. Defaults to `storage/sso-tokens`. |
| `DB_ADMIN_TOKEN_TTL` | Seconds a token stays valid. Default 60. |
| `DB_ADMIN_PANEL_URL` | Linked from the signed-out page. |
| `DB_ADMIN_SESSION_SECURE` | Keep `true` in production; `false` only for local HTTP. |
| `DB_ADMIN_IMPORT_MAX_BYTES` | The largest file an import accepts. Default 2 GB. A gzipped file may decompress to 20 times this. |
| `DB_ADMIN_IMPORT_BUDGET` | Seconds each import request runs statements before it reports progress. Default 20; keep it well under the web server's timeout. |

See [`.env.example`](.env.example) for all of them.

## Signing users in

There is no login form. Your control panel authorises the user, writes a token file and redirects them:

1. Generate a random token: 32–64 bytes, hex-encoded.
2. Write `<token-dir>/<token>` containing JSON, readable by the PHP-FPM pool:

   ```json
   {
     "user": "acct42",
     "password": "…",
     "database": "acct42_shop",
     "label": "example.com",
     "readonly": false
   }
   ```

   | Field | |
   | --- | --- |
   | `user` | Required. The database user to connect as. |
   | `password` | Its password. |
   | `database` | Optional: the database to open. |
   | `label` | Optional: shown in the header. |
   | `readonly` | Optional: `true` allows reading only. |

3. Redirect the user to `https://db.example.com/sso.php?token=<token>`.

The token is spent on first use and expires after `DB_ADMIN_TOKEN_TTL` seconds either way. The token never names a server: where the connection goes is configuration only.

[`examples/issue-token.php`](examples/issue-token.php) shows the panel side.

## Security notes

- **Scope the database user.** What a session can reach is exactly what its user's privileges allow. Give each account a user with privileges on its own databases only, and no global privileges such as `FILE`, `PROCESS` or `SUPER`.
- The password is kept in the server-side session encrypted, under a key held only in a cookie of its own. The session file alone does not reveal it.
- `LOAD DATA LOCAL INFILE` is switched off on every connection, so a query cannot read files the web server can see.
- Read-only sessions refuse any statement that is not a read, and run on the server in read-only transactions as well. They cannot edit rows, run table operations or import.
- Imports are kept in `storage/imports` while they run, readable by the pool user only, and belong to the session that started them. Finished and cancelled imports are deleted at once, abandoned ones after a day.
- Dumps leave `DEFINER` clauses out, so views and triggers import as the importing user. On MySQL with binary logging, creating a trigger needs `SUPER` unless the server sets `log_bin_trust_function_creators = 1`.
- Tokens are single-use and short-lived. Pages are sent with `Referrer-Policy: no-referrer`, so the token URL doesn't leak to other sites.
- Every change needs the session's CSRF token. Sessions end after `DB_ADMIN_SESSION_IDLE_TIMEOUT` seconds of inactivity, or after `DB_ADMIN_SESSION_LIFETIME` seconds regardless.
- The Content-Security-Policy allows scripts only from this origin. Alpine.js needs `'unsafe-eval'` to evaluate its directives. No directive is ever built from database data, and values are only ever rendered as text.

## Development

```sh
php -S 127.0.0.1:8080 -t public     # with DB_ADMIN_SESSION_SECURE=false in .env
php tests/run.php                   # unit tests only; CI also runs them against MariaDB 10.6–11.4 and MySQL 8
```

The database tests need a server. They drop every table in the test database, so never point them at one holding data you care about:

```sh
docker run -d --rm --name db-admin-test -p 127.0.0.1:33306:3306 \
    -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=dbadmin_test -e MARIADB_USER=tester -e MARIADB_PASSWORD=secret mariadb:11.4

DB_ADMIN_TEST_HOST=127.0.0.1 DB_ADMIN_TEST_PORT=33306 DB_ADMIN_TEST_USER=tester DB_ADMIN_TEST_PASSWORD=secret php tests/run.php
```

To get a session locally, drop a token into `storage/sso-tokens/` and open `/sso.php?token=…`:

```sh
t=$(php -r 'echo bin2hex(random_bytes(32));'); echo '{"user":"tester","password":"secret"}' > storage/sso-tokens/$t; echo "http://127.0.0.1:8080/sso.php?token=$t"
```

## Releasing

Set the new version in `VERSION`, commit, then push a tag:

```sh
git tag v0.1.0 && git push origin v0.1.0
```

The release workflow runs the test suite, then builds `db-admin-<version>.zip` with `build/release.sh` and attaches it, with its SHA-256 checksum, to a GitHub release. Tags with a suffix, such as `v0.2.0-rc.1`, are published as prereleases.

## Roadmap

- Structure editing: add, change and drop columns and indexes, create tables, with the generated `ALTER` shown first
- Search across a whole database
- Views, stored procedures and functions, triggers and events: list, show, drop, and include them in dumps
- The server's process list for the user's own connections

## License

MIT. Alpine.js is bundled under its own MIT license, see `public/assets/vendor/alpine.LICENSE.md`.
