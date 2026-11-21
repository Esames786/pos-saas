# Work summary — 9 Aug → 23 Aug 2026 (branch `feat/edge-config-refresh-v1`)

99 commits in the window. Two halves: **Khatri go-live + Cloud POS hardening** (9–12 Aug, shared
platform work before the Edge/Catering split) and the **Offline Edge workstream** (13–23 Aug: config
refresh, permission closure, sync engine 1A/1B, canonical gap closure Batch 1).

Branch state at the end: HEAD `70be516` (pushed). Production stays `c4fc021`, Cloud-only, Local
Mode inactive, `activation_ready=false`, no appliance deployed. Full Edge MySQL suite: 364 tests /
2101 assertions green; fast suite 194 green.

---

## Part 1 — Khatri go-live & Cloud POS hardening (9–12 Aug)

### 9 Aug — go-live day
| Commit | What was done |
|---|---|
| `44654ae` TERMINAL-LIMIT-1 | Closed the terminal-limit bypass on the update path (Khatri go-live item 21). |
| `fd11b53` docs | Recorded KHATRI BIRYANI = LIVE with go-live evidence, backup checksums, follow-ups. |
| `8dccc6f` KHATRI-ONBOARDING-2 | Network KOT printers + order-type-aware category→printer routing. |
| `cb4158f` DELIVERY-CHARGE-1 hotfix | Fixed a TDZ crash when the POS delivery panel resets. |
| `fd0f115` Khatri seeder | Reminder printer seeded; auto KOT/Receipt/Reminder switched on. |
| `a5dfea3` Khatri onboard | Re-running onboarding never rotates the owner password. |
| `a030e1c` Provisioner | Owner grants survive re-provisioning. |
| `f153d68` VEHICLE-NUMBER-1 | Quick-sale vehicle capture, online + offline paths. |

### 10 Aug — day-2 sprint
| Commit | What was done |
|---|---|
| `c592657` | Receipt change from tendered cash, ESC/POS auto-cut, Bingoo branding. |
| `09a9cf6` | POS tiles/categories CSS + print-format parity. |
| `ce6f21f` CUSTOMER-UX-1 | POS customer modal, address book, delivery-charge lock. |
| `af443dc` | Receipt line truncation drops the whole price rather than mangling it. |
| `071c983` KHATRI-MENU-2 | Child categories (Saada/Non-Saada/Matka) + ordering. |
| `d5fc95e` KHATRI-UX-2 | One-row POS context bar. |
| `6331060` REPORT-CENTER-3 | Section selection, combos, cancellations, parity, section permissions. |
| `74d9415` TENANT-RESET-1 | Guarded transactional reset that keeps master data. |
| `ac0e9cc` docs | Khatri day-2 evening sprint record. |
| `24395ce` | Owner System Reset page; recall restores the customer chip. |
| `b4ef353` CASH-SHORTAGE-1 | Show the shortage difference and raise a draft expense. |
| `3816596` KHATRI-GOLIVE-2 | Two-printer setup, named terminals, delivery counter user. |

### 11 Aug — POS/print/report hardening (biggest day)
| Commit | What was done |
|---|---|
| `9c471cd` | KOT per category, print polish, bill preview = the real bill. |
| `4312f75` | Order-type-scoped order lists, live layout editor, branch name. |
| `a91865f` | One-box customer flow, split cancellation approvals, default void reasons. |
| `2d0f7fe` | Address choice/add always available on delivery orders. |
| `98bcb73` | Fixed duplicate branch (provisioner keyed the branch on its name). |
| `1099553` | Final bill prints after preview; fresh order clears the customer. |
| `dc0b870` | Hardened POS submissions, data scope, print fallbacks. |
| `e28aa75` / `adae5e3` | Combo KOT routing fix; combo/modifier/split-bill linkage guard; self-auditing reset. |
| `d0ac021` | Customers required on delivery POS orders. |
| `439cf22` | Split cancellation policy + manager codes fixed. |
| `85ebcfd` / `cedb073` | Sales-return accounting + charge visibility; double-counted tax on category returns fixed. |
| `bdfd1db` | Manual POS discounts with per-branch approval. |
| `719d2f6` | Two silently-red MySQL gates repaired. |
| `35b08ac` / `869c7c6` | POS Add & Attach / Save address fixed; Complete Sale stays disabled on the empty cart. |
| `ca492f6` | Delivery rider reassignment with audit trail. |
| `46a6fc6` | Sale times shown in the branch timezone, not UTC. |
| `15802c0` | Receipt preview and paper agree; seeder matches live menu. |
| `d8a28ef` | Refuse a sale line with a product but no quantity. |
| `e451202` / `6fbe2ba` / `c201f8f` | Returns + cash reconciliation explicit; refund method mandatory; tests aligned. |
| `e421721` … `e6bcd9d` (7 commits) | Thermal sales report: retries, 72 mm fit, complete layout, NET SALES reconciliation, columns, bold/larger. |
| `752daad` / `7138700` | Dashboard + shift report agree with the Report Centre; thermal reports aligned with live printer profiles. |
| `b61bc5d` docs | Khatri printer deployment state. |
| `bdc6fcd` / `e1098ee` | Print jobs not lost to a momentarily busy printer; printers kept awake. |
| `0409a5c` / `5a42035` | Version-stamped agent download (+ fix of the 500 it introduced). |
| `c66f254` | Sales Summary cash target corrected. |
| `e49ba3b` | Delivery charge returned when the whole order comes back. |
| `847f4fa` / `e5c471f` / `f4758c9` / `287f1d3` | Layout font size reaches the printer; agent + scoped reports hardened; receipt top margin; bill preview reads like the bill. |

### 12 Aug — closing/permissions/tenant fixes
| Commit | What was done |
|---|---|
| `64eb58d` | Layout switches regain meaning; preview uses the paper size. |
| `81bfd77` / `37a8e9f` | No close may invent a count; Daily Closing aggregates the business day; close forms say count is required. |
| `6ce7357` docs | Chronicle of Khatri's first live trading day. |
| `49694a8` / `eb61ce0` | Bold rider-facing lines; Print Jobs times on the branch clock. |
| `db9194f` / `3348b98` / `e17d6b5` | Tenant identification before session (closes /login 500s); permission collection dropped on tenant switch; per-request permission cache (ends cross-tenant 403s). |
| `04156b6` / `889b44b` | Demo reset per tenant process; reset chain survives one failure. |
| `d6e52cd` docs | SSL renewal runbook (1 Sep deadline). |
| `267d512` | Dismiss obsolete print jobs without faking a print; tenant-context fix proven. |
| `b795405` / `6192092` | Shift open/close limited to the cashier's own terminal; Dine-in counter gets its own role. |
| `e4568c5` / `014a6f2` | Column receipt/KOT/reminder, AM/PM times, combos in cart preview; rounded receipt money, boxed rows, report date filters. |

---

## Part 2 — Offline Edge workstream (13–23 Aug)

### 13 Aug — split-brain fence, checkpoint, config refresh
| Commit | What was done |
|---|---|
| `c4fc021` EDGE-SPLITBRAIN-STOCK-1 | Official stock/FEFO/COGS authority fenced during Local Mode: a Branch Server never posts official stock; Cloud refuses a branch handed to its server. **This is the production HEAD.** |
| `8799749` PLATFORM checkpoint | Recorded the checkpoint before the Edge and Catering workstreams split (the merge base with canonical). |
| `ddc2a6e` EDGE-CONFIG-REFRESH-1 + COMPATIBILITY-CONTRACT-1 | Bootstrap wire contract v5 with a Cloud-authoritative monotonic `config_revision`; an already-bootstrapped appliance applies new config non-destructively (upsert existing IDs, insert new, tombstone missing, never delete referenced config; `restaurant_tables.status` occupancy merged; one transaction serialized on the meta row; older revision refused, replay no-op). Compatibility service classifies a device as compatible / software_update_required / feature_unavailable_offline; device-authed compatibility report endpoint; Cloud↔Edge parity matrix doc. 8 refresh + 5 compat proofs. |
| `247adb6` / `cabc0a4` | Shared fixes: finance statement 500 (Blade @endforeach); network/reprint buttons printing the previous bill. |
| `56c06fa` PLATFORM test isolation | Env-driven Edge-local test DB (`EDGE_TEST_LOCAL_DB`) so parallel worktrees (Edge/Catering/Codex) never drop each other's databases; proven with two concurrent suites. Cherry-pickable to Catering. |
| `7e437d1` EDGE-CONFIG-REFRESH-1 closure | ONE offline permission authority: per-user `model_has_permissions`; `role_has_permissions` cleared on refresh so a stale role row can never re-grant a revoked permission (regression proven red→green with real Spatie `can()`); Spatie cache flushed post-commit; late-failure atomicity proof; watermark executable proofs (price/recipe/printer/permission/delete-only each mint a new revision). |

### 14 Aug — sync engine preflight + outbox
| Commit | What was done |
|---|---|
| `d37a1a7` | Receipt preview Qty/Rate/Amount column collision fixed. |
| `2a4ac78` OFFLINE-SYNC-ENGINE-1A | Design checkpoint `docs/design/OFFLINE_SYNC_ENGINE_V1.md`: identity matrix (sale_uuid is the canonical identity, no new columns), effect/double-apply matrix — **Cloud ingestion must NOT reuse `SalesService::finalizePaidSale`** (its paid early-return skips FEFO/COGS while still posting GL) — pinned by an executable spike; immutable envelope, outbox state machine, idempotency/conflict policy, ACK contract, ordering by identity, split-brain-safe ingestion authority, security, 12-case failure matrix, slices 1B–1E. |
| `6a691ba` / `a5e0d55` | 403 on cached unnamed routes fixed; self-signup/billing research docs. |
| `d65d8ec` OFFLINE-SYNC-ENGINE-1B | Edge-only `edge_sync_outbox` (append-only, immutable envelope + hash, config_revision/epoch frozen per sale, lease state machine, no delete-on-ack); `EdgeSaleEnvelopeBuilder` (`edge-sale-envelope-v1`, canonical JSON reuse, fail-closed on unsupported content, no secrets); outbox row written in the SAME transaction as the paid sale (direct pay + held settlement); lease primitive with race/expiry proofs; rollback proof. 12 MySQL tests. |
| `259c8cf` / `59de856` | Shared POS fixes: vehicle# on takeaway, delivery-charge recall, cancel-KOT heading; Hold delivery order keeps its charge on recall. |

### 23 Aug — re-ground against canonical + gap closure Batch 1
| Commit | What was done |
|---|---|
| `6f8a63f` EDGE: printing routing, job numbering, layout rows | Ported canonical `9319a15` (terminal-keyed KOT routing + `terminal_id` on mappings), `1d66ab0` (collision-safe `PrintJobFactory`/`PrintJobNumber`), `468b9ef` (item/time font, column dividers, KOT category toggle). Edge-specific: bootstrap/refresh now export the four new layout columns (canonical had missed them). Cloud-only report-send path and Catering print services not carried. |
| `2646765` EDGE: business-date on returns and item voids | Ported `dcd1ae4`/`fae23c6`: `business_date` on `sales_returns` + `sales_order_line_cancellations`, stamped by the shared cancellation/return services — an offline void after midnight books to the order's business day. Includes the gap register `docs/status/edge-canonical-gap-2026-08-23.md` (23 rows classified: 6 MUST_PORT, 4 ALREADY_EQUIVALENT, 4 ONLINE_ONLY, 1 NOT_APPLICABLE (all Catering), 4 REQUIRES_EDGE_DESIGN, 4 CONFLICT_RISK). |
| `6493071` EDGE: draft and quick-sale attribution offline | Ported `0d41617` (quick-sale vehicle + waiter attribution; takeaway drops vehicle) and `0b5df5a` (POS-DRAFT-1 `is_draft`). Edge adaptation: `save_as_draft` on hold/revise, cleared on normal hold and on settle; KOT skip enforced SERVER-side (`queueKotEvents` refuses a draft; promotion sends it exactly once); quick-sale waiter captured and validated against the bound branch; sync envelope carries `restaurant_waiter_id`. New `EdgeCanonicalAlignmentMySqlTest` (7 proofs). |
| `71147ac` EDGE: lock product archetype contract | Ported `0a74301`/`5cf34af` — the product-creation contract test as a regression lock (Edge's ProductController guards are byte-identical to canonical). |
| `70be516` docs | Gap register Batch-1 outcome + gates. |

---

## Where things stand

- **Done:** config refresh + compatibility contract; permission authority closure; test DB isolation; sync design (1A) + immutable outbox (1B); canonical gap register; Batch 1 (printing, business date, draft/waiter, catalog lock). Edge compatibility with modern canonical ≈ 90% (from ≈ 70%).
- **Not built (by design, awaiting review):** OFFLINE-SYNC-ENGINE-1C (Cloud ingestion) and 1D (HTTP transport/ACK), 1E operator surface.
- **Open for Batch 2:** non-destructive appliance schema-upgrade path (`edge:local:db-init` is a destructive rebuild); decision on hard-requiring vehicle + waiter for quick sales offline; Edge HTTP payloads for draft/waiter if the local UI needs them.
- **Never touched:** production (`c4fc021`), Khatri/Kashif tenants, Catering code, deployment.
