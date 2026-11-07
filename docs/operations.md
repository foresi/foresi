# Operations

How to run Foresi on a server of your own: requirements, configuration, the
layout of releases, the worker and scheduled jobs, deploys and rollbacks,
monitoring, backups and restores, and a checklist for going live. The scripts
named here are in [`ops/`](../ops). Logging is described in
[docs/logging.md](logging.md).

The examples use `/srv/foresi` as the deploy root, `www-data` as the user of
PHP-FPM and the worker, `foresi` as the database and its role, and
`foresi.example.com` as the public address. Change them to yours.

## Requirements

- **PHP 8.4** with the extensions `ctype`, `dom`, `gd`, `iconv`, `intl`, `sodium`
  and `zip`, plus `pdo_pgsql` for PostgreSQL or `pdo_sqlite` for SQLite. OPcache
  is strongly recommended. `composer install` refuses to run without the
  required ones.
- **PostgreSQL 17** for production. SQLite 3.35 or newer works for a small
  installation, but it allows a single writer: run exactly one worker, and keep
  the database file outside the releases.
- Composer 2 and git on the server (the deploy script builds there). No Node is
  needed: the Tailwind binary is downloaded by the bundle on the first build.
- A web server with PHP-FPM (nginx or Apache), HTTPS, and a supervisor for the
  worker (systemd, supervisor or a Ploi daemon).
- For backups: `restic` and the PostgreSQL client tools of the server's major
  version (`pg_dump`, `pg_restore`, `psql`, `createdb`, `dropdb`), and, for
  point-in-time recovery, `pgBackRest`.

## Environment variables

Defaults are in `.env`, which is committed. Set the values of an installation in
`shared/.env.local` (mode `0600`, owned by the application user), or in the
environment of PHP-FPM and the worker; real environment variables win over the
files. Never put a production secret in a committed file.

| Variable | Secret | What it is |
| --- | --- | --- |
| `APP_ENV` | no | `prod` on a server. |
| `APP_SECRET` | **yes** | Signs the public links of sent documents, the remember-me and trusted-browser cookies and CSRF tokens. 32 random bytes in hex: `php -r 'echo bin2hex(random_bytes(32)), "\n";'`. **Never change it once documents have been sent**: every link already in a customer's mailbox stops working. Keep it in the backups of the configuration. |
| `DATABASE_URL` | **yes** (password) | For PostgreSQL `postgresql://foresi:PASSWORD@127.0.0.1:5432/foresi?serverVersion=17&charset=utf8`. |
| `DEFAULT_URI` | no | The public https address, such as `https://foresi.example.com`; links in mail lead there. |
| `APP_SHARE_DIR` | no | The data directory, `var/share` by default (relative to the release). The layout below links it to `shared/var/share`. The application uses `<APP_SHARE_DIR>/<APP_ENV>/`: `firm-images`, `sent-documents`, `inbound-mail`, `idoklad-documents`, `original-documents`. |
| `MAIL_SECRET_KEYS` | **yes** | Keys that seal the firms' mail server passwords and the TOTP secrets of two-factor sign-in. See [rotation](sending.md#passwords-mail_secret_keys). Losing them means every firm enters its mail password again and every user enrols two-factor sign-in again. |
| `APP_REQUIRE_2FA` | no | `true` (default): every user sets up an authenticator app. Keep it on in production. |
| `MESSENGER_TRANSPORT_DSN` | no | `doctrine://default?auto_setup=0` (default): the queues live in the database. |
| `LOCK_DSN` | maybe | `flock` (default) works on one host. With several hosts use a shared store; see [docs/import.md](import.md#one-import-at-a-time-per-target). |
| `SYMFONY_TRUSTED_PROXIES` | no | The addresses of a reverse proxy or load balancer in front of PHP, such as `127.0.0.1,REMOTE_ADDR`. See [web server](#web-server). |
| `BANK_INBOX_*`, `INBOUND_WEBHOOK_SECRET`, `INBOUND_WEBHOOK_TRUSTED_AUTHSERV` | **yes** (`BANK_INBOX_PASSWORD`, `INBOUND_WEBHOOK_SECRET`) | Bank mail; empty turns it off. See [docs/bank-pairing.md](bank-pairing.md). |
| `HEALTH_DISK_MIN_FREE_PERCENT` | no | `/health` is degraded below this free share of the data disk, `5` by default. |
| `SYSTEM_MAILER_DSN` | **yes** (password) | The mailer of the installation's own mail, such as invitations, for example `smtp://user:pass@smtp.example.com:587`. Never a firm's server, which sends only that firm's documents. The mail is queued on the `mail` transport with its retries, so the existing worker sends it. Empty sends no such mail: the screens say the installation cannot send e-mail yet and `/health` reports `system_mail` as degraded. Check it with `bin/console app:ops:test-mail you@example.com`. |
| `SYSTEM_MAIL_FROM` | no | The sender of that mail, an address with an optional name: `Foresi <noreply@example.com>`. Empty, or not an address, sends none. |

Optionally compile the files into `.env.local.php` for speed with
`composer dump-env prod` in each release; the deploy script does not do it, so
`.env.local` stays the single place to edit.

## Directory layout

Each deploy builds a complete release next to the live one and then switches a
symlink, so a request never sees half a release:

```
/srv/foresi/
├── current -> releases/20261106120000   # the live release; the web root is current/public
├── releases/
│   ├── 20261105093000/
│   └── 20261106120000/
│       ├── .env.local -> /srv/foresi/shared/.env.local
│       ├── var/cache/                   # per release, built by the deploy
│       ├── var/share -> /srv/foresi/shared/var/share
│       └── var/imports -> /srv/foresi/shared/var/imports
├── shared/
│   ├── .env.local                       # configuration and secrets (0600)
│   └── var/
│       ├── share/prod/                  # firm-images, sent-documents, inbound-mail, idoklad-documents, original-documents
│       └── imports/                     # uploaded import files
├── repo.git/                            # a mirror of the repository, kept by the deploy
└── deploy.env                           # optional settings of ops/deploy.sh
```

Everything a user created lives in the database and in `shared/var`. A release
can be deleted at any time; `shared/` and the database are what you back up.
The cache stays per release; this also means login throttling and rate limits
start fresh after a deploy.

## First install

1. Install PHP, the extensions, Composer, PostgreSQL 17 and the web server.
2. Create the role and the database:

   ```bash
   sudo -u postgres createuser --pwprompt foresi
   sudo -u postgres createdb --owner foresi --encoding UTF8 foresi
   ```

3. Create the layout and the configuration:

   ```bash
   sudo mkdir -p /srv/foresi/shared/var/share /srv/foresi/shared/var/imports /srv/foresi/releases
   sudo install -m 600 -o www-data -g www-data /dev/null /srv/foresi/shared/.env.local
   sudo chown -R www-data:www-data /srv/foresi
   ```

   Fill `shared/.env.local` with at least `APP_ENV=prod`, `APP_SECRET`,
   `DATABASE_URL`, `DEFAULT_URI` and `MAIL_SECRET_KEYS` (see the table).
4. Deploy as the application user. The first deploy runs every migration:

   ```bash
   sudo -u www-data env PRE_MIGRATE_BACKUP=0 /path/to/foresi/ops/deploy.sh v0.5.0
   ```

   On the first deploy the database is empty, so the dump before migrating is
   skipped here; set up [backups](#backups) before going live.
5. Create the first user, an owner, and its organisation:

   ```bash
   cd /srv/foresi/current && sudo -u www-data php bin/console app:user:create owner@example.com "Example Ltd" owner
   ```

   The role is `owner`, `member` (a team member of the staff) or `guest` (someone
   the organisation shares work with, who sees none of its modules). A guest
   represents a contact of the organisation: pass its id with `--contact=<id>`.
   A team member may pass their own person record the same way.

6. Sign in. With `APP_REQUIRE_2FA=true` the first sign-in asks to scan a QR code
   with an authenticator app and to keep the recovery codes. A user who loses
   the phone and the codes is reset by an operator with
   `bin/console app:user:reset-2fa <email>` and enrols again on the next sign-in.
7. Start the [worker](#the-worker) and the [scheduled jobs](#scheduled-jobs),
   then check `/health`.

## The worker

One long-running process handles mail, timed jobs (recurring invoices,
reminders, bank polling), imports and register checks; see the
[background worker](../README.md#background-worker). It must run all the time.

With systemd, use [`ops/systemd/foresi-worker@.service`](../ops/systemd/foresi-worker@.service):

```bash
sudo cp ops/systemd/foresi-worker@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now foresi-worker@1
```

Its `WorkingDirectory` is `/srv/foresi/current`, resolved when the worker
starts, so a worker that restarts after a deploy runs the new release. With
supervisor:

```ini
[program:foresi-worker]
command=/usr/bin/php /srv/foresi/current/bin/console messenger:consume scheduler_foresi mail async registry --memory-limit=256M --time-limit=3600
directory=/srv/foresi/current
user=www-data
environment=APP_ENV="prod"
numprocs=1
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=3600
```

On PostgreSQL more workers may run (`foresi-worker@2`, `numprocs=2`); on SQLite
exactly one. Under **Ploi**, add a daemon (Server › Daemons) with the same
command, the site user, and the site directory as its directory.

## Scheduled jobs

| When | Command | Why |
| --- | --- | --- |
| daily 03:15 | `bin/console app:import:purge` | deletes the files of imports ended over 30 days ago |
| every 15 minutes | `bin/console app:import:health` | fails stalled imports and starts queued ones |
| every 5 minutes | `bin/console app:health` | optional local check; non-zero exit when down |
| nightly 02:30 | `ops/backup.sh` | database dump and data directories into restic |
| weekly | `ops/restore-check.sh` | proves the latest backup restores |

With cron (`crontab -u www-data -e`); cron mails the output of a failing job to
`MAILTO`, which needs a working local `sendmail`:

```cron
MAILTO=ops@example.com
15 3 * * *   cd /srv/foresi/current && php bin/console app:import:purge --env=prod --quiet
*/15 * * * * cd /srv/foresi/current && php bin/console app:import:health --env=prod --quiet
30 2 * * *   /srv/foresi/current/ops/backup.sh > /dev/null
0 5 * * 0    /srv/foresi/current/ops/restore-check.sh > /dev/null
```

The scripts write their progress to stdout and problems to stderr, so with
`> /dev/null` cron mails only failures. With systemd, the timers in
[`ops/systemd/`](../ops/systemd) do the same; each service has
`OnFailure=foresi-alert@%n.service`, which mails the unit's journal through
`sendmail`. Both scripts also write a status file
(`/var/lib/foresi/backup.status`, `/var/lib/foresi/restore-check.status`) with
the time and `result=ok` or `result=failed`, for a monitoring agent to read: alert
when the result is failed or the time is older than a day (backup) or eight days
(restore check). Errors of the application itself go to its log; see
[docs/logging.md](logging.md).

## Web server

- **Document root** `/srv/foresi/current/public`, every request that is not a
  file to `index.php`. With nginx pass `$realpath_root` instead of
  `$document_root` to PHP, so PHP-FPM and OPcache see the release directory and
  pick up a new release after the reload:

  ```nginx
  server {
      listen 443 ssl http2;
      server_name foresi.example.com;
      root /srv/foresi/current/public;
      client_max_body_size 50m;

      location / { try_files $uri /index.php$is_args$args; }
      location ~ ^/index\.php(/|$) {
          fastcgi_pass unix:/run/php/php8.4-fpm-foresi.sock;
          fastcgi_split_path_info ^(.+\.php)(/.*)$;
          include fastcgi_params;
          fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
          fastcgi_param DOCUMENT_ROOT $realpath_root;
          fastcgi_read_timeout 600s;
          internal;
      }
      location ~ \.php$ { return 404; }
  }
  server { listen 80; server_name foresi.example.com; return 301 https://$host$request_uri; }
  ```

- **PHP-FPM pool** of its own, running as `www-data`, with
  `php_admin_value[upload_max_filesize] = 50M`,
  `php_admin_value[post_max_size] = 50M`, `max_input_vars` of at least `1000`,
  `memory_limit` of `512M` and `request_terminate_timeout = 600`.
  Keep `opcache.validate_timestamps=0` only if every deploy reloads PHP-FPM
  (the deploy script does).
- **Timeouts.** The accountant pack draws every PDF of a period in one request
  and sets its own PHP time limit (30 seconds per document, five minutes to
  close the archive); the web server and PHP-FPM must not cut it off first. Ten
  minutes (`fastcgi_read_timeout 600s`, `request_terminate_timeout = 600`) suit a
  few hundred documents; raise both for more.
- **HTTPS only.** Redirect plain HTTP, and add
  `Strict-Transport-Security: max-age=31536000` once HTTPS works. Public
  document links and the sign-in assume https.
- **Trusted proxies.** Behind a reverse proxy or load balancer (or Cloudflare),
  set `SYMFONY_TRUSTED_PROXIES` to its addresses so the application sees the
  client's address (for throttling and `/health` limits) and the https scheme.
  Do not trust every address unless PHP is reachable only through the proxy.

## Deploy

[`ops/deploy.sh`](../ops/deploy.sh) deploys a git ref (a tag such as `v0.5.0`, a
branch or a commit) as the application user:

```bash
sudo -u www-data /srv/foresi/current/ops/deploy.sh v0.5.0
```

It fetches the ref into a mirror, exports it into `releases/<timestamp>`, links
the shared files, runs `composer install --no-dev --optimize-autoloader`,
`importmap:install`, `tailwind:build --minify`, `asset-map:compile`, clears and
warms the prod cache, dumps the database with `ops/backup.sh --tag pre-migrate`
when migrations are pending, migrates, switches `current` atomically, reloads
PHP-FPM, tells the workers to restart and keeps the last five releases. A
failed build is removed and the live release keeps running. Deploying the
commit that is already live does nothing (`FORCE=1` deploys it again), and only
one deploy runs at a time.

Settings, from the environment or `/srv/foresi/deploy.env`:

| Variable | Default |
| --- | --- |
| `DEPLOY_ROOT` | `/srv/foresi` |
| `REPO_URL` | `https://github.com/foresi/foresi.git` |
| `PHP`, `COMPOSER` | `php`, `composer` |
| `KEEP_RELEASES` | `5` |
| `SHARED_FILES`, `SHARED_DIRS` | `.env.local`; `var/share var/imports` |
| `RELOAD_FPM_COMMAND` | `sudo systemctl reload php8.4-fpm` (allow it in sudoers for the deploy user) |
| `PRE_MIGRATE_BACKUP` | `1`; `0` skips the dump (SQLite, first install) |
| `BACKUP_COMMAND` | empty: the new release's `ops/backup.sh --tag pre-migrate` |
| `STOP_WORKERS` | `1` |

Migrations run before the switch, while the previous release still serves
requests, so a migration must not break the code of the previous release.
`messenger:stop-workers` is sent from the previous release too: the stop signal
is kept in a release's cache, and the workers read the cache of the release
they started from.

### Ploi

[`ops/ploi-deploy.sh.example`](../ops/ploi-deploy.sh.example) is the same
sequence as a Ploi deploy script for a site with zero-downtime deployment. Ploi
clones each release itself and switches the symlink only after the script, so
the script links `shared/` from the site's `-deploy` folder, builds, migrates,
and leaves a background step that reloads PHP-FPM and restarts the daemon's
workers once the switch happened. Ploi keeps three releases.

## Rollback

```bash
sudo -u www-data /srv/foresi/current/ops/rollback.sh            # the release before the live one
sudo -u www-data /srv/foresi/current/ops/rollback.sh 20261105093000
```

It switches `current`, reloads PHP-FPM and restarts the workers. **It does not
revert database migrations.** The older code then runs against the newer schema,
which is fine when the migrations only added tables or nullable columns. When a
release notes a migration that is not backward compatible (the changelog says
so under "Upgrading"), roll back by restoring the `pre-migrate` snapshot taken
just before it ([restore](#restore)) together with the code, and accept that
whatever was written since is lost.

## Upgrades

1. Read the "Upgrading" section of the [changelog](../CHANGELOG.md) for every
   version in between: new environment variables, PHP extensions, one-way
   migrations.
2. Pick a quiet time. Check that the queues are drained: `bin/console
   messenger:stats` shows no waiting `mail` or `async` messages, no import is
   running, and `bin/console messenger:failed:show` holds nothing new.
3. Take a backup (`ops/backup.sh --tag pre-upgrade`) and confirm
   `/var/lib/foresi/backup.status` says `result=ok`.
4. Deploy the new tag. The deploy dumps again before the migrations, migrates
   with `doctrine:migrations:migrate`, and ends with `messenger:stop-workers`,
   so the supervisor starts the workers on the new code.
5. Check `/health`, sign in, and watch the log and the `failed` queue for an
   hour.

## Monitoring

- **`GET /health`** needs no sign-in and returns JSON with an overall `status`
  of `ok`, `degraded` or `down`, the version, and per check a status and a
  number: `database`, `worker` (seconds since its last beat; down after three
  minutes), `schedule` (seconds since the last tick; down after fifteen),
  `failed_messages`, `disk_free_percent` of the data disk and `system_mail`
  (degraded while `SYSTEM_MAILER_DSN` or `SYSTEM_MAIL_FROM` is unset). The HTTP status is
  `200` while ok or degraded and `503` when down. It is rate limited per client
  address (60 a minute) and holds no tenant data.
- Point an **uptime check** at `https://foresi.example.com/health` every five
  minutes, alerting on anything but `200`, and on `"status":"degraded"` if the
  monitor can match the body.
- **`bin/console app:health`** prints the same checks as a table and exits
  non-zero when down, for cron or a monitoring agent on the host.
- The status files of the backup and the restore check (see
  [scheduled jobs](#scheduled-jobs)).

## Backups

Two layers, both to an object store in another location than the server:

1. **Nightly logical backups with restic** ([`ops/backup.sh`](../ops/backup.sh)):
   a `pg_dump -Fc` of the database and the shared data directories
   (`shared/var/share`, `shared/var/imports`) in one encrypted, deduplicated
   snapshot, kept for **30 days daily and 120 months monthly (ten years)**, the
   accounting retention period. The directories are backed up as they are, not
   as a tar, so unchanged files cost nothing the next night and a single file
   can be restored.
2. **Continuous WAL archiving with pgBackRest** for point-in-time recovery
   within the last weeks, to the same object store.

The database and the files belong together: a sent document, a raw bank e-mail
or a logo is referred to by a row and checked against its SHA-256. Back both up
and restore them as a pair.

### Setting up restic

The scripts read their settings from `/etc/foresi/backup.env` (another file
with `BACKUP_ENV_FILE`); a value in that file wins over the same variable in the
environment. Create it owned by root, group `www-data`, mode `0640`:

```bash
RESTIC_REPOSITORY=s3:https://s3.example.com/foresi-backups
RESTIC_PASSWORD_FILE=/etc/foresi/restic-password
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
PGHOST=127.0.0.1
PGUSER=foresi_backup
PGDATABASE=foresi
PGPASSFILE=/etc/foresi/pgpass
SHARED_DATA_DIRS="/srv/foresi/shared/var/share /srv/foresi/shared/var/imports"
SHARE_DIR=/srv/foresi/shared/var/share
```

Generate the repository password once (`openssl rand -base64 32 >
/etc/foresi/restic-password`, mode `0640`) and **keep a copy outside the
server** (a password manager): without it the backups cannot be read. The first
run initialises the repository. Give the backup a role of its own that can read
everything and create the scratch database of the restore check:

```sql
CREATE ROLE foresi_backup LOGIN PASSWORD '...' CREATEDB IN ROLE pg_read_all_data;
```

Run the backup as the application user, which can read the data directories.
`ops/backup.sh` takes `--tag NAME` (default `daily`) and `--prune` (or
`PRUNE=1`), which also frees the space of forgotten snapshots; prune weekly. The
dump exists on the server's disk only while it is being uploaded. Also keep a
copy of `shared/.env.local` (above all `APP_SECRET` and `MAIL_SECRET_KEYS`) in a
password manager; it is deliberately not part of the snapshot.

### WAL archiving with pgBackRest

[pgBackRest](https://pgbackrest.org/user-guide.html) archives every WAL segment
as PostgreSQL writes it and takes base backups, so the database can be restored
to any moment. A minimal `/etc/pgbackrest/pgbackrest.conf`:

```ini
[global]
repo1-type=s3
repo1-s3-endpoint=s3.example.com
repo1-s3-bucket=foresi-wal
repo1-s3-region=eu-central-1
repo1-path=/pgbackrest
repo1-retention-full=4
repo1-cipher-type=aes-256-cbc
compress-type=zst
start-fast=y

[foresi]
pg1-path=/var/lib/postgresql/17/main
```

Put the S3 keys and `repo1-cipher-pass` in a file only `postgres` reads (for
example `/etc/pgbackrest/conf.d/secrets.conf`). In `postgresql.conf`:

```
archive_mode = on
archive_command = 'pgbackrest --stanza=foresi archive-push %p'
wal_level = replica
```

**Changing `archive_mode` takes effect only after a restart of PostgreSQL**
(not a reload): plan a short outage. Then, as `postgres`:

```bash
pgbackrest --stanza=foresi stanza-create
pgbackrest --stanza=foresi check
pgbackrest --stanza=foresi --type=full backup     # weekly, and --type=diff daily
```

With `repo1-retention-full=4` and a weekly full backup the point-in-time window
is about four weeks; the restic snapshots cover the ten years.

## Restore check

[`ops/restore-check.sh`](../ops/restore-check.sh) proves the backups can be
read. It restores the newest snapshot into a temporary directory and a scratch
database `foresi_restore_check_<time>`, checks that the snapshot is at most 26
hours old (`MAX_AGE_HOURS`), compares the row counts of `invoice`, `payment`,
`party` and `recurring_invoice` with the live database (they may differ by
`TOLERANCE_ROWS`, 50, plus `TOLERANCE_PERCENT`, 5 %, the rows written since the
backup), and checks the SHA-256 of a random sample of 20 sent documents
(`SAMPLE_DOCUMENTS`) against `invoice_send.pdf_sha256`. It drops the scratch
database and the temporary directory, writes its status file and exits non-zero
on any problem. It reads the same `/etc/foresi/backup.env`; run it weekly, and
after any change to the backup.

## Restore

[`ops/restore.sh`](../ops/restore.sh) restores the database and the data
directories from a snapshot. Run it by hand, as root, after reading the plan at
the top of the script:

```bash
sudo /srv/foresi/current/ops/restore.sh --snapshot latest           # shows the snapshot, changes nothing
sudo /srv/foresi/current/ops/restore.sh --snapshot 1a2b3c4d --yes
sudo /srv/foresi/current/ops/restore.sh --before "2026-11-06 10:00" --yes
```

It stops PHP-FPM and the workers (`STOP_COMMAND`), restores the dump into a new
database and swaps it for the live one, moves the live data directories aside
(`<dir>.before-<time>`), puts the restored ones in place, and starts everything
again (`START_COMMAND`). Nothing is deleted: remove the old database
(`foresi_before_<time>`) and directories once the restore is confirmed. The
database role needs to create and rename databases: set `RESTORE_PGUSER=postgres`
(with its password in `PGPASSFILE`); the backup's own role cannot.
`restic snapshots --tag foresi` lists the snapshots.

**To a point in time** between two snapshots, restore the database with
pgBackRest and the files from the newest snapshot before that time:

```bash
sudo systemctl stop 'foresi-worker@*' php8.4-fpm
sudo systemctl stop postgresql
sudo -u postgres pgbackrest --stanza=foresi --delta --type=time \
    --target="2026-11-06 10:00:00+01" --target-action=promote restore
sudo systemctl start postgresql
sudo /srv/foresi/current/ops/restore.sh --before "2026-11-06 10:00" --files-only --yes
```

Files written after that snapshot (documents sent, bank mail received) are then
missing for rows that the database still has; the restore check and the pages
of those documents report them. Restore the code of the release that ran at that
time too, if the schema has changed since ([rollback](#rollback)).

## Go-live checklist

- [ ] PHP 8.4 with every extension of the [requirements](#requirements); `composer check-platform-reqs --no-dev` passes in the release.
- [ ] PostgreSQL 17; `DATABASE_URL` uses a role that owns only the Foresi database.
- [ ] `APP_SECRET`, `MAIL_SECRET_KEYS` and `.env.local` stored in a password manager, outside the server.
- [ ] `DEFAULT_URI` is the public https address; HTTP redirects to HTTPS; HSTS is on.
- [ ] Upload limits of 50M in PHP and the web server; timeouts of ten minutes for the accountant pack.
- [ ] `SYMFONY_TRUSTED_PROXIES` set when a proxy is in front.
- [ ] The worker runs under a supervisor and restarts after a reboot; `/health` shows the worker and the schedule `ok`.
- [ ] Cron or timers run `app:import:purge`, `app:import:health`, the backup and the restore check; failure mail reaches you.
- [ ] One backup has run and the restore check passed; the restic password and the pgBackRest cipher pass are kept outside the server.
- [ ] WAL archiving runs (`pgbackrest --stanza=foresi check`).
- [ ] An uptime check calls `/health` every five minutes.
- [ ] Every user has enrolled two-factor sign-in (`APP_REQUIRE_2FA=true`).
- [ ] A deploy and a rollback have been tried once, outside working hours.
- [ ] Moving from iDoklad: the [checklist for the switch day](recurring.md#checklist-for-the-switch-day) of the recurring invoices, and the bank mail [checks](bank-pairing.md#checking-the-setup).
