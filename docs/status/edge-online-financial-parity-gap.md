# Edge online financial-parity gap (returns / refunds / void / card)

The historical Edge contract said "returns/refunds/card = unsupported offline." With the goal now being
**offline should work like online**, these are no longer permanently out of scope — they are a defined
**financial-parity gap** with an explicit implementation sequence. Nothing risky is implemented in the current
operational-parity tranche: returns/refunds move official stock (FEFO), COGS, GL, and cash/bank, all of which
are Cloud-authoritative, and an offline refund must reconcile exactly-once with the Cloud on reconnect. The
online RETURN-UX improvement (`bff2e8d`, unit-aware stepper) is UI on top of that same authority — porting the
UI alone is not parity; the money semantics must be designed.

## Classification

| Behavior | Class | Why / what it touches |
|---|---|---|
| **Full sale return** | REQUIRES_NEW_SYNC_EVENT + REQUIRES_COMPENSATING_FINANCE_DESIGN | Reverses official FEFO/COGS, posts a sales-return GL, returns cash/bank. Cloud is the stock+finance authority; offline must queue a return EVENT bound to the original `sale_uuid` and reconcile exactly-once. |
| **Partial return** | REQUIRES_NEW_SYNC_EVENT + REQUIRES_COMPENSATING_FINANCE_DESIGN | As above, per-line/qty; the unit-aware stepper is the UI over this. |
| **Void (pre-settlement)** | CAN_OPERATE_SAFELY_OFFLINE (mostly) | Cancelling a held/unsettled local sale is already local (`cancelHeldSale`); voiding a *settled* sale is a return. |
| **Refund (cash)** | REQUIRES_COMPENSATING_FINANCE_DESIGN | Cash refund moves the till; must post against the original sale and reconcile; offline cash refund is possible but needs the return-event + cash-bank design. |
| **Delivery-charge refund** | REQUIRES_COMPENSATING_FINANCE_DESIGN | Same as a partial return with the delivery-charge line. |
| **Customer credit / settlement** | REQUIRES_CLOUD_CONNECTIVITY (likely) | Customer-ledger/credit is Cloud-authoritative; offline settlement against a customer balance needs a defined sync event or is `ONLINE_REQUIRED`. |
| **Card / provider payment** | ONLINE_REQUIRED | Real provider authorization needs the internet. Never fake an approval. Offline may only record a manually-authorized/offline-permitted tender per the provider contract, or mark the tender `ONLINE_REQUIRED`. |

## Exact next implementation order (a dedicated financial-parity tranche, NOT now)

1. **Return event model** — an immutable offline "sales return" envelope bound to the original `sale_uuid`
   (+ line/qty), mirroring the sale outbox: append-only, leased, verified-ACK, exactly-once. Reuse the sync
   engine (1B–1E) rather than inventing a second path.
2. **Cloud return ingestion** — a thin authenticated endpoint around the Cloud return authority (like the sale
   ingestion): Cloud posts the authoritative FEFO reversal / COGS / sales-return GL / cash-bank refund exactly
   once; the finance verifier gates it (no applied-without-complete-finance).
3. **Local operational effects** — the appliance's operational stock baseline returns the quantity locally
   (provisional), fenced so it never double-applies when the Cloud posts the official reversal.
4. **Refund cash-bank** — record the offline cash refund against the shift/till; reconcile on ACK.
5. **Void vs return split** — keep pre-settlement void fully local; route post-settlement to the return event.
6. **Card/provider** — define per-provider offline behavior (queued vs `ONLINE_REQUIRED`); never fake approval.
7. **RETURN-UX** — only after 1–6, bring the online unit-aware return UI to the Edge cashier surface (which
   itself depends on the cashier-UI workstream).

```
RETURNS_PARITY_STATUS = FINANCIAL_PARITY_PENDING (design defined; not implemented)
REFUNDS_PARITY_STATUS = FINANCIAL_PARITY_PENDING
CARD_PARITY_STATUS    = ONLINE_REQUIRED (provider authorization needs internet; never faked)
FINANCIAL_PARITY_PLAN = return-event outbox -> Cloud return ingestion (finance-gated) -> fenced local
                        operational reversal -> refund cash-bank -> void/return split -> card contract -> UI
```

**Rule:** do not implement offline return/refund/card finance until this sequence is designed and gated the
same way sale ingestion is (exactly-once, finance-complete-or-refuse). This is a separate tranche after
operational parity.

## Supplier finance (SUPPLIER-FINANCE-DIRECT-1) — classified 8 Sep 2026; CANONICAL since 70d24c1 (10 Sep 2026)

**Update 10 Sep 2026 — now canonical.** The workflow merged into `origin/feat/14d-2-plan-upgrade-requests` at
`70d24c1` (1e69481 SUPPLIER-FINANCE-DIRECT-1, 49381a1 idempotency + foreign-supplier guard, cd6a75e purchase-return GL
regression guard, 70d24c1 HTTP guards). The Online functionality Edge must eventually match:

- **Direct Supplier Payment without a Purchase Bill** — `SupplierPaymentController` (index/create/store/show),
  `JournalPostingService::postSupplierPayment` (Dr 2100 Accounts Payable / Cr cash-bank), supplier ledger entry.
- **Supplier Ledger → Record Payment** — `tenant.suppliers.ledger` with the payment entry point.
- **Supplier-aware General Journal / AP entry** — manual journal lines carrying `counterparty_type=supplier` mirrored
  into the supplier subledger; AP identified by the whole 2100 family.
- **Rules that travel with it:** a cash/bank account is REQUIRED on the payment (no cash/bank → no GL entry); the
  Purchase Bill is OPTIONAL (direct payment on account); the Purchase Return GL fix (cd6a75e: Dr 2100 / Cr 1400 with
  a regression guard) is part of the same live release. Live production/canonical head at the Q final gate: af6755d.

Classification: **SUPPLIER_FINANCE_OFFLINE_PARITY = FINANCIAL_PARITY_PENDING.** Edge still has no official offline
supplier-finance ingestion/authority; it must NOT invent local AP/GL posting. This is not "missing" and not
permanently Online-only: the financial-parity phase must include this newly-canonical workflow (Edge-originated
supplier-payment events → Cloud ingestion posting AP/GL exactly once, the same contract shape as sale ingestion).

A separate Main Finance session is building **direct supplier payment, supplier-ledger payment and a
supplier-aware General Journal** on `feat/supplier-finance-direct-v1` (`1e69481`, 8 Sep). As of the Edge
final catch-up it is **NOT on canonical** (`origin/feat/14d-2-plan-upgrade-requests` @ `e44eb01`).

```
SUPPLIER_FINANCE_OFFLINE_PARITY = FINANCIAL_PARITY_PENDING
```

Why: it is Cloud AP/GL posting (`SupplierPayableService::recordPayment`, `JournalPostingService::
postSupplierPayment`, `JournalService::post`, manual AP journal lines carrying a supplier dimension). The
restricted Edge artifact physically excludes `SupplierPayableService` and the supplier views on purpose,
and **no official offline financial-event ingestion exists yet** — so the appliance must not invent a
local AP/GL posting path. Nothing is omitted: the eventual financial-parity phase must carry this contract
too, as a Cloud-ingested financial event, exactly like returns/refunds.

Contract the future phase must honour (from the commit): payment + subledger + cash/bank + GL post in ONE
transaction with a null GL result treated as failure; a cash/bank account is mandatory; manual AP journal
lines require `counterparty_type=supplier` + a real supplier (mirrored into the subledger); AP is identified
by the whole `2100` account family (parent_id), not one code; supplier overpayment fails closed (no supplier
advance account exists); `supplier_ledgers.entry_type` gains `journal_adjustment` / `journal_reversal`.
It also carries a Cloud-side fix (purchase-return GL never posting after GL-BUSINESS-DATE-1) that Edge inherits
through the shared `JournalPostingService` once the branch merges into canonical — no Edge action.
