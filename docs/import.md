# Import and export

Foresi can load records from a CSV or XLSX file and write them back out. Contacts (the address book) are the first target; other modules plug in the same way. The iDoklad contact export is recognised and mapped automatically.

## How an import works

Open the address book and choose **Import**. Every step is saved, so you can leave and come back from the list of imports. The action that moves the import on (Upload file, Check, Start import, Done) and the way back sit in a bar docked to the bottom of the screen.

1. **Upload file.** Choose a CSV or XLSX file. It is stored privately for your organisation.
2. **Reading** (background). Foresi detects the encoding (UTF-8, or Windows-1250 as a fallback), the delimiter, the sheets and the header row. You can change each of them, and the file is read again.
3. **Match columns.** Each column is matched to a field, and the reason is shown next to it: a mapping remembered from an earlier import with the same headers, a known source such as iDoklad, the same name, or a similar name. Change any proposal or set a column to "do not import". Required fields that stay unmapped block the next step. Where a field has a fixed set of values (roles, countries, yes/no), the values found in the file are mapped once for the whole file.
4. **Options.** The mode, the key used to find existing records, what an empty cell does, and the date order and decimal separator when the file is ambiguous.
5. **Check** (background). A dry run of the whole import without writing anything. It shows how many records would be created, updated, skipped and archived, a sample per outcome, and every row that has an error, with the column and the value. The rows with errors can be downloaded as a file in the format you uploaded, with an added error column.
6. **Run** (background). Progress is shown live. You can leave the page; the import appears in the notices when it ends.
7. **Result** with the counts and links to the records that were created or changed. **Done** takes you back to the records, and **Undo import** is next to it while the import can be undone.

### Modes

| Mode | Existing records | Records not in the file |
|---|---|---|
| Add missing only | left alone | left alone |
| Update existing | overwritten from the file | left alone |
| Full replace | overwritten from the file | **archived**, never deleted |

Full replace makes the address book match the file. It can archive many records, so the check shows their number prominently and the import starts only after you type that number as a confirmation. By default it archives only records that earlier imports of the same profile (the saved mapping) created, updated or restored; you can widen the scope to every record of the target. A record you made by hand is therefore in the default scope once an import of the profile updated it. An archived record can be restored, and an undo restores it too.

### Matching

Existing records are found by a key you choose. For contacts the keys are the Foresi ID (a UUID, as the export writes it), the registration number, the VAT ID and the e-mail address. The default is the first key that is present in the mapping.

- Archived records take part in matching. Add missing only skips an archived match. Update and full replace restore it and update it, and report it as "restored".
- A key that matches several existing records is an error on that row ("ambiguous").
- A key repeated in the file: the first row wins, later rows are errors ("duplicate in file"). Keys are compared the way the target matches them, so `0000019` and `00000019` are one registration number, and `one@example.com` and `ONE@example.com, cc@example.com` are one e-mail address (the first address of a list counts).
- Empty cells leave existing values as they are, unless you choose to clear them.
- A file exported before identifiers became UUIDs has an `id` column of whole numbers. A row with such a value is a row error, because it names no record. Either do not map that column or delete it from the file and match by another key, such as the registration number or the e-mail address.
- Registry checks (ARES, VIES, the unreliable VAT payer register) never run inside the import. New and changed records that have a VAT ID are checked later in the background, respecting the rate limits.

### Undo

An import can be undone for 30 days after it ended, from the import's page. Created records are archived, updated records get their previous values back and archived records are restored. A record that someone changed after the import is not reverted; it is listed instead. An import that failed after it started writing (its worker died, for example) can be undone the same way: rows that were never written are passed over. An undo that failed can be started again; it skips the records it already put back. An import that failed while the file was read or checked wrote nothing and has no undo. After 30 days the file and the staged rows are deleted, undo is no longer possible, and the import record with its counts stays in the history.

### Limits

| Limit | Value |
|---|---|
| File size | 50 MB |
| Rows | 200,000 |
| Files and staged rows are kept | 30 days after the import ended |
| Rows per background chunk | 500 |

An import stops itself when more than half of the rows of the first chunks fail, which usually means the mapping is wrong. Rows written until then can be undone.

### Export

The address book exports all or the filtered contacts as CSV (UTF-8 with a byte order mark, `;` as the delimiter) or XLSX, using the same fields as the import, so an export can be imported again. Formulas in cells are neutralised.

## Running the worker

Reading, checking, running and undoing happen in a worker, not in the web request. Without a running worker an import stays in its current step. Messages are stored in the database. The worker consumes the `async` transport (and `registry`, at low priority, for the register re-checks):

```bash
php bin/console messenger:consume async registry --memory-limit=256M --time-limit=3600
```

The worker exits after an hour or at the memory limit, so run it under a supervisor that restarts it. With systemd (`/etc/systemd/system/foresi-worker@.service`):

```ini
[Unit]
Description=Foresi worker %i
After=network.target

[Service]
WorkingDirectory=/var/www/foresi
Environment=APP_ENV=prod
ExecStart=/usr/bin/php bin/console messenger:consume async registry --memory-limit=256M --time-limit=3600
Restart=always
RestartSec=5
User=www-data

[Install]
WantedBy=multi-user.target
```

Start one instance with `systemctl enable --now foresi-worker@1`. With supervisor:

```ini
[program:foresi-worker]
command=/usr/bin/php /var/www/foresi/bin/console messenger:consume async registry --memory-limit=256M --time-limit=3600
directory=/var/www/foresi
user=www-data
environment=APP_ENV="prod"
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=3600
```

- **PostgreSQL:** run several workers (`foresi-worker@1`, `@2`, or `numprocs=4` in supervisor). Chunks of one import are processed in parallel, and counters are added under a row lock.
- **SQLite:** run exactly one worker. SQLite allows a single writer, and a second worker only produces lock errors.
- Failed messages are retried three times and then kept in the `failed` queue (`bin/console messenger:failed:show`, `messenger:failed:retry`).
- After a deployment, restart the workers (`bin/console messenger:stop-workers` asks them to exit after the current message), so they run the new code.
- The files of the uploads are stored in `var/imports`. Every worker must see the same directory as the web process.

### One import at a time per target

Only one import per organisation and target (contacts, for example) runs or undoes at a time. The others wait with the state "queued" and start by themselves, the longest-waiting first. The decision is made under a lock.

By default the lock is a file lock (`LOCK_DSN=flock` in `.env`), which works on a **single host**: the web server and all workers must run on the same machine. If they run on several hosts, two of them could decide at the same moment and start two imports into one target. Set a lock store that all hosts share, in `.env.local`, for example PostgreSQL advisory locks or Redis:

```
LOCK_DSN=postgresql+advisory://user:password@db.example.com:5432/foresi
# or
LOCK_DSN=redis://redis.example.com:6379
```

The supported stores are listed in the Symfony Lock documentation. The import files directory must also be shared between the hosts.

## Scheduled commands

Run two commands regularly, for example from cron:

```cron
# Delete the files and staged rows of imports that ended more than 30 days ago.
15 3 * * * cd /var/www/foresi && php bin/console app:import:purge --env=prod
# Fail imports that stopped moving and start the imports queued behind them.
*/15 * * * * cd /var/www/foresi && php bin/console app:import:health --env=prod
```

`app:import:purge` also cancels imports that were left waiting for the user for more than 30 days.

`app:import:health` looks for an import in Reading, Checking, Running or Undoing that has changed neither state nor counters for four hours (twice the `redeliver_timeout` of 7200 seconds in `config/packages/messenger.yaml`). Such an import is marked Failed with the message "stalled" and the target is freed. This recovers from a worker that died for good. A slow import is not affected as long as chunks keep finishing, but an import whose chunks wait more than four hours in the shared queue behind other work (for example when no worker runs) is failed as well. The command then also looks at every target that has queued imports and none running, and starts the oldest queued one. So a run repairs a start that failed earlier, and running the command again changes nothing. If one import or target fails (for example the lock store is down), the error is logged, the others are still handled and the command exits with an error. It does not re-send lost chunk messages. A run that failed this way can be undone from the import's page (and then uploaded again), and an undo that failed can be started again, which reverts the remaining records.

## PHP settings

Set `upload_max_filesize` and `post_max_size` to at least `50M` in `php.ini`, and the same for the request limit of the web server (for nginx, `client_max_body_size 50m`). With a lower limit the upload screen reports the server limit instead of the application limit.

## Upgrading

Run the database migrations (`bin/console doctrine:migrations:migrate`) and start a worker before using the import. Import tables are new; no existing table loses data.

### Upgrading to 0.4.0

Version 0.4.0 identifies every record by a UUID instead of a whole number. The conversion is a one-way migration. Before upgrading:

1. Back up the database and the `var/imports` directory.
2. Stop the workers and let the Messenger queues drain (`bin/console messenger:stats` shows empty `async` and `registry` queues). A message queued before the upgrade cannot be read afterwards; it is moved to the `failed` queue rather than lost, but an import that depended on it must then be started again.
3. Finish or cancel imports that are in progress.  Its queued work is among the messages that cannot be read afterwards.
4. Run `bin/console doctrine:migrations:migrate`. It cannot be reverted and cannot be rehearsed with `--dry-run` or `--write-sql`; the application refuses both.
5. Start the workers.

Links to imports (`/imports/<number>`) and to contacts that were saved or sent before the upgrade stop working; open them again from the lists.
