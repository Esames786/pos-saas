# Bingoo POS SaaS — One Month of Work

**5 August – 5 September 2026** · Branch `feat/14d-2-plan-upgrade-requests` · Production Hostinger `187.77.140.39`

| | |
|---|---|
| **Commits** | 475 (448 work + 27 merges) |
| **Prod HEAD at close** | `56c9838` |
| **Application code** | 261 files, +34,405 / −1,146 |
| **Screens (Blade)** | 102 files, +16,118 / −830 |
| **Migrations** | 84 new — every one additive |
| **Guards (test files)** | 214 new, +47,130 lines |
| **Design / research docs** | 93 |
| **Live tenants** | Khatri Biryani, Kashif Food, Kashif Kitchen, Tawakal & The Kashif Foods |

> This document consolidates and supersedes nothing: the two fortnightly logs
> (`work-log-2026-08-11-to-08-24.md`, `work-log-2026-08-25-to-09-01.md`) remain
> the finer record for their windows. This one covers the whole month, including
> the two stretches no log covered — 5–10 August and 2–5 September — and adds
> the screen-by-screen index.

---

## 1. What the month actually produced

| Track | Outcome |
|---|---|
| **A restaurant went live and stayed live** | Khatri Biryani opened on 11 August and has traded every day since — 6,150 orders by 5 September. Two more tenants followed: Kashif Food (24 Aug) and Tawakal (1 Sep). |
| **A whole new product line was built** | Catering V1 — bookings, quotations with cost blocks, production releases, kitchen sheets, store issues, advances/refunds/final invoices, and a keyboard punch screen modelled on the client's twenty-year-old software. |
| **Reporting stopped lying** | Four separate untruths were killed: totals that did not reconcile to NET SALES, returns dated by calendar instead of business day, deal components counted as separate sales, and a deal reporting under another product's name. In every case money stayed identical — the *counts and names* became true. |
| **Printing became predictable** | Thermal output fits 72 mm, reads as columns, prints where the operator stands, and a reprint can no longer come out blank. Printer health, per-printer isolation, and a browser-triggered ping/reset/reboot. |
| **Shifts and cash got a timezone** | One canonical clock (`TenantClock`), business dates frozen at shift open, per-terminal shift locks, and cash/card/bank/cancellation breakups on Shift Report and Close Branch. |
| **Access control got teeth** | A permission editor in business language, per-branch manager-PIN approvals for cancellation and returns, terminal-scoped cashiers, and operator roles stripped of reports they should never have seen. |
| **The platform learned to look after itself** | Per-tenant scheduled DB backups, scheduled owner email reports, an idempotent print-job numbering authority, and a transaction reset that now has an opinion about every single table. |
| **Offline Edge reached a frozen, dormant baseline** | Branch-local runtime, auth, POS, restaurant layer and print worker — all built, all proven, all switched off in production on purpose. |
| **Three outages, all mine, all owned** | A cross-tenant 403 storm, a POS 500, and a Close Branch 500. Each produced a permanent discipline change, not just a fix. |

---

## 2. Chapter one — 5–10 August: the week before go-live

Khatri's opening was set for 11 August, and this week was spent making the POS
survivable for people who had never seen it.

**POS bug triage (`e4ed6b2` → `5671068`).** A code-grounded audit rather than a
wish list, then the fixes: the mouse wheel silently corrupting number inputs
(`83e52cd` — the real cause, found after two wrong guesses), split-bill quantity
capped on the remainder (`9ca1361`), Table Board "Close (Paid)" turned from a
dead end into an action (`7027427`), held orders showing a line count instead of
a summed quantity (`dc4c014`), receipt reprint and recent-prints persistence
(`4c99534`).

**Print routing (`03c730e`, `b73a75a`, `e1c7893`, `d5d0cc2`).** An "All
categories" wildcard, Reminder mappings restricted to reminder-capable printers,
default layouts seeded for new tenants, and a null-safe live preview.

**Shift, timezone and business date (`dd9826d` → `e788200`).** The month's
deepest platform change, done in fourteen parts. `TenantClock` became the single
authority; a shift **freezes** its business date and timezone at open; every POS
operation requires an open shift; sales reports key on business date, not
`created_at`; the header carries a live server-anchored clock and a business-date
badge. Two timezone concepts were separated on purpose — the **business**
timezone (branch → Asia/Karachi, anchors shifts) and the **display** timezone
(user → branch → Karachi, cosmetic only), so a user in London can never move a
Karachi shift's business date.

**Offline Edge (`54ee08c` → `3816596`).** The branch-local runtime contract,
local database with an immutable branch binding, device-bound offline
authentication, the local POS and restaurant layer, and the print worker's
lifecycle — each built, hardened, proven with MySQL integration tests, then
**frozen and left dormant in production**.

**Reports, permissions, money (`7861b37`, `b88ca44`, `473a50f`, `f2cee3e`,
`b4ef353`).** The shared sales report engine with a reconciliation proof; Report
Center with exports, print, Email Now and schedules; a permission editor that
speaks business language over granular keys; a customer-facing delivery charge
threaded through the whole money path; cash shortage raising a draft expense.

**Khatri onboarding (`13cd815`, `8dccc6f`, `fd0f115`, `3816596`).** Tenant
contract, custom plan, menu seed, network KOT printers with order-type-aware
category routing, two-printer setup, named terminals, delivery counter user.

---

## 3. Chapter two — 11–24 August: live trading, and a caterer's whole business

### 11 August — go-live day, 43 commits

Khatri opened, and the day was spent fixing what only real trading exposes.
Thermal reports fitting 72 mm (`c74ed6f`, `e421721`), reading as columns
(`d868a7d`), every total reconciling to NET SALES on the page (`0ec2fc7`), the
Sales Summary giving the counter the *right* cash target (`c66f254`), printers
kept awake so the kitchen ticket is not what wakes them (`e1098ee`), print jobs
no longer lost to a momentarily busy printer (`bdc6fcd`).

Then the POS itself: Add & Attach and Save Address doing nothing (`35b08ac`),
Complete Sale staying disabled on the cart it had just cleared (`869c7c6`) — both
symptoms of one root cause, **the POS view is two script blocks and helpers must
be top-level**. Sales-return accounting corrected to carry and prorate discount
and tax (`85ebcfd`, `cedb073`), a customer made mandatory on delivery orders
(`d0ac021`), manual discounts with per-branch approval (`bdfd1db`).

### 12 August — the 403 storm

`e17d6b5` is the most important commit of the month. Spatie's permission cache
was on the shared `database` store: one static-key row bound to a warm FPM
worker's first DB connection produced a wrong-guard Gate and **403s across every
tenant**. Fixed by moving the store to per-request `array` and forbidding bare
`permission:cache-reset` forever. Alongside it, `db9194f` put tenant
identification ahead of the session, closing the `/login` 500s
("No database selected"), and `267d512` separated `markPrinted()` (a physical
transport success) from queue cleanup (`cancelObsolete()`).

### 13–19 August — catering foundations

Booking statements and settlement (`e1c12ec`), audited refunds (`00ef190`),
customer credit and financial position (`a22151c`), dish pricing from named cost
blocks (`a8a5f35`), recipe-or-blocks costing choice (`a8069a9`), commercial
material rates and rate impact (`f7fe179`), quoted-rate override with Cost
Details (`f0fa9ea`), customer-supplied materials (`bfaf843`), and a reset that
finally knew about catering documents (`bdb44b7`).

Also `1d66ab0` — one collision-safe authority for print job numbers — and
`52b5c85`, posting a supplier's opening balance to the GL rather than only its
ledger (Dr 3300 Equity / Cr 2100, **not** P&L).

### 18–21 August — printing overhaul and report truth

Terminal-keyed KOT routing (`9319a15`, `9f1a766`), preview/print parity
(`e3b2ed8`), the Report Center's Print/Z/Export no longer silently narrowing to
one terminal (`c39e335`), and the Sales Report streamed to a thermal printer
through the agent (`703d789`).

Report truth: category and department order counts made DISTINCT rather than
per-product sums (`78d6a6f`, `8903742`), filters honoured in order-level
sections (`bec1d16`), returns and item-voids keyed on business day
(`dcd1ae4`), and a delivery-charge bridge closing BY ORDER TYPE and the global
sections to NET SALES (`719b4bb`, `24f6c29`).

### 22–24 August — the punch screen and the client's own catalogue

**KASHIF-ORDER-PUNCH** (`193c012` → `ffd8f7f`, fourteen commits). The client asked
for their old software's keyboard flow: type an item code, fill the details,
Ctrl+Enter, the row lands below and the cursor returns to the item box. Built on
the existing event screen rather than beside it. Three real bugs were found and
named honestly: a DOM sweep that **deleted saved rows** because it matched
server-rendered ones (`82a6359`), PARTY→OWN keeping the customer's share and
silently changing money (`c259607`), and a CSS descendant selector that ate a
column from the nested breakdown table (`ffd8f7f`). Auto-save was removed on the
owner's instruction: nothing saves until Save Estimate.

**KASHIF-LEGACY-REBUILD-1** (`616f793`). The client's real database, rebuilt from
their own workbook: 909 items keyed on the legacy `OrderItemId` as SKU, 18
categories, cost blocks derived from their own rates (`OrderRate = MeatRate +
ServiceRate`, exact on all 909 rows), party/complimentary flags read from columns
hidden past column N, and 4,848 customers. GL and stock fingerprinted at zero
movement.

**KASHIF-FOOD-ONBOARD** (`b3617c8`). A brand-new restaurant tenant: 199 products,
41 combos, 4 terminals, 3-station routing.

---

## 4. Chapter three — 25 August – 1 September

Covered in full by `work-log-2026-08-25-to-09-01.md`; the spine:

- **Printer health** (`13a741a`) — health pills, browser-triggered Test / ping /
  Reset / Reboot, an unreachable printer **defers** rather than fails, 90-second lease.
- **Scheduled owner reports** (`0b80549`) and **per-tenant scheduled backups**
  (`c49e883`) — both idempotent, both running in the tenant's own timezone.
- **POS Quick Report** (`2454d7c`) — a button on the POS that emails or prints an
  unscoped single-day sales report, byte-identical to Report Center's because it
  reuses the same document service.
- **Table reservation** (`3d241ce`) — reserve a free dine-in table and carry the customer onto the order when it opens; and `75dc5cf`, which REMOVED the Bill Preview button from the Table Workspace card (Review & Pay is the separate one, and it stays).
- **Terminal scoping** (`cba7e09` and the 30 Aug chain) — a cashier lands on their own
  terminal, reads others but sells only on theirs; reprint and cancellation print
  where the operator stands; the order keeps its own `terminal_id`.
- **Return manager approval** (`613c250`) — per-branch, same mechanism as cancel,
  bound to the refund amount rather than a line fingerprint; and **Close Table**
  (`67cde05`), which decides under a row lock so a bill punched at another
  counter a second earlier cannot be lost.
- **Dashboard scope + open bills** (`69e3968`, `11e940b`) — branch-wide cards
  owner-only, with a migration that back-grants existing roles so it cannot
  quietly take something away.
- **Report truth, again** (`03f0d99`) — a deal reported under its own name and
  counted once. Money moved **0.00**; `Singaporean Rice (Regular) (Midnight)
  191/119,630` became four correctly-named rows.
- **Two outages** — a POS 500 from a relation that does not exist, and a Close
  Branch 500 from `voided@endif` (a Blade directive glued to a word, so the file
  never compiled). Every Blade change is now compiled and lint-checked.

---

## 5. Chapter four — 2–5 September: the thermal report, and Kashif Kitchen's go-live

**`5aab512` DEAL-CATEGORY-1** — a deal reports under its own head and is counted once.

**`193da7b` RIDER-RETURNS-1** — the delivery reports say what came *back*, not just what went out.

**`d167164` KOT-SENT-POOL-2** — a second helping added to a running bill reaches the kitchen.

**ITEMS-BY-CATEGORY-1** (`903454f` → `bdec1e3`, five commits) — the item report
under category heads as a section of its own, closed with the same NET SALES
bridge, every thermal entry fenced with a rule, and a category head that actually
looks like a head on the roll.

**THERMAL-ITEM-LAYOUT-1/2** (`63b3e76`, `d1bd19a`) — the Z report fits the paper
and reads as a hierarchy: names wrap by indent rather than being cut, the closing
rule sits *below* the total, nothing between Qty and Amt. The preview was then
made to take the same shape as the roll. **The old guard only read the roll,
which is why a Blade fault could hide** — the Blade now has its own guard.

**`63dee02` REPORT-GRAND-TOTAL-WORDING** — the section-wide total said `KUL`
(Urdu) on an English report. Now `GRAND TOTAL`.

**`d89f32a` CHARGE-BREAKUP-1** — every charge on the bridge says whose money it is.

**`cffbf76` HIDE-AMOUNTS-2** — the shift's own page was the hole in the blind count.

**`e8bcee1` + `56c9838` — Kashif Kitchen go-live prep** (detail in
`docs/plans/kashif-kitchen-go-live-2026-09-05.md`):

- The **reset guard was narrower than the contract it protected** — it filtered
  tables to the `catering_` prefix, so it could never see anything outside it.
  Widened to the whole schema, it immediately surfaced four unclassified tables.
- **The customer phone fusion, fixed at the source.** The old software kept two
  numbers in one field; stripping punctuation fused them into a 22-digit string
  which then became the customer's *identity*. 161 customers were unreachable and
  63 were duplicate identities of people already in the book.
- **Suppliers imported** — 236 names, 28 phones, and **zero** opening balances:
  the 14 suppliers carrying ~6.49M credit are named and withheld pending the
  owner's confirmation and a GL posting step.
- **Reset run on production** — 14 tables, 190 rows, master data proven intact.

---

## 6. Screen by screen — what changed where

The count is how many commits touched that file this month.

### POS & restaurant

| Screen | × | What changed |
|---|---|---|
| `tenant/pos/index.blade.php` | **75** | The month's busiest file. One-box customer flow with address book; delivery charge and channel; vehicle capture; Draft alongside Hold; per-terminal shift badge; live server clock; Report and Quick Report buttons; Print-here fallback; recent-prints modal; combos shown by name only; blind-count amount masking; terminal scoping. |
| `pos/partials/table-bill-preview.blade.php` | 5 | Rendered in the receipt layout so the preview and the paper agree; forced `.00` dropped. |
| `restaurant/board.blade.php` | 4 | Close Table (row-locked on the server), reservation, cancel frees the table. |
| `sales-orders/index.blade.php` | 6 | Order-type scoped lists; vehicle and waiter columns; held/draft filters. |
| `sales-returns/create.blade.php` | 8 | Mandatory refund method; scoped search; single-item return fixed; partial return unblocked; manager PIN gate. |

### Printing

| Screen | × | What changed |
|---|---|---|
| `printing/documents/receipt.blade.php` | 20 | Column layout, AM/PM, rounded money, boxed rows, change from tendered cash, auto-cut, no finger-width gap at the top, line truncation that drops the whole price rather than mangling it. |
| `printing/documents/kot.blade.php` | 14 | Per-category KOT, deal names on components, terminal-keyed routing, reprint that can no longer be blank, category toggle. |
| `printing/documents/reminder.blade.php` | 9 | Bold what the rider reads at the door; all-branch routing; cancellation reminder carries the cancelling counter. |
| `printing/layouts/_form.blade.php` | 4 | Live-reacting editor; font size that actually reaches the printer; switches given back their meaning. |

### Reports

| Screen | × | What changed |
|---|---|---|
| `reports/center/print.blade.php` | **31** | The thermal Z report: 72 mm fit, columns not inline expressions, weight-only emphasis, NET SALES reconciliation on the page, business-date returns, category order, item-by-category section, three legible levels, `GRAND TOTAL` in English, charge breakup. |
| `reports/center/index.blade.php` | 9 | Section selection and section permissions, combos, cancellations, Z preset, Email Now, Send to Network, scope fixes. |
| `dashboard.blade.php` | 7 | Agreement with Report Center; operator scoping; branch-wide cards owner-only; open bills tile; 7-day view. |
| `shifts/index|close|close-branch.blade.php` | 13 | Branch grouping, per-terminal locks, required counts, cash/card/bank/cancellation breakup, blind count. |

### Catering (the new product line)

| Screen | × | What changed |
|---|---|---|
| `catering/events/show.blade.php` | **43** | The operator workspace: punch bar, slim rows, click-to-edit, Cost Details, quoted-rate override, complimentary, partial supply, history timeline with revert, in-place posting, no auto-save. |
| `catering/events/form.blade.php` + partials | 21 | POS-style customer search/add/reset, booked-date clash warning, service-time presets, in-workspace create and edit. |
| `catering/documents/partials/estimate-body.blade.php` | 8 | The quotation: serial → item → its materials underneath → figures; who supplies what; complimentary tag; Urdu where the document asks for it. |
| `catering/profiles/index.blade.php` | 8 | The dish book: catering switch, costing mode, party-supply and complimentary flags. |
| `catering/store-issues/index.blade.php` | 6 | The store counter: many bookings or none, searchable materials, remaining-bounded quantities. |
| `catering/cost-blocks/edit.blade.php` | 5 | Named cost blocks, material vs charge, rate basis. |
| `catering/releases/show.blade.php` | 5 | Production release with kitchen needs vs our store, and Issue Materials. |
| `catering/commercial-rates/index.blade.php` | 5 | The house rate book and its dated changes. |

### Platform

| Screen | × | What changed |
|---|---|---|
| `partials/sidebar.blade.php` | 9 | Catering menu, store issues, system reset, permission-gated everywhere; operator menu hidden per role. |
| `branches/form.blade.php` | 6 | Business timezone, negative-stock policy, cancellation and return approval modes. |
| `system-reset/index.blade.php` | 1 | Owner reset: typed business code + password + backup acknowledgement. |

---

## 7. Tenant by tenant

| Tenant | State | This month |
|---|---|---|
| **Khatri Biryani** | LIVE since 11 Aug | 6,150 orders. Printing overhaul, terminal-keyed KOT routing, report and finance corrections, nightly owner report at 00:30 PKT, backups 14:30/19:30/02:30. |
| **Kashif Food** | LIVE since 24 Aug | 2,309 orders. 199 products, 41 combos in four groups, 3-station routing, return manager PIN, report access revoked from operator roles, nightly report 02:30 PKT. Open issue: today's takings landing on yesterday (business-date, plan written 4 Sep). |
| **Kashif Kitchen** | Catering, prepped for go-live 5 Sep | Whole catering product line. Catalogue rebuilt from the client's own database (909 items, original IDs). Test data cleared, customer phones repaired, 236 suppliers imported. |
| **Tawakal & The Kashif Foods** | Onboarded 1 Sep | New tenant. |

---

## 8. Discipline changes this month bought

Each of these came from something going wrong, and each is now permanent:

1. **Never bare `permission:cache-reset`** — it poisons a shared master row. Always `system:clear-tenant-permission-cache`. *(the 403 storm)*
2. **A guard must run the real path.** Two outages came from tests that rebuilt a query instead of calling the controller. *(POS 500)*
3. **Compile every Blade change.** `voided@endif` never compiled and took Close Branch down for everyone. *(Close Branch 500)*
4. **A guard narrower than its contract is silent about exactly the untested thing.** *(the reset's `catering_` filter)*
5. **A new route grants to Owner only** — every other role needs an additive grant plus a cache clear.
6. **Never run `php artisan migrate` while the MySQL suite runs** — it wipes `pos_test_tenant` and produced 347 phantom errors once.
7. **Fix the source, not the rows.** Repairing 161 phones without fixing the extractor would have let the next rebuild bring the fault straight back.

---

## 9. Still open

- **Kashif Kitchen:** 61 now-visible duplicate customers (merge is the owner's call); 9 malformed phones needing a human; 14 supplier opening balances awaiting confirmation + GL posting; only one user and one role on the tenant — operator roles are needed before real staff book.
- **Kashif Food:** business-date misdating (plan written, not yet applied); one manager PIN shared by six users, which makes the audit trail meaningless.
- **Platform:** wildcard certificate `*.bingoopos.com` expires **17 September 2026** and cannot auto-renew — the renewal config is manual DNS-01 with no auth hook.
- **Catering:** the numbering counter runs on UTC while the business runs on Karachi, so a booking entered between midnight and 5 AM carries the previous day's date.
- **Offline Edge:** built, proven, frozen and dormant. Next is `EDGE-CONFIG-REFRESH-1`.
- **Cloud billing:** built on `feat/cloud-billing-onboarding-v1`, not deployed, awaiting a payment account.
