# MOVE-TABLE-CART-1 — Move Table ke baad car ka bill table 32 par chala gaya

Date: 2026-10-22 · Tenant jahan hua: kashiffood · Status: fix bana, test hua, deploy baqi

## Kya hua (prod, sirf parh kar sabit kiya)

Ek hi counter (DTQ 2 Counter), ek hi browser tab. Waqt Pakistan ka:

| Waqt | Kya hua | Saboot |
|---|---|---|
| 10:17 PM | Table 30 khula, bill 157 hold | KOT par "Table: 30" |
| 10:28:18 | Car h1 ka quick sale, bill 135 hold. POS hold ke baad bill ko cart me khula rakhta hai | `POST /held-sales` (page `mode=quick_sale`), KOT + receipt "QUICK SALE / Vehicle: h1" |
| 10:29:00 | Table board se Move: table 30 → 32 (session 8425). Ye jaiz kaam tha | `POST /restaurant/table-sessions/8425/move` |
| 10:29:30 | Cashier ne 1 Plain Rice dala aur Hold dabaya. Cart me ab bhi bill 135 tha | `POST /held-sales` (page `table_session_id=8425&mode=dine_in`), "ADDITION KOT #2, DINE IN, TABLE: 32" |

Nateeja: car ka bill 135 Dine In, table 32 ban gaya, table 32 ke apne bill 157 ke saath. Dono alag rows
rahe (merge nahi hue). Pehchaan: bill 135 ka `restaurant_floor_id` NULL hai. Move har order ka floor bhi
likhta hai, yani 135 move se nahi, baad wale Hold se juda.

## Wajah

1. **POS:** table me jaane ke har raaste (table kholna, continue, open-orders choice) pehle `clearCart()`
   karte hain. Sirf Move ka raasta (`index.blade.php` showTableMove) seedha `applyTableSession()`
   karta tha. Wo dine-in aur naya session to laga deta hai, magar cart me pehle se khula bill nahi hatata.
2. **Server:** `/held-sales` ne kabhi nahi poocha ke held bill pehle kahan tha. Quick sale ko chupke se
   doosri table ka dine-in bana diya. POS khud held bill ki type ya table nahi badalne deta
   (`lockOrderControls`), is liye aisi save sirf purane (stale) cart se aa sakti hai.

## Fix

- **POS:** Move ab `enterMovedTableSession(session)` se guzarta hai. Agar cart me usi moved table ka
  bill hai to wo rehta hai (move ke baad kaam jaari). Warna cart pehle saaf hota hai, phir table khulti
  hai, bilkul baqi raaston ki tarah.
- **Server:** `HeldSaleController@store`: agar request kisi table session ka naam le aur held bill ka
  apna session wo na ho (ya bill quick sale/takeaway/delivery ho), to 422. Kuch save nahi hota.
  Message me bill number, wo kahan ka hai, aur agla qadam (Add Round) hota hai.
  Move apna session id rakhta hai aur merge server par bill ka session khud badalta hai, is liye dono
  par is ka asar nahi.

## Asar (system map se)

| Cheez | Asar |
|---|---|
| `POST /held-sales` (`tenant.held-sales.store`) | Ek naya inkaar. `sales_orders`, `restaurant_table_sessions`, `restaurant_tables` sirf parhe jate hain |
| `/pos` (`tenant.pos.index`) | Move button ka JS |
| `POST /restaurant/table-sessions/{id}/move` | Badla nahi |
| Schema, stock, journals, payment | Kuch nahi |

## Tests

`MoveTableCartMySqlTest` (6, asal HTTP raaste se):
- 22 Oct wala case: quick sale hold, table move, phir purane cart se save → 422; car ka bill bilkul
  waisa (type, vehicle, table, lines); table 32 par sirf us ka apna bill.
- Table 30 ka bill table 32 ke session se → 422.
- Controls: Add Round apni table par; move ke baad apni table ka bill; quick sale akela → sab 200.
- POS page render: Move `enterMovedTableSession` se guzarta hai, wo pehle cart saaf karta hai, saare
  inline scripts `node --check` se parse.

Sabotage: server guard band → 2 asal tests laal (200 mila, yani bug dobara banta hai), controls hare.
Move ko purane `applyTableSession` par wapas → page test laal.

## Prod par abhi ka data

Bill 135 aur 157 dono table 32 ke session par held hain. Code fix purana data nahi badalta; counter
table 32 ka hisaab karte waqt 135 ko car h1 ka samjhe.
