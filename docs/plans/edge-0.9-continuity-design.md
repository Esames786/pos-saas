# Edge 0.9 — shift / order continuity (stream A): design note and financial invariants

Branch `edge09/a-continuity`. Brief §3C/§4.1, impact map K1. Mirror → adopt → merge, without touching the authority engine.

## What exists today (0.8.5, investigated)

- The envelope `shift` block carries the EDGE shift (uuid, business date, terminal, opener). Cloud ingestion
  (`EdgeInboundSaleIngestionService`) **ignores it**: an Edge sale lands on the Cloud with `shift_id = NULL`, no shift counters,
  no `sales_ledgers`, a NEW `sales_orders` row (`projectSale`, Cloud `sale_no`), official FEFO/COGS, GL, cash-bank; the
  registry (`edge_inbound_sale_ingestions`, unique `sale_uuid`) makes a replay `already_applied` with zero effects.
- `kot_events` ride the envelope but the Cloud creates no KOT batch / print job (EdgeNoDuplicatePrintAfterSync).
- Table sessions are not linked on the Cloud; handback is blocked while the appliance has open tables / held checks / open shifts.

## Design

1. **Mirror** (standby only): `LiveStateClient` GETs `/api/edge/live-state?since=` (device headers, TLS on, bounded
   timeouts); `LiveStateMirror::apply` replaces `edge_mirror_*` atomically per revision, frozen while LOCAL_ACTIVE, decides
   adoptability per order (unpaid, plain lines, coherent money). One guarded call in `EdgeAuthorityTick` standby phase.
2. **Adopt** (lazy, first POS touch after takeover, once per segment = `authority_takeover_at`): continuation shifts via the
   shared `ShiftService::open` (opening cash = mirrored expected cash), held bills and whole tables as Edge held sales with the
   **same Cloud identities** (sale_uuid, line_uuid, session_uuid); fired lines get `kot_sent_quantity = qty`.
   Stale (> `edge.continuity.max_mirror_age_seconds`, default 120, before the last acknowledged heartbeat) or untrusted
   (integrity error since the last good refresh, no revision, other branch) ⇒ **nothing adopted**, reason shown to the cashier,
   normal "open shift" flow.
3. **Merge** (Cloud ingestion, envelope `edge-sale-envelope-v4` only when continuity data is present): `shift.continues_cloud_shift_uuid`
   ⇒ the sale is attached to that ORIGINAL Cloud shift and gets the shared `SaleOperationalSettlementService::settle` (counters +
   sales ledger) exactly like a Cloud POS sale; `adoption` ⇒ the EXISTING Cloud order is completed instead of a second one.

## Financial invariants enforced (and how)

| # | invariant | enforcement |
|---|---|---|
| F1 | One shift per counter: no second Edge shift for a terminal, no duplicate Cloud shift | `ShiftService::open` terminal lock + UNIQUE(terminal, continues_cloud_shift_uuid, segment) on Edge; Cloud never creates a shift at ingestion (it attaches to the existing one, refuses a mismatched terminal/branch) |
| F2 | One order: an adopted order is never ingested as a new sale | same `sale_uuid` on Edge (UNIQUE) and Cloud; merge path locks + completes the existing row; an old Cloud refuses v4 (SCHEMA_UNSUPPORTED → deferred retry), never projects a duplicate |
| F3 | Payments recorded once | an order with ANY Cloud payment is not adoptable; merge re-checks under lock; payments projected once by `payment_uuid` inside the ingestion transaction |
| F4 | Cash movements once | shift counters / sales ledger only via `settle()` once inside the ingestion transaction; replay = `already_applied` before it |
| F5 | No KOT / receipt re-print | fired lines adopted as `kot_sent_quantity = qty` (shared KOT delta prints only new lines); Cloud ingestion still creates no KOT batch / print job |
| F6 | Conflicts are refused, never guessed | `ContinuityFingerprint` (lines + total) computed by the mirror and recomputed by the Cloud under lock; Cloud order paid/cancelled/changed, or a Cloud line missing on Edge ⇒ `refused` (ADOPTED_ORDER_CONFLICT / ADOPTED_LINES_DIVERGED) ⇒ needs attention |
| F7 | Stock / GL once | official FEFO + COGS for the final lines, GL + cash-bank + `EdgeFinancePostingVerifier` in the same transaction as today; the Cloud never posted stock for an unpaid held order |
| F8 | Nothing mirrored is a fact | adoption creates held drafts only; untouched adopted drafts are released before handback (`releaseUntouched`), the Cloud copy remains the truth |

Out of scope for milestone 1 (listed in the stream report): returns / cash-in-out inside a continuation, deal lines and partly
paid orders (mirrored, not adoptable), Edge voids of mirrored lines (refused at merge), the handback wiring of `releaseUntouched`.
