# Logging and error mails

In production Foresi writes one JSON object per line to `var/log/prod-YYYY-MM-DD.log`, a new file each day, and keeps 30 days. The application logs from info up; doctrine, event and messenger only warnings and failures. When a request, command or message ends in an error, the records leading up to it are written too. Deprecations go to `var/log/deprecations-YYYY-MM-DD.log`. Development logs to stderr.

Errors of the web, the console and the worker (a message that failed its last retry) are mailed to the operator, at most once an hour per distinct message, with the subject `[Foresi] LEVEL: start of the message`. Set `OPERATOR_MAILER_DSN` (for example `smtp://user:pass@smtp.example.com:587`) and `OPERATOR_EMAIL`; with either empty no mail is sent. This mailer is the operator's own and is never a firm's server. Client errors such as 404 are not mailed. Run `bin/console app:ops:test-alert` to check the path.

Before a record is written or mailed, values under keys such as password, secret, token, authorization, cookie, iban, email or body are replaced with `[redacted]`, and every text loses the token of a public document link (`/d/[redacted]`), its URL query strings and e-mail addresses.
