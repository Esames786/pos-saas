# Purchase and returns — round 2 (19 Oct 2026, evening)

Owner tried the new GRN screen on `pharmacydemo` and asked for the next round. Before / after view for the
owner: https://claude.ai/code/artifact/a166e2af-0c15-4730-a926-6fd371cd5f5d (Claude Doc). This page lists what he
asked, what the system does today (read from the code and the system map), what other software does, and
the plan in order. Rules from [owner product rules]: no new screens (the same page adds and edits), plain
words, pictures, the business decides what is shown, Master switch per tenant, live tenants OFF until he
switches them on.

## 1. What the owner asked (his words, then in plain English)

1. *"purchase ki screen k tamam modules k tab links"* — on the PO and GRN pages, links (tabs) to every
   purchasing page, so a person can jump between them.
2. *"new supplier foran say add"* — add a new supplier from the PO / GRN page itself.
3. *"supplier ka name search and type … easy"* — the supplier box: type to search.
4. *"batch … system mai already batch … attach … ya run time pe new batch"* — do not make people type the
   batch every time: pick a batch the system already knows (its expiry comes with it), or make a new batch on
   the spot.
5. *"reloading kum … ajax base"* — fewer page reloads; save without leaving the page.
6. *"box packet unit mai lere hain to return q qty wise"* — goods bought in boxes / strips / tablets must be
   returned the same way, not as a bare number.
7. *"product level discount fixed ya % … line discount … overall bill discount fixed or %"* — discounts:
   saved on the product (or supplier), on a line, and on the whole bill, each as Rs or %.
8. *"order return invoice, non invoice, hybrid"* — a return can be against a bill, without a bill, or both
   (start from a bill and add something extra). Same for customer (sales) returns.
9. *"restaurant, pharmacy, bakery … kg decimal … jis unit mai li ho us mai wapsi"* — every business: a return
   is in the unit it was sold or bought in, kg items with decimals, and nothing breaks for any tenant.
10. *"unit kg ya gram … restaurant POS alag tarhan handle karta tha … baqi tenants pe need hai?"* — is the
    kg / gram handling only for restaurants, and does the new product screen cover it?
11. *Pharmacy POS:* same receipt printer layout, bill preview, post-paid and KOT as today?

## 2. Fixed today (live with this round)

| What he saw | Why | Fix |
| --- | --- | --- |
| "Price per" switched to Tablet kept 892 → "Cost per Tablet Rs 892", Rs 35 lakh | switching the pack did not convert the price | 892 / Box becomes 4.46 / Tablet (and a whole free quantity too); amount stays the same |
| Expiry "0928" not understood | only "09/28" was read | "0928", "09-28", "9/28", "09/2028" all become 09/28 — on the page and on the server |
| "Write the batch number" stayed after typing it | the message only went on the next try | it goes as soon as the person types |

## 3. What the system has today (code + system map)

| Area | Today | Gap |
| --- | --- | --- |
| Purchasing pages | 6 pages (Suppliers, Purchase Orders, GRN, Purchase Bills, Supplier Payments, Purchase Returns), reached from the side menu | no tabs between them |
| Supplier on PO / GRN | plain drop-down, every supplier | no search by typing, no "new supplier" on the page; a new supplier needs a code, a name and a status |
| Batches | `inventory_batches` per branch + product (batch no., expiry, cost); stock is kept **per batch** (`stock_balances.inventory_batch_id`) | GRN makes a person type batch + expiry each time; there is no "pick a batch" list |
| Saving PO / GRN | the whole page posts and reloads; on an error the page comes back | — |
| Purchase discounts | PO / GRN / bill / return lines have a **Rs** discount; the bill has a **Rs** discount. The bill ignores a GRN line's discount (the new screen folds it into the cost) | no %, no bill discount on the GRN, nothing saved on the supplier or product, no scheme (10 + 1) memory |
| Sales discounts | bill discount Rs or % (`sales_orders.discount_type`), line discount, **Promotions** on products / categories, manager approval per branch | already there — use the same words on purchase |
| Purchase return | **with a GRN:** only that GRN's lines, in tablets. **Without a GRN:** add lines by hand and type the cost (starts at 0). No mix. | not in packs; no batch choice; **stock leaves the oldest-expiry batch, not the batch being returned** (`PurchaseReturnService::post` → `postOutFefo`; the batch is "informational"); cost starts at 0 without a GRN |
| Sales return | only against a sale; kg items take decimals (step 0.001); a medicine goes back into the batch it was sold from; a recipe dish never goes back to stock | no return without a bill; no mix; **always back to stock** — no "throw away" for stale bakery / perishable goods; not in packs |
| kg / gram on the counter | the same counter for every business: a product whose unit is kg / litre / metre opens "Enter Quantity" — type the quantity **or a rupee amount** (amount ÷ price per kg) | works for every business, not only restaurants. Missing: typing grams for a kg item ("250 g") |
| kg on the product screen | the new screen asks "Sold and counted in" for every business; the Mart has a "Loose item (by weight)" card that picks kg itself | fine; the GRN entry area already allows part quantities only on kg / litre / metre items |

## 4. What other software does

Web research, 19 Oct 2026, from help pages. Pharmacy: Marg, BUSY, GoFrugal, Logic ERP, Tally. Shops:
Square, Shopify POS, Lightspeed, Odoo, Loyverse, Toast. General: Business Central, SAP Fiori, NN/g. The
Pakistani pharmacy products (HysabOne, Oscar, Abuzar) publish no screen help, so they are not counted.

### Purchase entry (pharmacy)
- **Batch: pick or make, never type everything.**
  - Tally: "select an active batch or create a new one"; expired batches are not offered.
  - BUSY opens an "Item batch details" box after the quantity.
  - Marg lists batches nearest-expiry first. A batch number cannot be changed after it is made; its expiry
    can be.
- **Packs.**
  - GoFrugal buys in strips and sells in tablets ("units in one pack" per item).
  - Marg shows stock as "9:5" (9 strips 5 tablets).
  - Logic ERP has box → strip → tablet.
  - Free quantity is its own column (Marg).
- **Scheme.**
  - "10 + 1": typing 10 adds 1 free by itself.
  - A trade discount % can be turned into a scheme (10% = 9 + 1).
  - "Half scheme" exists too (Logic ERP, Marg).
  - Marg can keep the deal on the batch.
- **Discount order is a setting.** Marg switches "bill discount after tax"; GoFrugal and Logic ERP keep a
  sum formula per supplier.
- **A sample distributor bill** (one user-uploaded Karachi invoice, 2025): gross − discount = net, then
  + 1% sales tax and + 0.1% withholding.
- **Pakistan rules.**
  - Trade price = retail price less the 15% retailer margin (DRAP pricing policy).
  - Sales tax on registered medicines is 1% final.
  - Advance tax 236G / 236H rates change every Finance Act, so they must be rows the business sets, not
    numbers in code.
- **Defaults fill themselves.**
  - Logic ERP keeps the trade discount, cash discount and tax per supplier.
  - Marg fills "last deal" (rate, discount, free) from the last bill of this supplier and item. Alt+L shows
    the last deal; F6 shows the old rate.
- **New supplier without leaving.** Tally Alt+C; Marg F2 on the supplier list (and F2 on the item list for a
  new item).

### Purchase return
- **Marg debit note.**
  - Batch: "Manual / Select purchase batch / Open history".
  - Bills: "Multiple / Separate / Bill-by-bill".
  - ↓ fills the last bill; Space ticks its lines.
  - The reason is required.
- **Expiry sweep.**
  - Marg "Expired before MM/YY" loads every expired item at once.
  - Logic ERP "Retrieve expired / damaged stock", with "this supplier's lots only".
- **Returning in strips:** GoFrugal has "Expiry return by strip".
- **Price with no bill:** no vendor fixes a rule. ERPNext uses the original purchase rate.

### Sales (customer) return
- **Finding the sale:**
  - Square: receipt number, card or customer;
  - Odoo: receipt number, date or customer;
  - Marg: "Select from sold" offers only the batches sold to that customer.
- **Without a receipt.**
  - Square "unlinked refund": a reason is required, the permission is off by default, and there are
    limits.
  - Shopify "unverified return": the customer must be known, the refund is a gift card only, capped at
    today's price, with its own permission.
  - Lightspeed must have it switched on.
- **Back on the shelf or not.**
  - Per line: Shopify "Restock" toggle; Square "items to restock".
  - Per reason: Lightspeed restaurant void reasons say "Reverse stock yes/no".
  - Lightspeed says made-to-order food that cannot be resold is wastage.
  - Loyverse always restocks.
  - No product sets a default per item. Ours would (from the business and shelf life) — ahead of the market.
- **Weight / loose.**
  - Loyverse cannot return part of a weighed item.
  - Shopify cannot sell by weight.
  - GoFrugal works the refund from the strips sold.
  - Our counter already takes decimals — ahead of these.

### Line-entry screens
- **Save state.**
  - Business Central: no Save button, with a "Saving / Saved / Not saved" sign.
  - SAP: drafts save themselves; one person owns a draft.
  - NN/g: autosave + "Saved" feedback; the button that throws work away says "Discard".
- **Leave-page warning:** MDN says warn on leaving only while something is unsaved.
- **Linked papers:** Odoo PO → Receipt → Bill (a bill can be held to the received quantity); Marg Ctrl+P
  loads pending orders into the bill.

## 5. The plan

Same pages, nothing new in the menu. All behind Master switches: "New purchase screens" (already there) and a
new "New return screens". Live tenants stay on today's pages until the owner switches them on.

### 5.1 Purchasing tabs (all six pages)
A row of tabs at the top of each purchasing page: Suppliers · Purchase Orders · GRN · Bills · Payments ·
Returns. A role sees only the tabs it may open. A small count shows what waits, for example "Orders 3"
(approved, not received).

### 5.2 Supplier box
- Type to search by name, phone or code.
- "+ New supplier" (or F2) opens a small box on the same page: name and phone. The code is made by itself.
  Save, and the new supplier is chosen. No reload.
- Once a supplier is picked, show their balance and terms beside the box.

### 5.3 Batch box (GRN, purchase return, counter)
- In the batch field, ↓ opens this item's batches in this branch.
  - Nearest expiry is listed first; expired batches are not offered on a GRN.
  - Each row: batch · expiry · stock left (in packs) · last cost.
- **Pick a batch** and its expiry fills itself and locks. A batch keeps one expiry, as in Marg and Tally;
  the cursor moves to the quantity.
- **Type a new batch number** and it is made on the spot; its expiry (MM/YY) is asked right there.
- A 2D code scan still fills both.
- **Batches made beforehand** (owner: "system mai already batch banane ka option"):
  - the existing Inventory → Batches page (today a list only) gets "+ New batch" (item, branch, batch no.,
    expiry) and "change expiry";
  - the product screen shows that item's batches;
  - every batch made there appears in the batch box. No new page.

### 5.4 Save without reload
- Post saves in the background and stays on the page.
- A green line says "GRN-… saved", with **Print**, **Open** and **New** buttons.
- An error shows on its own line in the grid. Nothing typed is lost.
- **A small sign** says "Not saved yet · 3 lines" / "Saved". The browser warns on leaving **only** while
  something is unsaved.
- Lines being typed are kept in the browser, so closing the tab by mistake does not lose them. The button
  that throws them away says "Discard".
- **A PO opens into its GRN:** the GRN's supplier box shows "3 orders waiting" and loads one with a click
  (Receive is already there).

### 5.5 Discounts on purchase
- **On the product** (owner: "product level discount fixed ya %"): the product screen gets a "Price & discount"
  card.
  - **When buying:** disc % or Rs, and a scheme (10 + 1).
  - **When selling:** disc % or Rs.
  - These fill the PO / GRN line and the counter line by themselves.
- **On a line:** disc % or Rs (one box with a %/Rs switch), plus **scheme**: "10 + 1" fills the free
  quantity.
- **At the counter:** today only a whole-bill discount (Rs or %) and Promotions. Add a line discount (% or Rs),
  from the product's selling discount or typed. The branch's discount-approval rule stays.
- **On the bill (GRN):** disc % or Rs, plus extra charges (already there).
- **Tax rows:** sales tax, further tax, advance tax (236H) are rows the business sets once (name + %, before
  or after discount). The rates change each Finance Act, so they are never numbers in code.
- **What fills itself, first match wins:**
  1. the last deal with this supplier for this item (rate, disc %, free) — shown as "Last: 892 / Box,
     −5%, 10+1 · 11 Oct";
  2. the item's default;
  3. the supplier's default disc %.

  Everything can be changed on the line.
- **Order of the sum:**
  - quantity × price → line disc → scheme (free goods) → bill disc → tax → charges;
  - a supplier can keep "bill discount after tax" (as Marg allows);
  - the order is printed under the total so nobody guesses.
- **Money:**
  - a trade discount lowers the cost of the stock, the same way extra charges raise it (split by value);
  - free goods lower the cost per tablet (already live);
  - the supplier's bill is the net amount;
  - a cash discount for paying early is a separate thing (later).

### 5.6 Purchase return — one page, three ways
- **From a GRN or bill:** its lines come up showing what can still go back, **in Box / Strip / Tablet**.
- **Without a bill:** the same entry area as the GRN. Search an item, pick its batch ("from purchase" —
  only batches bought from this supplier — or any), and type the quantity in packs.
- **Mixed:** start from a GRN, then add more items through the entry area.
- **The price, shown with where it came from:**
  1. the bill's rate, when a bill is linked;
  2. else the batch's cost;
  3. else the last purchase rate from this supplier.

  Changing it needs a reason.
- **A reason on each line:** expired / damaged / wrong item / other.
- **"Expired before MM/YY" button:** loads every batch of this supplier that has expired, or will by that
  month. Tick them and they come in as lines.
- **Stock leaves the batch chosen.** This needs a stock-engine method that takes from one named batch. The
  oldest-first (FEFO) path stays for everything else.
- The credit note prints in packs.

### 5.7 Sales (customer) return — one page, three ways
- **With the bill:** scan the receipt, or search by number, phone or name.
- **Without a bill** (as Square and Shopify do it):
  - search the item; the price is today's price, and never more than it;
  - a reason is required;
  - anyone who can open Sales Returns can do it — owner, 19 Oct: *"jis ko screen ka access hoga wo saray kaam
    kar sakta hai"*; no extra permission inside a screen;
  - the refund is cash, card or store credit.
- **Mixed / exchange:** return lines plus new items in one go at the counter (later step).
- **Each line asks "Back on the shelf?"** (Yes / No — throw away). The default comes from the business:

  | Business | Default |
  | --- | --- |
  | pharmacy, sealed pack | Yes |
  | pharmacy, opened or expired | No |
  | bakery / perishable (the product has a shelf life), restaurant | No |
  | mart | Yes |

  "No" writes a wastage line instead of putting stock back.
- **Same unit as sold:**
  - sold 1 Strip → the return shows Strip / Tablet boxes, never more than was sold;
  - kg items keep decimals;
  - a recipe dish never goes back to stock (as today) — refund only.

### 5.8 kg / gram
- No change for restaurants (works today, every business).
- Add: in "Enter Quantity", typing "250g" means 0.250 kg.
- Add: on the new product screen, a kg item says under the price "price for 1 kg".

## 6. Pharmacy counter — answers

- **Printer layout:** yes, the same. Receipt layout settings (header, footer, logo, font), the same print
  agent, auto-print per counter, and the same 80 mm / A4 documents. The bill only gains a line under each
  medicine: "1 Strip + 3 Tab · B:PN2410-07 EXP:09/28".
- **Bill preview:** yes. The same Bill / Preview button prints a proforma at the counter that asks. The final
  receipt prints on payment.
- **Post-paid:** the counter already has Hold and Held orders (park a customer, serve the next one, recall
  later). A pharmacy normally takes payment at once. Customer credit (udhaar / khata) is not in the POS
  today. Payment types are cash, card, bank transfer and cheque. A khata would be a separate step if wanted.
- **KOT:** works exactly as today. The counter prints kitchen tickets only where the plan has a kitchen or an
  active printer takes KOTs. The pharmacy demo has neither, so no KOT button. If a business adds a KOT
  printer, it starts by itself.

## 7. Build order (P1 medical-store demo first)

| Step | Work | Needs |
| --- | --- | --- |
| 1 | Purchasing tabs + supplier search + "+ New supplier" + batch box + save without reload (PO, GRN) | — |
| 2 | Purchase return: packs, from GRN / without / mixed, "Expiring" list, **stock from the chosen batch** | a new stock-engine method + tests on every return path |
| 3 | Purchase discounts: line %/Rs, scheme, bill %/Rs, supplier default, remembered per supplier + item | owner's OK on the money rule (trade discount lowers stock cost) |
| 4 | Medical counter (1c) | — |
| 5 | Sales return: with / without / mixed, "Back on the shelf?", packs | owner's OK on who may return without a bill |
| 6 | "250g" in Enter Quantity; "price for 1 kg" hint | — |

Each step: tests through the real pages, the old pages unchanged for live tenants, deploy with the
permission / plan snapshots, the system map rebuilt.

## 8. Owner decisions

1. ~~Who may return without a bill~~ — **answered 19 Oct: whoever can open the screen does all of its work.**
   Still open: is store credit wanted as a refund choice?
2. Sales return default "Back on the shelf?" per business (table in 5.7) — OK?
3. Trade discount lowers the stock cost (standard) — OK?
4. Tax rows on purchase (1% sales tax, further tax, 236H) — wanted in the first release, or later?
5. Customer khata (udhaar) on the counter — wanted now, later, or not?
6. "Internet connected" purchasing (from 19 Oct) — still open.
