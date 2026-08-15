# SMTP setup for billing / transactional email (CLOUD-BILLING-3A)

Billing emails (first invoice issued, payment proof received, payment verified/rejected, trial
ending, subscription activated, past due) are **provider-neutral Laravel Mailables**. In dev/test
nothing is delivered (`MAIL_MAILER=log` / `array`). To actually send in production, point the app at
a real SMTP relay. **This is production configuration, kept out of source control — no credentials
live in the repo, in commits, in logs, or in chat.**

> Use a transactional email **relay** (a provider's SMTP endpoint). Do **not** run your own Postfix /
> mail server — deliverability and reputation are the provider's job.

## 1. `.env` keys (values are secrets — set them on the server only)

```
MAIL_MAILER=smtp
MAIL_HOST=              # your relay host, e.g. the provider's SMTP endpoint
MAIL_PORT=              # 587 (STARTTLS) or 465 (implicit TLS)
MAIL_USERNAME=          # relay username / API-key id
MAIL_PASSWORD=          # relay password / API key   ← secret, never commit
MAIL_ENCRYPTION=        # tls for 587, ssl for 465
MAIL_FROM_ADDRESS=      # e.g. billing@yourdomain — must be a domain you authenticate (see §3)
MAIL_FROM_NAME=         # e.g. "Bingoo Billing"
```

Only the **keys** are shown here. Fill the **values** directly in the server's `.env` (or the host's
secret manager). Never paste real credentials into the repo, a PR, a ticket, or a chat.

After editing `.env` on the server:

```
php artisan config:cache
```

## 2. Verify without spamming customers

- Send a single test to an inbox you control (e.g. `php artisan tinker` → `Mail::raw(...)`), or
  trigger one billing event against a throwaway tenant.
- Confirm `MAIL_MAILER=smtp` is actually in effect: a cached config with `log` will silently write to
  `storage/logs` instead of sending.

## 3. DNS authentication (required for deliverability — do this before going live)

Publish these records for the sending domain so mailbox providers accept the mail:

- **SPF** — a TXT record on the domain authorising the relay's sending hosts
  (e.g. `v=spf1 include:<relay-spf-domain> ~all`).
- **DKIM** — the relay gives you a public-key TXT record on a selector host
  (e.g. `<selector>._domainkey.yourdomain`); publish it so the relay can sign outgoing mail.
- **DMARC** — a TXT record at `_dmarc.yourdomain` (start at `p=none` to monitor, then tighten to
  `quarantine` / `reject` once SPF + DKIM pass consistently).

`MAIL_FROM_ADDRESS` must be on the domain you authenticate with SPF/DKIM, or mail will land in spam.

## 4. Reliability notes (already handled in code)

- Billing emails are **at-most-once**: a `billing_notification_log` row is claimed per (event,
  subject) before sending, so retries/overlaps never double-send.
- Billing/accounting state is authoritative: emails are sent **after** the state is committed and a
  transport failure is reported, never rethrown — a mail outage cannot roll back a payment or an
  activation. A transiently failed email is simply not delivered (the customer can still see the
  invoice in-app); it is not retried automatically in this phase.
- Consider running the mail transport over a queue in a later phase; today it sends synchronously.

## 5. What is NOT in scope here

- No Postfix / self-hosted MTA.
- No real provider account setup, credentials, or API keys in the repository — that remains separate
  production work performed on the server.
