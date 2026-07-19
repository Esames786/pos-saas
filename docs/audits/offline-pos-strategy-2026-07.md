# OFFLINE-POS — Phased Strategy (2026-07)

> Planning document only. No code changed. Audited at `a668974` (`v0.9.2-pilot`).

---

## 1. Executive summary

Today the POS is **fully online-dependent**: one dropped connection mid-rush means
no sales, no receipts, and a cart lost on refresh. This design phases offline
support from "don't lose the cart" (cheap, immediate value) to a cash-only offline
MVP with a sync queue, without ever compromising the finance/inventory integrity
rules (tb_diff=0, branch-aware negative stock) that the platform is built on.
**Build the Print Agent installer first** — offline receipts/KOTs need reliable
local printing, and the agent polls the CLOUD today, so offline printing needs the
agent reachable on the LAN (Phase 2 dependency).

## 2. Current state found in code (what offline must work around)

| Fact | Consequence for offline |
|---|---|
| POS page embeds full `productsPayload` (catalog+prices+`stock_by_branch`+`makeable_by_branch`), branches, payment methods, terminals at page load | Catalog caching is EASY — the data is already client-side per page load; it just isn't persisted |
| Totals are server-authoritative: `refreshServerTotals()` calls `/api/pos/totals/quote` on every cart change (service charge, promos, tax) | Offline must compute totals locally — need a JS mirror of `SalesTotalsService` for the MVP subset (no promos offline) |
| Sale submit = synchronous `FormData` POST to `/pos`; server resolves prices, validates stock, posts inventory (FEFO) + journals in one transaction | Offline sales CANNOT post finance/stock locally — they must queue and replay through the SAME endpoint logic at sync |
| Held sales live server-side (`HeldSaleController`) | Offline hold must be local-only, clearly separated from server holds |
| Only offline-adjacent state today: 2 `localStorage` keys for print toggles; **no service worker, no IndexedDB, no manifest** | Greenfield — no legacy to migrate |
| Print jobs are queued in the CLOUD and polled by the LAN agent | True offline printing needs a direct browser→agent LAN path (local HTTP endpoint on the agent) — Phase 2+ |
| Negative stock is branch-opt-in (`allow_negative_stock`, sale-family only); sale numbering is server-side (`nextSaleNo`) | Sync-time validation can reuse both mechanisms untouched |

## 3. User pain points

Internet drops mid-service → cart lost on refresh, no sale possible, staff writes
paper tickets; kitchen stops receiving KOTs; owner loses revenue AND data.

## 4. Phased strategy

### Phase 1 — Offline awareness + cart safety (small, ship first)

- Detect connectivity (`navigator.onLine` + heartbeat ping to a tiny endpoint).
- Sticky amber "OFFLINE — sales paused" banner; disable Complete Sale + server
  holds while down.
- Persist the cart (+ order type/channel/customer fields) to `localStorage` on
  every change; restore after refresh/crash (works online too — pure win).
- `beforeunload` guard when cart is non-empty.
- **No local sale posting.** Zero accounting risk.

### Phase 2 — Offline MVP (cash-only)

- **PWA shell**: service worker caches the POS page + assets; catalog/branches/
  payment-methods/tax config snapshot in IndexedDB, refreshed on every online load
  (stamped with `cached_at`, staleness warning after N hours).
- **Local totals** for the offline subset: standard lines + line tax by cached
  rates; NO promos, NO service-charge edge cases (dine-in blocked offline in MVP).
- Cash-only checkout → sale saved to IndexedDB queue with local number
  `OFF-{terminalId}-{yyyymmddHHMMss}-{seq}` + `client_uuid` (idempotency key).
- Receipt marked **"OFFLINE RECEIPT — PENDING SYNC"**; printed via the Print
  Agent's new LAN endpoint (agent installer P2 prerequisite) or browser print.
- On reconnect: replay queue to a new `/api/pos/offline-sync` endpoint that runs
  the EXISTING sale pipeline (price re-resolution, stock FEFO, journals) per sale,
  idempotent on `client_uuid`; server assigns the official `sale_no`.
- Sync status chip in POS: `N pending / N synced / N failed`.

### Phase 3 — Controlled multi-terminal offline

Per-terminal offline sequence ranges; sync-exception queue UI (supervisor
resolves: retry / convert to held / cancel with reason + manager approval);
duplicate detection dashboards; cross-terminal stock race reporting.

### Phase 4 — Full offline app (only if real demand)

Desktop wrapper (Electron/Tauri) bundling the print agent; local DB; background
sync; advanced conflict handling. Explicitly out of scope now.

## 5. MVP allowed/blocked matrix (strict)

| Offline allowed | Offline blocked (and why) |
|---|---|
| Cash sale (quick_sale/takeaway) | Credit sale — receivables/customer ledger must not fork offline |
| Cached stock items + plain service items | Sales return — refunds against server state |
| Local receipt + local KOT | Purchase/GRN, stock adjustment/transfer — official stock mutations need the single online choke point |
| Offline held orders (local) | Supplier/customer ledger, manager-approval discounts (PIN check is server-side), online payments, billing/plan actions, manufacturing posting — all server-authoritative |

Why: everything blocked either writes to ledgers/GL directly or depends on
server-side validation that cannot be trusted from a cached snapshot. The MVP rule
is one sentence: **offline may only create cash-sale intents; ALL posting happens
at sync through the existing pipeline.**

## 6. Data conflict + accounting rules

| Question | Rule |
|---|---|
| Duplicate sale numbers? | Local numbers are clearly non-official (`OFF-…`); official `sale_no` assigned server-side at sync (existing `nextSaleNo`); `client_uuid` unique index makes replays idempotent |
| Stock already sold by another terminal? | Stock is validated AT SYNC by the existing FEFO pipeline, in sync order |
| Negative stock setting? | Reused untouched: flag-ON branch → sync posts (backorder legs, cost fallback); flag-OFF + insufficient → sale lands in **Sync Exception queue**, never silently posted |
| Tax rounding? | Server recomputes at sync and is authoritative; if server total ≠ offline receipt total beyond a threshold (e.g. 1.00), flag the sale as exception instead of silently altering a printed amount |
| COGS timing? | COGS/journals are created only at sync (server time), sale_date preserved from offline timestamp — consistent with existing backdated-document behavior |
| Failed sync? | Stays in queue with error; retry/resolve via exception UI; cashier cannot delete — manager permission required (reuse manager-approval pattern) |
| Receipt printed before final number? | Offline receipt shows local number + "PENDING SYNC"; official reprint available after sync (existing reprint flow) |
| Synced vs unsynced marking? | Queue states: `pending → syncing → synced(sale_id) | failed(reason)`; server stores `offline_no` + `client_uuid` + `synced_at` on the sale for audit |
| Audit? | Sales report filter "offline-origin"; per-terminal offline counters; exception log |

## 7. Risks

| Risk | Mitigation |
|---|---|
| Stale cached prices sold offline | Snapshot staleness banner; server re-resolves prices at sync; large drift → exception |
| Two terminals selling the last unit | Accepted MVP trade-off (same as any offline POS); branch can enable negative-stock; else exception queue |
| IndexedDB cleared before sync (browser data wipe) | Sync banner nags until queue empty; document "do not clear browser data"; Phase 4 desktop app removes this class |
| JS totals drift from server | Keep offline subset minimal (no promos/service charge); exception threshold |
| Offline printing without cloud | Depends on Print Agent LAN endpoint (installer P2+) — until then browser print dialog fallback |

## 8. QA plan (MVP)

Kill network mid-cart → banner + cart survives refresh · offline cash sale →
queued + offline receipt · reconnect → auto-sync, official number, stock/journal
posted once (replay the POST → no duplicate via client_uuid) · flag-OFF branch
oversell offline → exception queue, NOT posted, tb_diff=0 · flag-ON branch →
backorder posts with cost fallback · blocked actions genuinely blocked offline ·
multi-tab safety · full-tenant smoke green after sync tests.

## 9. Production rollout

Phase 1 to all tenants (safe) → Phase 2 behind a per-tenant/branch feature flag,
demo tenant first → pilot with one real client on cash-heavy retail → Phase 3 by
demand. Every phase ends with the standard branch-aware finance smoke.

## 10. Recommended build order (with Print Agent)

1. **PRINT-AGENT-INSTALLER-1** (pairing backend + Windows installer) — immediate
   value for current restaurants; prerequisite for offline printing.
2. **OFFLINE-POS-PHASE-1** (awareness + cart safety) — small, ships fast.
3. **OFFLINE-POS-PHASE-2 MVP** (cash-only + sync queue + agent LAN print).
4. Sync-exception dashboard (Phase 3 seed).

## 11. Implementation prompt (next sprint)

**PRINT-AGENT-INSTALLER-1** first (see companion doc). Then
**OFFLINE-POS-PHASE-1**: connectivity detection, offline banner, localStorage cart
persistence + restore, beforeunload guard, disable-posting-offline. Non-goals in
Phase 1: service worker, IndexedDB, offline sales, sync endpoint.
