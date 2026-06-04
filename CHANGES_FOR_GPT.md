# POS SaaS — Recent Changes Summary (Last 2 Commits)

Share this with GPT to give it full context of what was built and how the system works.

---

## Project Stack

- **Laravel 13** multi-tenant SaaS POS
- **Master DB** (`pos_saas_master`): tenants, domains, plans
- **Tenant DB** (`pos_tenant_{code}`): all business data per tenant
- `TenancyManager::activate($tenant)` switches default DB connection to tenant
- Demo tenant: `http://demo.pos-saas.test` — `owner@demo.com / password`

---

## Commit 1 — `feat: fake printer + demo printer/agent seeder`

**What was added:**

### `tools/print-agent/fake-printer.js`
A Node.js TCP server on `127.0.0.1:9100` that acts as a fake thermal printer. Any raw ESC/POS text sent to it is printed to the console. Used for local testing without real hardware.

### `TenantDemoSeeder::seedPrintersAndAgent()`
Seeds the tenant DB with:
- **Fake Kitchen Printer** — network, KOT role, `127.0.0.1:9100`, Main Branch, `agent_enabled=true`
- **Fake Receipt Printer** — network, receipt role, `127.0.0.1:9100`, Main Branch, `agent_enabled=true`
- **Print Agent** — code `AG-DEMO-LOCAL`, token `demo-local-agent-token-for-testing-only-change-in-prod`, `branch_id=null` (handles all branches)
- **TerminalPrinterSetting** for all 4 terminals mapped to both fake printers with `auto_print_receipt=true` and `auto_print_kot=true`

---

## Commit 2 — `changes` (Prompt 11B — Printing/KOT Stabilization)

This was a large stabilization commit. Everything below was added or fixed.

---

### New/Replaced: `PrintRoutingService`

**File:** `app/Services/Printing/PrintRoutingService.php`

KOT routing priority per line:
1. `CategoryPrinterMapping` for product's category (branch-specific or global)
2. Terminal's `kot_printer_id` from `TerminalPrinterSetting`
3. Default active KOT printer for the sale's branch
4. Browser/manual fallback (`printer = null`)

Key methods:
```php
receiptPrinter(SalesOrder $sale): ?Printer
defaultKotPrinter(SalesOrder $sale): ?Printer
kotRoutesForSale(SalesOrder $sale, array $onlyLineIds = [], bool $isReprint = false): array
// Returns: [['printer' => Printer|null, 'line_ids' => [], 'line_quantities' => ['id' => qty]]]
```

---

### New/Replaced: `PrintJobService`

**File:** `app/Services/Printing/PrintJobService.php`

Constructor injects `PrintRoutingService`. Key behaviors:

- `queueReceipt(SalesOrder $sale)` — resolves receipt printer internally, creates job
- `queueKot(SalesOrder $sale, ..., bool $isReprint)` — **returns array** of jobs, handles routing internally
- `markKotLinesQueued()` — stamps `kot_sent=true, kot_sent_quantity=quantity` at queue time **only for network printer routes** (NOT browser fallback — otherwise the preview would show empty items)
- `markKotLinesPrinted()` — second confirmation when agent or user marks printed
- `markPrinted(PrintJob $job)` — uses `DB::connection('tenant')->transaction()`, calls `markKotLinesPrinted()`, increments print counts
- `nextJobNo()` — uses `random_int` (removed broken `lockForUpdate` outside transaction)

**CRITICAL RULE:** For browser fallback jobs (`printer = null`), lines are NOT marked as sent at queue time. They are only marked when the user clicks "Mark Printed" on the preview page.

---

### New/Replaced: `EscPosPayloadService`

**File:** `app/Services/Printing/EscPosPayloadService.php`

`build(PrintJob $job)` — passes `$job` to `kot()` method.

KOT method uses `$job->payload['line_quantities']` (stored at creation time) instead of recalculating from model. This prevents drift if model state changes between queue and print.

```php
// Payload structure for KOT jobs:
[
    'sales_order_id'  => int,
    'sale_no'         => string,
    'printer_id'      => int|null,
    'line_ids'        => [int, ...],
    'line_quantities' => ['line_id' => float, ...],  // exact qty to print
    'is_reprint'      => bool,
    'fallback'        => bool,
]
```

Reprint: prints `** REPRINT **` banner, uses full `quantity` not delta.
Delta KOT: uses stored `line_quantities` which already contain the delta calculated at queue time.

---

### Updated: `PrintJobController`

**File:** `app/Http/Controllers/Tenant/PrintJobController.php`

- Removed `PrintRoutingService` from constructor (routing now inside `PrintJobService`)
- Removed raw `SalesOrderLine::whereIn()->update()` block (now handled by service)
- `queueKot()` keeps `?reprint=1` support and JSON responses for POS AJAX
- `retry()` now also resets `claimed_by_agent_id` and `claimed_at`
- Kept all JSON response shapes the POS JS depends on

---

### Updated: `PrintAgentApiController`

**File:** `app/Http/Controllers/Tenant/Api/PrintAgentApiController.php`

`pending()` now only returns jobs where:
- `printer_id IS NOT NULL`
- `printer.printer_type = 'network'`
- `printer.agent_enabled = true`
- `printer.ip_address IS NOT NULL`

Uses `DB::connection('tenant')->transaction()`.

`printed()` and `failed()` now route through `PrintJobService` so KOT lines get confirmed/failed properly.

---

### Updated: `PrintDocumentController`

**File:** `app/Http/Controllers/Tenant/PrintDocumentController.php`

Uses `$printJob->reference_id` (with `payload['sales_order_id']` as fallback) instead of relying on payload key only.

For KOT preview: passes `$isReprint` to view, pre-filters lines using delta (`quantity - kot_sent_quantity > 0`) for non-reprint jobs.

---

### Updated: `SalesOrderLine` model

**File:** `app/Models/Tenant/SalesOrderLine.php`

Added `kitchen_note` to `$fillable`. Updated `kot_sent_quantity` cast precision to `decimal:6`.

---

### Updated: Browser Preview Views

**Files:** `resources/views/tenant/printing/documents/kot.blade.php` and `receipt.blade.php`

Both now have:
- "Mark Printed" form button (POST to `/printing/jobs/{id}/mark-printed`) — required for USB/manual fallback
- KOT view shows delta qty or full qty based on `$isReprint` flag
- Product name uses stored `$line->product_name` not live `$line->product?->name`
- Receipt uses `sale_date` not `created_at`

---

### Updated: POS JavaScript

**File:** `resources/views/tenant/pos/index.blade.php`

**Bug fixes:**

1. **`terminalAutoKot('')` now returns `false` when no terminal selected**
   - Before: checked if ANY terminal had auto_kot, always returning `true` and firing silently
   - After: no terminal = always show Swal "Print Kitchen Order?" prompt

2. **`fireKotSilently()` now checks response**
   - Before: response thrown away (`.catch(() => {})` only)
   - After: checks `data.jobs[].fallback` → opens `preview_url` in new tab for manual printing

3. **Toast/Swal ordering fix in `submitHeldSale()`**
   - Before: `handleKotAfterSale()` then `toast()` — toast killed the Swal dialog immediately
   - After: `toast()` first, then `handleKotAfterSale()` — dialog appears after toast

4. **`openFallbackPreviews(data)` shared helper**
   - Applied to all 4 fire-and-forget handlers: `fireKotSilently`, `requeueSingleJob`, `reprint-all-kot-btn`, `reprint-receipt-btn`
   - Handles both KOT (`data.jobs[]`) and receipt (`data.fallback`) response shapes

---

### New Migrations

| Migration | What it does |
|-----------|--------------|
| `2026_05_20_000001` | Adds `kot_sent` boolean to `sales_order_lines` (idempotent) |
| `2026_05_20_000002` | Adds `kot_sent_quantity` decimal to `sales_order_lines` (idempotent) |
| `2026_05_21_000001` | Stabilise KOT columns, normalise `pending→queued` in print_jobs |
| `2026_05_21_000002` | Drops dead `kot_printed_quantity` column from `sales_order_lines` |
| `2026_05_21_000003` | Makes `category_printer_mappings.branch_id` nullable (global mappings) |

---

### Print Agent JS Files

**Files:** `tools/print-agent/print-agent.js` and `pos-test-agent.js`

- Idle counter: logs `[IDLE] No pending network print jobs` every 20 ticks instead of being silent
- `processJob()` now SKIPs browser-type and null-printer jobs instead of marking them as failed
- `pos-test-agent.js` is the combined test agent (fake printer + agent in one process)
- `config.json` format: `{ "baseUrl": "http://demo.pos-saas.test", "agentCode": "AG-DEMO-LOCAL", "token": "...", "pollMs": 3000 }`

---

## How Printing Works End-to-End

### Auto silent printing (network printer)
```
POS hold/complete → handleKotAfterSale() → fireKotSilently()
→ POST /printing/jobs/kot/{saleId}
→ PrintJobController::queueKot()
→ PrintJobService::queueKot()
→ kotRoutesForSale() → finds network printer
→ createKotJob() → job created with line_quantities in payload
→ markKotLinesQueued() → kot_sent_quantity stamped
→ Agent polls /api/print-agent/pending
→ Agent sends raw_payload to printer IP:9100
→ Agent calls /api/print-agent/jobs/{id}/printed
→ PrintJobService::markPrinted() → markKotLinesPrinted() confirms
```

### Manual / browser fallback
```
Same flow until kotRoutesForSale() finds no printer
→ createKotJob() with printer_id=null, fallback=true
→ Lines NOT marked at queue time
→ JSON response has fallback=true + preview_url
→ POS JS opens preview in new tab
→ Cashier presses Ctrl+P → selects USB/Windows printer
→ Cashier clicks Mark Printed
→ PrintJobService::markPrinted() → markKotLinesPrinted() marks lines
```

### No Terminal + City Branch scenario
```
terminalAutoKot('') = false → Swal prompt appears
User clicks "Print KOT" → fireKotSilently()
Server: no terminal, no category mapping, City Branch printers not found
→ browser fallback → preview opens
```

---

## Known Limitations

1. **Duplicate browser fallback jobs**: If user holds twice before clicking "Mark Printed", two KOT jobs are created (lines not marked at queue time = delta always = full qty). Accepted trade-off.
2. **Per-row Reprint creates new full-sale reprint job** (not the specific existing job). Preview opens correctly via `openFallbackPreviews` but a new job is created each time.
3. **No category printer mappings in demo data**: KOT routing always uses terminal → branch fallback. Category routing code works but is untested with real data.
4. **City Branch + No Terminal = always browser fallback**: Both printers are on Main Branch. Branch filter won't find them without a terminal selected.
