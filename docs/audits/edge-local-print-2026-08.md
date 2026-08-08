# EDGE-LOCAL-PRINT-1 — Branch Server local print delivery (2026-08)

Sprint start: EDGE-LOCAL-POS-1 = GREEN + FROZEN + DEPLOYED DORMANT (prod `5b965b9`, cloud-only).
**Direction LOCKED by review**: the Branch Server itself is the ONE physical transport authority — a
local delivery loop over LAN TCP. The Cloud Windows print agent stays untouched and is NOT exposed
on/against the appliance (a future agent-compat mode would be mutually exclusive, later sprint).

## Locked invariants
- **KOT BUSINESS EVENT ≠ PHYSICAL PRINT COMPLETION.** The frozen POS layer creates kot_batches /
  kot_batch_lines / logical print_jobs and advances kot-sent bookkeeping; this sprint only DELIVERS
  existing queued intents. Delivery retries never regenerate business events.
- **Transport is payload-immutable**: `raw_payload` is materialized at job CREATION; delivery sends
  the exact stored bytes (+ the same `\n\n\n` trailing feed the Cloud agent appends — pinned) and
  NEVER calls EscPosPayloadService again. Proven: queue payload A → mutate the sale → FakePrinter
  receives payload A byte-for-byte.
- **Physical printing is AT-LEAST-ONCE** with logical dedupe. A completed socket write is not proof
  paper emerged; a lease expiring mid-print redelivers the same stored job (physical duplicate is
  possible BY DESIGN — proven with the printer receiving the same bytes twice).
- Shared Cloud domain untouched semantically: no synthetic print_agents row, `cancelled` NOT
  repurposed, `print_jobs.attempts` keeps its Cloud meaning (markFailed transitions only).

## Slice 1 — what was built
- **`edge_local_print_deliveries`** (edge migration; NEVER on Cloud tenants): per-job transport
  metadata — `delivery_state` (waiting|leased|retry_wait|delivered|terminal_failed), `worker_uuid`,
  **`lease_token`** (64-char random — the ONLY completion authority), lease timestamps,
  `failure_count`, `next_attempt_at`, `last_error`. FK RESTRICT to print_jobs. print_jobs.print_status
  stays the authoritative business status.
- **`EdgeLocalPrintDeliveryService`**: claim (branch_server + binding required; queued + printer-bearing
  + active NETWORK printer, branch-or-GLOBAL scope — the same predicate Cloud routing uses; backoff
  elapsed; no live lease; **NULL-printer intents are NEVER claimed** (§20) — historical browser
  intents stay diagnostics); completeSuccess/completeFailure verify the CURRENT token under row locks
  — **stale completion is impossible** (a dead worker's token is refused; a printed job additionally
  can never be demoted); temporary failures back off ([5,15,30,60,120]s, Edge-only bookkeeping,
  print_status stays queued) until the 5th failure runs the shared markFailed ONCE
  (`terminal_failed`); explicit local retry resets to queued/waiting with the exact Cloud admin-retry
  field contract.
- **`EdgeNetworkPrinterTransport`**: PHP TCP (8s connect/write timeouts), exact stored payload +
  pinned trailing feed. No ESC/POS init/cut in this slice (payload capability = later pass).
- **`edge:local:print-worker`** (CLI-allowlisted): `--once` deterministic cycle / loop with idle
  sleep / `--max-jobs`; fail-closed off branch_server or without a binding; printer IP/port only from
  bootstrapped trusted config; one job's failure never kills the loop; SIGTERM handled where the
  platform supports it (Windows service wiring = installer sprint).
- **§19 narrow shared guard** in `PrintJobService::markFailed`: a PRINTED job early-returns —
  protects Cloud too (an agent that already reported printed still passes the ownership check on its
  own duplicate failure report). Cloud behavior otherwise untouched.
- **§16 bootstrap ROUTING PARITY fix**: Cloud routing selects printers/mappings with
  `branch_id = branch OR NULL`, but the export only shipped `= branch` — global default printers
  silently never reached the appliance (Edge would browser-fallback where Cloud picks a device).
  Export now ships branch + GLOBAL printers/mappings; the importer accepts NULL-branch rows as
  GLOBAL (a row bound to a DIFFERENT branch is still CROSS_BRANCH-refused). Parity proven end-to-end:
  Cloud resolves global printer G for the branch → bootstrap → Edge resolves the SAME G; foreign-
  branch printers never leak; no dangling references.
- **§17 truthful `local_print` readiness**: not_configured | blocked (unresolved NULL-printer intents,
  dangling mapping/terminal printer refs) | ready — never an activation claim; `activation_ready`
  stays hard false.

## Proof inventory (all real MySQL; master DB unreachable inside every Edge worker)
- `CloudPrintAgentContractMySqlTest` (NEW, 4/19): pins the previously-untested Cloud contract —
  pending claims only queued/network/own-branch jobs, 2-min lease blocks then re-hands, wrong token
  401, foreign agent 403, printed idempotent, printed-never-demoted, failed/attempts, retry→claimable.
- `EdgeLocalPrintDeliveryMySqlTest` (7/64): E2E receipt + KOT through the REAL worker command and a
  REAL TCP FakePrinter — exact stored bytes incl. after sale mutation; business event untouched by
  delivery (1 batch, kot_print_count 1, sent advanced once); NULL-printer never claimed; backoff →
  terminal(markFailed once) → local retry → delivered; stale token refused (printed survives);
  fail-closed off branch_server; truthful readiness states.
- `EdgeLocalPrintRaceTest` (3/17, genuine OS processes): simultaneous claim → exactly one lease;
  delivered-then-died → lease expiry → SAME bytes redelivered (physical duplicate documented) → the
  dead worker's stale failure REFUSED, printed survives; died-before-send → live lease blocks a second
  claim, then exactly one delivery after expiry.
- `EdgeLocalImportMySqlTest` +1 (8/32): the §16 global-printer parity proof.

## Out of scope (unchanged)
Local Mode activation (`activation_ready=false`), sync, Windows service/installer wiring, ESC/POS
capability upgrades, reroute-unresolved-intent operation (deliberate audited op, later), Cloud agent
redesign. Production remains `5b965b9`, cloud-only, Edge dormant — this WIP is NOT deployed.
