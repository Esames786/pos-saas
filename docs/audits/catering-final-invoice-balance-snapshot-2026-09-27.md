# Catering Final Invoice: Stale `balance_due` Snapshot

Date: 2026-09-27 (Asia/Karachi)
Scope: Full zero-to-end trace of one live catering invoice reported as "invoice shows a due, screen shows none", widened to every final invoice on the tenant
Production tenant inspected: `kashifkitchen`
Implementation status: **investigation only — read-only. No production row, journal, or file was modified.**

## Executive conclusion

The ledger is correct. The event screen is correct. The **printed invoice, the thermal slip, the calendar, and the events list are all wrong**, because they read a column that is written once and never refreshed.

`catering_final_invoices.balance_due` (with `advance_total` and `advance_applied`) is a value frozen at the moment the invoice is issued. Money that arrives *after* issue is posted correctly to the general ledger but never written back to that column. Since the real-world sequence at this tenant is almost always **issue the invoice, then collect**, the frozen value is wrong on nearly every invoice.

Two of the three final invoices on the tenant are affected. One of them is an operational block, not only a cosmetic one: booking `EV-20260925-0066` is `completed` and fully paid, and its **Close booking** action is hidden because the gate reads the frozen column.

This is a single-cause defect with five consumers. It is not a posting bug, and it needs no journal correction.

## The trace — CI-20260925-0002

Booking `EV-20260925-0066`, customer `MR,SHEHZAD`, grand total 38,000.

| Time | Event | Journal |
| --- | --- | --- |
| 2026-09-25 11:39:54 | Final invoice `CI-20260925-0002` issued | JE#14 `catering_final_invoice` — Dr **1300** 38,000, Dr **4200** 500 / Cr **4160** 38,500 |
| 2026-09-25 11:41:04 | Advance #12 received, 38,000, `posting_type = settlement`, `cash_bank_account_id = 3` | JE#15 `catering_settlement` — Dr **1210** 38,000 / Cr **1300** 38,000 |

Net movement for this booking after both entries:

```
1300  Accounts Receivable   = 0
2300  Customer Advances     = 0
1500  Undeposited Funds     = 0
```

The customer owes nothing, and the ledger says so.

`CateringFinancialPositionService::position()` agrees:

```
gross_received 38,000   net_received 38,000
billed         38,000   billed_source 'invoice'
applied        38,000   balance_due   0
```

The invoice row does not:

```
grand_total   38,000
advance_total 0    advance_applied 0    balance_due 38,000
status issued      journal_entry_id 14
advance_application_journal_entry_id NULL
```

Seventy seconds separate the frozen value from the truth.

### The system classified the receipt correctly

`posting_type` on advance #12 is `settlement`, not `advance`. The posting layer correctly recognised that this money arrived after invoicing and credited **1300** rather than **2300**. Nothing in the posting logic needs to change. The only omission is that no one told the invoice row.

## Blast radius — every final invoice on the tenant

Comparing the frozen column against a live `position()` for each invoice:

```
CI-20260909-0001   issued   frozen = 489,605   live = 0   MISMATCH
CI-20260925-0002   issued   frozen =  38,000   live = 0   MISMATCH
CI-20260927-0003   issued   frozen =       0   live = 0   ok
```

`CI-20260909-0001` (booking `EV-20260909-0002`, `mr. farhat hussain`) follows the identical shape:

| Time | Event |
| --- | --- |
| 2026-09-09 12:12:34 | Invoice issued, 489,605 |
| 2026-09-09 12:17:58 | Advance #1 — 200,000, `settlement`, JE#2 |
| 2026-09-09 12:19:00 | Advance #2 — 289,605, `settlement`, JE#3 |

The third invoice is clean only because its money arrived **before** the invoice, so the value frozen at issue time was already correct. That is the exception, not the rule: two of three invoices are wrong, and the failing pattern is the ordinary one.

## The consumers

One column, six read sites. Five of them are wrong whenever money follows the invoice.

| Location | Use | Effect |
| --- | --- | --- |
| `resources/views/tenant/catering/documents/final-invoice.blade.php:177-178` | Prints "Advances Received" and "Balance Due" | Customer receives a paper demanding money already paid |
| `resources/views/tenant/catering/documents/final-invoice.blade.php:181` | Gates the `FULLY PAID` stamp on `balance_due <= 0` | The stamp can essentially never appear |
| `app/Services/Catering/CateringDocumentPrintService.php:149` | `balance` in the thermal print payload | Same wrong figure on the counter slip |
| `app/Services/Catering/CateringCalendarService.php:145` | `balance_due > 0 ? 'Balance Due' : 'Complete'` | Paid bookings sit on the calendar labelled "Balance Due" |
| `resources/views/tenant/catering/events/index.blade.php:190-192` | Row balance on the events list | List and detail screens disagree with each other |
| `resources/views/tenant/catering/events/index.blade.php:368` | Gates the **Close booking** action on `balance_due <= 0` | **A fully paid, completed booking cannot be closed from the UI** |

One reader is correct and should stay as it is: `app/Http/Controllers/Tenant/Catering/CateringFinalInvoiceController.php:24` puts the balance in a flash message *at issue time*, when the frozen value is still true.

The events list carries an explicit comment at line 188 — `// the invoice's own frozen balance is the authority`. That was the design decision, and it is the root of this report. Treating the column as authoritative is only safe if something keeps it fresh, and nothing does.

### Confirmed operational block

```
EV-20260909-0002   status=closed      CI-20260909-0001   frozen_due=489,605   close_button=HIDDEN
EV-20260925-0066   status=completed   CI-20260925-0002   frozen_due= 38,000   close_button=HIDDEN
```

`EV-20260925-0066` is stuck: completed, paid in full, and with no route to `closed` through the interface.

## What is *not* broken

Stated explicitly so no one "fixes" the wrong layer:

- The general ledger. Both journal entries are balanced and posted to the right heads.
- `posting_type` classification. Post-invoice receipts are correctly marked `settlement` and hit 1300.
- `CateringFinancialPositionService::position()`. Verified against the raw ledger on both affected bookings.
- The event detail screen, which already computes live from `position()`.

No journal correction, reversal, or repost is required. The ledger does not need touching.

## Recommended fix

**Keep the column fresh; do not convert every reader to a live computation.**

A live `position()` per row is the obvious instinct, but the calendar and the events list render dozens of bookings at a time and would pay a per-row cost for it. The cheaper and more durable shape is to make the column a derived value with exactly one writer.

1. **One choke point.** Whenever money moves on an event — advance created or voided, refund posted, invoice re-issued — recompute `advance_total`, `advance_applied`, and `balance_due` from `position()` and write them back. One function, so a future payment path cannot reintroduce this by forgetting.
2. **Backfill command** for the two existing rows. Dry-run by default, writes only on `--yes`, and touches **only those three columns — no journal lines**, because the ledger is already right.
3. **Guard test.** Issue an invoice, post a settlement, then assert `balance_due` is 0, the `FULLY PAID` stamp renders, and the **Close booking** gate opens. The guard must fail against the current code before it is trusted — otherwise it only proves the test runs.

Optionally, the printed invoice may also compute live as a belt-and-braces measure; it renders one document at a time and can afford it. The snapshot columns remain useful as a historical record of the position at issue.

## Reproduction

Read-only, against production:

```bash
ssh -i ~/.ssh/bingoo_prod root@187.77.140.39
cd /var/www/html/pos-saas
sudo -u www-data XDG_CONFIG_HOME=/tmp/psy HOME=/tmp/psy php artisan tinker --execute="
  \$t = App\Models\Master\Tenant::where('tenant_code','kashifkitchen')->first();
  app(App\Services\Tenancy\TenancyManager::class)->activate(\$t);
  \$svc = app(App\Services\Catering\CateringFinancialPositionService::class);
  foreach (App\Models\Tenant\CateringFinalInvoice::with('event')->get() as \$inv) {
      \$p = \$svc->position(\$inv->event);
      printf('%-20s frozen=%10s live=%10s %s' . PHP_EOL,
          \$inv->invoice_no,
          number_format((float) \$inv->balance_due, 0),
          number_format((float) \$p['balance_due'], 0),
          abs((float) \$inv->balance_due - (float) \$p['balance_due']) > 0.01 ? 'MISMATCH' : 'ok');
  }
"
```

Two environment notes, both learned the hard way:

- `php artisan` on production must run as `www-data`, never as root. A root-owned `storage/logs/laravel-<date>.log` returns 500 on every request that logs.
- `XDG_CONFIG_HOME` and `HOME` are redirected because `www-data` cannot write psysh's default config path, and tinker aborts without them.

## Related

- `docs/explainers/catering-customer-balances-2026-09-27.html` — the customer-facing explainer of how catering balances, advances, and the ledger interact. It describes the intended behaviour; this report documents where the implementation departs from it.
