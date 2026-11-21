# Edge operational baseline cutover protocol (Cloud side) — OFFLINE-SYNC-ENGINE-1C

Batch 2 surfaced a real product rule: a Config Refresh **watermark change invalidates the accepted
operational stock baseline**, and in-binding baseline replacement is deliberately REFUSED
(`EdgeOperationalBaselineService` — blind replacement can oversell). This document defines the
Cloud-side state machine + evidence for the future cutover so 1C does not hide it. The Edge-side
baseline download/transport lands in 1D/1E; only the Cloud authority + evidence are defined here.

## Why a cutover is needed
- An accepted baseline binds `(branch, device, activation_epoch, source_revision)` and gates offline
  selling. When Cloud config advances (revision N -> N+1), the appliance's `source_revision` moves and
  `currentAccepted()` returns null — offline selling stops until a NEW baseline is accepted.
- A baseline must never be blindly replaced while unsynced local sales may exist: the new baseline's
  on-hand quantities must ACCOUNT for those pending sales, or the branch oversells.

## State machine (per branch + activation_epoch)
```
selling(N)
  └─(config refresh to N+1)─> baseline_stale(N->N+1)
        └─(1) drain/account prior-generation pending sales ─> drained(N+1)
              └─(2) Cloud computes authoritative stock position ─> position_ready(N+1)
                    └─(3) Cloud issues new baseline (revision/source watermark) ─> baseline_issued(N+1)
                          └─(4) Edge accepts new baseline via controlled cutover ─> baseline_accepted(N+1)
                                └─(5) offline selling resumes ─> selling(N+1)
```
No transition may skip step (1): selling never resumes on a baseline that does not account for the
prior generation's un-ingested sales.

## Step detail + evidence
1. **Drain or account for prior-generation pending sales.** Every `edge_sync_outbox` row for the
   branch at the prior generation must be `acknowledged` by Cloud ingestion (1C) — OR explicitly
   accounted (a documented exception in the ingestion registry). Evidence: the ingestion registry
   (`edge_inbound_sale_ingestions`) shows a terminal status (applied/conflict/refused) for every
   pending `sale_uuid` of that generation; the outbox has no `pending`/`leased` rows for it.
2. **Cloud calculates the authoritative stock position.** After all prior-generation sales are
   ingested (official FEFO posted), the Cloud on-hand per product IS the authoritative position — no
   separate calculation trusts Edge provisional quantities. Evidence: `stock_balances` at the branch
   as of the drain point.
3. **Cloud issues a new baseline** bound to `(branch, device, activation_epoch, new source_revision)`
   with `baseline_uuid` + `content_hash` over the item set. This reuses the existing accept contract
   shape; issuance is a Cloud record the appliance downloads in 1D.
4. **Edge accepts the new baseline only through controlled cutover.** The appliance clears the stale
   baseline and accepts the issued one at the new watermark. This is the ONLY sanctioned replacement:
   never an in-binding "replace now" — the refusal in `EdgeOperationalBaselineService::accept` stays.
   The cutover clears the prior baseline's balances/movements (already drained/ingested) and accepts
   the new one; a partially-cut baseline is refused.
5. **Offline selling resumes** under `source_revision == new watermark`; `currentAccepted()` matches
   again.

## What 1C owns vs defers
- **1C (this slice):** ingestion drains pending sales (step 1) and makes the authoritative Cloud stock
  position real (step 2, as the natural result of official FEFO). The registry is the drain evidence.
- **1D/1E (deferred):** baseline issuance transport (step 3 delivery), the Edge cutover command
  (step 4), and the operator surface for a stuck cutover.

## Non-negotiables
- No unsafe "replace baseline now" shortcut is added anywhere.
- Ingestion never posts Edge provisional operational movements as official stock; Cloud posts its own
  official FEFO (that IS the authoritative position feeding step 2).
- A branch stuck mid-cutover cannot sell offline (fail-closed) — the accepted-baseline gate already
  enforces this.
