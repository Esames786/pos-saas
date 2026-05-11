# POS SaaS — Task Breakdown (tasks.md)

## Phase 0 — Foundation
- Setup Laravel 13 project
- Configure master DB
- Implement tenant resolver middleware
- Dynamic DB switching
- Install Spatie permissions
- Basic auth screens

## Phase 1 — Layout & Theme
- Convert HTML theme to Blade
- Create layouts (auth/app)
- Sidebar + header
- Permission-based menu rendering

## Phase 2 — Admin (Master)
- Tenants CRUD
- Domains CRUD
- Databases provisioning
- Plans & features
- Subscriptions
- Route sync/publish

## Phase 3 — Users & Access (Tenant)
- Users CRUD
- Roles & permissions UI
- Branch-user assignment

## Phase 4 — Branch/Terminal/Shift
- Branch CRUD + settings
- Terminals CRUD
- Shift open/close
- Daily closing + variance
- Cash denomination count

## Phase 5 — Catalog
- Categories
- Products
- Variants
- Barcodes
- Branch pricing

## Phase 6 — Inventory
- Batches
- Stock ledger
- Adjustments/transfers/wastage
- Alerts (low stock/expiry)

## Phase 7 — Recipes
- Ingredients
- Recipe headers/lines
- Costing

## Phase 8 — Purchasing
- Suppliers
- PO
- GRN
- Bills
- Supplier payments

## Phase 9 — POS (Store)
- Scan/search
- Cart
- Discounts
- Payment modal
- Receipt print

## Phase 10 — Restaurant
- Floors/tables
- Table sessions
- Waiter assignment
- Split bill

## Phase 11 — Printers & KOT
- Printers CRUD
- Prep stations
- Routing rules
- KOT tickets
- Print jobs + retry

## Phase 12 — Promotions & Tax
- Promotions engine
- Tax profiles/rates/rules

## Phase 13 — Reports
- Sales, inventory, purchasing
- Shift/daily closing reports

## Phase 14 — Settings
- Currency/denominations
- Templates (receipt/KOT)
- Number sequences
- Payment methods/banks

## Phase 15 — Payments & Subscription
- Integrate 2Checkout
- Integrate PayFast/PayPro
- Webhooks
- Subscription lifecycle

## Phase 16 — QA & Deployment
- End-to-end testing
- Print testing (LAN)
- Performance tuning
- Deploy (Nginx, SSL, queues)

