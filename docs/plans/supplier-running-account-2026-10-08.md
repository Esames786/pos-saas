# SUPPLIER-RUNNING-ACCOUNT-1 — Supplier ko general payment, balance se zyada bhi

Date: 2026-10-08 · Source: parallel session ki guide (Claude Docs "SUPPLIER-RUNNING-ACCOUNT-1"), owner ke usool 8 Oct.
Ye MD us guide ko code ke saath milata hai, aur jahan code ne kuch aur dikhaya wahan farq likhta hai.

## Owner ke usool (kashifkitchen, 8 Oct)
- Payment general hoti hai, bill-wise nahi. Supplier Payments wala koi bhi user de sakta hai.
- Payment baqi se zyada ho sakti hai (50,000 bina bill; 1,000 ke bill par 1,500).
- **Alag advance account NAHI.** Dr 2100 Accounts Payable / Cr cash-bank, jaisa aaj.

## Aaj (prod, read-only 8 Oct)
- `SupplierPayableService::assertNoSupplierAdvance()` balance 0 se neeche jaane par rok deta hai (payment + manual journal AP).
- `PurchasingService::postPayment()` bill ko sirf `purchase_bill_id` par chhoota hai; bill ke saath zyada payment
  `amount_paid` ko bill se bhi upar likh deti hai.
- kashifkitchen: 10 bills (998,880), 5 payments (1 general 117,600 + 4 bill-wise 27,180), returns 0. Sirf FAISAL BEEF
  COUNTER (KHATRI) me farq: ledger 831,600 / bills ka `balance_due` 949,200 (−117,600).

## Kya banega
1. **Setting "Supplier running account"** — nayi table `purchasing_settings` (ek row, ManufacturingPostingSetting/
   CateringSetting jaisa). Default **OFF**: har doosre tenant ka bartaao ek line bhi nahi badalta — rok, bill update, sab.
2. **ON par rok nahi** — payment aur manual journal AP dono. Form Save se pehle batata hai:
   "After this payment, <supplier> will be X in advance".
3. **GL wahi**: Dr 2100 / Cr cash-bank. Koi naya account nahi. Manfi balance har jagah **"Advance X (Dr)"**, nanga minus nahi
   (supplier list, supplier page, ledger, payment form ka dropdown).
4. **Credit bills ko chukata hai, purane pehle** — nayi table `supplier_credit_allocations`
   (supplier_id, source_type payment|return, source_id, purchase_bill_id, amount):
   - Payment: chuna hua bill pehle (sirf us ke baqi tak), phir supplier ke sab se purane khule bills (bill_date, id).
     Baqi = advance (allocation nahi bani credit).
   - **Purchase return bhi** (guide me nahi tha): return supplier ki credit hai; is ke bina return wale supplier ke bills
     phir ledger se zyada dikhte. Us ke GRN ka bill pehle, phir purane.
   - Naya bill post ho to supplier ki bachi credit (purani pehle) pehle us par lagti hai.
   - Bill ke `amount_paid` = us par lagi allocations; `balance_due` = total − paid; status paid/partial/posted.
5. **Reports**: aging `balance_due` parhta hai — ab sahi. Aging safhe par "Suppliers in advance" ki chhoti list.
   Balance Sheet par 2100 net (owner ne maana).

## Guide se farq — kyun
- **ON karna + purana data darj karna EK command** (`finance:supplier-running-account {tenant} --enable`, dry-run default,
  `--yes` par likhta hai). Wajah: aaj ki bill-wise payments ki koi allocation row nahi; agar sirf setting ON hoti to engine
  unhein "bachi credit" samajh kar naye bill par **dobara** kharch kar deta. Command pehle purani bill-wise payments ko
  allocation me darj karta hai (bill ki had tak; zyada hissa advance), phir general payments/returns purane bills par,
  phir setting ON — ek transaction. Journals aur supplier ledger ko haath nahi.
- **Allocation bhi setting ke peeche** — OFF tenant ke bills bilkul pehle jaise.
- **Openings aur manual journal AP lines allocate nahi hote.** Opening/journal debit ka koi bill nahi; kashifkitchen par
  dono 0 (237 suppliers ki openings jaan-boojh kar post nahi). Jis supplier par ye hon, us ka `balance_due` jod ledger se
  in ke barabar mukhtalif rahega — command report me aise supplier alag dikhata hai.
- Toggle sirf command se (guide: no new route, no new permission).

## Kya NAHI badlega
- GL posting, supplier ledger, cash-bank, permissions, routes — ek line nahi.
- OFF tenant par: rok ka paighaam lafz ba lafz, bill update purane tareeqe se.

## Tests (asli raasta — controller / service, rebuilt query nahi)
- OFF: zyada payment ruki, kuch save nahi, paighaam wahi.
- ON: bina bill 50,000 → saved, "Advance 50,000.00 (Dr)", journal Dr 2100 / Cr cash; phir 30,000 ka bill → bill paid
  (allocation 30,000), advance 20,000.
- 1,000 ka bill, 1,500 us par → bill `amount_paid` 1,000 (1,500 nahi), advance 500; agla bill 500 kha leta.
- 1, 2, 3 Oct ke bills, 2.5 bills jitni general payment → pehle do paid, teesra partial; Σ`balance_due` = ledger.
- Return → us ka bill pehle chukta.
- Command: dry-run kuch nahi likhta; `--yes` ke baad FAISAL jaisa data ledger ke barabar; journals/ledger rows ki ginti
  aur jod same; purani bill-wise payment naye bill par dobara nahi lagti.
- Form: ON par advance warning; page ke scripts parse (`node --check`).
- Jaan-boojh kar tod kar sabit karna ke har test pakadta hai.

## Rollout (har qadam owner ki ijazat se)
1. Deploy (migration: 2 nayi tables, additive). 2. kashifkitchen par command dry-run → owner ko dikhana.
3. `--enable --yes`. tb_diff = 0 pehle/baad.
