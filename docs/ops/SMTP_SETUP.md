# SMTP Setup (outgoing email)

> Status today: production runs `MAIL_MAILER=log` — signup, password-reset, and any
> future notification emails are written to `storage/logs/`, **never actually sent**.
> Fix this BEFORE onboarding real clients.

## 1. Pick a provider
Any SMTP provider works. Common choices: Mailgun, Postmark, SES, Brevo, Zoho, or the
mailbox host for `support@bingoopos.com`. You need: host, port, username, password,
encryption (TLS on 587 is typical).

## 2. Configure production `.env`
```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.yourprovider.com
MAIL_PORT=587
MAIL_USERNAME=<smtp username>
MAIL_PASSWORD=<smtp password>          # never commit this anywhere
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=support@bingoopos.com
MAIL_FROM_NAME="Bingoo POS"
```
Then rebuild the config cache (env is read at cache-build time only):
```bash
php artisan config:clear && php artisan config:cache
```

## 3. Verify
```bash
php artisan mail:test you@yourinbox.com
```
- Shows the active mailer/from without exposing secrets.
- Warns if the mailer is still `log`.
- With `log` you can inspect the message in `storage/logs/laravel-*.log`.

## 4. Deliverability (do once)
- Add **SPF** record including your provider, e.g. `v=spf1 include:yourprovider.com ~all`.
- Add the provider's **DKIM** records.
- Optional **DMARC**: `v=DMARC1; p=none; rua=mailto:support@bingoopos.com`.

## 5. Queue note
Mail may be queued in future (`QUEUE_CONNECTION=database`). Without a running queue
worker, queued mail silently never sends — see `docs/ops/QUEUE_WORKER_SETUP.md`.

## Never
- Never commit SMTP credentials to git.
- Never paste the SMTP password into chat/logs/reports.
