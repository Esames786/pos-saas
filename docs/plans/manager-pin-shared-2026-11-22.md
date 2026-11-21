# MANAGER-PIN-SHARED-1: a shared manager PIN must not refuse the person typing it (22 Nov 2026)

## What went wrong

The 21 Nov deploy (`dc0babbf`, Edge Phase 3 security) added the rule "you cannot approve your own request". The rule
is right. The PIN lookup in front of it was not built for shops where **several people share one PIN**:

- `ManagerApprovalService::verifyPin()` took the **first** active PIN row that matched, in whatever order the database
  returned them, and then refused if that row was the requester's own.
- A live restaurant gives the owner and every counter the same PIN. On 21 Nov at 23:25 the owner, logged in on a
  phone, did a Rs 1,000 return and typed the shared PIN. The first matching row was his own, so the screen said
  "You cannot approve your own request", although four other people with that PIN could approve it.
- With `password@` on the counters (before 22 Nov), the Delivery counter's own row came first among those PINs, so
  the Delivery counter could never get an approval with it.

## The fix

Among the PIN rows that match, take the first person who **may approve this request**:

1. not the requester;
2. holds the permission the action needs (`ACTION_PERMISSIONS`, unchanged);
3. works on the order's branch (default branch or an active branch assignment), preferred. When nobody does, the
   first candidate goes on and the shared creator gives the branch refusal exactly as before.

Rows are now read in `id` order, so the choice does not depend on the database's whim.

**Unchanged:** one person with their own PIN behaves exactly as before. The refusals keep their wording and their
order: no match → "Invalid manager PIN."; only the requester's own PIN → "You cannot approve your own request…";
nobody else holding the permission → "This user is not authorized to approve that action."; nobody on the branch →
"This manager is not authorized for the order branch.". The shared creator still refuses approver = requester
(defence in depth). The Edge path (`EdgeLocalAuthService::verifyManager`) names the manager by their own login, never
by a PIN, so it is not touched.

## Impact (system map)

- **Tables:** `manager_pins` (read; `last_used_at` written on the chosen row), `manager_approvals` (insert, as before);
  read only: `users`, `branches`, `branch_user`, `permissions` / `model_has_permissions` / `model_has_roles` /
  `role_has_permissions`, `sales_orders` (branch of the order).
- **Route:** `tenant.api.manager-approvals.verify` (`ManagerApprovalController@verify`), the only caller of `verifyPin()`.
- **Screens:** every manager-PIN box: POS manual discount, KOT item void, held-order cancel; Returns (with bill and
  no-bill).
- **No** migration, route, permission, view or JS change.

## Before / after (same PIN on owner + counters, owner's row first)

| Logged in | Before | After |
|---|---|---|
| Owner | refused: own request | approved by the next holder with the permission |
| A counter | approved by the owner | approved by the owner (unchanged) |
| Someone whose PIN nobody else has | refused: own request | refused: own request (unchanged) |

## Tests

- `CloudManagerApprovalEligibilityMySqlTest`, four new cases:
  - the requester's own row first → the next holder approves;
  - a PIN only the requester holds → still refused;
  - a holder without the permission is passed over;
  - a holder on the order's branch is preferred, and nobody on the branch → the old branch refusal.
- `SalesReturnManagerApprovalMySqlTest::test_a_shared_pin_approves_a_return_through_the_screen_path`: the real screen
  path. It POSTs `/api/manager-approvals/verify` on a tenant host, then posts the return with that approval; a PIN only
  the requester holds → 422 with the self-approval message.
- Sabotage: the same new tests against the old service (`git show dc0babbf:…`) must be red.

## Results (22 Nov 2026, MySQL test set `_mps`)

- **Fix, new tests:** Cloud eligibility 4/4, return screen path 1/1, green.
- **Sabotage (old service from `dc0babbf`):** all 4 shared-PIN tests red, each with the live error.
  - own row first → "You cannot approve your own request…";
  - no-permission holder first → "This user is not authorized…";
  - other-branch holder first → "This manager is not authorized for the order branch.";
  - screen path → 422 instead of 200.
  - The unique-own-PIN test stays green both ways, as it should.
  - The fix was restored afterwards and its sha checked.
- **Related sets on the fix, all green:**

  | Set | Tests |
  |---|---|
  | CloudManagerApprovalEligibility | 11 |
  | CloudManagerApproval | 3 |
  | SalesReturnManagerApproval | 11 |
  | ManagerApprovalComboVoid | 8 |
  | EdgeManagerApprovalEligibilityHttp | 7 |
  | EdgeBootstrapApproverEligibility | 3 |
  | EdgeLocalAuth | 19 |
  | EdgeCashierReturnParityHttp | 4 |
  | EdgeLocalRestaurantHttp | 6 |
  | EdgeDiscountFlow | 8 |
  | EdgeSharedPosContract | 5 |
  | EdgeCashierPermissionMatrix | 3 |
  | Feature PosRuntime | 7 |

- **System map** rebuilt on the fix tree (279 tables, 751 screens).

## Data change already on production (22 Nov, before this code)

On the owner's order, every active manager PIN at the live restaurant (owner + 5 counter/cashier users, 6 rows) was set
to one shared PIN. The old hashes are saved on the laptop, not in the repo. With the old code a counter then got the
owner as approver, but the owner logged in could not approve anything. This fix makes the owner case work too.

## Deployed (22 Nov 2026 ~00:55 PKT, prod `dc0babbf` → `ead217f5`)

- **Before / after:**
  - Permissions: no role or user lost anything; grants unchanged on every tenant.
  - Chart of accounts: identical on every tenant.
  - Smoke: same 13 lines before and after, `tb_diff=0`, `neg_on_disallowed=0`, `department_negative=0`.
  - Log ERRORs: 20 before and 20 after. These are the known catering-mail queue error, which has been there daily
    since before 10 Nov.
- **HTTP:** `/login` 200; `/sales-returns/create` → login redirect.
- **Live proof** at the restaurant, rolled back so no approval was kept. All 8 users share one PIN, and the replay is a
  return approval on the order that was refused on 21 Nov.
  - Owner logged in → approved by the next holder (a counter).
  - Every other user → approved by the owner.
  - A wrong PIN → "Invalid manager PIN.".
  - Approvals kept: 0.
- **Data the same night (owner's order):** the shared PIN was put on all 8 active users there (2 new rows). The
  previous hashes are saved on the laptop.
