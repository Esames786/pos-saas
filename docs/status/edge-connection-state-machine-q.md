# OFFLINE EDGE — Q: CONNECTION STATE MACHINE + WARM STANDBY FRESHNESS

Status: **built and proven in executable tests. Automatic local activation is NOT enabled; the physical pilot is not
run.** P (branch authority lease, accepted at d8d8f8c) remains the ONLY authority mechanism. Q sequences the states
around it, keeps the standby fresh while the Cloud writes, and orchestrates the controlled handback.

## The product model Q implements

| Situation | Writer | Cloud branch | Appliance | Cashier sees |
|---|---|---|---|---|
| Online, heartbeats acknowledged | Cloud | open | warm standby, continuously refreshed | ONLINE |
| One failed heartbeat | Cloud | open | standby | ONLINE |
| ≥2 consecutive failures | Cloud | open | standby | INTERNET CONNECTION UNSTABLE |
| ≥4 consecutive failures, lease still live | Cloud | open (fences itself at its TTL) | standby, refuses local writes | INTERNET CONNECTION LOST |
| Lease lapsed on the appliance clock (TTL + skew) | nobody | fenced | gates decide; supervisor confirms | PREPARING LOCAL MODE |
| Supervised takeover | Appliance | fenced | local writer | LOCAL MODE ACTIVE |
| WAN back (first acknowledged beats) | Appliance | fenced (holder edge) | still the writer | CONNECTION RESTORED |
| Draining / reconciling | Appliance | fenced | still the writer | SYNCHRONIZING |
| Controlled handback, Cloud not yet acknowledged | nobody | fenced | fenced (handing_back) | RETURNING TO ONLINE |
| Cloud acknowledged | Cloud | open | warm standby again | ONLINE |

Reconnect never switches the writer. Nothing transitions authority automatically: takeover and handback are
supervised commands. Every connection-state change is persisted with a reason and audited
(`edge_local_connection_transitions`); the state is a pure function of persisted facts, so a restart re-derives it.

## What was built

Cloud
- Heartbeat response now advertises `cloud_config_revision` / `cloud_config_watermark` (monotonic revision allocated
  idempotently for the branch's config watermark) and `stock_watermark` / `stock_as_of` (a content hash of the branch's
  authoritative sellable position in official `stock_balances`).
- `POST /api/edge/config/refresh` (device-authenticated) emits the current config refresh package for the device's
  branch — the real bootstrap sections, deterministic identity per revision, contract: paired READY/ACTIVE device in
  the active slot, entitled tenant, the manual Local-Mode branch status deliberately not gating it.
- Baseline issuance carries the stock watermark in `cloud_position`.

Appliance
- `EdgeConnectionStateMachine` — derive / evaluate (persist + audit) / persisted; thresholds
  `edge.authority.unstable_after_failures` (2), `lost_after_failures` (4), `handback_min_consecutive_acks` (2).
- `EdgeStandbyFreshnessService` — after every acknowledged heartbeat while standby: pull the config refresh when the
  advertised revision is ahead (EDGE-CONFIG-REFRESH-1 applier), pull a fresh baseline when the advertised stock
  watermark differs: INITIAL acceptance (never had one), CUTOVER (config revision moved), or the new same-revision
  STANDBY REFRESH (`EdgeBaselineCutoverService::acceptStandbyRefresh`: standby only, outbox drained, audited).
  `freshEnough()` is the proof: applied config revision = advertised, accepted baseline watermark = advertised (or
  issued strictly after the last acknowledged heartbeat).
- Gate `STANDBY_FRESH_ENOUGH` added to the P gates. Takeover records `authority_takeover_freshness` (JSON proof). A
  supervisor may consciously accept a stale standby only with `--accept-stale --reason` (audited); no other gate can
  be overridden.
- `EdgeAuthorityTick` — one worker tick: heartbeat → evaluate → standby freshness (standby) or bounded outbox drain +
  reconciliation (local; lost ACKs recovered exactly once, divergences surfaced) → evaluate.
- `edge:local:authority-worker` — the ONE supervised worker (DB singleton with liveness heartbeat, duplicate start
  exits cleanly, stale slot taken over, cooperative `--stop`, bounded HTTP timeouts, credentials from config only) —
  added to `EdgeSupervisionPlan` as `BingooEdgeAuthorityWorker` (continuous, at startup, restart policy).
- `EdgeHandbackOrchestrator` — assess (1 connectivity stable · 2 Cloud fenced · 3 drained · 4 reconciled · 5 no permanent
  failure · 6 reservations projectable · 7 open tables / held or draft checks / open shifts) → HANDBACK_BLOCKED with
  explicit reasons, nothing discarded; run → reservation handback → P handback (fence, audited HANDING_BACK, Cloud
  ack, standby) → Cloud POS re-enabled → warm standby resumes (fresh pull). A failed Cloud call leaves both sides
  fenced for a retry.
- Cashier: the sync summary's `connection` label and the page chip follow the state machine; no internals exposed.
- Commands: `edge:local:authority-status` (connection, gates, freshness proof, handback blockers),
  `edge:local:authority-takeover --confirm [--accept-stale --reason]`, `edge:local:authority-handback [--assess]`.

## Executable proof (MySQL, real HTTP where the Cloud is involved, independent processes where authority is proven)

| Class | Proves |
|---|---|
| `EdgeConnectionStateMachineMySqlTest` (5) | ONLINE → UNSTABLE → LOST → PREPARING_LOCAL deterministically; one failure is a blip; multiple failures inside the lease still refuse mutation; freshness-gated takeover; stale stock needs an audited supervisor decision; stale config refuses; reconnect keeps the appliance the writer through RESTORED / SYNCING / RECONCILING; HANDING_BACK fenced; restart recovers the same state at every checkpoint |
| `EdgeStandbyFreshnessHttpMySqlTest` (3, two databases) | Cloud 100 → 95 → 92 online sales; the standby pulls 100 (initial) then 92 (standby refresh); WAN dies; Cloud fences at its TTL first; takeover starts from 92 with the watermark proof; other branch untouched; a failed refresh before the cut fails closed and only an audited acceptance proceeds; a Cloud config change is pulled (real applier, real price) and stale config refuses takeover |
| `EdgeHandbackOrchestratorHttpMySqlTest` (4, two databases) | open tables / held checks / open shifts block with reasons and nothing is discarded; unsynced or permanently failed sales block; a REAL offline sale drains through the real sender into the real Cloud ingestion (100 → 98), a lost ACK is recovered exactly once with no duplicate ingestion or stock posting, then the clean controlled handback returns the Cloud to writing and the standby equals the Cloud position; a failed handback leaves both sides fenced until retried |
| `EdgeCashierConnectionStateHttpMySqlTest` (2) | the real cashier route shows every business label; no uuid / epoch / hash / lease / watermark / revision reaches the till; the page renders the chip |
| `EdgeAuthorityWorkerLifecycleMySqlTest` (3, real processes) | a real supervised tick with the Cloud master dead records the failed heartbeat and never takes over; duplicate start exits cleanly; stale slot taken over; cooperative stop; no secret on the command line |
| `EdgeConnectionPartitionTest` (1, independent Cloud and appliance processes and databases) | every state under a real partition with a process restart at each: persisted = derived, and the Cloud fence and appliance fence never both allow a write; reconnect stays local; handback blocked until reconciled; handback over a dead wire leaves both fenced; Cloud ack → ONLINE; the audit trail holds the whole path |

Also extended: `EdgeSupervisionMySqlTest` (the worker task), `EdgeArtifactBootTest` (the worker ships in the artifact),
the P suites unchanged and green.

## Not done, on purpose

- No automatic local activation (supervisor confirmation stays required); no physical Windows certification; no
  physical WAN unplug; no USB dual-mode print agent; no installer.
- Reservation handback still projects into the appliance's canonical tables as built in the parity tranche; the Cloud
  side of that projection belongs to the next tranche's review.
- Warm-standby freshness covers config (all bootstrap sections, incl. tables/reservation columns, users, permissions,
  terminals, printer mappings, promotions, customers) and official stock. Business-date / shift state stays Online-owned
  until handback (open shifts are an explicit handback blocker).

## Gates at the final head (10 Sep 2026)

- Focused Q + P + supervision: 28 tests, 545+ assertions, green (each class also green alone; the two-process partition
  proofs green).
- Full MySQL suite at 910f885 (Q): 1450 tests, 1448 passed, 2 red = known canonical Dompdf/A4-PDF debt.
- Reconcile 6: canonical 1b10a62..70d24c1 merged clean (086f21b; rollback tag edge-pre-reconcile6-910f885). Full
  MySQL suite at 086f21b: 1532 tests, 1527 passed, 5 red = the same Dompdf debt plus three NEW canonical catering PDF
  tests that also need Dompdf (byte-identical to canonical). Fast Feature+Unit: 225 tests, the two stale canonical
  SQLite unit tests red as before. New Edge regressions: 0.
- Supplier finance became canonical in this delta (70d24c1): classified FINANCIAL_PARITY_PENDING — see
  edge-online-financial-parity-gap.md.

## Final Q gate re-ground (10 Sep 2026, `git fetch --all --prune`)

- CURRENT_CANONICAL_AT_FINAL_GATE = af6755d (live production/canonical Online head: Supplier Finance release + Catering
  customer-credit / negative-payment release).
- SHARED_BRANCH_POS_DELTA_REVIEWED = yes. Delta 70d24c1..af6755d: 2 commits, 11 files — 8 catering, plus a catering
  permission label (PermissionCatalogService), a catering sidebar link and a catering route. Zero shared normal
  branch-POS behavior changed → nothing to replicate on Edge. Reconcile 7 merged clean (c9ad004; rollback tag
  edge-pre-reconcile7-30ba958).
- Catering stays physically excluded from the restricted Edge artifact (EdgeArtifactTest green after the merge; the new
  `CateringCustomerCreditController` falls under the existing `app/Http/Controllers/Tenant/Catering` exclusion). It is
  not part of the normal Branch POS Edge scope and is not pulled in for parity.
- Supplier finance: SUPPLIER_FINANCE_OFFLINE_PARITY = FINANCIAL_PARITY_PENDING (register + gap document carry the live
  workflow list and rules).
