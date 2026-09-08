# SUPPLIER-FINANCE-DIRECT-1 — Supplier ko FINANCE mein seedha shamil karna

**Branch:** `feat/supplier-finance-direct-v1` (worktree `pos-saas-hideamounts`)
**Base:** `origin/feat/14d-2-plan-upgrade-requests` = `e44eb01`
**Tareekh:** 2026-09-08 · **CAPABILITY kaam — koi prod mutation nahi**

---

## 1. Pehle jo mila — kya pehle se mojood hai

Owner ki maang thi ke supplier payment sirf Product Purchasing ke andar na ho. Tehqeeq
par nikla ke **bunyaad pehle se mojood hai**, aur usay dobara banana mana bhi hai
(requirement C). Jo authority reuse ho rahi hai:

| cheez | kahan | kya karti hai |
|---|---|---|
| `SupplierPayableService::recordPayment()` | Finance | payment row + subledger + cash/bank + GL |
| `PurchasingService::postPayment()` | Purchasing | supplier subledger (credit) + bill balance |
| `PurchasingService::postSupplierLedger()` | Purchasing | **subledger ka ek hi choke point** — supplier row `lockForUpdate()` (BUG-042) |
| `JournalPostingService::postSupplierPayment()` | Finance | `Dr 2100 AP / Cr cash-bank` |
| `JournalService::post()` / `reverse()` | Finance | GL authority; `(source_type, source_id)` par idempotent; unbalanced par throw |
| `ManualJournalController` | Finance | General Journal — manual balanced entry |

**Aur ek baat jo owner ko maloom nahi thi:** `purchase_bill_id` **pehle se `nullable` hai**
— controller ki validation aur `postPayment()` dono mein. Yani bill ke baghair supplier ko
paisa dena **aaj bhi mumkin hai**. Kami UI aur hifazat mein thi, engine mein nahi.

---

## 2. Jo kami thi — saat khaali jagahein

### G1 · Atomicity toot-ti thi (requirement F)
`recordPayment()` mein GL journal transaction ke **BAHAR** post hota hai:

```php
$payment = DB::transaction(fn () => /* row + subledger + cash/bank */);
$this->journalPosting->postSupplierPayment($payment, $userId);   // <-- BAHAR
```

Agar GL fail ho to payment, subledger aur cash/bank **pehle se commit** — bilkul wohi
soorat jo requirement F mein mana ki gayi hai ("supplier ledger moved but GL did not").

### G2 · GL kabhi post hi nahi hota tha (requirement B + K)
`cash_bank_account_id` nullable hai, aur `postSupplierPayment()` uske baghair `null`
laut-ta hai. Nateeja: **subledger mein AP ghata, GL mein AP jaisa ka waisa** → AP control
aur subledger mein farq. Requirement K yehi farq mana karti hai.

### G3 · General Journal mein supplier ka koi khaana nahi (requirement D)
Manual journal `2100 Accounts Payable` par credit/debit kar sakta hai **bina batae ke kis
supplier ka**. Yani AP control hil jata hai aur subledger ko pata bhi nahi chalta.

### G4 · Supplier Ledger adhoori (requirement G)
Description ka column nahi, aur **Record Payment ka button nahi**.

### G5 · Ledger se payment ka raasta nahi (requirement A)
Payment sirf `/supplier-payments/create` se, supplier khud chunna parta.

### G6 · Advance ka koi nizam nahi (requirement I)
COA mein `2300 Customer Advances` hai magar **supplier advance ka koi account ya logic
nahi** — poore `app/` mein ek bhi jagah nahi. Phir bhi `postSupplierLedger()` credit par
`balance - amount` karta hai **bina farsh ke**, to overpayment chupke se manfi balance
bana deta hai — yani ek aisi accounting jo system mein mojood hi nahi.

**`SUPPLIER_ADVANCE_SUPPORTED = no`.** Requirement I ke mutabiq: fail closed.

### G7 · Permissions (requirement L)
Naye route par pehle Owner, baqi roles ko sirf additive grant.

---

## 3. Do asli takrao — aur kya faisla liya

### Takrao 1 · GL jaan-boojh kar fail-soft hai, requirement F fail-hard maangti hai

`JournalPostingService` ke docblock mein saaf likha hai:

> Every method is idempotent and **SAFE: it returns null and reports the problem rather
> than throwing**, so a missing account or unmapped event can never break the operational
> flow that triggered it.

Aur `PurchasingService::postBill()` par:

> **BUG-044 FIX** — operational-only bill posting. GL journal is **intentionally excluded**
> so a GL failure never rolls back the operational bill creation.

Ye purana, soch kar liya gaya faisla hai — aur requirement F ke bilkul ulta.

**Faisla:** dono ko un ki apni jagah par rehne diya.
- **Payment ka raasta** (jo requirement F chalati hai) ab **fail-hard** hai: GL transaction
  ke ANDAR, aur `null` laut-ne par throw → sab kuch roll back.
- **Purchase bill / purchase return ka raasta ACHHOOT** — BUG-044 ka bartaao waisa hi,
  kyunke requirement kehti hai `PURCHASE_PATH_UNCHANGED` aur `PURCHASE_RETURN_UNCHANGED`.

Yani fail-soft se fail-hard ki tabdeeli **sirf** supplier-payment aur naye
journal-adjustment par hai, poore GL par nahi.

### Takrao 2 · Advance ka guard purana bartaao badalta hai

Aaj overpayment manfi balance bana deta hai. Guard lagane se wo mana ho jayega.

**Faisla:** guard **sirf** direct-payment aur journal-adjustment par. `postSupplierLedger()`
par **nahi** — kyunke purchase return bhi credit karta hai, aur wahan guard lagana
`PURCHASE_RETURN_UNCHANGED` torh deta.

---

## 4. Kya banaya — aur kis usool par

### Ek hi authority (requirement C)
Koi doosra supplier accounting engine nahi. Subledger ki har satar — payment,
journal adjustment, reversal — usi `PurchasingService::postSupplierLedger()` se guzarti
hai, jo supplier row par `lockForUpdate()` karta hai. Isi liye running balance concurrency
mein bhi theek rehta hai (BUG-042 ka faida muft mila).

### AP ki pehchan
AP account code `2100` hai. `accounts` table mein `parent_id` mojood hai, is liye tenant
kal `2100` ke neeche sub-account bana sakta hai. Guard sirf `2100` par nahi, uski **poori
nasl** par lagta hai — warna `2101` bana kar guard se bacha ja sakta tha.

### `entry_type` — migration ki zaroorat nahi
`supplier_ledgers.entry_type` **`string(50)` hai, enum nahi**. Is liye
`journal_adjustment` aur `journal_reversal` bina schema tabdeeli daal diye.

### Manual AP ka aaina (requirement D)
Manual journal ki jo satar AP par lagti hai, uske saath `counterparty_type = supplier` aur
asli `supplier_id` lazmi. Phir wohi harkat subledger mein utar-ti hai:

| journal line | AP par asar | subledger |
|---|---|---|
| `Cr 2100` | payable barha | supplier **debit** |
| `Dr 2100` | payable ghata | supplier **credit** |

Aina bilkul ulta hai kyunke AP credit-normal hai aur subledger ka `current_balance`
"hum supplier ka kitna dete hain" ginta hai.

### Purane journals nahi tootay
Shart sirf **manual/interactive** raaste par hai (`source_type = manual_journal`).
System ke banaye journals — purchase bill, purchase return, supplier opening balance, sale,
catering — sab pehle jaise. `postSupplierOpeningBalance()` (`Dr 3300 Equity / Cr 2100`) ko
chhooa bhi nahi: requirement H.

---

## 5. Do jagah ka phanda — likh kar rakh raha hoon

Manual journal ke form mein line ka markup **DO** jagah hai:
1. render hui rows ka `@foreach` (`lines[{{ $i }}]`)
2. JS ke liye template row (`lines[__I__]`)

Supplier ka picker **dono** mein dalna zaroori hai — warna pehli do rows par kaam karega
aur "Add line" se bani row par gayab hoga. **Aaj hi subah** yehi ghalti
`toForm()`/`toQuery()` par hui thi (QUICK-REPORT-BRANCH-SCOPE-1). Guard dono ko dekhta hai.

---

## 6. Jo NAHI kiya

- **Kashif Kitchen ke rokke hue opening balances post NAHI kiye** aur andaza bhi nahi
  lagaya (Σ6.49M cr / Σ4.49M dr) — owner ki tasdeeq ka intezar. Ye kaam sirf **salahiyat**
  banata hai.
- Koi prod data nahi chhera.
- Supplier advance ki accounting **ijaad nahi ki** — fail closed, aur report mein
  `SUPPLIER_ADVANCE_SUPPORTED=no`.
- Purchase bill / return / opening balance ka GL bartaao nahi badla.
- Deploy nahi kiya.
