# MASTER-HISAB-1 — Bingoo ka apna hisab, Bingoo Master me (29 Oct 2026)

**Asked by the owner, 29 Oct 2026:** "make a hisab screen in Bingoo Master — multi account, easy. Mohsin and Tabish own
Bingoo. Start from this month." Then: every month's profit is split **50/50**; show what each owner *should* have had
and what he *got*; money is the company's — whoever holds more than his share sends it to the other owner (or back to
the company). The real WhatsApp card charge (Meta bill + bank tax/fee) must be kept so the real WhatsApp profit shows.
Final go: "professional screen — the whole hisab in front, in cards, graphs and a data table; entering money in / out
must be easy."

## What exists today (not rebuilt)

| Existing | What it does | Why it is not the hisab |
|---|---|---|
| Master → Invoices (`Central/InvoiceController`, `subscription_invoices`, `subscription_payments`) | bills a tenant, records the tenant's payment with proof | knows nothing about *whose* account the money went into, company expenses, or owners' shares; barely used (payments happen outside the system) |
| Master → WhatsApp Usage (`whatsapp_messages`) | messages per tenant, billed amount, **estimated** cost | the cost is an estimate per message (Billing rates), never the card charge |
| Master → Billing Rates (`platform_settings`, `App\Services\Saas\BillingRates`) | client rate 9.85, our estimated cost ~5.95 per message | estimate only |

No existing screen holds the company's own money, so one new screen: **Master → Hisab** (`/hisab`). Add = Edit (one
modal), every action on the same screen.

## Impact (from the system map, built 28 Oct at `bc9c973c`; prod now `46088129`)

- **New master tables** (nothing in a tenant DB):
  - `company_partners` — owner, share %, optional link to a `central_users` login.
  - `company_accounts` — a *khata*: where money sits. `partner_id` = whose personal account it is; NULL = the
    company's own account.
  - `company_entries` — one voucher: date, type `in` / `out` / `transfer`, client (`tenants.id`, optional), note;
    WhatsApp only: `usd_amount`, `bank_fee`, `message_count`. Soft delete with reason.
  - `company_entry_lines` — account, category, amount (`to_account_id` for a transfer). One voucher can split across
    khatas (Kashif Kitchen 1,50,000 = Mohsin 75,000 + Tabish 75,000) or across categories (Khatri 25,886 = monthly
    25,000 + WhatsApp 886).
  - `company_entry_logs` — every create / edit / delete with before and after.
- **Reads:** `tenants` (client picker), `central_users` (who entered).
- **Writes outside the new tables:** `platform_settings.whatsapp_cost_pkr` — when the *newest* WhatsApp card entry
  gives a message count, the estimate becomes its real cost per message (owner agreed 29 Oct). It only feeds the
  margin shown on WhatsApp Usage and the usage invoice's cost column; the client rate (`whatsapp_rate_pkr`) is never
  touched.
- **New screens / routes** (route name = permission, `route.permission`): `central.hisab.index` (GET `/hisab`),
  `central.hisab.entries.store`, `.update`, `.destroy`, `central.hisab.setup.update`.
- **Permissions:** added to `MasterSeeder` (deploy step 3b reseeds it and syncs Super Admin, so it is the only place
  to list them). No tenant role, no tenant permission.
- **Sidebar:** one link under Platform, `@can('central.hisab.index')`; it runs no query.
- **Tests:** new `tests/MySql/MasterHisabMySqlTest.php`. Billing rates tests unaffected (no change to that screen).
- **Deploy:** new tables read and written only by the new page; no live code writes them, so one deploy is safe
  (the schema-first rule is about live code writing a new column).

## The rules (the "hisab")

- **Balance of a khata** = money in − money out − transfers out + transfers in.
- **Profit** = money in − money out (transfers move money, they never make or lose it).
- **Share** = profit since the start × the owner's share % (Mohsin 50, Tabish 50; shares must total 100).
- **Got** = the balance of all the owner's khatas (what came to him, less company bills he paid, plus/minus what the
  owners sent each other).
- **Difference** = got − share. A positive owner sends to a negative one; money in a company khata belongs to no one
  until it is paid out. Unsettled differences carry to the next month (the settlement is cumulative).
- **WhatsApp card entry:** Meta bill ($), bank charged for it (Rs), bank tax/fee (Rs), messages → the line amount is
  bill + fee; the screen shows the dollar rate (bill ÷ $), tax % (fee ÷ bill) and cost per message.

## The screen

1. Header: month picker, three buttons — 💰 **Paisa aaya**, 🧾 **Kharcha**, 🔁 **Ek khate se doosre mein** — and
   ⚙ Khate / owners.
2. Cards: this month's income, expenses, profit; all money the company has now.
3. **Barabari** card: per owner — share (so far), got, difference — and the line "Mohsin → Tabish Rs X" with a button
   that opens the transfer already filled in.
4. Khata cards: balance of each khata; clicking one turns the table into that khata's statement (in / out / balance).
5. Charts (ApexCharts, already in `public/assets/plugins`, loaded on this page only): 12-month income vs expenses
   with profit; income by client; expenses by category. Colours validated for the Master dark surface (`#102038`).
6. WhatsApp: billed to clients, card charge, profit; latest dollar rate, tax %, cost per message.
7. Data table (DataTables, already in `public/assets/js`): every entry of the month — date, number, what, client,
   khata, in, out, note, who — with search, sort, edit and delete.

## October 2026 — the owner's figures (entered after deploy, on his word)

| Date | Entry | Khata |
|---|---|---|
| 3 Oct | Kashif Food monthly 35,000 in | Mohsin |
| 3 Oct | Claude 30,000 out | Mohsin |
| 10 Oct | The Kashif Foods monthly 20,000 in | Mohsin |
| 10 Oct | Hostinger 12,000 out | Mohsin |
| 9 Oct | WhatsApp: Meta $1.35, bank 378.66 + tax 157 = 535.66 out, 90 messages | Mohsin |
| ? | Khatri 25,886 in = monthly 25,000 + WhatsApp 886 | Mohsin |
| ? | Kashif Kitchen one-time fee 1,50,000 in | Mohsin 75,000 + Tabish 75,000 |
| 29 Oct | Kashif Kitchen monthly 25,000 in | Tabish |

Expected: income 2,55,886 · expenses 42,535.66 · profit 2,13,350.34 · share 1,06,675.17 each · Mohsin got
1,13,350.34, Tabish 1,00,000 → **Mohsin sends Tabish 6,675.17**. WhatsApp: rate 280.49, tax 41.46 %, cost 5.95 per
message, profit 350.34.

## Not in this release

- Linking a verified invoice payment to a hisab entry automatically (when monthly invoicing starts).
- Receipt / screenshot attachments.
- Opening balances (the hisab starts at zero on 1 Oct 2026).
