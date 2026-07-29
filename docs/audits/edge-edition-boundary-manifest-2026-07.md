# Bingoo Edge — Boundary Manifest (EDGE-EDITION-ARCHITECTURE-1)

Status: **design only** — 2026-07. This is the allowlist/denylist reference for the future
`EDGE-BUILD-STRIPPER-1` builder. **No builder and no code are created in this sprint.**
The build must ship **only** what is listed as `INCLUDE`; anything not listed is excluded
by default (allowlist, not blacklist), so a new cloud module added later is excluded
automatically.

Legend: `INCLUDE` ship as-is · `INCLUDE (shared)` shared logic, keep identical to cloud ·
`INCLUDE (reduced)` ship with cost/GL columns stripped where safe · `REPLACE` ship an
Edge-specific version · `EXCLUDE` never ship.

---

## 1. Controllers (`app/Http/Controllers/Tenant`)

### INCLUDE — POS / restaurant / shift / printing
```
POSController                       INCLUDE
SalesOrderController                REPLACE (capture path only; finalize→EdgeSaleCaptureService)
HeldSaleController                  INCLUDE
RestaurantFloorController           INCLUDE
RestaurantTableController           INCLUDE
RestaurantTableSessionController    INCLUDE
RestaurantWaiterController          INCLUDE
ShiftController                     INCLUDE
TerminalController                  INCLUDE
ComboController                     INCLUDE
ModifierGroupController             INCLUDE
PaymentMethodController             INCLUDE (cash-family visible; card/gateway hidden)
CustomerController                  INCLUDE (local cache + quick-store only)
DeliveryChannelController           INCLUDE (own/manual delivery only)
DeliveryRiderController             INCLUDE (own/manual delivery only)
PrinterController                   INCLUDE
PrintJobController                  INCLUDE
PrintDocumentController             INCLUDE
PrintAgentController                INCLUDE (re-pointed to local Edge URL)
CategoryPrinterMappingController    INCLUDE
```

### EXCLUDE — cloud-only (finance / purchasing / stock authority / mfg / SaaS)
```
Finance/*                           EXCLUDE
Reports/*                           EXCLUDE
Manufacturing/*                     EXCLUDE
KitchenProductionController         EXCLUDE
PurchaseOrderController             EXCLUDE
PurchaseBillController              EXCLUDE
PurchaseReturnController            EXCLUDE
SupplierController                  EXCLUDE
SupplierPaymentController           EXCLUDE
StockAdjustmentController           EXCLUDE
StockTransferController             EXCLUDE
StockCountController                EXCLUDE
DepartmentController                EXCLUDE
DepartmentCountController           EXCLUDE
DepartmentDashboardController       EXCLUDE
DepartmentStockTransferController   EXCLUDE
SalesLedgerController               EXCLUDE
TenantBillingController             EXCLUDE
TenantUpgradeController             EXCLUDE
TenantUserController                EXCLUDE (provisioning)
SalesReturnController               EXCLUDE (returns are against official cloud state)
```

---

## 2. Services (`app/Services`)

### INCLUDE (shared) — must stay byte-identical to cloud to avoid drift
```
Sales/SalesTotalsService            customer-facing totals (depends on PromotionService only)
Sales/PromotionService              INCLUDE — but usage_limit counter is CLOUD-authoritative
Sales/SaleIdempotencyService        client_uuid contract (shared with sync)
Kitchen/UnitConversionService       unit maths
Printing/PrintJobService            receipt/KOT jobs (+ HARDEN-1 ensure-once)
Printing/PrintRoutingService        printer routing
Printing/EscPosPayloadService       ESC/POS build
Edge/BranchOperatingModeService     mode/lifecycle guard (env-only role/binding)
```

### REPLACE / OMIT — the finance-coupled posting engine stays cloud-only
```
Sales/SalesService::finalizePaidSale   OMIT on Edge (cloud posts inventory+COGS+GL on sync)
InventoryService (postOutFefo, FEFO)   OMIT on Edge
RecipeConsumptionService (COGS)        OMIT on Edge
postSalesLedger / journal posting      OMIT on Edge
NEW EdgeSaleCaptureService             REPLACE — local operational capture, no GL
```
Inspection confirmed `finalizePaidSale` calls `inventoryService->postOutFefo`,
`recipeConsumptionService->consumeForSalesOrderLine`, and `postSalesLedger` — exactly the
official-accounting surface that must not ship.

### EXCLUDE — cloud-only service namespaces
```
Services/Finance/*      Services/Manufacturing/*   Services/Purchasing/*
Services/Inventory/*  (official ledger/FEFO)       Services/Reporting/*
Services/Tenancy/*    (provisioning/switching)     Services/Billing/*
```

---

## 3. Models (`app/Models/Tenant`, 115 total)

### INCLUDE (operational subset)
```
Branch Terminal User ManagerPin
Category CategoryTranslation Product ProductVariant ProductBarcode
ProductBranchPrice ProductTranslation ProductVariantTranslation
Combo ComboComponent Modifier ModifierGroup Unit UnitConversion
PaymentMethod Customer DeliveryChannel DeliveryRider
RestaurantFloor RestaurantTable RestaurantTableSession RestaurantWaiter
SalesOrder SalesOrderLine SalePayment Shift CashCountLine
Printer PrintJob PrintAgent CategoryPrinterMapping
TerminalPrinterSetting UserPrinterSetting ReceiptLayoutSetting
ServiceChargeSetting VoidReason Promotion PromotionTarget
CurrencyDenomination Currency
```

### INCLUDE (reduced) — snapshot / strip GL & cost columns
```
StockBalance            read-only snapshot; drop average_cost/GL linkage where safe
```

### EXCLUDE — official finance / receivables / payables
```
Account JournalEntry JournalLine SalesLedger CustomerLedger SupplierLedger
CashBankAccount CashBankAccountTransaction DailyClosing
ExpenseCategory ExpenseVoucher ExpenseVoucherLine
OpeningBalanceBatch OpeningBalanceLine CustomerPayment
```

### EXCLUDE — purchasing / suppliers / stock authority
```
Supplier SupplierPayment PurchaseOrder PurchaseOrderLine
PurchaseBill PurchaseBillLine PurchaseReturn PurchaseReturnLine
GoodsReceipt GoodsReceiptLine
StockLedger StockAdjustment StockAdjustmentLine StockTransfer StockTransferLine
StockCountSession StockCountLine InventoryBatch
Department DepartmentCategoryMap DepartmentConsumptionException
DepartmentCountAdjustment DepartmentCountLine DepartmentCountSession
DepartmentProductOverride DepartmentStockBalance DepartmentStockLedger
DepartmentStockTransfer DepartmentStockTransferLine
```

### EXCLUDE — manufacturing / production / recipes-as-GL
```
ManufacturingBom ManufacturingBomLine ManufacturingConsumptionLine
ManufacturingConsumptionRecord ManufacturingCustomer ManufacturingPostingSetting
ManufacturingRejectionLine ManufacturingRejectionRecord
ManufacturingScrapLine ManufacturingScrapRecord
MaterialRequisition MaterialRequisitionLine ProductionOrder
KitchenProduction KitchenProductionIngredient KitchenWastage
FinishedGoodReceipt FinishedGoodReceiptLine WipJob WipJobLine
Recipe RecipeConsumption RecipeIngredient
```
(`SalesReturn`, `SalesReturnLine`, `ManagerApproval` → EXCLUDE on Edge MVP; local-only
approval handled by a REPLACE service in a later phase.)

---

## 4. Views (`resources/views/tenant`)
```
INCLUDE: pos/**  restaurant/**  printing/**  (+ layout/partials they require)
EXCLUDE: finance/**  reports/**  manufacturing/**  purchases/**  suppliers/**
         stock*/**  departments/**  billing/**  provisioning/**  admin/**
```

---

## 5. Excluded non-code (repo hygiene / secrets)
```
EXCLUDE: .git  .github  tests/  docs/  deploy.sh  scripts/  tools/ (except bundled agent)
         database/seeders (demo/reset)  *.map source maps  dev composer deps
         .env / any production credentials  storage logs from cloud
         system:reset / demo-reset artisan commands
NEVER SHIP: cloud DB credentials, master DB config, other tenants' data,
            permanent device token (minted at pairing instead)
```

---

## 6. Artifact verification checklist (future `verify-edge-artifact.php`)
```
[ ] No finance routes/controllers/models present
[ ] No manufacturing routes/controllers/models present
[ ] No purchasing/supplier routes/controllers/models present
[ ] No stock-authority (ledger/adjust/transfer/count/department) present
[ ] No billing / provisioning / tenant-switching present
[ ] No SalesService::finalizePaidSale / InventoryService / journal posting present
[ ] No cloud DB credentials, no .env secrets, no master DB config
[ ] No other tenant data in the local DB
[ ] No .git / tests / docs / dev dependencies / source maps
[ ] Only EDGE_REQUIRED + INCLUDE(shared) services and pos/restaurant/printing views present
[ ] EdgeSaleCaptureService present; finalize path routes to it (not to finalizePaidSale)
[ ] route:list shows only Edge-permitted endpoints
```
The build **fails closed** if any check trips.

---

## Update — OFFLINE-EDGE-ENTITLEMENT-1 (2026-07)
The commercial gate now exists in the CLOUD app (not the Edge artifact): module registry
entry `offline_edge`, `OfflineEdgeEntitlementService`, `OfflineEdgeController`,
`/settings/offline-edge[/download]`, permissions `tenant.offline-edge.index|download`. These
are cloud-side entitlement/download-gate concerns and are INCLUDE-cloud / EXCLUDE-from-Edge
(the Edge artifact never carries billing/entitlement/download UI). The future
`verify-edge-artifact.php` checklist should assert the offline-edge SETTINGS/entitlement
controller and routes are ABSENT from the stripped Edge build.
