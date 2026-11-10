# BILL-DATE-FILTER-1 — Supplier Invoices list: From / To + Today / Yesterday (10 Nov 2026)

Owner, 10 Nov (screenshot of Purchases → Supplier Invoices): "add date range filter today … also in supplier new screen".

## What

The Supplier Invoices list (`/purchase-bills`) gets **From / To** on the invoice date (`purchase_bills.bill_date`) and two
quick buttons, **Today** and **Yesterday**, in the same filter form as Supplier and Status, so all of them work together.
It reuses the Sales Returns pattern (`partials/date-range-filter` and `SalesReturnController::dateRange`):

- `range=today|yesterday` is the branch's own calendar day (`TenantClock::displayTimezone`, Asia/Karachi);
- typed dates win otherwise; either end may be empty; a date that is not `Y-m-d` is ignored, not an error.

"Tomorrow" was read as **Yesterday**, because an invoice is never dated tomorrow. That is the same pair Sales Returns has.

No switch: a list filter changes nothing until it is used, and the owner asked for it for this screen.

## Impact (system map)

- Tables: `purchase_bills` (read only). Route: `tenant.purchase-bills.index` (same permission).
- Files: `app/Http/Controllers/Tenant/PurchaseBillController.php` (index + `dateRange`),
  `resources/views/tenant/purchase-bills/index.blade.php`.
- Test: `tests/MySql/PurchasingSafetyMySqlTest::test_the_invoice_list_filters_by_invoice_date_with_today_and_yesterday`.
  It goes through the real route and covers: no dates, Today, Yesterday, From–To, From only, Today + supplier, a broken
  date, and the boxes showing the chosen day. The file is 6/6 green; with the controller change removed the test fails.

## Not done (ask first)

The other purchase tabs (Goods Receipts, Payments, Supplier Returns, Purchase Orders) have no date filter either. The
same few lines would add one to each.
