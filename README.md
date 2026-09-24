# DB Simply

A small, self-hosted web UI for MariaDB and MySQL, built to sit next to a hosting control panel the way phpMyAdmin does: the panel signs the user in with a one-time link, and the session connects to the one server the app is configured for, as the one database user the link was issued for.

- Databases and tables with row counts, sizes, engine, collation and overhead
- Browse any table page by page: sort by any column, filter with conditions, search every text column; long and binary values are shown safely, and opened in full on click
- JSON and PHP-serialized values (WordPress options and meta) are decoded for reading, without `unserialize()`; classes are never instantiated
- Edit, insert, copy and delete rows. Rows are always picked out by their primary or unique key, and every change touches one row per key at most
- Structure: columns, indexes, foreign keys, triggers and the `CREATE` statement, and changing it: add, change and drop columns, indexes and foreign keys, table options, new tables. Every change shows the statement it will run first
- Stored procedures and functions, triggers, events and views: list them, show their definitions, open them in the SQL editor to change them, drop them
- Search a whole database for a value, table by table, and open the matching rows
- Find and replace across a whole database, safe for serialized PHP: WordPress options, meta and widgets keep working after a domain change. Preview with examples first, then it runs in resumable slices
- See your own queries running on the server, and stop them
- Table operations: rename, empty, truncate, drop, optimize, analyze, check and repair, one table or several at once
- SQL console with syntax highlighting: runs several statements in order, stops at the first error, cuts long results off, and asks before anything that throws data away (`DROP`, `TRUNCATE`, `DELETE` or `UPDATE` without `WHERE`)
- Export a database or some of its tables as an SQL dump (optionally gzipped), a table's rows or a query's result as CSV. A whole database takes its procedures, functions and events along. Exports stream, so their size is not limited by memory
- Import `.sql` and `.sql.gz` files of any size: uploaded in chunks, run in slices of a few seconds each, and resumable past a failed statement
- Read-only sessions, for support access
- The URL records the database, table, tab, page, sort and filters, so a reload lands on the same view and every tab can work in a database of its own
- No build step, no runtime dependencies: plain PHP 8.3+, mysqli, and a vendored copy of Alpine.js

## Requirements

- PHP 8.3 or newer with the `mysqli`, `mbstring`, `session` and `sodium` extensions
- MariaDB 10.6+ or MySQL 8.0+
- A web server that serves only the `public/` directory

## Install

Download the zip from the [latest release](https://github.com/wpsimply/db-simply/releases/latest). It holds only the files a server needs, inside a single `db-simply/` directory:

```sh
version=0.4.0
curl -fsSLO "https://github.com/wpsimply/db-simply/releases/download/v${version}/db-simply-${version}.zip"
curl -fsSLO "https://github.com/wpsimply/db-simply/releases/download/v${version}/db-simply-${version}.zip.sha256"
sha256sum -c "db-simply-${version}.zip.sha256"
unzip -q "db-simply-${version}.zip" -d /var/www
cd /var/www/db-simply && cp .env.example .env
```

Cloning the repository works too, but brings the tests and CI files along.

### With Composer

```sh
composer create-project wpsimply/db-simply /var/www/db-simply
```

This installs the runtime files and leaves out the tests, examples and CI files, like the release zip. It also copies `.env.example` to `.env` and sets the storage directories to `0700`.

To pin DB Simply in another project instead, `composer require wpsimply/db-simply`, and set `DB_SIMPLY_HOME` in the real environment (the PHP-FPM pool's `env[...]`) to a directory outside `vendor/` that holds `.env`, `config.php` and `storage/`. `DB_SIMPLY_HOME` can't be set in `.env`, because it decides where `.env` is read from.

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
2. `DB_SIMPLY_*` environment variables, read from `.env` and the real environment (the real environment wins)
3. `config.php`, if present (copy `config.example.php`)

The settings that matter:

| Variable | Purpose |
| --- | --- |
| `DB_SIMPLY_DB_HOST`, `DB_SIMPLY_DB_PORT` | The server every session connects to. |
| `DB_SIMPLY_DB_SOCKET` | A Unix socket to use instead of host and port. |
| `DB_SIMPLY_DB_SSL`, `DB_SIMPLY_DB_SSL_CA`, `DB_SIMPLY_DB_SSL_VERIFY` | TLS to the server. |
| `DB_SIMPLY_HIDDEN_DATABASES` | Databases never listed or opened in the browser, comma separated. Defaults to the system schemas. This tidies the list; it is not access control, see the security notes. |
| `DB_SIMPLY_TOKEN_DIR` | Where the control panel drops sign-on tokens. Defaults to `storage/sso-tokens`. |
| `DB_SIMPLY_TOKEN_TTL` | Seconds a token stays valid. Default 60. |
| `DB_SIMPLY_SSO_ISSUE_URL` | The panel page `sso.php?start` sends the browser to, for a token bound to it. See below. |
| `DB_SIMPLY_SSO_REQUIRE_BINDING` | `true` refuses tokens that are not bound to a browser. Default `false`. |
| `DB_SIMPLY_PANEL_URL` | Linked from the signed-out page. |
| `DB_SIMPLY_SESSION_SECURE` | Keep `true` in production; `false` only for local HTTP. |
| `DB_SIMPLY_IMPORT_MAX_BYTES` | The largest file an import accepts. Default 2 GB. A gzipped file may decompress to 20 times this. |
| `DB_SIMPLY_IMPORT_BUDGET` | Seconds each import request runs statements before it reports progress. Default 20; keep it well under the web server's timeout. |
| `DB_SIMPLY_CSV_ESCAPE_FORMULAS` | `true` prefixes a quote to CSV values a spreadsheet would run as a formula (`=`, `+`, `-`, `@`), for exports opened in Excel or Sheets. Numbers are left alone. Default `false`, so a CSV holds exactly what the database does. |

See [`.env.example`](.env.example) for all of them.

## Signing users in

There is no login form. Your control panel authorises the user, writes a token file and redirects them. Each token is bound to the browser that asked for it, so a link can only be used by the person it was issued to:

1. The panel's "Open database" button sends the browser to `https://db.example.com/sso.php?start`, with any parameters the panel needs to know which account is meant (`&account=42`).
2. DB Simply gives the browser a random proof in a cookie and sends it on to `DB_SIMPLY_SSO_ISSUE_URL` with those parameters and `binding=<hash of the proof>`.
3. The panel checks that the user is signed in to the panel and that the account is theirs, then writes a token:
   1. Generate a random token: 32–64 bytes, hex-encoded.
   2. Write `<token-dir>/<token>` containing JSON, readable by the PHP-FPM pool:

      ```json
      {
        "user": "acct42",
        "password": "…",
        "database": "acct42_shop",
        "label": "example.com",
        "readonly": false,
        "binding": "<the binding it was sent>"
      }
      ```

      | Field | |
      | --- | --- |
      | `user` | Required. The database user to connect as. |
      | `password` | Its password. |
      | `database` | Optional: the database to open. |
      | `label` | Optional: shown in the header. |
      | `readonly` | Optional: `true` allows reading only. |
      | `binding` | The `binding` parameter, exactly as received. The token is then spent only by the browser holding the proof. |

4. The panel redirects the browser to `https://db.example.com/sso.php?token=<token>`. Never show the link or let it be copied.

The token is spent on first use, whoever opens it, and expires after `DB_SIMPLY_TOKEN_TTL` seconds either way. The token never names a server: where the connection goes is configuration only.

Without the binding, anyone given a link can open it, and whoever issued it can sign someone else into their own account. A token without `binding` is still accepted, so a panel can move to bound tokens at its own pace; once it binds every token, set `DB_SIMPLY_SSO_REQUIRE_BINDING=true` to refuse any that are not.

[`examples/issue-token.php`](examples/issue-token.php) shows the panel side. If it is reached without a `binding`, it sends the browser to `sso.php?start` first, so the panel's existing button can keep pointing at it.

## Security notes

- **Scope the database user.** What a session can reach is exactly what its user's privileges allow. Give each account a user with privileges on its own databases only, and no global privileges such as `FILE`, `PROCESS` or `SUPER`.
- The password is kept in the server-side session encrypted, under a key held only in a cookie of its own. The session file alone does not reveal it.
- `LOAD DATA LOCAL INFILE` is switched off on every connection, so a query cannot read files the web server can see.
- Read-only sessions refuse any statement that is not a read, and every connection they open is held to read-only transactions on the server as well, so a stored function called from a `SELECT` cannot write either. They cannot edit rows, change structure, drop objects, run table operations or import.
- Hidden databases are left out of the lists and cannot be opened in the browser, but the SQL console reaches whatever the user's privileges allow. Keep a database from a user by not granting it, not by hiding it.
- Structure changes are built from checked parts, never from text the browser sends: types come from a fixed list, lengths must be numbers, names are quoted, values are quoted literals, and the only default expression is `CURRENT_TIMESTAMP`. Anything else is written in the SQL editor, where it is plain to see.
- The process list shows only the signed-in user's own connections, and only those can be stopped.
- Nothing read from the database is ever passed to `unserialize()`. Serialized values are shown, and rewritten by find and replace, by reading the format itself, so nothing in them is instantiated.
- A CSV export holds values exactly as stored, so a value such as `=HYPERLINK(…)` is a live formula when the file is opened in a spreadsheet. Set `DB_SIMPLY_CSV_ESCAPE_FORMULAS=true` if exports are opened that way.
- Find and replace changes rows only through their row key, a table without one is left alone, and the key columns themselves are never rewritten.
- Imports are kept in `storage/imports` while they run, readable by the pool user only, and belong to the session that started them. Finished and cancelled imports are deleted at once, abandoned ones after a day.
- Dumps leave `DEFINER` clauses out, so views and triggers import as the importing user. On MySQL with binary logging, creating a trigger needs `SUPER` unless the server sets `log_bin_trust_function_creators = 1`.
- Tokens are single-use and short-lived, and bound to the browser that asked for them. Pages are sent with `Referrer-Policy: no-referrer`, so the token URL doesn't leak to other sites.
- Over HTTPS the session cookies carry the `__Host-` prefix and no domain, so a site on a sibling subdomain (another account's, on a shared server) cannot plant a session in the user's browser.
- Every change needs the session's CSRF token. Sessions end after `DB_SIMPLY_SESSION_IDLE_TIMEOUT` seconds of inactivity, or after `DB_SIMPLY_SESSION_LIFETIME` seconds regardless.
- The Content-Security-Policy allows scripts only from this origin. Alpine.js needs `'unsafe-eval'` to evaluate its directives. No directive is ever built from database data, and values are only ever rendered as text.

## Development

```sh
php -S 127.0.0.1:8080 -t public     # with DB_SIMPLY_SESSION_SECURE=false in .env
php tests/run.php                   # unit tests only; CI also runs them against MariaDB 10.6–11.4 and MySQL 8
```

The database tests need a server. They drop every table in the test database, so never point them at one holding data you care about:

```sh
docker run -d --rm --name db-simply-test -p 127.0.0.1:33306:3306 \
    -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=dbsimply_test -e MARIADB_USER=tester -e MARIADB_PASSWORD=secret mariadb:11.4

DB_SIMPLY_TEST_HOST=127.0.0.1 DB_SIMPLY_TEST_PORT=33306 DB_SIMPLY_TEST_USER=tester DB_SIMPLY_TEST_PASSWORD=secret php tests/run.php
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

The release workflow runs the test suite, then builds `db-simply-<version>.zip` with `build/release.sh` and attaches it, with its SHA-256 checksum, to a GitHub release. Tags with a suffix, such as `v0.2.0-rc.1`, are published as prereleases.

## Roadmap

- Integration with the WP Simply panel, replacing phpMyAdmin

## License

MIT. Alpine.js is bundled under its own MIT license, see `public/assets/vendor/alpine.LICENSE.md`.
