# Address "save" ho jata hai, magar order par nahi chadhta

**Tareekh:** 2026-09-06 · **Tenant:** Tawakal + The Kashif Foods (`tawakalkashif`)
**Halat:** research mukammal (prod ke asli orders par), code likhna baqi
**Kis ne pakra:** owner — "bill preview pe customer address nhi ara, final receipt pe ara hai"

---

## 1. Shikayat aur asal masla

Owner ne dekha ke ek hi order ki **preview** par address nahi aata magar **final bill** par aa jata
hai. Preview par shak gaya. **Preview bekasoor hai.**

Asal baat ye hai: address **Save** karne se wo **customer ke khaate** me jata hai — **us order par
nahi**. Order par tab jata hai jab `attach()` chale, aur `attach()` sirf **"Attach to Order"** ka
button chalata hai.

Beech ki der me li gayi koi bhi preview (ya hold) address ke baghair hi banegi — kyunke us waqt
order par address hai hi nahi.

## 2. Saboot — owner ke apne orders

```
12:04:00   customer_addresses row bani     ("flat no b-4 shams square block a north")
12:04:11   order #37 HOLD   →  delivery_address KHALI      (11 second baad!)
12:06:03   order #41 PAY    →  delivery_address bhara hua
```

Dono orders wohi customer (`m aeem`, id 5), wohi address. Address hold se **11 second pehle** save
ho chuka tha aur phir bhi order par nahi tha — yani "save" aur "order par lagna" do alag cheezein
hain.

Preview khud bekasoor hai; maine asli endpoint chala kar dekha:

```
POST /api/pos/bill-preview   order_type=delivery, delivery_address diya
   →  "Deliver to: House 5, Block A, Gulshan"     ✅

POST /api/pos/bill-preview   order_type=takeaway
   →  address nahi (jaan-boojh kar — delivery ka pata sirf delivery par)
```

## 3. Code me theek jagah

`resources/views/tenant/pos/index.blade.php`

**`attach()` — yehi ek jagah hai jo order par address lagati hai** (~6591):
```js
const addrEl = $id('delivery_address');
if (addrEl) addrEl.value = address || '';
```

**Save-address ka handler — `attach()` nahi chalata** (~6663):
```js
selectedCustomer.addresses = (selectedCustomer.addresses || []).concat([data.address]);
renderAddresses();
justAdded.checked = true;              // radio chun liya
notify('success', 'Address saved');    // "Address saved"
// ← yahan #delivery_address ko koi haath nahi lagata
```

## 4. Ye dhoka kyun hai (cashier ki ghalti nahi)

Save dabate hi teen cheezein kehti hain "kaam ho gaya": **"Address saved"** ka paighaam, address par
**tick**, aur customer ka **chip pehle se juda hua**. Kahin ye ishara nahi ke ek aur button dabana
baqi hai.

Owner ka apna raasta is dhoke ko aur bara karta hai: unhone **pehle customer bina address ke attach
kiya**, phir usi customer ko dobara dhoond kar address daala. Chunke customer pehle se juda hua tha,
"Attach to Order" dobara dabane ki koi wajah nazar hi nahi aati thi.

## 5. Owner ka doosra sawal — kya address AGLE order par chipak jata hai?

**Nahi. Ye maine khaas taur par dekha, aur chaar jagah pehle se hifazat mojood hai:**

| Kab | Kahan | Kya hota hai |
|---|---|---|
| Review & Pay / New Order / Cancel / mode switch | `index.blade.php` ~4003 | `delivery_address` saaf. `preserveTable` (Add Round) isteshna hai — wohi party ka wohi order |
| Order recall | ~5004 | `el.value = sale.delivery_address \|\| ''` — jis order par address nahi, wo **saaf kar deta hai** |
| Order type delivery se hat jaye | ~2198 | channel, rider aur address teeno saaf |
| Customer chip ka × | ~6586 | address bhi saath jata hai |

To is taraf se koi kaam **nahi** karna. Owner ka andesha wajib tha, magar ye pehle se bandh hai.

## 6. Tajweez

Address **Save** hote hi wohi address order par bhi chadh jaye — modal band kiye baghair (cashier
shayad aur bhi kuch karna chahe), aur chip refresh ho jaye.

Yani save-handler ke `justAdded.checked = true;` ke saath hi `#delivery_address` bhi bhar do —
`attach()` ki poori shakl nahi (wo modal band karta hai aur "Customer attached" kehta hai), sirf
address wala hissa.

⚠️ **Ehtiyat:** ye tab hi kare jab `isDelivery()` sach ho. Warna non-delivery order par address
bhar dena us shakl ko toot-ta hai jo section 5 ki chaaron hifazatein banaye rakhti hain.

## 7. Guard

- Delivery order par: customer attach karo (bina address), phir address save karo → `#delivery_address`
  bhar jana chahiye **bina** "Attach to Order" dabaye.
- Usi ke baad bill-preview me `Deliver to:` aana chahiye.
- Non-delivery par: address save karne se `#delivery_address` **khali hi rahe**.
- Section 5 ki chaaron hifazatein qaayam rahen (naya order / recall / type switch / chip clear).
