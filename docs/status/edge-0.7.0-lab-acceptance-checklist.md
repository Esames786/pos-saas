# Offline Edge 0.7.0-edge — LAB acceptance checklist (second Windows cashier laptop)

Date: 26 Sep 2026. For: the owner, performing the laptop-side steps. LAB only — the disposable tenant `edgehomelab`; no production data, no P6, no cable pull.

## Where things are

| Item | Value |
|---|---|
| Edge cashier URL (LAB appliance, 0.7.0-edge, commit 7b8f886) | `https://DESKTOP-0024EPM.local:8443/edge/local/login` (fallback `https://192.168.1.6:8443`) — the LAB CA is already trusted on the cashier laptop (P5C) |
| Online POS URL (LAB Cloud, same commit, export of 7b8f886) | `http://127.0.0.1:9701` on the Edge laptop only (loopback). For a paired Online screenshot on the cashier laptop the LAB Cloud would have to listen on the LAN — not enabled; paired Online screenshots are taken on the Edge laptop's browser at the same viewport |
| Cashier | employee code `LAB2C5D` — password in `C:\Users\Dell\BingooEdgeLab\secrets\cashier.pass` on the Edge laptop (never in chat/docs) |
| Approver (manager approval prompts) | employee code `LABMF2DE` — Edge-local password in `C:\Users\Dell\BingooEdgeLab\secrets\approver.pass` (never in chat/docs); holds only `tenant.pos.void-kot-item` |
| Evidence folder | `C:\Users\Dell\BingooEdgeLab\evidence\release-0.7.0-edge\` (put laptop screenshots under `laptop\`) |
| Same-machine Playwright proof (read-only, NOT a substitute) | `…\evidence\release-0.7.0-edge\N-browser-proof-readonly\` |

## What warm standby allows on the appliance (important)

The LAB appliance is in **warm standby**: the Cloud is the active writer. The appliance refuses every branch mutation with
`Local Mode is not active — the Cloud POS is the active writer for this branch (standby)` (HTTP 422). Gated by that fence:
sale completion, bill preview / Review & Pay, KOT firing (normal/addition/cancellation), KOT reminder, table open/close/move/merge,
Add Round, reservations, held-order completion, returns. **Those workflows cannot produce real cashier interaction on the laptop
while the appliance stays in standby.** Everything else below can.

Owner decision needed before those rows: (a) a supervised, LAB-only Local Mode takeover for the acceptance window (isolated LAB
branch, hand back afterwards, recorded), or (b) accept dialog/layout-level evidence for them on Edge and take the functional
evidence on the Online LAB POS only.

Also: the LAB tenant menu is minimal (3 plain products, cash only, no tables/floors/variants/modifiers/weighted items/denominations).
Rows marked "needs LAB menu" require a disposable LAB menu seed on the LAB tenant (owner approval requested separately).

## Laptop-side steps (cashier laptop; capture a screenshot per step, name it `NN-<step>.png`)

| # | Step | Standby-possible? | Needs LAB menu? |
|---|---|---|---|
| 01 | Open the Edge URL; padlock valid; login page renders | yes | no |
| 02 | Log in as `LAB2C5D`; the POS page opens (not the status page) — Online-equivalent layout, header, category tabs, product grid, cart | yes | no |
| 03 | Category/product presentation: tap categories, search, tiles, prices | yes | partly (only 3 products today) |
| 04 | Variants picker, modifier dialog (options, price deltas), weighted-item quantity entry | yes (dialogs) | **yes** |
| 05 | Barcode/SKU entry field: scan/type a SKU → line added | yes | **yes** (SKUs/barcodes) |
| 06 | Cart editing: qty +/−, remove line, line note, clear cart, new sale | yes | no |
| 07 | Hold / Draft / Recall (held orders dialog) | hold = mutation → **needs Local Mode**; dialog opens in standby | no |
| 08 | Table board and dine-in (floors, tiles, open table, Add Round) | board opens; open/round **need Local Mode** | **yes** (tables/floors) |
| 09 | Request Bill / Bill Preview | **needs Local Mode** (422 in standby) | no |
| 10 | Table move / merge | **needs Local Mode** | **yes** |
| 11 | Sent-line void + manager approval (approver `LABMF2DE`) | **needs Local Mode** (KOT must exist) | no |
| 12 | Cash sale: Review & Pay → tendered → complete | **needs Local Mode** | no |
| 13 | Direct Pay KOT | **needs Local Mode** | no |
| 14 | Normal / addition / cancellation KOT to the FakePrinter | **needs Local Mode** | no |
| 15 | KOT Reminder | **needs Local Mode** | no |
| 16 | Receipt print and reprint; Recent Prints dialog | print needs a paid sale (**Local Mode**); dialog opens in standby | no |
| 17 | Shift open / close (denomination count) | to verify on the laptop (shift endpoints are not behind the writer fence) | no |
| 18 | Returns dialog (cash-only offline returns) | dialog opens; a return **needs Local Mode** | no |
| 19 | Permissions / denied actions: log in as a user without a permission → 403 message (use the approver `LABMF2DE`, who lacks `tenant.pos.index`: the POS page must be refused and the status page shown) | yes | no |
| 20 | Responsive behaviour: 1366×768 and the laptop's native size — no horizontal scroll, tiles/tabs readable | yes | no |

## Rollback boundary (recorded)

Crossed on 25 Sep 22:36:49 UTC (first v7 refresh applied). Any rollback from here = `Restore-EdgeAppliance.ps1` from backup
#37 `01M3CTZHD2QTYZJPB9TVYM1E23` or #38 `01M3CVDH0X6CBESGEA2CQMVGEE` + LAB Cloud on `30105df`. No pointer rollback.
