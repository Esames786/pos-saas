# PRINT-OFFLINE-HERE-1: when the print agent is gone, the counter prints its own slips (20 Nov 2026)

## The owner's ask
19–20 Nov, Khatri Biryani: the shop internet went down. The only print agent (SERVER-PC, 192.168.100.41) was last
seen 19 Nov 17:46:52 PKT. After that nothing printed:
- 81 slips that night were cancelled by `printing:autoclose-stuck` after 60 minutes.
- On 20 Nov the Delivery counter worked on a dongle from 14:17, and 18 of its slips sat in the queue.
- Nobody on the counters knew; the POS showed nothing.

Owner: *"agent ka response nahi aa raha to matlab printer DC ho gaye … us ke system se hi saare KOT aur preview, bill, receipt
ka popup aaye, manual print force pe."* Not after a 15-second wait: the system must already know.

Decisions (owner asked me to suggest; these are my defaults, written here so they can be changed):
1. **15 seconds** of agent silence = offline. The agent reports every 3 s (heartbeat) and polls every 1–3 s.
2. When the agent is offline, **kitchen KOTs print at the counter too** (a runner takes them to the kitchen).
3. Every counter PC already has its printer driver installed (owner, 20 Nov). Off the LAN (hotspot) only a USB printer works.

## What exists today
| Thing | What it does | Why it did not help |
|---|---|---|
| **Last Print** button (printer icon, POS bottom right) → *Recent Prints* | Lists the jobs of the **last sale only**. Print Here on **failed** rows; Reprint puts the job back on the network queue | With the agent gone, a job stays `queued` forever. It never becomes `failed`, and Reprint queues a second copy that never prints either |
| **POS-PRINT-HERE** (`804e7afb`, 23 Aug) | 4/9/15 s after a print, if a job is **failed**, a popup offers "Print Here" | Only the agent marks a job failed. With PRINTER-HEALTH-1 an unreachable printer is *deferred*, not failed. So the popup almost never shows, and never when the agent itself is gone |
| No-printer fallback | A job with **no printer** (`printer_id` NULL) opens a preview tab with Mark Printed | Only for counters with no printer set up, not for a printer that cannot be reached |
| Printers page "Health" | The agent's last ping per printer | Shows the **last** result forever: "Online · 4ms" 21 hours after the agent died |

## The change (no agent change, no new switch, no new column)
### Server
1. **`PrintHealthService`** (new, `app/Services/Printing`):
   - `forTerminal(branchId, terminalId)` answers three things:
     - **state:** `ok`, `offline`, or `none` (no network printer, so nothing to show).
     - **agent:** name, PC, seconds since it was last seen.
     - **waiting:** this terminal's queued network jobs nobody has printed.
   - Agent eligibility mirrors `PrintAgentApiController::pending()` exactly: active, branch NULL or the job's branch,
     terminal NULL or the job's terminal. Online = `last_seen_at` within 15 s.
2. **`GET /api/pos/print-health?terminal_id=`** (`tenant.api.pos.print-health`). It falls under the `tenant.api.pos` prefix
   already open in `EnsureRoutePermission`, so no role needs a new permission. Terminal access is checked through
   `UserDataScope::operatorTerminalId`.
3. **`POST /printing/jobs/{printJob}/print-here`** (`tenant.printing.jobs.print-here`, also an open prefix) takes one job
   off the network queue and gives it to the counter:
   - Allowed only while the job is `queued` and not claimed in the last 2 minutes (the same lease the agent uses).
   - Uses `PrintJobService::markPrinted()`, so KOT sent-bookkeeping and print counters behave exactly as for a normal print.
     It also records `payload.printed_here = {at, by, reason}`.
   - Already `printed` → returns `already_printed`, and the counter does **not** print it again. This covers the race
     where the agent came back a second earlier. Claimed → 409.
   - Access is checked with the controller's existing `assertPrintJobAccess`.
4. **Printers page:** a red banner when no agent is online. Health then reads "Pata nahi · aakhri jaanch …" instead of the
   stale "Online". The page header also shows when the agent was last seen.

### POS (`resources/views/tenant/pos/index.blade.php`)
1. **Printer light** in the context row, next to Shift and Reminder:
   - It polls `/api/pos/print-health` every 10 s, every 3 s while red, and immediately after any KOT or bill is sent
     (hooked into `watchPrintFailure`, which every print path already calls).
   - 🟢 *Printer chal rahe hain* · 🔴 *Printer se rabta nahi · parchi yahin niklegi*. The tooltip names the agent PC and its
     last-seen time.
2. **Forced print queue:** while red, any waiting job of this terminal opens the Print Here window by itself. The existing
   `#printHereModal` grows a list: KOTs first, then the bill. For each job:
   - `print-here` → iframe preview → print dialog → next job on `afterprint`.
   - Jobs the agent printed meanwhile are skipped and marked "printer se nikal chuki".
3. **Stuck banner** (red state, waiting jobs not yet taken): *"N parchiyan printer tak nahi pahunchin — Sab yahin print
   karo"*. This is the manual door for the same queue.
4. **Recent Prints:** "Print Here" also on `queued` rows while red, beside Reprint.

### Not changed
- Job creation, routing and the agent.
- `fallback` (no-printer) behaviour and the failed-job popup (still there for a printer that fails while the agent is alive).
- Reminder confirmation, the autoclose command (it now finds nothing to cancel, because the counter took the jobs).

## Before → after on screen
| Where | Before | After |
|---|---|---|
| POS context row | Shift · Reminder | Shift · Reminder · **printer light** |
| Order saved, agent offline | Nothing happens; slip waits; cancelled at 60 min | Print window opens within ~1 s: KOT(s), then the bill |
| Agent goes down mid-service | Nobody knows | Light turns red within 15 s; waiting slips listed with one button |
| Recent Prints, agent offline | Queued rows: only Reprint (useless) | Queued rows also get **Print Here** |
| Printers page, agent offline | "Online · 4ms" (stale) | Red banner "agent … offline since …"; Health "Pata nahi · aakhri jaanch …" |
| Agent comes back | — | Light green in ≤10 s; new slips go to the network; nothing prints twice |

## Impact (system map, built 17 Nov 2026 15:13 UTC)
- **Tables:**
  - `print_agents` read (`is_active`, `branch_id`, `terminal_id`, `last_seen_at`).
  - `print_jobs` written only through `PrintJobService::markPrinted` (plus `payload.printed_here`).
  - `printers` read. No migration.
- **Screens:**
  - `/pos`.
  - `/api/pos/print-health` (new).
  - `/printing/jobs/{printJob}/print-here` (new).
  - `/api/pos/print-jobs/{saleId}` (adds `print_status` use only).
  - `/printing/printers`.
- **Permissions:** none new. Both routes are under prefixes `EnsureRoutePermission` already lets through, and data scope is
  enforced in the controllers.
- **Tests:**
  - New `tests/MySql/PrintOfflineHereMySqlTest.php`.
  - Existing printing and POS tests run as the related set.

## Tests (MySQL, DB set `_poh`)
- Health is `ok` with a fresh agent and `offline` after 15 s of silence. A terminal-pinned agent does not count for another
  terminal. A different branch's agent does not count. An inactive agent does not count.
- The waiting list holds only this terminal's queued network jobs: not printed or cancelled ones, not no-printer
  fallback ones.
- print-here on a queued KOT:
  - The job becomes `printed`; KOT lines are marked sent; `kot_print_count` goes up; `payload.printed_here` is set.
  - The agent's `pending` no longer returns it.
- print-here on an already printed job returns `already_printed` and changes nothing. On a job claimed 30 s ago it returns
  409. On another terminal's job (pinned user) it returns 403.
- The POS renders the light, and the Printers page renders the offline banner. Both are tested through the controller.
- Sabotage each guard (the 15 s window, the claim lease, the printed check, the terminal filter) and watch a test go red.

## Local demo
- Worktree `D:/laragon2/www/pos-saas-printhere`, with a copy of Khatri from the laptop backup `20261109_093634`. Prod is
  never touched.
- The local agent `node tools/print-agent/print-agent.js` and a fake printer give the green state. Stopping the agent gives
  red after 15 s; then save an order.

## Rollout
- Deploy only. No agent reinstall, no permission grant, no switch.
- Applies to every tenant, but only acts when a terminal's agent is silent.

## Results (20 Nov 2026, worktree `pos-saas-printhere`, branch `feat/print-offline-here-20261120`)
- **New test** `PrintOfflineHereMySqlTest`: **14/14 green** (64 assertions). On a fresh DB set `_poh2`, the POS render
  test passed both before and after it: 24/24.
- **Sabotages, all RED, files restored byte-identical:**

  | Sabotage | Tests that went red |
  |---|---|
  | A. 15 s widened to 1 h | 3 |
  | B. Claim lease ignored | 1 |
  | C. `already_printed` removed | 2 |
  | D. Terminal filter dropped | 2 |
  | E. Printers page keeps the stale "Online" | 1 |
- **Related MySQL set: 172/172 green** (904 assertions). It covers:
  - agent contract, printer health, printer form, agent delete, print-prepare;
  - bill preview, POS render, lifecycle, routing, reminder pause, KOT reprint and split, cancel KOT terminal;
  - payload resilience, slip end, recall hidden item, move table, held dead session, shift handover;
  - hide amounts, online inbox, catering send-to-printer, report network bridge.
- **Unit + feature:** 122 tests, 5 red. **All 5 are red on unchanged prod code too:**
  - `EscPosReportPayloadTest` (two decimals);
  - `AgentDownloadIsVersioned` ×2;
  - `DeliveryRiderReassignment` (`'rider'` is missing from `POSController` on prod);
  - `PosScriptScope`: the main block declares `var id` at prod line 6575, and the test flags the customer block's `$id(`.
    It was run on the canonical checkout and is red there too.
- **Rendered POS:** all 7 inline scripts pass `node --check`.
- **The POS render red seen once on `_poh`** came from the shared master test DB. A mid-run test mapped
  `tenant.pos.index` → `tenant.pos` in `route_catalogs`, but that DB has only 3 modules. It was green on a fresh set.
- **Owner UX:**
  - A print window the counter closes without printing does **not** reopen by itself. Those slips stay on the red bar's
    "Sab yahin print karo".
  - Only slips made in the last 2 minutes open by themselves. Older ones wait on the bar, for example slips from before
    the page was opened.

## After the owner's demo (20 Nov 2026, local Khatri copy)
The owner ran it on his laptop.
- **Green:** the KOTs went to the fake Delivery and BBQ printers.
- **Agent stopped:** the light went red with "4 parchiyan printer tak nahi pahunchi". The queue window opened by itself and
  printed all 4 KOTs ("4 / 4 sab ho gayin"). All 4 were recorded `printed_here: agent_offline`, and nothing reached the
  network printers.

Three polish items from that run:
1. **Toast.** While red, "KOT sent to kitchen" now reads "Printer se rabta nahi — KOT isi computer se print ho rahi hai"
   (`kotSentToast`).
2. **Preview buttons.** The queue opens the preview with `?here=1`. KOT, receipt and reminder previews then hide their own
   "Print / Mark Printed" buttons, because the slip is already recorded. A preview opened directly is unchanged.
3. **Auto-close.** The window closes by itself 2 s after the last slip, if every slip printed or was skipped. If one is
   stuck, it stays open.

Re-run on a fresh DB set `_poh2`:
- Target 15/15.
- Sabotages A–F all RED; F brings the buttons back.
- Related MySQL set **198/198**, now with KOT deal heading, thermal item layout, vehicle number and KOT ticket time.
- Unit: the same 5 reds, all red on prod.
- The live POS page's 10 inline scripts pass `node --check`.
