# Supplier bill net of an earlier return — posting matrix (21 Oct 2026)

> **Built** as SUPPLIER-NET-BILL-1 (`PurchaseBillService`, `SupplierNetBillMySqlTest`). Differences from the design below:
> bills cannot be voided in this system, so the "netted bill voided" case does not exist. **Review fixes (21 Oct,
> `docs/plans/supplier-invoice-review-fixes-2026-10-21.md`):** the GL posts inside the same transaction (a journal that
> cannot post saves nothing); a supplier who deducted only PART of a return is recorded with the amount they deducted
> (never as a discount) — the return's unused credit is spent first, the rest stays supplier credit; a blocked net
> invoice no longer tells the person to record a full invoice the supplier did not send.
>
> **Owner, 21 Oct — a credit that already sits on another invoice is never moved silently** (replaces "moved off it
> first" in the running-account row below):
> - it settled an invoice that is now **fully paid** → "Net invoice — returns already deducted" is **blocked** and the
>   screen says why (that invoice would reopen): choose "Full invoice — before returns" only if the supplier's invoice
>   lists everything; if the supplier deducted the return, leave the goods receipt without an invoice and have the
>   accountant resolve the paid invoice first;
> - it sits on an invoice that is **still open** → the review says it in words — "Rs 200.00 of this credit will be moved
>   off Bill X: its outstanding goes from 300.00 to 500.00" — and posting needs a tick from a person who may void
>   supplier payments (`tenant.supplier-payments.void`). Each move writes `supplier_credit_reallocations` (new invoice,
>   return, from-invoice, amount, outstanding before → after, who confirmed, when) before the allocation is released.
>   Without the tick, or without that permission, the server refuses (not only the screen).
>
> **Screen:** compact supplier / goods-receipt strip; items in the main area (a long list scrolls in its own box); a
> sticky summary on the right — Received value → Return adjustment → Actual supplier invoice → Supplier credit applied
> → Remaining — with one navy "Review invoice"; on a phone a bar at the bottom keeps Remaining and Review invoice on
> screen. A normal invoice asks nothing; the two-option question appears only when a return came before the invoice,
> nothing pre-picked. The return is subtracted once: from the invoice (net) or as credit paying it (full), never both.
> "How to use this screen" (closed by default) on every purchasing task screen.

Owner, 21 Oct: record the ACTUAL supplier invoice. Both must end at payable 800:

- GRN 1,000; supplier bill 1,000; separate return credit 200 → payable 800 (works today).
- GRN 1,000; supplier adjusted the return and billed 800 → payable 800, **not 600** (today: 600).

The 200 already credited is reconciled by a source-linked record so it is never deducted twice or reused. The old
return is never edited or deleted. No fake gross invoice. Until this is built and tested, nothing about it is live.

## Why today gives 600

The return before the bill credits the supplier ledger (−200) and books Dr 2100 / Cr 1400 200; with no bill yet, the
running account parks the 200 as credit — or settles the supplier's OLDEST open bill with it. A bill of 800 then
debits 800 and takes the parked credit first: 800 − 200 = 600, and inventory GL sits 200 below stock value.

## The model

When a bill is entered for a receipt that has posted returns before it, the bill screen asks — nothing pre-picked —
"Is the supplier's invoice for everything received, or already less the returns?" and lists those returns.

- **Full** — exactly today: the returns stay credits.
- **Net** — the bill records the invoice as typed (800) and one `purchase_bill_return_nettings` row per return it nets
  (bill, return, amount = what is still unused of that return's credit). That row is the source of three entries:

| Book | Entry from the netting row | Net effect with the return + the 800 bill |
|---|---|---|
| Supplier ledger | debit 200, "return R-… taken off invoice B-…" | −200 + 800 + 200 = **800** |
| GL | Dr 1400 / Cr 2100 200 — the mirror of the return's own split (stock part to 1400, purchase-only part to its cost head) | 1400: −200 + 800 + 200 = 800 = stock value; 2100: 800 |
| Running account | the return's credit is marked used by the netting — never offered to this bill or any other; if it had already settled another bill, that allocation is moved off it first (that bill owes again what the supplier never credited on it) | bill B due 800 |

## Rules the server enforces

- Only returns of THIS receipt, same supplier and branch, status posted, with credit still unused.
- One return can be netted once (unique: return); a netting is never edited, only voided with its bill (same date
  choice as every money void).
- Inside the transaction that holds the supplier row: the bill, its nettings, the ledger row, the allocation moves.
- The GL netting entry is posted with the bill's; if it cannot post, nothing is saved (as supplier payments today).
- A bill voided later voids its nettings: the return's credit becomes free again.

## Tests to write first (MySQL, real services)

1. Full bill after a return → 800 (today's case, pinned already).
2. Net bill after a return → 800; stock 8, 1400 = 800, 2100 = 800; the return's credit used once.
3. Net bill when the return had already settled an older bill → the older bill owes again; totals right.
4. The same return netted on two bills (two people at once) → the second refused.
5. Net bill voided → the return's credit is free again; nothing double.
6. Free goods returned (credit 0) → nothing to net.

## Until it is built

Today's screen keeps working as it does. If needed sooner, a temporary rule: a bill for a receipt with an unused
return credit asks the same question, and "net" is refused with a clear message (record the full invoice, or wait) —
never a guessed gross amount.
