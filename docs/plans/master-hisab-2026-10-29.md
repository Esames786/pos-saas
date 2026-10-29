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

## Results (29 Oct 2026, local only — nothing deployed)

- **New test** `tests/MySql/MasterHisabMySqlTest.php`: 7 tests / 220 assertions green (DB set `_hsb`). It walks the
  owner's October through the real routes and reads the rendered page: cards 2,55,886 / 42,535.66 / 2,13,350.34;
  share 1,06,675.17 each; got 1,13,350.34 vs 1,00,000; one move "→ 6,675.17"; WhatsApp 886 / 535.66 / 350.34,
  tax 41.46 %, rate 280.49, 5.95 per message; Billing rates cost 5.9518, client rate untouched; statement closing
  1,13,350.34; transfer settles both to 0; November carries the settlement; edit logged, stale edit refused,
  delete needs a reason and leaves the totals; plain refusals; 403 without the permission; every route in MasterSeeder.
- **Sabotage** (detached copy at `60e7510a`, DB set `_hsx`): 9 / 9 deliberate breaks turned the test red (wrong
  difference sign, transfer not credited, deleted entry still counted, stale edit allowed, card fee ignored, shares not
  checked, same-khata transfer allowed, Billing rates not following the card, a permission missing from MasterSeeder);
  control on the clean tree green; tree clean after each.
- **Related, frozen** (detached copy at `60e7510a`, DB set `_hsr`, HEAD and clean tree proven before and after):
  30 MySQL test classes that seed Master or render Master pages — 288 tests, 1 failure =
  `ProductScreenV2MySqlTest::test_each_business_sees_only_its_own_cards`, already red on the production base
  (open items, 27 Oct). Unit suite 139 tests, 3 failures — compared with the production base below.
- **Browser walk** (Chrome, local test master DB): 32 / 32 checks — cards, settlement line, three graphs drawn, data
  table search, money-in popup with two parts and its live sentence, save, delete with a reason, WhatsApp card sums
  (535.66 · 280.49 · 41.46 % · 5.95), settle button opens a filled transfer, edit shows both parts, owners total 100 %,
  khata statement, phone width 400 px without sideways scroll, no JavaScript errors.
- **System map** rebuilt (`a6647274`): 5 new master tables, 5 `central.hisab.*` screens.
- **Unit suite on the production base `46088129`**: the same 3 failures (`CateringClientFeedbackUiRegressionTest`
  sidebar-collapsed, `EscPosReportPayloadTest` two-decimal money, `DeliveryRiderReassignmentRegressionTest`) — none
  from this change.

## Deployed — 29 Oct 2026 ~19:55 UTC (30 Oct ~00:55 PKT), merge `ca85d2f1`

- Owner's word: "han kardo deploy". Production was `5e36d094` (two website releases after this branch's base); merged
  into the branch first (`3f00339a`), hisab + the three website test files re-run on the merge: 43 tests green.
- Master backup `20261029_195128` (596.5 KB, dump complete). Before-snapshots: permissions, reconciliation, smoke.
- `deploy.sh`: tenants ok=13. After: the 5 tables exist; Super Admin holds the 5 `central.hisab.*` permissions;
  smoke clean ×13 (tb_diff 0); PERMISSIONS OK (no role lost anything). Reconciliation flagged 2 differences, both
  kashiffood POS tables — 7 open orders saved in the window (6 paid, 1 held): 21 old lines gone, 21 re-inserted,
  22 KOT links blanked (live use; `recon_explain.php`). No error in the log since the deploy.
- Pages rendered on prod as the Super Admin: `/hisab`, `/hisab?month=2026-10`, `/contact-enquiries`,
  `/whatsapp-usage` all 200, no error.
- Master has ONE login (`central_users` #1, Super Admin) — only that login sees Hisab.
- **October entered** through the service (`prod_october.php`, dry run first; dates from the owner: Khatri 25,886 on
  18 Oct, Kashif Kitchen 1,50,000 on Tuesday 27 Oct): income 2,55,886 · expenses 42,535.66 · profit 2,13,350.34 ·
  Mohsin share 1,06,675.17 got 1,13,350.34 · Tabish got 1,00,000 · **Mohsin → Tabish 6,675.17**. Billing rates
  `whatsapp_cost_pkr` 5.95 → 5.9518 (client rate untouched).
- Same night, owner's word, before the deploy: Master invoices INV-20261020-1103 (kashifkitchen, 25,000, paid
  29 Oct) and INV-20261010-0337 (tawakalkashif, 20,000, paid 10 Oct) marked paid through `recordPayment`, with each
  subscription's end date / status / plan put back exactly (`activateSubscriptionFromPaidInvoice` would have set
  kashifkitchen to 20 Oct — already past — and tawakalkashif to 31 Oct); access gate still "active" for both.
