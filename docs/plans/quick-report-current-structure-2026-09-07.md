# Quick Report — mojooda dhaancha (jaisa aaj hai)

**Tareekh:** 2026-09-07 · **Halat:** sirf documentation — code me kuch nahi badla
**Kyun:** khule bills shaamil karne se pehle ye likhna zaroori tha ke aaj kya kahan se
aata hai. Jori hui tabdeeli → `quick-report-include-open-bills-2026-09-07.md`

---

## 1. Ye cheez hai kya

POS ki screen par ek button (**Quick Report**) jo **poore tenant** ka ek din ka Sales
Report banata hai aur teen me se ek kaam karta hai: yahin thermal par chhapo, network
printer par bhejo, ya owner ko A4 PDF email karo.

Buniyadi baat: **ye apna koi hisab nahi karta.** Ye sirf ek chunne wali screen hai jo
Report Center ka wohi engine, wohi A4 document, wohi thermal template aur wohi ESC/POS
bytes dobara istemal karti hai. Isi liye output Report Center se **hu-ba-hu** milta hai.

Bana: QUICK-REPORT-SEND-1 (`2454d7c`, 27 Aug), plan
`docs/plans/pos-quick-report-send-2026-08-27.md`.

## 2. Kaun khol sakta hai

Ek hi permission: **`tenant.pos.quick-report-send`**

    private const PERMISSION = 'tenant.pos.quick-report-send';
    abort_unless(auth('tenant')->user()?->can(self::PERMISSION), 403);

Button bhi usi @can ke peeche hai, aur poori modal bhi.

⚠️ **Ye report jaan-boojh kar UNSCOPED hai** — `UserDataScope` bilkul nahi lagti. Yani jis
ke paas ye permission hai wo **poore tenant** ka, har branch, har terminal, har order type
ka data dekh leta hai, chahe uska apna kaam ek counter tak mehdood ho. Permission hi
poori hifazat hai, aur yehi is feature ka maqsad tha.

## 3. Modal me kya kya hai

**Business date** — ek din. Khali/ghalat ho to `TenantClock::currentBusinessDate()`.
**Save my selection** — chunaav apne user ke naam mehfooz ho jaye (section 7).

**Sections — 10, sab default ON:**

| Key | Modal par | Engine ka method |
|---|---|---|
| `overview` | Overview | `overview()` |
| `categories` | Categories | `byCategory()` |
| `items` | Items | `byItem($f, 'net', true)` — combos nikal kar |
| `category_items` | Items by Category | `byCategoryItems()` |
| `deals` | Deals | `byDeal()` |
| `waiters` | Waiters | `byWaiter()` |
| `order_types` | Order Types | `byOrderType()` |
| `order_type_combos` | Order-Type Combos | `orderTypeCombos()` |
| `cancellations` | Cancellations | `cancellations()` |
| `cash_bank` | Cash & Bank | `cashBank()` |

Report Center me ek gyarhwan section bhi hai — **`detailed`** — jo Quick Report me
**jaan-boojh kar nahi** hai (wo sirf CSV ke liye hai).

**Chaar sections apni andar wali chunaav-list bhi kholte hain:**

- **Categories** → poora category tree, sub-categories samet (Steaks, Pasta, Beef Boti
  Rolls, Midnight, Platters wagera). Sab khali = har category. Ek category chunne se uski
  **sub-categories aur items khud shaamil** ho jate hain.
- **Items** → `All items` ka toggle. On ho to alag items ka chunaav nazar-andaz.
- **Waiters** → sab khali = har waiter.
- **Order Types** → Dine In / Takeaway / Quick Sale / Delivery. Sab khali = sab.

**Network printer** — sirf "Send to network" ke liye.

**Teen buttons:** Print here · Send to network · Email to owner

## 4. Chunaav poori report ko tang karta hai — sirf us section ko nahi

Ye ehem baat hai aur modal khud bhi likhta hai:

> "Picking a category also includes its sub-categories & items; the whole report (items,
> waiters, order types, totals) then follows what you pick."

Yani category/item/waiter/order-type ke chunaav **filters** ban kar engine me jate hain,
aur **AND** ki tarah jurte hain — har section aur **NET SALES ka headline** bhi unhi ke
mutabiq banta hai. Ye "sirf ye section dikhao" nahi, "poori report is hisse ki dikhao" hai.

## 5. Filters kaise bante hain

    $filters = $this->engine->normalizeFilters([
        'date_from'  => $date,   'date_to' => $date,        // ek hi din
        'branch_ids' => Branch::where('status','active')->pluck('id'),   // POORA tenant
        'category_ids' => ...,
        'product_ids'  => $request->boolean('all_items') ? [] : ...,
        'waiter_ids'   => ...,
        'order_types'  => ...,
    ]);

Koi `terminal_id`, koi `cashier_id`, koi `shift_id` nahi — aur yehi iraada hai.

**Population** engine ke andar tay hoti hai, caller ke paas iska koi ikhtiyar nahi:

    // SalesReportEngine.php:30
    public const POPULATION = ['paid', 'partially_returned', 'returned'];

Yehi wo ek satar hai jise khule bills ke liye badalna hoga — tafseel doosre MD me.

## 6. Teen raaste, ek hi data

| Route | Method | Kya karta hai |
|---|---|---|
| `GET /pos/quick-report/print` | `print()` | `document->data()` → thermal blade → browser me chhapne ke liye |
| `POST /pos/quick-report/email` | `email()` | `document->pdf()` → SalesReportMail → owner recipients |
| `POST /pos/quick-report/send-to-network` | `sendToNetwork()` | ESC/POS bytes → PrintJobFactory → queued print job |

**Owner ke email kahan se:** `report_schedules.recipient_emails` ki pehli row; wo na ho to
tenant ka `owner_email`. Koi durust email na mile to 422 — chup-chaap kuch nahi jata.

## 7. "Save my selection" — per user

| Route | Method |
|---|---|
| `GET /pos/quick-report/settings` | `settings()` |
| `POST /pos/quick-report/save-settings` | `saveSettings()` |

Table: **`pos_quick_report_settings`**, ek row per `user_id`, `payload` JSON me sections,
category_ids, product_ids, waiter_ids, order_types, all_items. Save karte waqt sections ko
`SECTIONS` ke khilaf chhana jaata hai (`array_intersect`) — yani koi ghair-mojood section
payload me nahi ghus sakta.

## 8. Jo baatein aage kaam aayengi

- **Output Report Center se byte-identical hai.** Quick Report ka apna koi hisab nahi, is
  liye engine me kuch badla to dono jagah badlega — jab tak tabdeeli sirf Quick Report ke
  call site par na ho.
- **Section aur filter do alag cheezein hain.** Section = "chhapna hai ya nahi".
  Filter = "kis maal ka". Filter poori report ko tang karta hai.
- **`detailed` yahan nahi hai** — CSV-only.
- **Kuch sections jori hui hain:** modal me likha hai ke ek ke baghair doosra chhapne se
  jama mel nahi khata (isi liye default sab ON hain).
- **`cash_bank` payments par chalta hai**, orders par nahi — aur yehi wajah hai ke khule
  bills isay hila nahi sakenge.
