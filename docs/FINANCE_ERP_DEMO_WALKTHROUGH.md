# Finance ERP Demo Walkthrough — Bingoo POS

**Demo tenant:** financedemo.bingoopos.com
**Login:** demo@financedemo.com / demo1234  (or owner@financedemo.com / demo1234)
**Plan:** Finance ERP — full double-entry GL, no restaurant modules

---

## Pre-demo verification checklist

Before the client call, confirm:

```
[ ] Login works (demo@financedemo.com / demo1234)
[ ] Dashboard loads without errors
[ ] Sidebar groups collapse/expand on click
[ ] Soon badges are inline (not wrapping to a new line)
[ ] Finance menu opens and shows all sub-items
[ ] Manufacturing menu opens and shows Coming Soon cards
[ ] Restaurant and Kitchen sections are NOT visible (not on this plan)
[ ] /finance/trial-balance shows tb_diff = 0.00
[ ] /finance/profit-loss shows net profit > 0
[ ] /finance/balance-sheet matches P&L current earnings
```

---

## Demo dataset (what is pre-loaded)

| Item | Value |
|---|---|
| Branch | Head Office (HO) |
| Products | 6 (Steel Bracket A1, Aluminium Sheet 2mm, Plastic Casing Unit, Copper Wire Roll, Carton Box Large, Industrial Adhesive 1L) |
| Opening Cash | PKR 500,000 |
| Opening Inventory | PKR 283,500 (6 products × 100 units at cost) |
| Opening Accounts Payable | PKR 120,000 |
| Owner Capital | PKR 663,500 (balancing entry) |
| Sales orders | 5 paid orders (approx. PKR 16,950 total revenue) |
| Expense posted | 1 (LESCO electricity — PKR 3,000) |
| Net profit | ~PKR 13,950 (no COGS booked in demo; illustrative) |
| Trial balance | Difference = 0.00 (perfectly balanced double-entry) |

---

## Walk-through script

### Step 1 — Login

Open `https://financedemo.bingoopos.com/login`

- **Credentials:** demo@financedemo.com / demo1234
- Point out: this is a private tenant subdomain; each client gets their own isolated database

---

### Step 2 — Dashboard overview

Land on `/dashboard`

**Talking points:**
- Multi-branch cloud POS with integrated ERP finance layer
- Single login manages Point of Sale, Inventory, Purchasing, and Finance
- All transactions post automatically to the General Ledger — no manual re-entry
- Role-based access: the Owner role here has full access; cashiers see only POS

---

### Step 3 — Chart of Accounts — Level-wise view

Navigate to **Finance → Chart of Accounts** (`/finance/accounts`)

**What to show:**
- Hierarchical tree view: root types (Asset, Liability, Equity, Income, Expense) fold into sub-categories
- Colour-coded type badges: Asset = blue, Liability = red, Equity = green, Income = teal, Expense = amber
- L1 / L2 level chips on child rows
- "This is our standard ERP chart — we configure it to match your business on setup"

**Demo filters:**
1. Filter by Type = Asset → shows all asset accounts flat
2. Clear → tree view returns

**Available now:** Yes, fully operational

---

### Step 4 — Journal Entries / Double Entry

Navigate to **Finance → Journal Entries** (`/finance/journal-entries`)

**What to show:**
- Every Sale, Expense, Opening Balance, and Payment creates an auto-posted journal entry here
- Select the opening balance entry → show Debit: Cash 500k + Inventory 283.5k = Credit: AP 120k + Capital 663.5k
- "Zero-data-entry finance" — POS transactions feed the GL in real time

**Available now:** Yes

---

### Step 5 — General Ledger

Navigate to **Finance → General Ledger** (`/finance/general-ledger`)

**What to show:**
- Pick account: Cash (1110) → shows all movements, running balance
- Multi-branch filter: select All Branches or a specific branch
- Date range filter
- Export button (CSV/PDF) → **Available now**

---

### Step 6 — Trial Balance with multi-branch filter

Navigate to **Finance → Trial Balance** (`/finance/trial-balance`)

**What to show:**
1. Default load shows all accounts
2. Point out **Difference = 0.00** — the system is always in balance
3. Show **multi-branch select**: tick Head Office → Difference stays 0.00
4. Show **Select All** / **Clear** buttons on branch selector
5. Export CSV → instant download

**Key talking point:**
> "The trial balance proves your books are balanced. In a manual system this takes days to reconcile; here it is calculated in real time from every posted transaction."

**Available now:** Yes

---

### Step 7 — Profit & Loss

Navigate to **Finance → Profit & Loss** (`/finance/profit-loss`)

**What to show:**
- Revenue section: ~PKR 16,950 from 5 demo sales orders
- Expenses section: PKR 3,000 electricity bill
- Net Profit: ~PKR 13,950
- Date range filter → narrow to current month
- Multi-branch filter → same Select All / Clear pattern
- Export PDF/CSV

**Available now:** Yes

---

### Step 8 — Balance Sheet

Navigate to **Finance → Balance Sheet** (`/finance/balance-sheet`)

**What to show:**
- Assets: Cash + Inventory + Receivables
- Liabilities: AP + Other payables
- Equity: Capital + **Current Earnings** = matches P&L Net Profit exactly
- Point out: "Current Earnings on the Balance Sheet equals the P&L net profit — the system guarantees this at all times"
- As-of-date filter + multi-branch filter

**Available now:** Yes

---

### Step 9 — Branch P&L

Navigate to **Finance → Branch P&L** (`/finance/branch-profit-loss`)

**What to show:**
- Side-by-side revenue and expense comparison across branches
- For the demo there is one branch (Head Office) — explain this scales to any number of branches

**Available now:** Yes

---

### Step 10 — Receivables Aging

Navigate to **Reports → Sales → Receivables** (`/reports/sales/receivables`)

**What to show:**
- Customer-wise outstanding amounts aged by 0–30, 31–60, 61–90, 90+ days
- Demo data has cash sales so receivables are minimal — explain live data will populate this as credit sales are posted

**Available now:** Yes

---

### Step 11 — Supplier Payables Aging

Navigate to **Reports → Purchases → Payables** (`/reports/purchases/payables`)

**What to show:**
- Supplier-wise outstanding payables aged by due date
- Opening AP of PKR 120,000 appears here against 3 demo suppliers
- Link to Purchase Orders and GRN: when a GRN is received and billed, it moves to AP automatically

**Available now:** Yes

---

### Step 12 — Purchase Orders and GRN (Supply Chain foundation)

Navigate to **Purchasing → Purchase Orders** (`/purchase-orders`)

**What to show:**
- Create a PO → select supplier, items, quantities, price
- Receive against PO (GRN) → inventory auto-updated, AP created in GL
- Supplier Bill → converts AP to payable, links to original PO

**Available now:** Yes (Purchase Orders + GRN + Supplier Bills + Payments)

**Coming soon in this plan:**
- Quotation Management
- Purchase Requisitions
- Purchase Returns / Debit Notes

---

### Step 13 — Bank Management foundation

Navigate to **Finance → Cash & Bank Accounts** (`/finance/cash-bank-accounts`)

**What to show:**
- Cash and bank accounts mapped to GL accounts
- Each payment (sale cash / supplier payment) posts here and to the GL
- Balances reconcile with Trial Balance

**Coming soon:** Bank Reconciliation (match bank statement to GL lines)

Click **Finance → Bank Reconciliation** → shows Coming Soon card with roadmap

---

### Step 14 — ERP Extensions — Coming Soon

**ERP Extensions** group in sidebar → click **Bank Reconciliation**

`/finance/bank-reconciliation`

**What to show:**
- "Coming Soon / Planned ERP Extension" heading
- Planned workflow bullets: import statement → auto-match → flag unmatched → lock period
- "Under development / customization" badge
- "Want this for your business?" — contact/request section

**Talking points:**
> "These modules are on our active roadmap. For enterprise clients we can schedule and prioritize specific modules as part of the rollout plan."

---

### Step 15 — Manufacturing — Customers separate from POS

Click **Manufacturing → Manufacturing Customers**

`/manufacturing/customers`

**What to show:**
- Clearly reads: "Manufacturing Customers are separate from your POS/Sales customer base"
- Explain: "Your current customers in POS and Sales Receivables are completely untouched when manufacturing is activated. Manufacturing maintains its own client list for production orders, job-work, and project costing."

---

### Step 16 — Manufacturing ERP roadmap

Walk through the Manufacturing sidebar group Coming Soon pages:

| Page | Path | Description |
|---|---|---|
| Bill of Materials | `/manufacturing/bom` | Define raw materials per finished product |
| Material Requisition | `/manufacturing/material-requisitions` | Issue raw materials to production |
| Production Orders | `/manufacturing/production-orders` | Plan and track production runs |
| Work in Process | `/manufacturing/wip` | Track value inside production |
| Finished Goods | `/manufacturing/finished-goods` | Receive completed output into stock |
| Scrap / Hard Waste | `/manufacturing/scrap` | Record and value production waste |
| Rejections | `/manufacturing/rejections` | QC rejection capture and disposition |
| Production Consumption | `/manufacturing/consumption` | Actual vs BOM variance |
| Production Reports | `/manufacturing/reports` | Output, yield, WIP, variance analysis |

**Talking points:**
> "Each module here is a planned ERP extension. The roadmap is customizable — we build what your manufacturing workflow needs, in the order that gives you the most value earliest."

> "This is a manufacturing ERP layer sitting on top of a live, operational POS and supply chain system — not a standalone manufacturing app."

---

## Honest capability wording

Use these phrases:

| Say | Don't say |
|---|---|
| Available now | Fully built / production-ready for all cases |
| Coming soon / Planned ERP extension | Live BOM production / completed WIP |
| Customizable manufacturing workflow | FBR-certified / official compliance guarantee |
| Under development | Done and tested |
| On our active roadmap | Guaranteed delivery date |

---

## Common client questions

**Q: Can we import our existing chart of accounts?**
A: Yes — we import from Excel/CSV on setup. The system uses a standard 4-level COA that we map to your existing codes.

**Q: Does this replace our standalone accounting software?**
A: For most SMEs, yes — double-entry GL, trial balance, P&L, and balance sheet are live. We do not yet have statutory tax filing integration (e.g. FBR IRIS) — that is on the roadmap.

**Q: How do manufacturing customers stay separate from POS customers?**
A: They are stored in a separate module with no shared keys. Your AR, sales ledger, and POS customer list remain unchanged when manufacturing is activated.

**Q: What happens if we need a module that is "Coming Soon"?**
A: We prioritize and schedule it as a customization sprint. Enterprise and Finance ERP clients get direct input on the roadmap.

**Q: Is the data shown live or dummy?**
A: This is a sandboxed demo tenant with illustrative data. Your production tenant starts blank (or with your imported data).

---

## Post-demo follow-up checklist

```
[ ] Send client the demo tenant credentials for self-exploration
[ ] Share Bingoo Finance ERP plan page: bingoopos.com/features (Finance ERP section)
[ ] Confirm which manufacturing modules they need first
[ ] Confirm number of branches (affects multi-branch filter demo value)
[ ] Confirm if they need Purchase Requisitions prioritized
[ ] Schedule provision of their trial tenant
```
