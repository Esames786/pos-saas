# DASHBOARD-CARDS-WHEN-USED-1 — Tax and Expiry cards only where they mean something; how the 1% bills (2 Nov 2026)

## 1. The 1% website commission: how it bills (no new box)

The owner asked whether the 1% will attach to the monthly invoice the way WhatsApp does, and whether the breakdown
gets a new box or goes in the existing one.

What already exists (old commits reviewed):

- **`f8cb20df` ONLINE-ORDERING-1 (11/n):** every paid website order makes a commission row, `website_order_charges`,
  equal to the order's net sale × 1%. Returns count as minus, on their own day.
- **`0ccdbf7b` BILLING-TENANT-CYCLE-1** (owner: "1 se 31 ka concept nahi … aik invoice, breakup ke saath"):
  - Usage is written OPEN.
  - On each tenant's own invoice day (Khatri the 15th, Kashif Food the 25th),
    `billing:generate-monthly-invoices` builds **one** invoice in one transaction.
  - That invoice has a plan line, WhatsApp day-wise lines, and **website day-wise lines**, for example
    "2 orders · net sale 2,250.00 · 1%".
  - All open usage is locked into it, and the running total starts from zero again.
- **`8fa3cf6b` DASHBOARD-BILLING-ONE-BOX-1 + `0ccdbf7b`:** the dashboard shows **one** "Usage so far" box. When website
  charges exist it already splits the money ("WhatsApp X · Website orders Y") and the counts ("N messages · M web
  orders"), with "Added to the invoice on <date>". The Details modal lists them separately under "Accruing this
  month". The box shows WhatsApp only today because no website order has been paid **and** processed yet. The
  commission rows are written nightly at 03:40 PKT (`billing:website-commission --yes`), so a website order paid today
  appears in the box tomorrow morning.

**Answer:** no new box and no new invoice. The 1% lands in the same running box and on the same monthly invoice as
WhatsApp, in its own lines with its own subtotal. One small polish is in scope here: the box's icon is the WhatsApp
logo. Once website charges exist it shows a neutral receipt icon, so the box no longer looks WhatsApp-only.

## 2. Tax Collected and Expiry Alerts: only when used

Owner, 2 Nov (crossed both cards): "tax collected and expiry tab aen agar waqai mai tax collect hua hai and product
ki expiry waqai mai ho ya inventory ho".

The precedent is already on this dashboard: **Service Charge** and **Tips** render only when their amount is > 0.

- **Tax Collected** shows when any active branch has tax switched on, **or** tax was collected today, **or** tax sits
  on open bills. Without tax it is never shown.
- **Expiry Alerts (30d)** shows when the business tracks expiry: any product with expiry tracking, **or** any stock
  batch with an expiry date, **or** an alert exists. A restaurant that never uses expiry never sees it.

Prod check, 2 Nov:

| Tenants | Tax | Expiry |
|---|---|---|
| All 13 | no branch has tax on; tax collected in the last 30 days = 0 | — |
| demo, pharmacydemo | — | track expiry (6 / 41 products, 14 / 47 batches) |
| Everyone else, all restaurants | — | none |

So after this change Tax is hidden everywhere until someone switches tax on. Expiry stays only on demo and
pharmacydemo.

## Impact (system map)

- **Tables (read only):** `branches.is_tax_enabled`, `sales_orders.tax_amount`, `products.has_expiry`,
  `inventory_batches.expiry_date`.
- **Screen:** tenant Dashboard.
- **Code:** `DashboardController::index`, two flags; `dashboard.blade.php`, two `@if`s and the usage box icon.
- **Tests:** the existing Dashboard MySQL tests, plus one new test. The new test covers:
  - a restaurant with no tax and no expiry sees neither card;
  - a branch with tax on sees Tax;
  - a product with expiry tracking makes Expiry appear;
  - the page is rendered through the controller.
