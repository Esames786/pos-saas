# Khatri Live Day One

**Bingoo POS — Khatri Biryani live-support chronicle, 2026-08-11 → 2026-08-12**
Production state as of commit **`37a8e9f`** on `feat/14d-2-plan-upgrade-requests` (deployed, verified).

---

## Where the system stands right now

| Area | State |
|---|---|
| Production | Hostinger `187.77.140.39`, app at `/var/www/html/pos-saas`, HEAD `37a8e9f`, no pending migrations |
| Test gates | Fast suite **184 tests / 31,641 assertions** · Full MySQL suite **275 tests / 1,618 assertions** — both green |
| Khatri books | **Reconciled to the rupee.** Day 2026-08-11: 26 orders, 35,970 billed (all cash) − 7,570 refunds = **28,400 net cash** = 18,350 drawer + 10,050 expenses. Trial balance diff 0. GL 1110 = documents = shift = reports |
| Shift #1 (Aug 11) | Closed: expected 28,400 · counted 18,350 · variance −10,050 (corrected; see data fixes) |
| Daily Closing Aug 11 | **Created and tied**: sales 35,970 / refunds 7,570 / expected 28,400 / counted 18,350 / short 10,050. Awaiting owner Approve |
| Expenses | Draft `EXP-SHORT-20260811-S1` = **10,050** (the day's drawer-paid expenses) — owner to reclassify into real categories and post (books Dr 6930 / Cr cash) |
| Print agent | **v2.3.1** on the client PC, one active registration (#6 `DELIVERY COUNTER-4`), five old registrations deactivated. Runs as a boot-time Scheduled Task (SYSTEM), self-restarts, survives reboots |
| Print layouts (Khatri) | KOT font 18 + Reminder 18 (double width & height, 21 chars/line) · Receipt 15 (double height, money columns intact) — all changeable on the layout screen, no deploy needed |
| Print queue | 0 queued / 0 claimed / 2 old failed jobs (14:36 KOT, 14:55 receipt — retryable from the queue screen if those orders matter) |

### Open items / risks

1. **Wildcard cert `*.bingoopos.com` expires 2026-09-17** and cannot auto-renew (manual dns-01, no hook). Needs a DNS-01 hook or manual re-issue before then. ← most time-critical
2. Four `No database selected` errors during trading on Aug 11 — undiagnosed, did not recur.
3. Two failed print jobs from Aug 11 afternoon (pre-2.3.x) — manual retry if wanted.
4. Agent installer is unsigned → SmartScreen "Run anyway" (code-signing cert on backlog).
5. Physical LAN printer certification for the Reminder document remains formally open (works in production, never lab-certified).
6. Layout toggles: any values saved through the pre-fix modal were silently wiped; Khatri's were restored to migration defaults — owner should review the three layouts once in the now-honest UI.

---

## The day, commit by commit (40 commits, oldest first)

### Morning — POS integrity before opening (`dc0b870` → `719d6f2`)

| Commit | What |
|---|---|
| `dc0b870` | Harden POS submissions: `UserDataScope::assertPosSelection` rejects forged branch/terminal; report scoping by allowed terminals/order types |
| `e28aa75` / `adae5e3` | Combo KOT routing fix; combo/modifier/split-bill linkage guard; self-auditing tenant reset |
| `d0ac021` | Delivery orders **require a customer** (hard validation) |
| `439cf22` | Cancellation audit records the correct per-scope approval mode |
| `85ebcfd` / `cedb073` | Sales-return accounting: returns carry discount, prorate tax, refuse component rows; then fix the **double-counted tax** the first pass introduced in report return values |
| `bdfd1db` | Manual POS discounts with per-branch approval mode (`manual_discount_approval_mode`); short cash can no longer become a silent discount |
| `719d2f6` | Repaired two MySQL gates that had been **silently red** (only `--filter` subsets were ever run) — lesson: always run the full suite |

### Midday — live firefighting (`35b08ac` → `46a6fc6`)

| Commit | What |
|---|---|
| `35b08ac` / `869c7c6` | POS dead buttons: helpers declared inside `DOMContentLoaded` were invisible to the customer-modal IIFE — "Add & Attach" / "Save address" silently did nothing. Hoisted to top level + busy-button release no longer resurrects a cleared cart's actions. Guarded by `PosScriptScopeRegressionTest` |
| `ca492f6` | Rider reassignment on delivery orders, with audit trail (Codex, reviewed) |
| `46a6fc6` | **Sale times shown in the timezone they happened** (frozen shift tz → branch → default), not UTC — `TenantClock::formatSale` everywhere |
| `15802c0` | Receipt preview and paper agree; seeder matches the live menu (Biryani Chicken / Biryani Saadi sub-categories, new items inserted live without reseeding) |
| `d8a28ef` | **Zero-quantity sale lines refused** ("stop 0 qty sale") |

### Afternoon — money must reconcile (`e451202` → `752daad`)

| Commit | What |
|---|---|
| `e451202` / `6fbe2ba` / `c201f8f` | Returns and cash made explicit; **refund method now mandatory** — a method-less return posted into Undeposited Funds and never moved cash (that stranded 1,530 live; corrected, see data fixes) |
| `e421721` / `bdc6fcd` | Print agent auto-retry on transient socket errors (max 3); thermal report fits 72mm |
| `8993520` → `d868a7d` | Thermal sales report rebuilt as a true 4-column layout (Item/Reason/Events/-Qty), every section closing with a bridge to NET SALES |
| `e6bcd9d` | Thermal reports bold + larger (12px/700) — counter could not read them |
| `0ec2fc7` | **Every report total reconciles to NET SALES on the page** — the 2,580/3,570/4,170 confusion resolved into one explained arithmetic |
| `752daad` | Dashboard and shift report agree with the Sales Report Centre (paid + partially_returned + returned population, returns deducted by return date) |
| `c66f254` | Sales Summary gave the counter the wrong cash target — now deducts returns and exposes billed vs net |

### Evening — the 350 the client caught (`e49ba3b`)

| Commit | What |
|---|---|
| `e49ba3b` | **A full return now refunds the delivery charge** (a partial keeps it — the rider made the trip). New `sales_returns.delivery_charge_amount`, GL `Dr 4150` reversal, return-screen preview mirrors the server rule, Report Center bridge uses net delivery. The client and the cashier's 22,500 were right; the system was wrong by 350 |

### Night — printing overhaul (`e1098ee` → `287f1d3`)

| Commit | What |
|---|---|
| `e1098ee` | Keep-awake: printers poked every 20 s so the kitchen ticket is not what wakes a sleeping printer (the 17 s / 34 s KOTs). Agent 2.2.0 |
| `0409a5c` / `5a42035` | Version-stamped, uncacheable agent download (then fixing the 500 the first attempt introduced — the test that "passed" had only grepped source) |
| `7138700` / `b61bc5d` | Thermal cancellation markup fixed; live printer profiles documented (BlackCopper + XPrinter, 80mm/42) |
| `847f4fa` | **The layout screen's font size finally reaches the printer.** `scaleFor()` bands: ≤14 normal · 15–17 double height · 18–20 double both (21 chars) · 21+ triple. It had been CSS-preview-only — changing it never altered paper. Kitchen-read fields scale; reference fields stay small; printer-side word-wrap (the printer chops, it never wraps) |
| `e5c471f` | (Codex, reviewed) Agent 2.3.0 keep-awake/printing mutual exclusion; return `lockForUpdate` concurrency; combo return-status fix; terminal-scoped summary returns |
| `f4758c9` | **`@page { size: <paper> auto; margin: 0 }`** on all three print documents — the blank band at the top of the roll was the browser's default 10 mm page margins. Receipt column collision (`11,200.001,200.00`) fixed. Thermal receipt scales height-only, TOTAL bold. **Agent 2.3.1**: pokes parallel with 1.2 s timeout (serial pokes at 4 s each delayed real tickets up to 8 s), printer list can no longer be silently wiped by one heartbeat response |
| `287f1d3` | Bill preview reads like the bill: one line per item (`1x Chicken Biryani (1/2 kg) …… 330.00`), matching the thermal composition, instead of a four-column table that wrapped names over three lines |

### After midnight — the layout switches and the mis-close (`64eb58d` → `37a8e9f`)

| Commit | What |
|---|---|
| `64eb58d` | **Layout switches tell the truth.** The edit modal's JS blob hardcoded 14 of 18 toggles — the missing four always displayed OFF and *saving anything wiped them to false*. Single `ReceiptLayoutSetting::TOGGLE_FIELDS` constant now feeds blob, preview URL, save and preview controller; each document shows only the switches it actually prints (KOT 6, Reminder 8, Receipt 15); preview borrows a real delivery sale so Delivery Details visibly responds; paper KOT gained the branch-name and vehicle gates its preview already had. Bill preview also carries the paper's size bands + wider on-screen slip |
| `81bfd77` | **No close may invent a count.** All three close flows (shift, Close Branch, standalone Daily Closing) silently turned a blank count into 0 — exactly how a cashier closed a 28,400 drawer at 0. Every flow now requires an explicit count (typed 0 allowed); an untouched denominations grid counts as *no* count. **Daily Closing aggregates by frozen `business_date`**, not the UTC date of `closed_at` — an overnight shift no longer lands on the wrong day or gets double-counted |
| `37a8e9f` | Close forms say the count is required (the hint still read "leave empty…") |

---

## Live data corrections (all on production, all backed up to `/root/backups/`, all fingerprint-verified)

| # | What | Amount | Writes |
|---|---|---|---|
| 1 | Returns posted with no refund method stranded money in **1500 Undeposited Funds** | 1,530 | Correcting GL entries (`sales_return_correction`); 1500 nets 0 |
| 2 | Three full returns never refunded their **delivery charges** (`HS-…413` 100, `SO-…594` 150, `HS-…284` 100) | 350 | `sales_returns` + `Dr 4150 / Cr 1110` journals + cash-bank rows + shift counters **by delta** |
| 3 | **Shift #1 closed at counted 0** instead of 18,350 | — | `shifts` #1 counted/variance/notes; draft `EXP-SHORT-20260811-S1` resized 28,400 → 10,050 |
| 4 | Aug 11 **Daily Closing created** through the real deployed controller as owner | — | one `daily_closings` row; proved no duplicate shortage voucher |

Every script asserted its exact expected state before writing, refused anything else, and fingerprinted untouched tables (sales / payments / stock / journals / expenses) before and after — all `UNCHANGED`.

---

## Regression tests added in this arc

`SaleTimeDisplayRegressionTest` · `PosScriptScopeRegressionTest` · `ZeroQuantityLineRegressionTest` · `RefundMethodRequiredRegressionTest` · `ThermalSalesReportLayoutRegressionTest` · `DashboardMatchesReportCentreRegressionTest` · `AgentDownloadIsVersionedRegressionTest` · `PrintAutoRequeueMySqlTest` · `SalesReturnIntegrityMySqlTest` (delivery-refund rule) · `KotTextScaleRegressionTest` · `PrintAgentKeepAwakeRegressionTest` · `ReceiptLayoutToggleIntegrityTest` · `DailyClosingReconciliationMySqlTest`

---

## Hard-won lessons (also in memory)

- **Three print paths, not one**: agent ESC/POS KOT · agent ESC/POS receipt · **browser** bill-preview via Windows driver. Identify which path a complaint is about before changing code.
- The printer never wraps — it chops. Anything scaled must be wrapped to the width the scale leaves.
- Receipts scale **height-only**: double width halves 42 columns to 21 and the money stops aligning.
- A blank form field must never become a silent 0 in a money flow.
- Shift counters move by **delta**, never rebuilt — a rebuild drops whatever the formula forgets.
- Numeric-string PHP array keys become ints; `'1110' === $key` is always false in a foreach.
- Run the **full** MySQL suite before claiming green — two gates sat silently red for days.
- When bumping the agent version, rebuild **both** the exe and the installer — the download endpoint reads the version from source but serves the dist bytes.

*Compiled 2026-08-12 · session covering 2026-08-11 00:00 → 2026-08-12 04:00 PKT*
