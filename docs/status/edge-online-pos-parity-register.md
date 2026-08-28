# Edge ↔ Online POS parity register

Goal (locked): **Offline Edge should give the same operator experience and business semantics as the current
online POS for every workflow that can safely operate offline.** Functional/behavioral parity — not code
copying. Internet-required actions are never faked: they are `ONLINE_REQUIRED`, `QUEUED_FOR_ONLINE`, or an
explicit unavailable state with a truthful message. Cloud-authoritative accounting is never given a second
local authority.

Canonical reviewed: `origin/feat/14d-2-plan-upgrade-requests` @ `15afd50` (18–28 Aug 2026 shared POS work).
Every shared workflow ends as one of: **FULL_OFFLINE_PARITY / ONLINE_REQUIRED / FINANCIAL_PARITY_PENDING**.

## The dominant finding — the Edge cashier UI is a first-class gap

`EdgeLocalPosController` is a **JSON API** (28 json responses, 0 views); the only Edge Blade is
`resources/views/edge/auth/login.blade.php`. **Edge has no local browser cashier UI.** So every *screen-level*
parity item (POS tiles, View Tables header, Preview Bill modal, Quick Report screen, payment modal) is gapped
at the UI layer — not cosmetic, not "productization-only". The **business behavior** for those workflows can
exist offline at the API/service layer (and reservations now do), but an operator cannot yet *run* the cashier
workflow in a browser the way they do online.

```
EDGE_CASHIER_UI = PARITY_GAP  (major workstream: build the offline browser cashier surface on the Edge stack;
                               until then, screen-level parity for tiles/preview/report/payment is UI-gapped)
```

## Register

| Online POS workflow | Class | Edge status |
|---|---|---|
| **Table Reservations** (reserve/view/cancel + customer carry-over) | **FULL_OFFLINE_PARITY (business layer)** / UI-gapped | DONE at the service+API layer — `EdgeTableReservationService`, `edge.local.pos.restaurant.table.reserve/reservation/unreserve`; Local-Mode authority, concurrency, backup census, cross-DB recovery. No cashier UI (see above). |
| **Review & Pay → Preview Bill** (running bill, no mutation) | FULL_OFFLINE_PARITY (business) / **UI-gapped** | The totals math exists offline (`SaleTotalsService`); a no-mutation preview endpoint is a small add. The modal itself needs the Edge cashier UI. **Next.** |
| **Payment modal / wider layout** | **UI-gapped** | Pure UI; needs the Edge cashier UI. |
| **POS-HEADER / POS-TILE / POS-WINDOW** (`15afd50`,`9e93a4b`,`d2ab907`,`441c3a9`) | **UI-gapped** | Cloud POS Blade/CSS layout; equivalent behavior belongs in the (missing) Edge cashier UI. |
| **KOT/receipt presentation** (variant/divider/tail-feed) | **PORT (small)** | Edge reuses the shared `EscPosPayloadService`; the cosmetic changes port there. **Next.** |
| **POS Quick Report** (view/thermal/network/email) | **MIXED** | Local report facts + thermal/network via the Edge print authority are offline-buildable (business layer, UI-gapped for the screen); **email = ONLINE_REQUIRED** (never faked). **Next.** |
| **Recent Prints / local-USB fallback** | ALREADY_STRUCTURALLY_EQUIVALENT / UI-gapped | Edge owns local printing (`EdgeLocalPrintDeliveryService`, per-printer isolation). Operator "recent prints" listing needs the Edge UI. |
| **RETURN-UX** (`bff2e8d`, unit-aware stepper) | **FINANCIAL_PARITY_PENDING** | Returns touch stock+COGS+GL — see `edge-online-financial-parity-gap.md`. Not in this tranche. |
| Draft / Draft→Hold KOT suppression | ALREADY_EQUIVALENT | Edge held/draft (`status=held`,`is_draft`); draft parked without a kitchen ticket. |
| Recall must not switch active terminal | ALREADY_EQUIVALENT | Edge settle stamps the operator's own per-session terminal (server-authoritative). |
| business_date on returns/voids | ALREADY_EQUIVALENT | Edge migration present; write-path identical. |
| Product stock/non-stock consumption guard | ALREADY_EQUIVALENT | `is_stock_tracked`-guarded consumption; catalog guard ported. |
| Collision-safe print-job numbering | ALREADY_EQUIVALENT | Shared PrintJobService numbering. |
| Printer per-printer isolation | ALREADY_EQUIVALENT | `edge_local_print_deliveries` per-printer FIFO + backoff. |
| Cloud scheduled tenant DB backup | CLOUD_ONLY | Edge has its own encrypted appliance backup. |
| Scheduled owner email report | ONLINE_REQUIRED | Needs internet SMTP; never faked offline. |
| Printer agent shelf / manager health UI | CLOUD_ONLY (manager UI) | Edge runtime already isolates printers. |
| Kashif onboarding / catalog rebuild, supplier-opening GL, tenant admin/billing | CLOUD_ONLY | Not Edge runtime. |

## Summary
```
ONLINE_POS_CHANGES_REVIEWED = 18–28 Aug shared POS + reservation P1/2/2b
ALREADY_EQUIVALENT          = draft, recall-terminal, business_date, product contract, print-job numbering, printer isolation
MUST_PORT (business layer)  = Table Reservations (DONE), Preview Bill endpoint (next), KOT/receipt EscPos parity (next), Quick Report facts (next)
INTERNET_REQUIRED           = Quick Report email, scheduled owner report
CLOUD_ONLY                  = tenant scheduled backup, agent shelf/manager UI, onboarding, supplier GL, tenant admin/billing
REQUIRES_FINANCIAL_DESIGN   = returns/refunds/void/card  (see edge-online-financial-parity-gap.md)
UI_GAP (dominant)           = Edge has no local browser cashier UI — screen-level parity is blocked until it is built

NORMAL_OPERATOR_POS_PARITY_PERCENT ≈ business/API layer ~85% (reservations, dine-in, held/draft, sales, print, business_date) ;
                                     screen/UI layer ~15% (login only) — the cashier UI is the gating gap.
FULL_OFFLINE_PARITY_PERCENT ≈ 70% (financial return/refund/card parity + the cashier UI are the remaining majority)
```

**Acceptance rule (locked):** a shared online cashier action must either work the same offline, or the software
must explicitly say Internet is required. A missing button / hidden behavior is not parity.
