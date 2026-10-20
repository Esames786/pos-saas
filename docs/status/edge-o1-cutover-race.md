# O1 — `error:CUTOVER_ALREADY_DONE` on the first standby tick after a handback (owner §4-8, 21 Oct 2026)

Owner instruction: "Perform a READ-ONLY / disposable-pair reproduction … If purely idempotent: normalise the worker status from error:* to
current/already_current in a focused follow-up with regression test. If reproduction shows stale stock or STOCK_AUTHORITY_READY can remain true
while stock is behind: STOP — that becomes a release blocker."

```
O1_REPRO_STATUS=REPRODUCED (attempt 1, disposable pair 8096 ↔ clone 9704 only; LAB, LAB Cloud, 9702, production untouched)
VERDICT=PURELY_IDEMPOTENT — benign race, cosmetic error: in the worker status; NOT a release blocker
FOLLOW_UP=b3648abd (focused change + regression test; no authority / takeover / fencing / sync semantics changed)
```

## 1. Mechanism (code and reproduction agree)

- `CUTOVER_ALREADY_DONE` has one throw site, `EdgeBaselineCutoverService::acceptCutover` (under `SELECT … FOR UPDATE`), when an accepted
  baseline already sits at the current revision with a *different* `baseline_uuid`. `acceptCutover` has one production caller,
  `EdgeStandbyFreshnessService::refreshStockIfBehind`, which runs inside the standby tick.
- The tick is invoked from TWO processes: the authority worker, and the handback orchestrator's inline step 13 (immediately after
  `authority_state` flips to `standby`). With the worker running during a handback (the normal appliance posture) the two ticks overlap:
  both read "revision moved", both refresh the config (one applies, the other gets the applier's already-applied result), both POST
  `/api/edge/sync/baseline` and receive their OWN package — the Cloud mints a fresh ULID per issuance from the same `stock_balances`
  (identical items, `content_hash` and `stock_watermark`; the Cloud persists no issued-baseline table). The row lock serialises them: the
  first inserts the new baseline, the second is refused before any write.
- "Behind" then "current": the loser compared `currentAccepted()->stock_watermark` (null once the accepted baseline's `source_revision` no
  longer matched the just-moved `edge_local_meta.source_revision`) with the advertised watermark → cutover path. On the next tick the
  winner's baseline is at the new revision and at the advertised watermark → `current`.
- `STOCK_AUTHORITY_READY` = "an accepted baseline exists for (branch, device, epoch) at the LOCALLY applied revision". It never compares with
  the Cloud; freshness is the separate gate `STANDBY_FRESH_ENOUGH` (applied revision == advertised AND accepted watermark == advertised, or
  issued after the last ack), which `canTakeOver()` requires and which only an audited `--accept-stale` may override. The only window
  without stock authority is the ~1 s between the config-refresh commit and the baseline insert, when `currentAccepted()` is null — fail-closed,
  and the appliance is standby anyway.

## 2. Reproduction (20 Oct 21:53–21:58 UTC, scratchpad `o1\attempt-1.log`)

Start as Team FB left it (8096 standby/online, revision 23, worker stopped, lease released). `authority-worker --once` → `current`; lease
lapsed → `authority-takeover --confirm --by="Team O1 repro"` → LOCAL MODE (9 gates true); clone `Product::touch()` on product 1 via the model
→ config revision 24 minted on the next heartbeat; `worker start` (drained 0, clean, in_sync 7; lease holder edge, fenced); `--assess` ready →
handback fired 18 s after a worker tick line → "Authority handed back to the Cloud".

| tick | summary (worker log, exact) |
|---|---|
| 3 | `config: refreshed:23->24`, **`stock: error:CUTOVER_ALREADY_DONE: a different baseline is already accepted at the current revision; no cutover is pending.`**, returnable/supplier_finance/purchase_return `current` |
| 4, 5 | everything `current` |

Appliance rows: right after the handback (before the worker's tick line) baseline 21 `01M5DN2P…` at revision `91fdb93a…`, watermark
`sw:4526ec…`, kind `cutover`, accepted 21:57:00 by the orchestrator's inline tick (audit row `performed_by authority-worker` — the string
was hard-coded, so the audit could not tell the callers apart); baseline 20 superseded; ticks 3/4/5 added no rows; meta applied 24 = seen 24,
seen watermark == accepted watermark. Cloud: one revision-24 allocation. Stock after the final `current` tick: products 1/2/3 appliance
59/98/77 = clone 59/98/77, 15 balance rows on both sides. Same pattern in Team FB's run of 20 Oct (baseline 20 accepted 08:29:16 inside
the handback window, worker tick 5 lost).

Final pair state: 8096 standby / online / revision 24 / outbox 7 acknowledged, 0 open shifts, worker stopped; clone lease holder cloud,
standby, released 21:58:03 ("Team O1 repro attempt 1 complete"), fenced false; the only clone-side change is `products.id=1.updated_at`.

## 3. The focused follow-up (b3648abd)

- `EdgeStandbyFreshnessService::refreshStockIfBehind($performedBy)` catches ONLY `CUTOVER_ALREADY_DONE`, re-reads the accepted baseline and
  returns **`already_current`** when it is at this revision AND at the watermark the tick set out to reach (the fetched package's or the
  advertised one); it still stamps `standby_stock_refreshed_at`. Any other refusal, or a winner at a different watermark, rethrows → `error:`.
- `tick($performedBy)`: the orchestrator's inline tick is attributed `handback-orchestrator` in the cutover audit row.
- `edge:local:authority-handback` prints the inline tick's `freshness` block.
- Regression: `EdgeStandbyFreshnessHttpMySqlTest::test_a_second_baseline_issued_for_the_same_revision_is_reported_already_current_not_as_an_error`
  binds a cutover service that performs the competing cutover between the losing tick's fetch and accept (its own fetch → its own ULID) and
  asserts `already_current`, one accepted baseline, exactly one new audit row, `freshEnough()` ok, operational position == Cloud (92), the next
  tick `current`, and that a different refusal (wrong epoch) is not swallowed.
- Isolated `_t25` run: Freshness + Handback suites 8 / 160; Cutover + Baseline round-trip + AuthorityTick + NoDuplicatePrintAfterSync 37 / 305 —
  green (one pre-existing PHPUnit deprecation notice).
