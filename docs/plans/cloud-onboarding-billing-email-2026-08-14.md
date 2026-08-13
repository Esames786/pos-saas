# Cloud Self-Signup, Billing, Payment & Email — Research + Plan

**Date:** 2026-08-14 · **Status:** RESEARCH ONLY — no code written · **Target:** execute next week
**Scope:** the public marketing/signup site (`bingoopos.com`) trial → billing → payment → email flow.
**Confirmed decisions (owner, 2026-08-14):**
1. Payment account details stored **admin-editable in the DB** (not just `.env`).
2. **NayaPay** added as a 3rd manual method alongside EasyPaisa + JazzCash (same account).
3. Yearly price = **monthly × 10** ("2 months free").
4. Email: **use an SMTP relay, NOT a self-hosted mail server** (see §5). Provider TBD (Namecheap Private Email recommended).
5. Build order: **Phase 1 (payment) → Phase 2 (yearly) → Phase 3 (email)**.

**Manual payment recipient (to configure):** Account title **Syed Mohsin Sajjad**, number **03328252838**, methods **EasyPaisa / JazzCash / NayaPay**.

---

## 0. Executive summary — can a customer sign up + pay today?

- **Signup + subdomain:** ✅ works automatically. `POST /start-trial` provisions a tenant DB, owner user, roles/permissions, CoA, and flips the subdomain active. New subdomains resolve via the wildcard DNS (`A * → 187.77.140.39`) + the `*.bingoopos.com` TLS cert.
- **Yearly plan:** ❌ the Monthly/Yearly toggle is **cosmetic only** — every signup is monthly.
- **Payment:** ❌ mechanism exists (upload proof → admin verify) but **no account details are shown anywhere** and **no invoice is created at signup**, so a customer cannot actually pay.
- **Email:** ❌ `MAIL_MAILER=log` — nothing is sent. Only 3 Mailables exist; no billing emails at all.

---

## 1. Findings — Yearly → Monthly bug (confirmed)

The Monthly/Yearly toggle carries **no state**; "yearly" is lost immediately and has nowhere to be stored.

| Layer | File:line | Problem |
|---|---|---|
| Toggle JS | `resources/views/public/pricing.blade.php:379-388` | Only toggles a CSS class `show-yearly` to swap which price `<div>` shows (CSS `:32-34`). No hidden input, URL, or state. |
| Trial link | `pricing.blade.php:125` (+ `home.blade.php:499`, `:154-157`) | Static `url('/start-trial?plan='.$plan->code)` — **no `?billing=`**. |
| Trial form | `resources/views/public/start-trial.blade.php:113-198` | No period field; plan summary hardcoded "per month" (`:67`, `:183`). |
| Controller | `app/Http/Controllers/PublicSiteController.php:55-76, 162-206` | `trialCreate`/`trialStore` read only `plan`/`plan_id`. |
| Validation | `app/Http/Requests/Public/StartTrialRequest.php:29-65, 79` | No `billing` rule; `signupData()` returns `validated()` → any period param stripped. |
| Service | `app/Services/Saas/SelfSignupService.php:69-75` | `Subscription` created with no period. |
| **Schema** | `database/migrations/2026_05_11_121154_create_master_saas_tables.php:80-90` | **`subscriptions` has NO `billing_period` column.** (Only `plans` has `billing_period`, per-plan default `monthly`, `:65`.) |

**Fix requires:** (a) toggle appends `?billing=yearly` to the trial link; (b) hidden/period field in the form; (c) validate + pass through `signupData()`; (d) additive migration `subscriptions.billing_period`; (e) use it to compute the first invoice (yearly = monthly × 10) and `current_period_ends_at`.

---

## 2. Findings — Trial signup end-to-end (works, with gaps)

**Path:** `routes/public.php:19` `POST /start-trial` (throttle 5,1, central-only) → `PublicSiteController::trialStore` → `StartTrialRequest` (unique tenant_code, reserved-subdomain block, honeypot `website`, active/public/non-custom plan) → `SelfSignupService::registerTrial` (`app/Services/Saas/SelfSignupService.php:30`):
- Master txn (`:51-78`): `Tenant` (pending) + `TenantDomain` (primary, pending) + `Subscription` (trial, `trial_ends_at`).
- `TenantProvisioner::provisionTenant` (`app/Services/Tenancy/TenantProvisioner.php:27`): `CREATE DATABASE pos_tenant_<code>`, run tenant migrations, `seedTenantBaseData` (owner user `:206-214`, permission catalog `:278-907`, Owner role `:952`, CoA/cash-bank/expense-categories `:961-972`), then marks DB `completed`, tenant `active`, domain `active`, flushes permission cache.
- Failure → `cleanupFailedSignup` drops the DB + master rows (good orphan cleanup).

**Gaps that bite a new customer today:**
- **No first invoice at signup** — nothing to pay against until an admin manually creates one (`Central\InvoiceController::store`). No automated trial→invoice.
- **Yearly not stored** (§1).
- **Provisioning runs synchronously in the web request** (~117 migrations) — timeout risk on a slow signup; consider queueing later.
- **Trial-days fallback mismatch:** literal `14` in `SelfSignupService:48` vs config default `30` (`config/saas.php:4`). Cosmetic unless `plan->trial_days` is null.
- **Welcome email best-effort** — silently swallowed if mail misconfigured.
- **Subdomain/TLS:** relies on pre-existing wildcard DNS + wildcard TLS (both present; cert expires **2026-09-17**). No programmatic DNS.

**Verification recommended:** a throwaway test signup (create → confirm subdomain loads → clean up via `cleanupFailedSignup`/`demo:reset`). Not done yet.

---

## 3. Findings — Manual payment / proof flow (incomplete)

**Works:** `TenantBillingController::uploadPaymentProof` (`:44-82`) → `SubscriptionBillingService::recordTenantProofPayment` (`:141-194`) stores proof on the **private `local`** disk under `billing-proofs/<tenant>/<invoice>/<uuid>.<ext>`, creates `SubscriptionPayment` (status `pending`). Admin `Central\InvoiceController::verifyPayment`/`rejectPayment` → only **verified** payments count → fully-paid invoice → `activateSubscriptionFromPaidInvoice` flips subscription `active`.

**Missing (blocks real payment):**
- **No recipient account details anywhere.** Only a static sentence `resources/views/tenant/billing/show.blade.php:83` ("Pay via bank / JazzCash / EasyPaisa…") + method `<select>` `:98` (`bank/jazzcash/easypaisa/cash/manual`). **No account title/number** in config, `.env`, DB, or views. **NayaPay absent entirely.** `payment_gateways` table has only `code,name,type,is_active` (`create_master_saas_tables:114-121`).
- **No invoice auto-created at signup** (see §2).

---

## 4. Findings — Email touchpoints

`MAIL_MAILER=log` on prod → **nothing is sent.** All 3 senders are best-effort (try/catch swallowed):
1. Trial welcome — `PublicSiteController:187-196` → `TrialWorkspaceCreatedMail` (no password by design).
2. Tenant password reset — `app/Models/Tenant/User.php:133-138` → `TenantPasswordResetMail` (fails silently → user sees "link sent" but gets nothing).
3. Scheduled sales report — `ReportScheduleService:113`, `SalesReportCenterController:211` → `SalesReportMail`.

**No email at all for:** invoice issued, payment verified/rejected, trial-expiry/past-due. Customers get **zero** billing notifications. Only 3 Mailables exist (`app/Mail/`).

---

## 5. Email — deep dive + recommendation

**Prod state:** `MAIL_MAILER=log`, `MAIL_HOST/USERNAME/PASSWORD` empty; no local MTA on ports 25/587/465; MX → Namecheap forwarding; SPF `v=spf1 include:spf.efwd.registrar-servers.com ~all` (only Namecheap authorized).

**Self-host (Postfix on the server) — NOT recommended.** Not a load problem (Postfix is light); the killers are: outbound **port 25 often blocked** by the host; server IP has **no mail reputation** → spam/reject; must configure **SPF+DKIM+DMARC+PTR** for the IP (PTR often not controllable); ongoing spam-relay hardening. An SMTP relay is *lighter* on the server (no daemon) and delivers reliably.

**Recommended: SMTP relay.**
- **Option A (recommended) — Namecheap Private Email:** add a `support@bingoopos.com` mailbox; relay `mail.privateemail.com:587` (STARTTLS) with the mailbox login; add Namecheap's SPF/DKIM.
- **Option B — transactional service** (Brevo/Sendinblue ~300/day free, or Mailgun/SendGrid/Amazon SES): verify domain, add their SPF+DKIM, use their SMTP creds. Better for volume + tracking/bounces.

**Code side (tiny, identical either way):** set `MAIL_MAILER=smtp` + host/port/username/password/encryption in `.env`, `php artisan config:cache`, `php artisan mail:test <you@email>` to confirm. **Real work = choosing provider + adding DNS (SPF/DKIM).**

---

## 6. Implementation plan (next week)

### Phase 1 — Make payment possible (highest priority)
- **1a. Admin-editable payment instructions (DB).** New master table e.g. `billing_payment_methods` (or extend `payment_gateways`) with `label, account_title, account_number, instructions, is_active, sort_order`. Seed EasyPaisa/JazzCash/NayaPay = Syed Mohsin Sajjad / 03328252838. Central admin CRUD page. Render on `tenant/billing/show.blade.php` (replace the static sentence `:83`) and add NayaPay to the method `<select>` `:98`.
- **1b. Auto-create the first invoice** at trial signup (or at trial-end sweep) via `SubscriptionBillingService::createInvoice` so a new customer has something to pay. Decide: invoice at signup vs at trial-end. Amount uses the plan price + billing period (§Phase 2).
- **QA:** test signup → billing page shows account details + NayaPay → upload proof → admin verify → subscription active. Central/tenant tests.

### Phase 2 — Yearly/monthly billing
- **2a.** Additive migration `subscriptions.billing_period` (enum monthly|yearly, default monthly).
- **2b.** Toggle JS appends `?billing=yearly|monthly` to each trial link; form carries a hidden `billing_period`; `StartTrialRequest` validates it; `signupData()` passes it; `SelfSignupService` stores it on the Subscription.
- **2c.** Invoice amount = `billing_period==='yearly' ? monthly*10 : monthly`; period end set accordingly. Start-trial + pricing views show the correct "/year" vs "/month".
- **QA:** pick Yearly → signup → subscription.billing_period=yearly → invoice = 10× monthly.

### Phase 3 — Email
- **3a.** Provider decision + `.env` SMTP + SPF/DKIM DNS + `mail:test`.
- **3b.** Add missing Mailables/notifications: invoice-issued, payment-verified, payment-rejected, trial-expiry/past-due. Queue them. Keep welcome + password-reset working (they already exist).
- **QA:** trigger each event → email delivered (not spam) → check SPF/DKIM pass.

---

## 7. Open questions / risks
- Invoice timing: at signup or at trial-end? (affects Phase 1b).
- Wildcard TLS cert **expires 2026-09-17** — renew before self-signup is promoted (separate track).
- Provisioning-in-request timeout risk — queue later if signups grow.
- Deliverability depends entirely on correct SPF/DKIM for the chosen relay.

---

## 8. Cross-branch note
This is a **platform/Cloud** feature. Per `docs/development/parallel-workstreams.md` it belongs on the integration branch and must be shared to `feat/edge-config-refresh-v1` + `feat/catering-events-v1`. See `docs/status/shared-platform-changelog.md` for what has been shared to all branches since the 2026-08-13 split.
