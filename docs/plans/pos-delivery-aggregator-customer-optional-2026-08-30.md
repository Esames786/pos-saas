# POS — Aggregator delivery: customer optional (Foodpanda et al.)

**Date:** 2026-08-30 · **Tenant driver:** Kashif Food (go-live) · **Scope:** cloud POS

## Problem
A delivery order in the POS **always** requires an attached customer. That is correct for
**own delivery** (the restaurant drives to the customer, so it needs the name / phone / address),
but wrong for an **aggregator** channel like **Foodpanda / Uber Eats**: the platform owns the
customer relationship and the restaurant never captures customer details. Kashif's counter cannot
save a Foodpanda order without inventing a fake customer.

## Current behaviour (both layers, unconditional on `order_type=delivery`)
- **Client** `resources/views/tenant/pos/index.blade.php` → `requireDeliveryCustomer()`
  blocks Hold / Review & Pay when the order is delivery and no `customer_id` is attached.
- **Server** `app/Http/Controllers/Tenant/SalesOrderController.php` → `validateDeliveryAttribution()`
  throws `customer_id: "Attach a customer before saving a delivery order."` under the same condition.

Neither looks at the delivery **channel type**.

## Data model — the distinction already exists
`delivery_channels.type` is one of:
- `own` — restaurant delivers (`DeliveryChannel::isOwn()` = `type === 'own'`).
- `aggregator` — third-party platform.

Kashif channels today: **Own Delivery** (`own`), **Foodpanda** (`aggregator`), **Website** (`own`).
The POS channel `<select id="delivery_channel_id">` already renders `data-type="{{ $channel->type }}"`
on each option, so the client can read the selected channel's type with no new payload.

## Decision — gate the customer requirement on channel type
When the selected delivery channel is an **aggregator**, the customer becomes **optional**. For
**own** channels (and when no channel is chosen) the requirement stays exactly as today. Chosen over a
per-channel "customer required" toggle because it is automatic — any future aggregator inherits the
behaviour with zero configuration, and it needs no new column/UI.

**Truth table (order_type = delivery):**

| Channel type | customer_id empty | Result |
|---|---|---|
| `aggregator` (Foodpanda) | yes | **allowed** (skip) |
| `own` (Own Delivery, Website) | yes | blocked — attach customer |
| any | present | allowed |

Non-delivery order types are untouched.

## Implementation (two layers, both required)
1. **Client** `requireDeliveryCustomer()` — after the existing `isDeliveryOrder && !customerId`
   check, read the selected `#delivery_channel_id` option's `data-type`; if `aggregator`, `return true`
   (skip the prompt). One function, so Hold / Review & Pay / direct-pay all inherit it.
2. **Server** `validateDeliveryAttribution()` — before throwing, resolve the channel from
   `delivery_channel_id` and skip the throw when `type === 'aggregator'`. Server is the real gate;
   the client is UX. (`delivery_channel_id` is already present in `$data` for a delivery order — it is
   only nulled on line 868 for non-delivery / table-session orders, after this check.)

## Safety / edge cases
- **Own delivery unchanged** — still requires a customer (we deliver; we need the address).
- **No channel selected** on a delivery order → not aggregator → customer still required (current behaviour).
- **Aggregator with a customer** attached → still allowed (customer is optional, not forbidden).
- **Recall of a held aggregator order** → same `requireDeliveryCustomer()` path, so it skips too.
- No schema change, no new permission, no migration. Additive guard only.

## Test
- Client: PosFrontendRegression (delivery-requires-customer test must still pass for `own`).
- Manual on Kashif: Foodpanda delivery, no customer → Hold + Pay succeed; Own Delivery, no customer → still blocked.

## Rollback
Revert the two guards; behaviour returns to "customer always required on delivery".
