# Refund ka tareeqa bare haroof me aaye to drawer ka hisab chup-chaap toot jata hai

**Tareekh:** 2026-09-06 · **Tenant par pesh aaya:** Tawakal + The Kashif Foods
**Halat:** research mukammal, code likhna baqi
**Darja:** **LATENT** — UI se ye ho hi nahi sakta (section 4). Aaj ye MERE apne script se hua.

---

## 1. Kya hua

Owner ne dekha: The Kashif Foods ke saare orders cancel/return karne ke baad bhi shift **expected
5,580.00** maang rahi thi.

```
shift #2 (The Kashif Foods)
   opening_cash              0.00
   total_cash            5,580.00
   total_refunds         5,580.00   ← return darj hua
   total_cash_refunds        0.00   ← magar CASH refund ke tor par nahi
   expected_cash         5,580.00   ← is liye ghata hi nahi
```

`expected_cash = opening + total_cash − total_cash_refunds` = `0 + 5580 − 0`. Yani daraz se 5,580
nikal chuke the, magar system unhein abhi bhi daraz me maang raha tha.

## 2. Wajah — MySQL narm hai, PHP sakht

`sales_returns.refund_method` ek **ENUM** hai:

```sql
enum('cash','bank_transfer','card','other')
```

Maine service ko `"CASH"` bheja (payment method ka `code`, bare haroof me).

- **MySQL ENUM** case ki parwah nahi karta → usne chup-chaap `cash` store kar diya
  (hex se tasdeeq: `63617368` = `cash`)
- **PHP** ne wohi **kaccha** `"CASH"` compare kiya:

```php
// SalesReturnService::updateShiftForReturn()
$cashRefund = $refundMethod === 'cash' ? $grandTotal : 0;   // "CASH" === 'cash'  →  FALSE
```

Nateeja:
```php
$shift->increment('total_refunds', $grandTotal);   // ye chala
if ($cashRefund > 0) {                             // ye nahi
    $shift->increment('total_cash_refunds', ...);
    $shift->decrement('expected_cash', ...);
}
```

Return ka record **bilkul theek** bana (`refund_method = cash`), GL bhi theek (trial balance 0.00) —
sirf **shift ka daraz** anjaan raha. Na error, na warning.

## 3. Yehi cheez teen aur khaanon par lagti hai

Wohi shakl `card`, `bank_transfer` aur `other` par bhi hai — `total_card_refunds`,
`total_bank_refunds`, `total_other_refunds`. Case galat ho to teeno khamosh reh jate hain, aur
`total_refunds` phir bhi barh jata hai — yani **jama aur tafseel ka farq** paida ho jata hai, jo
shift report par sab se pehle nazar aata hai.

## 4. Aaj ye UI se NAHI ho sakta — aur ye jaan-na zaroori hai

`processReturn()` ka poore code me **ek hi caller** hai:

```
app/Http/Controllers/Tenant/SalesReturnController.php:244
```

aur uski validation pehle hi rok deti hai:

```php
'refund_method' => ['required', Rule::in(['cash', 'bank_transfer', 'card', 'other'])],
```

`Rule::in` case-sensitive hai. Yani screen se `"CASH"` bhejne par request pehle hi rad ho jati hai.

**To ye aaj koi live nuqsan nahi kar raha.** Khatra un raaston ka hai jo controller se nahi guzarte:
script, artisan command, Edge sync, ya kal koi API. Aaj wohi hua — mera apna script.

## 5. Kya theek karna hai

`updateShiftForReturn()` apne aap ko us par bharosa na kare jo caller ne bheja. Compare se pehle
`strtolower(trim(...))` — bilkul waise hi jaise DB khud karta hai. Ek jagah, chaaron khaanon ke liye.

Behtar: `processReturn()` shuru me hi qeemat ko normalise kar de, taake **store** aur **shift** dono
ek hi qeemat dekhen. Abhi dono alag jagah se aati hain — store MySQL ke reham par, shift PHP ke.

## 6. Guard

- `processReturn` ko `"CASH"` de kar bulao → `total_cash_refunds` barhe aur `expected_cash` ghate.
  **Purane code par ye test RED hona chahiye** — yehi is bug ka nishan hai.
- Wohi `"Cash"`, `"cash "` (space ke saath) ke liye.
- `card` / `bank_transfer` par bhi ek ek.
- Aur ek: `refund_method` DB me hamesha lowercase enum value hi ho.

## 7. Aaj ka data theek kar diya

Shift #2 par haath se durusti ki gayi — wohi hisab jo service khud karti agar usay `cash` mila hota:

```
total_cash_refunds  0.00      →  5,580.00
expected_cash       5,580.00  →      0.00
```

Baqi kuch nahi chhua: `total_refunds`, GL, returns ke records sab pehle se theek the
(trial balance 0.00, GL tareekh business_date se milti hui).

Backup pehle liya: `/root/backup_tawakalkashif_20260906_122532.sql.gz`
