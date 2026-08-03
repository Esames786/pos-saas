# LOCAL-PRINT-LAN-CERT-1

Date: 2026-08-03
Candidate: `a0452e7` on `feat/14d-2-plan-upgrade-requests`
Status: **BLOCKED - real LAN printer topology is not available from this environment**

## Certification boundary

This was a certification sprint, not a feature sprint. No printing behavior, Print Agent binary, Local Mode, offline sales, or synchronization code was changed. Production was not deployed.

The software preflight and rollback-only database proofs passed. Physical paper output, printer failure/recovery, and multi-terminal LAN behavior cannot be certified against the currently configured topology because it contains only loopback fake printers.

## Repository preflight

| Check | Result | Evidence |
|---|---|---|
| Candidate commit | PASS | HEAD `a0452e70818bd140e881b38024ad196af38fb609` |
| Working tree | PASS | Only `tools/print-agent/dist/FakePrinter.exe` is untracked; it was not staged or modified |
| Commit contents | PASS | 32 files; no PsySH history, scratch, temporary QA file, or Print Agent binary |
| PHP lint | PASS | Every PHP file changed by `a0452e7` reports no syntax errors |
| Automated tests | PASS | 12 passed, 44 assertions; 3 SQLite-only routing tests skipped because local PHP lacks `pdo_sqlite` |
| Config, route, and Blade caches | PASS | All compiled successfully |

## Observed topology

The local `demo` tenant contains Main Branch, City Branch, and Mall Outlet.

| Device | Observed configuration | Certification use |
|---|---|---|
| Fake Kitchen Printer | Network type, KOT role, 80mm, `127.0.0.x:9100`, Reminder capability off | Fake output only; not a physical LAN printer |
| Fake Receipt Printer | Network type, Receipt role, 80mm, `127.0.0.x:9100`, Reminder capability off | Fake output only; not a physical LAN printer |
| Demo Local Agent | Windows agent at masked LAN address `192.168.1.x`; last seen 2026-06-26 | Stale/offline for this certification session |

There are no category printer mappings and no Reminder-capable printer. Printer make/model, reachable LAN IP/port, POS terminal/browser, and active Print Agent machine were not available.

## Completed software evidence

### Non-fiscal rendered output

A rollback-only test injected fiscal values into a Reminder payload and rendered both ESC/POS and HTML output. These labels and marker values were absent from both outputs:

`SUBTOTAL`, `DISCOUNT`, `TAX`, `SERVICE CHARGE`, `TIP`, `TOTAL`, `PAID AMOUNT`, `BALANCE`, `PAYMENT METHOD`, `CERTIFICATION-CARD`, `98765.43`, and `12345.67`.

- ESC/POS SHA-256: `6e71b1931aefcbdb217c8c889bc050f9edb8773b7a53e9ad35114dfde133eae3`
- HTML text SHA-256: `30ca78b254eae15958324c897d5e2148d5f27762e06fad2da97041a3aba3a306`

### Historical Reminder destination

A rollback-only MySQL scenario created two prior Reminder destinations, removed their current mappings, and generated a cancellation correction:

- one prior Reminder job had status `printed`;
- one prior Reminder job had status `queued`, representing an offline agent;
- both printer IDs received exactly one correction Reminder after mapping removal;
- no synthetic rows remained after rollback.

### Tenant and Edge smoke

All seven tenants reported zero unbalanced journal entries, zero negative stock on branches that disallow it, and zero negative department stock. `EDGE_FEATURE_ENABLED=false` was confirmed.

The immutable candidate already records rollback-clean MySQL evidence for the four-order-type routing matrix and a real two-process enqueue race converging on one logical Reminder job. No application code changed after that evidence.

## Physical certification matrix

| Scenario | Status | Blocker |
|---|---|---|
| Baseline Dine In KOT and Add Round `(R)` | BLOCKED | No reachable physical KOT printer |
| Skip KOT and browser fallback | BLOCKED | Requires controlled POS and printer topology |
| Agent disconnect/reconnect | BLOCKED | Agent not active in this session |
| Complete-order Reminder fan-out | BLOCKED | No Reminder-capable physical printer |
| Same printer KOT + Reminder + Receipt | BLOCKED | No capable physical printer topology |
| 58mm/80mm layout and paper evidence | BLOCKED | No physical printer or output photo/PDF |
| Updated Reminder Revision 2/3 | BLOCKED | No physical Reminder printer |
| Ask/Auto and mixed-rule precedence | BLOCKED | No two-printer Reminder topology |
| Four-order-type physical matrix | BLOCKED | No physical mapped printers |
| Manual duplicate counters on paper | BLOCKED | No physical Reminder printers |
| Partial/whole cancellation output | BLOCKED | No physical KOT/Reminder printers |
| Queue, claim, failure, and reconnect | BLOCKED | No active real agent/printer pair |
| Receipt regression on paper | BLOCKED | No physical Receipt printer |
| Two POS terminal concurrency | BLOCKED | Two controlled terminals unavailable |

## At-least-once limitation

The current Print Agent contract is at-least-once. If paper prints but acknowledgment is lost, retrying the same logical job can produce another physical copy. This session could not reproduce that behavior on hardware, so exactly-once printing is not claimed.

## Requirements to resume

1. One active branch and POS terminal/browser on the intended LAN.
2. An online Print Agent paired to that tenant/branch.
3. At least two reachable physical printers with make/model, masked IP, port, and paper width recorded.
4. At least one printer with KOT + Reminder capability; a second Reminder printer for Ask/Auto and per-printer duplicate tests.
5. Category and order-type mappings matching the certification matrix.
6. Permission to stop/restart the agent and temporarily make a printer unreachable.
7. Photos, PDFs, or captured text from each physical output.

Once available, execute the physical matrix and classify the same immutable candidate as `CERTIFIED` or `CERTIFIED WITH KNOWN AT-LEAST-ONCE RETRY LIMITATION`.

## Result

**BLOCKED** for real LAN certification. Software preflight is green, but `a0452e7` is not yet a physically certified printing release candidate.
