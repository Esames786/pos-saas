# POS Review&Pay — discount vs Bill Preview (verification) + "Preview Bill" on the payment modal

**Status:** RESEARCH / VERIFICATION + DESIGN (nothing built)
**Date:** 2026-08-28
**Scope:** POS Payment ("Review & Pay") modal + the two Bill Preview paths. Additive UI only.

---

## Q1 — "If I apply a discount in Review & Pay, close it, and open Bill Preview, does the discount show?" → VERIFIED: it depends on WHICH Bill Preview

There are **two different Bill Preview buttons** in the POS, and they take different data:

| Button | JS | Endpoint | Uses the modal discount? |
|---|---|---|---|
| **Top "Bill Preview"** (table bar) `#pos-session-bill-preview` | `showTableBillPreview(sessionId)` | `GET /restaurant/table-sessions/{id}/bill-preview` | **NO** |
| **Cart "Bill Preview"** `#bill-preview-btn` ("Current Cart Preview") | `billPreview()` | `POST /api/pos/bill-preview` | **YES** |

### Why the top one does NOT show the discount
1. The manual discount you apply in Review & Pay lives ONLY in the browser: JS vars
   `_manualDiscountType/_manualDiscountValue` mirrored to the hidden inputs
   `#pos-discount-type` / `#pos-discount-value`. **It is NOT saved to the server / table
   session until you actually press "Close & Pay Table Bill".**
2. `showTableBillPreview` fetches the **server-side, committed** table bill
   (`table-sessions/{id}/bill-preview`) and sends NO discount — so it can only show what is
   already stored, which does not include your un-committed discount.

### Why the cart one DOES show it
`billPreview()` POSTs to `/api/pos/bill-preview` with `discount_type`, `discount_value`,
`promo_code`, `tip`, and per-line `discount_amount` read from the current cart/modal state.
The `POSController::billPreview` endpoint already accepts and applies all of these
([POSController.php:497](../../app/Http/Controllers/Tenant/POSController.php#L497)), so its
"Current Cart Preview" reflects the discount live.

**Answer to the owner:** the discount **will show** in the *cart* Bill Preview (Current Cart
Preview) but **will NOT show** in the *table* Bill Preview (the top button) — because the table
preview reads the committed server bill and the discount isn't committed until you pay. This is
correct/expected, not a bug.

---

## Q2 — Preview inside Review & Pay showing EVERYTHING applied before close → FEASIBLE, LOW-EFFORT

**Owner's actual ask (2026-08-28):** inside the Review & Pay modal, a preview that shows the running
**discount + tax + service charge + tip/promo** — everything applied before "Close & Pay" — so the
operator can show/print the exact pre-bill to the customer.

**Already covered by the engine.** The cart `billPreview()` POSTs to `/api/pos/bill-preview`, which
runs the **same `SalesTotalsService`** that "Close & Pay" uses — so its preview already reflects the
**discount, tax, service charge, tip, promo and per-line discounts** exactly as they will be charged.
And `billPreview()` reads the LIVE modal state (`#pos-discount-type` / `#pos-discount-value`,
`_tipAmount`, promo) that the operator set inside Review & Pay. So we only need to reach it from the
Payment modal — no new totals logic.

Confirmed facts:
- Payment modal = `#paymentModal` (a Bootstrap `modal fade`, inside `#pos-sale-form`).
- `billPreview()` renders the discounted/taxed/service-charged preview into `#billPreviewModal`
  (iframe) with a working **Print** button (`#print-bill-preview-btn`).

### Design
- Add a **"Preview Bill"** button to the Payment modal footer (beside **Back** / **Close & Pay**),
  gated the same as the existing cart preview (or always visible to the payment operator).
- On click it calls the existing **`billPreview()`** — which reads the CURRENT
  `#pos-discount-type` / `#pos-discount-value` (already set the moment the operator taps *Apply
  Discount*), so the preview shows the DISCOUNTED bill.
- The preview opens in `#billPreviewModal` with its existing **Print** button, so the operator can
  print / hand / show it to the customer, then return to the Payment modal to finish.

### The one thing to get right — nested modals
The Payment modal is open when the operator taps Preview Bill, and `#billPreviewModal` opens on
top. Bootstrap supports stacked modals but the backdrop/scroll can misbehave. Two safe options:
- **(A)** Show the preview modal on top (stack) and, on its close, keep the Payment modal open —
  set `data-bs-backdrop`/z-index so the Payment modal stays put. Simplest for the operator (they
  land back on payment).
- **(B)** Open the preview in a lightweight print window (like the current *print-bill-preview-btn*
  fallback) instead of a modal — no stacking at all; the customer copy prints directly.
  Recommended if stacking proves fiddly on the shop's browser.

### Reuse map (little new code)
| Need | Reuse |
|---|---|
| Discounted cart preview HTML | `billPreview()` + `POST /api/pos/bill-preview` (already discount-aware) |
| Print the customer copy | existing `#print-bill-preview-btn` handler |
| Current discount value | `#pos-discount-type` / `#pos-discount-value` (already mirrored on Apply Discount) |

New: one button in the Payment modal + a small click handler (call `billPreview()`), plus the
nested-modal handling (A or B).

### Notes / edge cases
- The preview is of the CURRENT cart+discount, i.e. exactly what "Close & Pay" would charge — so it
  is a truthful pre-bill for the customer.
- Branch discount permission is unchanged: the operator can only apply a discount they're allowed
  to (existing `manual_discount_approval_mode` gate); Preview Bill just shows whatever was applied.
- No server change needed — `/api/pos/bill-preview` already does everything.

---

## Recommendation
1. Q1 is answered — no code needed.
2. Q2: add a **"Preview Bill"** button to the `#paymentModal` footer (beside Back / Close & Pay) that
   calls the existing `billPreview()`. Because `billPreview()` already goes through `SalesTotalsService`
   and reads the live modal discount/tip/promo, the preview shows the **full running bill (discount +
   tax + service charge + tip)** — exactly what Close & Pay will charge. **No server change.**
   - Implementation: **option (A) stacked modal** (call `billPreview()` as-is; `#billPreviewModal`
     opens over `#paymentModal`; on close the operator lands back on payment) — simplest, Bootstrap 5
     supports stacking. If the shop's browser shows any backdrop/scroll glitch, fall back to **option
     (B) print-window** (fetch the preview HTML and open it in a print window like the existing
     `#print-bill-preview-btn` fallback) — zero stacking.
   - Build it option-A first, test the stack on the counter browser; keep B as the guaranteed fallback.

Effort: 1 button + 1 small handler (+ minor z-index/backdrop handling for A). Additive UI only; no
change to totals, payment, or the existing cart/table previews.
