# POS SaaS — Requirements Document (requirements.md)

## 1. Functional Requirements

### 1.1 Tenancy
- Subdomain-based access (*.mywebsite.com)
- Admin activation flow
- DB-per-tenant isolation

### 1.2 Users & Permissions
- Spatie roles/permissions
- Route-name == permission
- CRUD + action permissions (print/export/approve)

### 1.3 Branch & Shift
- Multi-branch
- Terminals per branch
- Shift open/close
- Daily closing with variance
- Optional denomination cash count

### 1.4 Catalog
- Categories (hierarchical)
- Products (SIMPLE/RECIPE/HYBRID/SERVICE)
- Variants, barcodes
- Branch pricing

### 1.5 Inventory
- Stock ledger (IN/OUT)
- Batches + expiry
- FEFO consumption
- Adjustments, transfers, wastage

### 1.6 Recipes
- Ingredients + UOM
- Recipe headers/lines
- Auto deduction on sale

### 1.7 Purchasing
- Suppliers
- PO → GRN → Bill → Payment
- Supplier ledger

### 1.8 POS
- Store mode (barcode)
- Restaurant mode (tables)
- Split bill by items
- Discounts (manual/promo/coupon)
- Payments (cash/card/bank/cheque)

### 1.9 Restaurant
- Floors, tables
- Table sessions
- Waiter assignment (no waiter login required)

### 1.10 Printers & KOT
- Printers (IP/system)
- Prep stations
- Routing rules
- KOT + print jobs
- Remember printer pattern (terminal+user)

### 1.11 Promotions
- % or fixed
- date/time range
- order-type filter (dine-in/takeaway/store)
- min bill, max cap

### 1.12 Tax/GST
- Branch tax profile
- Product/category overrides
- Inclusive/exclusive pricing

### 1.13 Payments & Subscriptions
- Gateways: 2Checkout, PayFast/PayPro, Payoneer
- Webhooks
- Trial → active → past_due → cancelled

### 1.14 Reports
- Sales, inventory, expiry
- Purchasing
- Shift/daily closing

### 1.15 Settings
- Currency + denominations
- Print templates (receipt/invoice/KOT)
- Number sequences
- Banks & payment methods

## 2. Non-Functional Requirements
- Performance: fast POS interactions (<200ms UI actions)
- Reliability: print job retries
- Security: tenant isolation, CSRF, auth guards
- Scalability: DB-per-tenant, horizontal scaling
- Localization-ready (i18n)

## 3. Constraints
- Pakistan-based payment stack
- Browser POS (touch + desktop)

## 4. Acceptance Criteria
- Accurate stock after each sale/purchase
- Correct tax and discount allocation
- Permission-based UI and API enforcement
- Stable printing to multiple LAN printers

