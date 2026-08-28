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
