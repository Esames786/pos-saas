# RETURN-MANAGER-APPROVAL-1 — Post Return par manager PIN

**Status:** RESEARCH / PLAN — banaya nahi gaya
**Date:** 2026-09-01
**Maanga gaya:** owner — "branch level access for return, cancel ki tarah — Post Return dabane
par manager PIN maange"
**Asar:** har tenant (Kashif Food + Khatri) — magar **default aisa rakha jayega ke deploy par
kuch na badle**

---

## 1. Aaj kya hota hai

`/sales-returns/create` par cashier items chunta hai, refund method deta hai, **Post Return**
dabata hai — aur return **usi waqt post ho jaata hai**. Koi manager, koi PIN, koi rukawat nahi.

`SalesReturnController::store()` sirf ye dekhta hai:
- sale `paid` ya `partially_returned` hai
- operator ke terminal/order-type ke daaire me hai (`UserDataScope`)
- branch us operator ko assigned hai
- branch Edge par nahi hai (`assertSaleMutationAllowed`)

Phir seedha `SalesReturnService::processReturn()` — jo **paisa wapas karta hai, stock wapas
lautata hai aur GL me entry daalta hai**. Yani ye POS ka sab se hassas amal hai jis par abhi
koi manager gate nahi.

## 2. Jo nizam pehle se mojood hai (naya kuch nahi banana)

Cancel aur manual discount dono par ye chal raha hai, aur **bilkul wahi tukde return par lagenge**:

| Tukda | Kahan | Kya karta hai |
|---|---|---|
| Branch setting | `branches.held_kot_cancellation_approval_mode`, `…_line_…`, `manual_discount_approval_mode` | `manager_required` \| `auto_approve` |
| PIN check | `POST /api/manager-approvals/verify` → `ManagerApprovalService::verifyPin()` | `manager_pins` par `Hash::check`, phir approval row |
| Approval kharch | `ManagerApprovalService::consume()` | ek hi baar, 10 minute me, usi cashier ka, usi payload par |
| UI | POS ka SweetAlert PIN modal | PIN → verify → `approval_id` form me |
| Audit | `manager_approvals` + Reports → Manager Approvals | kis ne maanga, kis ne diya, kis cheez par |

`consume()` ki chaar zamanaten pehle se hain, aur yehi is kaam ko mehfooz banati hain:
1. **Ek baar** — `consumed_at` set hone ke baad dobara nahi chalti
2. **10 minute** — purani approval expire
3. **Usi cashier ki** — doosre ki approval kaam nahi karti
4. **Usi cheez par** — payload match na ho to rad

Aur `createApprovalForAuthenticatedManager()` ye bhi dekhta hai ke **manager us branch ka hai**.

## 3. Tajweez

### (a) Branch par nayi setting

```
branches.sales_return_approval_mode   ENUM: manager_required | auto_approve
```

⚠️ **Default `auto_approve` rakhna hai**, `manager_required` nahi.

Cancel aur discount ka default `manager_required` hai — magar wo **shuru se** aisa tha. Yahan
agar default `manager_required` rakha to **deploy hote hi dono restaurants me har return ruk
jayega** jab tak owner setting na kholey ya har return par PIN na de. Ye behaviour badal dena
hai, aur wo owner ka faisla hai, deploy ka side effect nahi.

Branch form me pehle se ek "Approvals" section hai (`held_kot_cancellation_approval_mode` aur
`manual_discount_approval_mode` wahin hain) — nayi setting usi ke saath, tteesri qatar me:
**"Sales returns — Post Return par manager approval"**.

### (b) Controller me gate

`SalesReturnController::store()` me, `processReturn()` **se pehle** — bilkul wahi shakal jo
`SalesOrderController` me manual discount ki hai:

```php
$needsManager = ($salesOrder->branch->sales_return_approval_mode ?? Branch::SALES_RETURN_AUTO_APPROVE)
    !== Branch::SALES_RETURN_AUTO_APPROVE;

if ($needsManager) {
    if (empty($data['manager_approval_id'])) {
        return back()->withErrors(['manager_approval_id' =>
            'Manager approval is required to post a return.'])->withInput();
    }
    $approval = ManagerApproval::find($data['manager_approval_id']);
    // ... consume() with the payload below
}
```

**Payload jo bandhna hai** (ye tay karta hai ke approval sirf isi return par chale):

```php
[
    'sales_order_id' => (int) $salesOrder->id,
    'branch_id'      => (int) $salesOrder->branch_id,
    'refund_amount'  => round($refundAmount, 2),
    'refund_method'  => $data['refund_method'],
]
```

⚠️ **`refund_amount` payload me hona ZAROORI hai.** Warna cashier Rs 100 ka return manzoor
karwa kar, wahi approval le kar Rs 10,000 ka return post kar sakta hai. `consume()` payload
match karta hai, is liye raqam bandhte hi ye raasta band ho jaata hai.

⚠️ **Refund amount server par dobara ginna hoga** approval bandhne se pehle. Form ka
`refund_amount` cashier ke qaboo me hai; `SalesReturnService` khud hisaab lagata hai. Approval
usi hisaab par bandhni chahiye jo asal me post hoga, na ke us par jo form ne bheja.

`action_type` = **`sales_return`** (naya, cancel/discount se alag — taake ek ki approval doosre
par na chal sake; `consume()` `action_type` match karta hai).

### (c) UI

Return wali screen par abhi koi PIN modal nahi. POS ka modal (`pos/index.blade.php` ~3880) ek
chhota SweetAlert hai jo `/api/manager-approvals/verify` par PIN bhejta hai aur `approval_id`
wapas leta hai. Wahi shakal `sales-returns/create.blade.php` par:

1. **Post Return** dabao → agar branch `manager_required` hai to PIN modal khule
2. PIN sahi → `approval_id` mile → chhupe hue input me bhar kar form submit ho
3. PIN ghalat / cancel → form submit hi na ho

**Server ka gate UI par bharosa nahi karega** — modal na bhi ho, `store()` bina approval ke
return post nahi karega. UI sirf soolat hai, hifazat nahi.

## 4. Jo NAHI karna

- **Default `manager_required` mat rakhna** — upar (a) me wajah likhi hai
- **`manager_approval_id` ko `nullable` chhod kar bhool jaana** — validation me daalna hai
  (`nullable|integer|exists:manager_approvals,id`) warna ghalat id par 500 aayega
- **Edge ka raasta chhoona** — return pehle se `assertSaleMutationAllowed` se rukta hai jab
  branch Edge par ho, is liye Edge par koi return hota hi nahi. Us par kuch nahi karna.
- **Purane returns par asar** — ye sirf naye returns par lagta hai; koi migration data nahi badalta

## 5. Test plan

`SalesReturnManagerApprovalMySqlTest`:
- branch `auto_approve` → return **bina approval ke post ho** (aaj jaisa — ye sab se ahem hai,
  kyunki deploy par kuch nahi badalna chahiye)
- branch `manager_required` + koi approval nahi → **rad ho, aur return na bane**
- sahi approval → post ho, aur approval **`consumed_at` ke saath** band ho jaye
- **wahi approval dobara** → rad (single use)
- **Rs 100 ki approval par Rs 10,000 ka return** → rad (payload match)
- **doosre cashier ki approval** → rad
- **11 minute purani approval** → rad
- `cancel_held_order` ki approval `sales_return` par → rad (action_type match)
- Guard sabit karna: gate hata kar dekhna ke test laal hota hai

Regression: SalesReturn, Shift, Report, Finance suites — return GL aur stock ko chhoota hai,
is liye `tb_diff=0` bhi dekhna hai.

## 6. Deploy notes

- Ek tenant migration (nayi column, default `auto_approve`), Branch model + controller +
  form, SalesReturnController, returns create blade.
- **Koi nayi route nahi** — `/api/manager-approvals/verify` pehle se mojood hai aur
  `EnsureRoutePermission` ki allow-prefix list me nahi, magar `tenant.api.manager-approvals.verify`
  operator roles ke paas pehle se hai (POS ka cancel usi se chalta hai). **Deploy se pehle
  tasdeeq karni hai** ke Kashif ke teenon operator roles ke paas wo permission hai.
- Deploy ke baad: dono tenants par ek return screen render kar ke dekhna, aur `auto_approve`
  hone ki waja se behaviour bilkul wahi rehna chahiye.

## 7. Owner ka faisla darkar

1. **Kis branch par on karna hai?** Kashif Food, Khatri, ya dono?
2. **Har return par PIN, ya sirf ek hadd se upar?** (misaal: Rs 1,000 se zyada). Hadd wali
   surat me branch par ek `sales_return_approval_threshold` bhi chahiye hoga.
3. Cancel ki tarah **line-level** bhi chahiye ya poore return par ek hi PIN kaafi hai?
