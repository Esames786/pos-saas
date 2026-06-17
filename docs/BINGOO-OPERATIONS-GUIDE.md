# Bingoo POS — Complete Operations Guide (Hands‑On Demo Walkthrough)

This guide walks a **first‑time user** through running the **entire** system from end to end:
**create a product → buy it from a supplier → receive it (GRN) → sell it (POS) → handle a return → run the restaurant → keep the books (accounting) → read your reports.**

It uses the **Enterprise demo workspace** because that plan has **every module switched on** (Retail, Inventory, Purchasing, Restaurant, Kitchen, Sales Controls, Multi‑branch, and Finance/Accounting).

Every step gives you **exact example data to type**, and each step ends by pointing you to the **next** step. If you follow it top to bottom and type the same example values, all the numbers will line up at the end (your sales, stock, and accounting will reconcile).

> **How to read this guide**
> - **Menu path** = where to click in the left sidebar.
> - Tables list each **field**, the **example value** to type, and notes.
> - Fields marked **(required)** must be filled; the rest are optional.
> - 💡 = a tip. ➡️ = go to the next step.

---

## The example business we'll set up

Throughout this guide we pretend you run one combined business and we reuse the same data everywhere:

| Thing | Example value |
|---|---|
| Business | **Bingoo Mega Store** (a shop **and** a small bistro) |
| Branch | **Main Branch – Gulberg** |
| Units | **Piece (PCS)**, **Kilogram (KG)** |
| Categories | **Beverages**, **Burgers**, **Kitchen Raw** |
| Retail product | **Coca‑Cola 500ml** (buy 60, sell 80) |
| Ingredient | **Chicken Boneless** (raw material, by KG) |
| Menu item | **Chicken Burger** (made from a recipe) |
| Supplier | **Metro Distributors** |
| Customer | **Ahmed Khan** |

---

## Part 0 — Log in & get oriented

1. Open your browser and go to the demo workspace login page:
   - **Demo URL:** `https://enterprisedemo.bingoopos.com/login`
     *(on a local install this is `http://enterprisedemo.pos-saas.test/login`)*
   - **Email:** `demo@enterprisedemo.com`
   - **Password:** `demo1234`
2. Click **Log in**. You land on the **Dashboard**.
3. Look at the **left sidebar** — this is your map. It is grouped into sections:
   **Administration · Operations · Inventory · Sales · Purchasing · Restaurant · Kitchen Inventory · Printing · Reports · Sales Controls · Finance · Catalog.**

> 💡 The demo already contains sample data. That's fine — we will **add our own** new records so you learn every screen. Your new records sit alongside the samples.

➡️ Next: set up the foundation (branch, units, categories).

---

## Part 1 — Foundation setup

Before products can exist, you need a **branch** (a physical location), **units** (how you measure things), and **categories** (how you group products).

### Step 1.1 — Create a Branch
**Menu path:** Sidebar → **Operations → Branches** → **Add Branch**

| Field | Example value | Notes |
|---|---|---|
| Name **(required)** | `Main Branch – Gulberg` | Your shop/outlet name |
| Code | `MAIN` | Short code |
| Business Type | `Both` (Retail + Restaurant) | Pick *Retail*, *Restaurant*, or *Both* |
| Phone | `042-111-2222` | |
| Email | `main@bingoomega.com` | |
| Address | `12 Main Boulevard, Gulberg, Lahore` | |
| Timezone | `Asia/Karachi` | |
| Tax enabled? | `Yes` | Turn on if you charge sales tax |
| Tax Registration No | `1234567-8` | Shown on invoices if enabled |
| Show tax number on invoice | `Yes` | |
| Receipt Footer | `Thank you for shopping at Bingoo Mega Store!` | Prints at bottom of receipts |
| Status | `Active` | |

Click **Save**.

➡️ Next: units of measure.

### Step 1.2 — Create Units of Measure
**Menu path:** Sidebar → **Catalog → Units of Measure** → **Add Unit**

Create **two** units (do this twice):

**Unit A — Piece**
| Field | Example value |
|---|---|
| Name **(required)** | `Piece` |
| Code | `PCS` |
| Unit Type | `Quantity` (whole numbers) |
| Is Base Unit? | `Yes` |
| Base Factor | `1` |
| Active | `Yes` |

**Unit B — Kilogram** (for kitchen raw materials)
| Field | Example value |
|---|---|
| Name **(required)** | `Kilogram` |
| Code | `KG` |
| Unit Type | `Weight` (allows decimals like 1.5) |
| Is Base Unit? | `Yes` |
| Base Factor | `1` |
| Active | `Yes` |

➡️ Next: categories.

### Step 1.3 — Create Categories
**Menu path:** Sidebar → **Catalog → Categories** → **Add Category**

Create **three** (repeat the form):

| Name **(required)** | Code | Parent | Sort Order | Active |
|---|---|---|---|---|
| `Beverages` | `BEV` | *(none)* | `1` | Yes |
| `Burgers` | `BUR` | *(none)* | `2` | Yes |
| `Kitchen Raw` | `RAW` | *(none)* | `3` | Yes |

➡️ Next: create your products.

---

## Part 2 — Catalog: create products

A **product** is anything you buy, hold, or sell. We'll create three kinds:
1. A simple **retail product** you buy and sell as‑is (Coca‑Cola).
2. A **raw ingredient** you buy by weight and consume in the kitchen (Chicken).
3. A **menu item** you sell but produce from a recipe (Chicken Burger).

### Step 2.1 — Retail product: Coca‑Cola 500ml
**Menu path:** Sidebar → **Catalog → Products** → **Add Product**

| Field | Example value | Notes |
|---|---|---|
| SKU | `CC-500` | Your internal code |
| Name **(required)** | `Coca-Cola 500ml` | |
| Product Type | `Standard` | A normal stocked item |
| Category | `Beverages` | from Step 1.3 |
| Unit of Measure | `Piece (PCS)` | from Step 1.2 |
| Status | `Active` | |
| Description | `Chilled 500ml bottle` | |
| Purchase Price | `60` | what you pay |
| Selling Price | `80` | what the customer pays |
| Tax Rate (%) | `0` (or `17` if taxable) | |
| Default Variant Barcode | `5449000000996` | scan this at POS |
| Reorder Level | `24` | low‑stock alert threshold |
| Reorder Quantity | `48` | suggested re‑buy qty |
| Sellable | `Yes` | shows in POS |
| Purchasable | `Yes` | can appear on POs |
| Track Stock | `Yes` | keep stock counts |
| Has Variants | `No` | (single product) |
| Track Expiry | `No` | |
| Batch Tracking | `No` | |
| Taxable | `No` (or Yes) | |

Click **Save**.

### Step 2.2 — Raw ingredient: Chicken Boneless
Repeat **Add Product** with:

| Field | Example value |
|---|---|
| SKU | `RAW-CHK` |
| Name | `Chicken Boneless` |
| Product Type | `Standard` |
| Category | `Kitchen Raw` |
| Unit of Measure | `Kilogram (KG)` |
| Purchase Price | `550` (per KG) |
| Selling Price | `0` (not sold directly) |
| Sellable | `No` |
| Purchasable | `Yes` |
| Track Stock | `Yes` |

Click **Save**. *(We'll buy this from the supplier and consume it in a recipe later.)*

### Step 2.3 — Menu item: Chicken Burger
Repeat **Add Product** with:

| Field | Example value |
|---|---|
| SKU | `BUR-CHK` |
| Name | `Chicken Burger` |
| Product Type | `Standard` |
| Category | `Burgers` |
| Unit of Measure | `Piece (PCS)` |
| Selling Price | `450` |
| Default Variant Barcode | `BUR-CHK-01` |
| Sellable | `Yes` |
| Purchasable | `No` (you make it, not buy it) |
| Track Stock | `No` (stock is driven by its recipe) |

Click **Save**.

➡️ Next: add the people you trade with — a supplier and a customer.

---

## Part 3 — People: suppliers & customers

### Step 3.1 — Create a Supplier
**Menu path:** Sidebar → **Purchasing → Suppliers** → **Add Supplier**

| Field | Example value | Notes |
|---|---|---|
| Name **(required)** | `Metro Distributors` | |
| Code | `SUP-METRO` | |
| Contact Person | `Imran Sheikh` | |
| Phone | `0300-1112233` | |
| Email | `sales@metro.com` | |
| Tax Number | `9876543-2` | |
| Address | `Industrial Area, Lahore` | |
| Payment Terms (days) | `30` | credit period |
| Opening Balance | `0` | what you already owe them (leave 0) |
| Status | `Active` | |

Click **Save**.

### Step 3.2 — Create a Customer
**Menu path:** Sidebar → **Sales → Customers** → **Add Customer**

| Field | Example value | Notes |
|---|---|---|
| Customer Code | `CUST-001` | |
| Name **(required)** | `Ahmed Khan` | |
| Phone | `0321-4445566` | |
| Email | `ahmed@example.com` | |
| Tax Number | *(blank)* | |
| Gender | `Male` | |
| Date of Birth | `1990-05-20` | |
| Address | `45 Model Town, Lahore` | |
| Status | `Active` | |

Click **Save**.

➡️ Next: the full purchasing cycle — order it, receive it, get billed, pay the supplier.

---

## Part 4 — Purchasing cycle (buy your stock)

This is the heart of "buying." There are four documents, in order:
**Purchase Order (PO) → Goods Receipt (GRN) → Purchase Bill → Supplier Payment.**

> ⚠️ **Important:** A Purchase Order is **only a request** — it does **not** change stock. Your stock goes **up only when you post the GRN** (you actually received the goods).

### Step 4.1 — Create a Purchase Order
**Menu path:** Sidebar → **Purchasing → Purchase Orders** → **Create Purchase Order**

**Order Header**
| Field | Example value |
|---|---|
| Supplier **(required)** | `Metro Distributors` |
| Branch **(required)** | `Main Branch – Gulberg` |
| Order Date **(required)** | *(today's date — pre‑filled)* |
| Expected Date | *(today + 3 days)* |
| Notes | `Weekly stock order` |

**Product Lines** — for each line either **scan the barcode** in the "Scan Barcode / SKU" box (press Enter) **or** pick from the **Product** dropdown. Then fill the row:

| Product | Variant | Quantity | Unit Cost | Discount | Tax |
|---|---|---|---|---|---|
| `Coca-Cola 500ml` | *(default)* | `48` | `60` | `0` | `0` |
| `Chicken Boneless` | *(default)* | `10` | `550` | `0` | `0` |

💡 When you pick a product, **Unit Cost auto‑fills** from its purchase price — you can override it.

Click **Save Purchase Order**. The PO status becomes **Pending/Approved** (no stock change yet).

### Step 4.2 — Receive the goods (GRN)
**Menu path:** Sidebar → **Purchasing → GRN** → **Create GRN** (Goods Receipt Note)

| Field | Example value | Notes |
|---|---|---|
| Purchase Order | *(select the PO from Step 4.1)* | pulls in its lines |
| Supplier **(required)** | `Metro Distributors` | auto‑fills from PO |
| Branch **(required)** | `Main Branch – Gulberg` | |
| Receipt Date **(required)** | *(today)* | |
| Notes | `Received in full` | |

**Product Lines** (note GRN adds **Batch No** and **Expiry** columns for batch‑tracked items):

| Product | Quantity (received) | Unit Cost | Batch No | Expiry |
|---|---|---|---|---|
| `Coca-Cola 500ml` | `48` | `60` | *(blank)* | *(blank)* |
| `Chicken Boneless` | `10` | `550` | *(blank)* | *(blank)* |

Click **Save / Post GRN**.

✅ **Now your stock is real:** 48 × Coca‑Cola and 10 KG Chicken are added to *Main Branch* inventory. *(Behind the scenes the system also raises an "Accounts Payable" entry — you owe the supplier.)*

### Step 4.3 — Enter the supplier's bill (Purchase Bill)
**Menu path:** Sidebar → **Purchasing → Purchase Bills** → **Create Purchase Bill**

| Field | Example value | Notes |
|---|---|---|
| Goods Receipt (GRN) **(required)** | *(select your GRN)* | pulls in amounts |
| Supplier Invoice No | `INV-2025-554` | the number on their invoice |
| Bill Date **(required)** | *(today)* | |
| Due Date | *(today + 30 days)* | matches payment terms |
| Bill‑level Discount | `0` | optional, applies to whole bill |
| Bill‑level Tax | `0` | optional |
| Notes | `Matches GRN exactly` | |

Click **Save Bill**. The bill total here = **48×60 + 10×550 = 2,880 + 5,500 = 8,380**.

### Step 4.4 — Pay the supplier
**Menu path:** Sidebar → **Purchasing → Supplier Payments** → **Add Payment**

| Field | Example value | Notes |
|---|---|---|
| Supplier **(required)** | `Metro Distributors` | |
| Against Bill | *(select the bill from Step 4.3)* | links payment to bill |
| Branch | `Main Branch – Gulberg` | |
| Payment Date **(required)** | *(today)* | |
| Pay From (Cash/Bank) | `Cash in Hand` | which account the money leaves |
| Payment Method | `Cash` | |
| Amount | `8380` | full settlement |
| Reference No | `PAY-001` | |
| Bank/Cheque fields | *(leave blank for cash)* | fill only for bank/cheque |
| Notes | `Paid in full` | |

Click **Save Payment**.

✅ The supplier balance is now **0** and your **Cash in Hand** dropped by 8,380. The accounting entries post automatically.

➡️ Next: confirm the stock actually arrived.

---

## Part 5 — Check your inventory

**Menu path:** Sidebar → **Inventory → Stock Balances**

You should now see, at *Main Branch*:
- **Coca‑Cola 500ml = 48**
- **Chicken Boneless = 10 (KG)**

Other useful screens here:
| Screen | What it shows |
|---|---|
| **Movements** | Every stock in/out with reason (you'll see the GRN as an "in") |
| **Batches** | Batch/expiry tracking (for batch products) |
| **Low Stock** | Items at/under their reorder level |
| **Expiry Alerts** | Items nearing expiry |

➡️ Next: sell something.

---

## Part 6 — Selling at the POS (retail)

### Step 6.1 — Make a sale
**Menu path:** Sidebar → **Sales → POS**

1. **Pick the branch/terminal** if prompted (choose *Main Branch – Gulberg*).
2. **Add items to the cart**: either **scan** the Coca‑Cola barcode `5449000000996`, or **search** "Coca" and click the product. It appears in the cart at **80** each.
3. Set **quantity = 2** (cart total **160**).
4. **Select customer** (optional): choose `Ahmed Khan`. *(Walk‑in is fine for cash sales.)*
5. Apply a **discount** if you want (e.g., `0`).
6. Click **Pay / Charge**.
7. Choose **Payment Method = Cash**, enter **Cash Tendered = 200** → it shows **Change = 40**.
8. Click **Complete / Charge**. The receipt is generated (and printed if a printer/print‑agent is set up).

✅ Stock for Coca‑Cola drops from 48 → **46**. The sale and its profit post to accounting automatically.

> 💡 **Hold / Park a sale:** if a customer steps away, click **Hold** to park the cart. Find it later under **Restaurant → Held Sales** (also used for retail) and resume it.

### Step 6.2 — Review sales
| Screen | Menu path | Shows |
|---|---|---|
| Sales Orders | **Sales → Sales Orders** | every completed sale (open yours to view/print) |
| Sales Ledger | **Sales → Sales Ledger** | money owed by credit customers |

### Step 6.3 — Process a sales return
**Menu path:** Sidebar → **Sales → Sales Returns** → **New Return**

1. Find the original sale (search by receipt no or customer **Ahmed Khan**).
2. Choose the item to return: **Coca‑Cola 500ml, qty 1**.
3. Reason: `Customer changed mind`.
4. Refund method: `Cash`.
5. Click **Save Return**.

✅ Stock goes back up by 1 (46 → **47**), and a refund + reversal posts to accounting.

➡️ Next: run the restaurant side.

---

## Part 7 — Restaurant operations (dine‑in)

### Step 7.1 — Create a Floor
**Menu path:** Sidebar → **Restaurant → Floors** → **Add Floor**

| Field | Example value |
|---|---|
| Branch **(required)** | `Main Branch – Gulberg` |
| Name **(required)** | `Ground Floor` |
| Sort Order | `1` |
| Active | `Yes` |

### Step 7.2 — Create a Table
**Menu path:** Sidebar → **Restaurant → Tables** → **Add Table**

| Field | Example value |
|---|---|
| Branch **(required)** | `Main Branch – Gulberg` |
| Floor **(required)** | `Ground Floor` |
| Table No **(required)** | `T1` |
| Capacity | `4` |
| Active | `Yes` |

### Step 7.3 — Create a Waiter
**Menu path:** Sidebar → **Restaurant → Waiters** → **Add Waiter**

| Field | Example value |
|---|---|
| Branch **(required)** | `Main Branch – Gulberg` |
| Name **(required)** | `Bilal Ahmed` |
| Code | `W-01` |
| Phone | `0333-7778899` |
| Status | `Active` |

### Step 7.4 — Take a dine‑in order
**Menu path:** Sidebar → **Restaurant → Table Board**

1. Click table **T1** (it's green/free).
2. Assign **Waiter = Bilal Ahmed**, **Guests = 2**.
3. Add items: search **Chicken Burger** → add **×2** (total 900).
4. Click **Send to Kitchen** — this fires a **KOT** (Kitchen Order Ticket). Table T1 turns "occupied".

### Step 7.5 — Kitchen Display (KDS)
**Menu path:** Sidebar → **Restaurant → Kitchen Display**

- The new ticket shows **Chicken Burger ×2 — Pending**.
- Staff click **Start** → **Ready** → **Served** as they cook and deliver.

### Step 7.6 — Settle the table
1. Back on **Table Board**, click **T1 → Bill/Pay**.
2. (If configured) a **service charge** is added automatically.
3. Choose **Payment Method = Cash**, complete payment.
4. Table T1 turns green (free) again.

✅ The dine‑in sale posts like any other sale, and the recipe consumes ingredients (next part).

➡️ Next: tie the kitchen to inventory with recipes.

---

## Part 8 — Kitchen Inventory (recipes & production)

This makes selling a **Chicken Burger** automatically **consume raw chicken** from stock and capture its true food cost.

### Step 8.1 — (Optional) Unit Conversions
**Menu path:** Sidebar → **Kitchen Inventory → Unit Conversions**
Use this if you buy in one unit and use in another (e.g., buy Chicken in **KG**, use in **grams**). For this guide we keep everything in KG, so you can skip it.

### Step 8.2 — Create a Recipe / BOM
**Menu path:** Sidebar → **Kitchen Inventory → Recipes / BOM** → **Add Recipe**

| Field | Example value | Notes |
|---|---|---|
| Name **(required)** | `Chicken Burger Recipe` | |
| Finished Product **(required)** | `Chicken Burger` | the menu item it produces |
| Yield Quantity | `1` | makes 1 burger |
| Yield Unit | `Piece (PCS)` | |
| Active | `Yes` | |
| Notes | `Standard recipe` | |

**Ingredients** (add a line per raw material):
| Ingredient (Product) | Quantity | Unit | Cost Override |
|---|---|---|---|
| `Chicken Boneless` | `0.15` | `Kilogram (KG)` | *(blank — uses stock cost)* |

Click **Save Recipe**.

✅ Now each Chicken Burger sold consumes **0.15 KG chicken** from inventory and records the cost.

### Step 8.3 — Productions (batch cooking, optional)
**Menu path:** Sidebar → **Kitchen Inventory → Productions**
Use this when you pre‑cook a batch (e.g., make 20 burger patties in the morning). It consumes ingredients now and adds finished stock.

### Step 8.4 — Wastage
**Menu path:** Sidebar → **Kitchen Inventory → Wastages**
Record spoilage (e.g., `0.5 KG Chicken — spoiled`). This reduces stock and records the loss.

➡️ Next: optional sales controls, then the accounting books.

---

## Part 9 — Sales Controls (optional but recommended)

**Menu path:** Sidebar → **Sales Controls**

| Screen | Use it for | Example |
|---|---|---|
| **Promotions** | discounts/offers | `10% off Beverages, this week` |
| **Service Charge** | auto charge on dine‑in | `Ground Floor: 5%` |
| **Void Reasons** | reasons staff must pick when voiding | `Wrong item`, `Customer cancelled` |

These tighten control so cashiers can't give silent discounts or void without a reason.

➡️ Next: the accounting/finance module.

---

## Part 10 — Finance & Accounting

Most of your books are **already posting automatically** from the steps above (purchases, sales, returns, supplier payment). This part shows the accounting screens and how to add the few things you enter by hand (like expenses).

### Step 10.1 — Review the Chart of Accounts
**Menu path:** Sidebar → **Finance → Chart of Accounts**
You'll see the standard accounts already seeded (Cash, Bank, Inventory, Accounts Payable, Sales, COGS, etc.). To add one:

| Field | Example value |
|---|---|
| Code | `6300` |
| Name | `Utilities Expense` |
| Type | `Expense` |
| Normal Balance | `Debit` |
| Parent | *(optional)* |
| Active | `Yes` |

### Step 10.2 — Review Cash & Bank Accounts
**Menu path:** Sidebar → **Finance → Cash & Bank Accounts**
The demo has **Cash in Hand** and a **Bank** account. To add a bank account:

| Field | Example value |
|---|---|
| Name | `Meezan Bank – Current` |
| Code | `BANK-MEEZAN` |
| Account Type | `Bank` |
| Linked Account | `Bank` (from Chart of Accounts) |
| Branch | `Main Branch – Gulberg` |
| Bank Name | `Meezan Bank` |
| Account Number | `0123456789` |
| IBAN | `PK00MEZN0000000123456789` |
| Currency | `PKR` |
| Opening Balance | `100000` |
| Default? | `No` |
| Active | `Yes` |

### Step 10.3 — Create an Expense Category
**Menu path:** Sidebar → **Finance → Expense Categories** → **Add**

| Field | Example value |
|---|---|
| Name | `Utilities` |
| Code | `EXP-UTIL` |
| Linked Account | `Utilities Expense (6300)` |
| Sort Order | `1` |
| Active | `Yes` |

### Step 10.4 — Record an Expense (e.g., electricity bill)
**Menu path:** Sidebar → **Finance → Expenses** → **Add Expense**

**Header**
| Field | Example value |
|---|---|
| Voucher No | `EXP-001` |
| Branch | `Main Branch – Gulberg` |
| Expense Date | *(today)* |
| Payment Date | *(today)* |
| Pay From (Cash/Bank) | `Cash in Hand` |
| Payee Name | `LESCO` |
| Notes | `Monthly electricity` |

**Lines**
| Expense Category | Description | Amount | Tax |
|---|---|---|---|
| `Utilities` | `Electricity – June` | `15000` | `0` |

Click **Save**. ✅ Cash drops by 15,000 and the expense posts to the ledger automatically.

### Step 10.5 — Record a Customer Payment (for credit sales)
**Menu path:** Sidebar → **Finance → Customer Payments** → **Add**

| Field | Example value |
|---|---|
| Customer **(required)** | `Ahmed Khan` |
| Against Sale (Sales Order) | *(select a credit sale, if any)* |
| Branch | `Main Branch – Gulberg` |
| Payment Date | *(today)* |
| Receive Into (Cash/Bank) | `Cash in Hand` |
| Amount | `500` |
| Reference No | `RCPT-001` |
| Notes | `Part payment` |

Click **Save**.

### Step 10.6 — Read the financial statements
These are generated from the ledger — just open and (optionally) set a date range, then **Export CSV**:

| Report | Menu path | What it answers |
|---|---|---|
| **Journal Entries** | Finance → Journal Entries | every auto/manual posting |
| **General Ledger** | Finance → General Ledger | all activity per account |
| **Trial Balance** | Finance → Trial Balance | debits = credits (proves books balance) |
| **Profit & Loss** | Finance → Profit & Loss | are you making money? |
| **Branch‑wise P&L** | Finance → Branch‑wise P&L | profit per branch |
| **Balance Sheet** | Finance → Balance Sheet | what you own vs owe |
| **Accounting Export** | Finance → Accounting Export | download CSV packs for your accountant |

> 💡 Open **Trial Balance** last — total Debit should equal total Credit. If it does, every step above posted correctly.

➡️ Next: operational reports and closing the day.

---

## Part 11 — Reports & closing the day

**Menu path:** Sidebar → **Reports**

| Report | Shows |
|---|---|
| **Sales Reports** | sales by day/product/branch/cashier |
| **Shift Reports** | each cashier's shift totals |
| **Inventory Reports** | stock valuation, movements |
| **Purchase Reports** | payables, purchases by supplier |
| **Receivables Aging** | who owes you and for how long |
| **Restaurant Reports** | table turnover, dine‑in performance |
| **Kitchen Reports** | recipe consumption, food cost |
| **Audit Reports** | manager approvals, voids |
| **Print Reports** | print job history |

### Close the day
**Menu path:** Sidebar → **Operations → Daily Closing**
At end of day, **count your cash drawer**, enter the counted amount, and the system compares it to expected cash (sales − refunds + payments). Save the closing — this locks the day's totals.

> 💡 **Shifts** (Operations → Shifts): cashiers **open a shift** when they start and **close** it when they leave; closing reconciles their drawer.

---

## Part 12 — Administration (users, terminals, shifts)

**Menu path:** Sidebar → **Administration**

| Screen | Use it for | Example |
|---|---|---|
| **Users** | add staff logins | `Bilal – Cashier`, email + password |
| **Roles & Permissions** | control what each role can see/do | *Cashier* = POS only; *Manager* = everything except billing |
| **Terminals** (Operations) | register each POS device | `Counter 1` at Main Branch |
| **Billing** | your subscription/plan & invoices | view plan, request upgrade |

> 💡 Give cashiers the **Cashier** role (POS + held sales only). Keep Finance, Users, and Reports for owners/managers.

---

## Appendix A — The complete flow at a glance

```
SETUP            Branch → Units → Categories
CATALOG          Products (retail + ingredient + menu item)
PEOPLE           Supplier + Customer
BUY              Purchase Order → GRN (stock +) → Purchase Bill → Supplier Payment (cash −)
SELL (retail)    POS sale (stock −) → [Return] → Sales Ledger
RESTAURANT       Floor → Table → Waiter → Table Board order → KOT → KDS → Settle
KITCHEN          Recipe/BOM → (Production) → Wastage   [selling consumes ingredients]
FINANCE          Chart of Accounts → Cash/Bank → Expense → Customer Payment
                 → Trial Balance / P&L / Balance Sheet (auto‑reconciled)
REPORTS          Sales / Inventory / Purchases / Restaurant / Kitchen
CLOSE            Shift close → Daily Closing
```

## Appendix B — Example data quick reference

| Record | Key fields |
|---|---|
| Branch | Main Branch – Gulberg (MAIN), Both, Asia/Karachi |
| Units | Piece (PCS, quantity), Kilogram (KG, weight) |
| Categories | Beverages (BEV), Burgers (BUR), Kitchen Raw (RAW) |
| Coca‑Cola 500ml | CC-500, buy 60, sell 80, barcode 5449000000996 |
| Chicken Boneless | RAW-CHK, KG, buy 550, not sellable |
| Chicken Burger | BUR-CHK, sell 450, made by recipe |
| Supplier | Metro Distributors (SUP-METRO), terms 30 days |
| Customer | Ahmed Khan (CUST-001) |
| PO/GRN | 48 Coca‑Cola @60 + 10 KG Chicken @550 = 8,380 |
| Recipe | Chicken Burger ← 0.15 KG Chicken Boneless |
| Expense | Utilities / Electricity / 15,000 |

## Appendix C — Daily routine checklist (after setup)

1. **Open shift** (cashier) → Operations → Shifts.
2. **Sell** all day at **Sales → POS** (scan, charge, print).
3. **Receive deliveries** as they arrive → **Purchasing → GRN**.
4. **Record expenses** as you pay them → **Finance → Expenses**.
5. **Close shift**, then **Operations → Daily Closing** (count cash).
6. **Check Reports → Sales** and **Finance → Profit & Loss** before leaving.

---

*Guide covers every module in the Enterprise demo: Catalog, Inventory, Stock Count/Transfers, Purchasing, Sales/POS, Restaurant, Kitchen Display & Inventory, Printing, Sales Controls, Reports, Finance/Accounting, and Administration. Follow it once end‑to‑end and you'll know how to run the whole system.*
