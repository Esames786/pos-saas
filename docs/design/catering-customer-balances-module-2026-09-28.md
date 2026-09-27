# Customer Catering Balances — module design

Date: 2026-09-28 (Asia/Karachi)
Audience: whoever builds and reviews this module
Status: **design only — nothing built yet.** Awaiting the owner's decisions in §9.
Placement: Finance section, as **Customer Catering Balances**

## 1. What the owner asked for

> "Ek screen ho — since ab saare customer invoices se link hain — mujhe customer
> dikhao, group by name and phone number. Jis ke against click karne pe us ko
> order ka redirection aur count bhi ho, aur un ka current balance, hisaab, aur
> record payment ka option against invoices. Ya hisaab se related saara ledger
> bhi main dekh sakoon, taake main event se bahar ja kar bhi customer ki
> invoices, balances, ledger, account receivable, payment, refund, negative
> positive sab manage kar sakoon."

Everything in this module exists in the data already. Today it can only be
reached **one booking at a time**, from inside that booking's screen. A customer
who has held eleven events has eleven separate places to look and no total.

## 2. Why this is not the existing Customer Payments screen

Finance already has **Customer Payments** (`tenant.finance.customer-payments.*`).
It is being removed from this tenant on the same day this document is written,
and the reason matters for the design here.

That screen records receipts against **sales receivables** — money owed from POS
and sales invoices. A caterer's money does not arrive that way. It arrives as a
**catering advance** against an **event**, and the posting rule depends on
whether the event has been invoiced yet:

| When the money arrives | `posting_type` | Ledger |
| --- | --- | --- |
| Before the final invoice | `advance` | Dr cash/bank · Cr **2300** Customer Advances |
| After the final invoice | `settlement` | Dr cash/bank · Cr **1300** Accounts Receivable |

A generic receipt screen cannot make that decision, because it does not know
which event the money belongs to. That is why this module is event-aware and
why recording a payment here must go through `CateringAdvanceService::record()`
rather than a new posting path. **One posting authority, reached from two
screens** — never two authorities.

## 3. The hard part: what is a "customer"

The owner asked for **group by name and phone**. That phrasing is doing real
work, and getting it wrong is the main risk in this module.

Bookings carry both a `customer_id` (a row in `customers`) and their own
`customer_name` / `customer_phone` columns. These do not always agree:

- Bookings made before the enrolment bug was fixed on 22 Sep have a name and a
  phone but **no `customer_id`**. A linking command exists for these.
- Some bookings have **no phone at all**, so no customer can be derived.
- Some have two phone numbers in one box, or a phone typed into the name field.

So there are two possible groupings, and they answer different questions:

| Grouping | Answers | Risk |
| --- | --- | --- |
| By `customer_id` | "what does this customer record owe" | bookings with no link vanish from the totals |
| By normalised phone | "what does this person owe" | one person with two numbers appears twice |

**Recommendation: group by `customer_id`, and show unlinked bookings in a
separate, visible "Not linked yet" group** rather than folding them in or
hiding them. A total that silently omits rows is worse than a total with a
visible remainder beside it — and the remainder is also the work queue for
fixing the links.

Identity stays on **phone 1**, exactly as `CustomerDirectory::findOrCreateByPhone`
already decides it. This module must not invent a second rule for who is who.

## 4. Screens

### 4.1 List — Customer Catering Balances

One row per customer. Columns:

| Column | Source |
| --- | --- |
| Customer | `customers.name` |
| Phone | `customers.phone` (phone 2 shown on the detail screen only) |
| Events | count of that customer's `catering_events` |
| Billed | Σ billed per event (see §5) |
| Received | Σ advances − Σ refunds |
| **Balance** | Σ outstanding per event |
| **Credit** | Σ customer credit per event |

Balance and Credit are **two columns, not one signed column.** The owner asked
to see "negative positive" — but one customer can be owed money on one event and
owe money on another at the same time, and a single net figure hides that.
Showing both, each positive under its own name, is the same choice
`CateringFinancialPositionService` already makes internally.

Filters: search by name or phone, branch, "has balance", "has credit".
Default sort: largest balance first.

### 4.2 Detail — one customer

Header: name, both phones, address, totals from the list row.

Then three tabs, all of them **read from the same services the event screen
reads**:

1. **Events** — every booking, with date, status, billed, received, balance,
   and a link through to the booking. This is the "order ka redirection and
   count" the owner asked for.
2. **Documents** — final invoices and their numbers, dates, totals, and current
   balance; production releases; cancelled estimates.
3. **Ledger** — every money movement for this customer in date order: advances,
   settlements, refunds, invoice postings, with the journal entry number beside
   each so it can be opened in Finance.

### 4.3 Record a payment

From the detail screen, against a chosen **event** (not against "the customer"),
because the posting rule in §2 needs the event. The form is the existing advance
form; this screen only chooses the event for you and returns you here afterwards.

If the customer has more than one event with a balance, the form lists them with
their balances and makes the operator pick. It must never split one payment
across events automatically — that is an allocation decision with accounting
consequences, and it belongs to a person.

## 5. Where every figure comes from

This is the part that must not be improvised, because the module's whole value
is that its numbers agree with the event screen.

```
billed       CateringFinancialPositionService::position()['billed']
             (invoice → cancelled → current estimate, in that order)
received     Σ advances − Σ refunds
outstanding  CateringFinancialPositionService::outstanding($billed, $received)
credit       position()['customer_credit']
```

**Never read `catering_final_invoices.balance_due`.** That column is written
when the invoice is issued and never again — the model refuses to update it
("a catering final invoice is immutable once issued"), and that refusal is
correct: an issued invoice is a frozen document. Reading it as though it were
current is exactly the defect found on 27 Sep, when invoice CI-20260925-0002
printed a 38,000 balance that had been paid seventy seconds after issue. See
`docs/audits/catering-final-invoice-balance-snapshot-2026-09-27.md`.

### Performance

`position()` runs two aggregate queries per event, so calling it per row on a
list of customers is an N+1 and will not do. The list must aggregate in SQL:
`withSum` on advances and refunds, joined to the events, grouped by customer.
The detail screen may call `position()` per event, because one customer's events
are few.

The rule to keep: **`outstanding()` stays the single definition of the
arithmetic**, whether the inputs came from `position()` or from a `GROUP BY`.

## 6. Permissions

Route names are permission names, so this module needs its own:

```
tenant.finance.catering-customer-balances.index
tenant.finance.catering-customer-balances.show
```

Recording a payment reuses the **existing** catering advance permission. A
second permission that also lets someone take money would make "who may receive
money" a question with two answers.

Two things that are easy to get wrong here:

- `deploy.sh` grants new route permissions to **Owner only**. Manager, Delivery
  and any custom role get theirs from a tenant seeder that deploy does not run.
  Each role must be granted **additively** (`givePermissionTo`, never
  `syncPermissions`), then `system:clear-tenant-permission-cache`.
- The screen shows every customer's money, so it should default to
  **Owner-only** until the owner says otherwise.

## 7. Module gating

Add these routes under the **`catering`** module, not `finance`. A restaurant
tenant with Finance enabled has no catering events and should not see an empty
screen; a catering tenant should see it whether or not it has the generic
Customer Payments module — which, for Kashif Kitchen, it deliberately does not.

Set `route_catalogs.module_key = 'tenant.catering'` for both routes. Leaving it
unset makes the gate fail open.

## 8. What this module must not do

- **No new posting path.** Every rupee still moves through
  `CateringAdvanceService::record()` or `CateringRefundService::record()`.
- **No writing to a final invoice.** It is immutable by design.
- **No merging of customers.** Two rows that look like one person are a data
  question with a separate, deliberate tool; a balances screen that quietly
  merges them would also quietly merge their money.
- **No supplier or general-ledger work.** Main Finance owns that.

## 9. Open questions for the owner

1. **Unlinked bookings** — show them as a separate "Not linked yet" group (the
   recommendation in §3), or run the linking command first and keep this screen
   to linked customers only?
2. **Who may open it** — Owner only, or Manager too?
3. **Cancelled events** — include them in a customer's totals? They can still
   hold a refundable credit, so leaving them out can make a real liability
   invisible. Recommendation: include, with the status shown.
4. **Statement print** — should the detail screen print a customer statement
   (all events, all payments, closing balance) for handing to the customer?
   Not in the request; worth asking because it is the usual next thing.

## 10. Suggested build order

Each step is useful on its own, and each can be certified before the next.

1. List screen, read-only, linked customers only — proves the aggregation
   matches the event screen on live data.
2. Detail screen: Events tab and Documents tab.
3. Ledger tab.
4. Record payment, reusing the advance form.
5. "Not linked yet" group, plus a link action on each row.

The guard for step 1 is the one that matters most and must be written first:
for every customer, **the module's total equals the sum of `position()` over
that customer's events**, computed independently. If those two ever disagree,
the module is telling a story the booking screens do not.
