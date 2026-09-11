# OFFLINE EDGE — F2: SUPPLIER FINANCE PARITY

Status: **built and proven in executable tests** (appliance authority, two-database Cloud sync with the real
device-authenticated transport, independent-process race, encrypted backup recovery, real operator HTTP pages,
artifact boot). Cloud remains the official AP / GL / cash-bank authority; the appliance never posts finance itself.
No physical pilot; production Local Mode not activated; Purchasing (purchase returns, bills, GRN) not expanded.

## The Online contract F2 mirrors (re-grounded from CURRENT code, canonical af6755d)

| Fact | Where it lives on the Cloud |
|---|---|
| ONLINE_SUPPLIER_PAYMENT_AUTHORITY | `SupplierPayableService::recordPayment(array $data, ?int $userId)` — ONE tenant transaction: `supplier_payments` row (`PurchasingService::nextPaymentNo`), supplier subledger credit (`PurchasingService::postPayment` → `postSupplierLedger` under a row lock, optional bill `amount_paid / balance_due / status`), `postCashBankTransaction` (idempotent `supplier_payment` movement, cash/bank row locked), `JournalPostingService::postSupplierPayment` (Dr `2100` / Cr the cash/bank COA account; a `null` entry rolls the whole transaction back), `assertNoSupplierAdvance` (negative payable → RuntimeException, nothing saved) |
| ONLINE_SUPPLIER_LEDGER_AUTHORITY | `PurchasingService::postSupplierLedger` (`supplier_ledgers` + `suppliers.current_balance`) read by `SupplierController::ledger` (`tenant.suppliers.ledger`) with the "Record Payment" entry point → `/supplier-payments/create?supplier_id=…&from=ledger` |
| ONLINE_MANUAL_AP_AUTHORITY | `ManualJournalService::post` (extracted from `ManualJournalController` in this tranche, behaviour unchanged; the controller delegates): `JournalService::post('manual_journal', …)`, cash/bank line movements, `SupplierPayableService::mirrorApLinesToSupplierLedger` (`journal_adjustment` / `journal_reversal`, advance guard on the credit direction); the AP-line rule `assertApLinesNameTheirSupplier` |
| ONLINE_AP_CONTROL_FAMILY | `SupplierPayableService::apAccountIds()` — account code `2100` and its whole `parent_id` descendant family |
| ONLINE_CASH_BANK_AUTHORITY | `cash_bank_accounts` (active, `account_id` → chart) + `cash_bank_account_transactions`, balances moved under `lockForUpdate` by the payment / manual-journal authorities; COA mapping `JournalPostingService::cashBankCoaId` |
| UI truth | Record Supplier Payment: Against Bill **optional** ("No specific bill (general payment)"), Pay From (Cash/Bank) **required** (the empty option is a placeholder the validator rejects — `cash_bank_account_id` required + active), Payment Method required. General Journal: `counterparty_type=supplier` + Supplier select enabled and required only on AP-family lines, disabled (not submitted) otherwise; no top-level Supplier field |
| Permissions | route names = permission names: `tenant.suppliers.ledger`, `tenant.supplier-payments.store` (+ index/create/show), `tenant.finance.manual-journals.store` (`EnsureRoutePermission`, `TenantProvisioner` catalog) |
| SUPPLIER_ADVANCE_SUPPORTED | **no** — no supplier-advance account or rule exists; the canonical guard fails closed |

Answers the tranche asked for: CASH_BANK_REQUIRED = yes · LEDGER_ONLY_PAYMENT_POSSIBLE = no · PAYMENT_WITHOUT_PURCHASE_BILL = yes ·
SPECIFIC_BILL_PAYMENT = yes (optional allocation) · SUPPLIER_ADVANCE_SUPPORTED = no · PURCHASE_RETURN_OFFLINE_PARITY = FINANCIAL_PARITY_PENDING.

## Warm supplier-finance projection (read-only, device-authenticated)

- Cloud `EdgeSupplierFinanceProjectionService` packages, for the branch appliance: suppliers (identity, code, name, status,
  AUTHORITATIVE payable), open Purchase Bills (identity, number, outstanding), cash/bank accounts (identity, type, COA mapping,
  active/default), the active chart with the canonical AP family (`is_ap`), the recent OFFICIAL supplier ledger rows (window
  `edge.supplier_finance.ledger_window_days` = 30, capped per supplier) labelled with the Edge event that produced them, the
  Edge events the Cloud has already APPLIED (window 45 days), the canonical rules and permission names. NOT the finance DB.
- Watermark `sf:` + sha256 over a content fingerprint (suppliers / open bills / cash-bank / chart / ledger window / applied
  registry). `EdgeStandbyAdvertiser` puts `supplier_finance_watermark` / `as_of` on every heartbeat ACK; when it moves, the
  standby worker pulls `POST /api/edge/supplier-finance/refresh` (`EdgeStandbyFreshnessService::refreshSupplierFinanceIfBehind`).
- Appliance tables `edge_supplier_finance_*` are replaced wholesale on every refresh (`EdgeSupplierFinanceCacheService::apply`);
  the binding carries `supplier_finance_cache_watermark / as_of / refreshed_at`, `standby_supplier_finance_watermark_seen / as_of_seen`,
  `supplier_finance_rules`.
- Freshness rule (the F1 rule): cached watermark = last advertised, or projection received strictly after the last acknowledged
  heartbeat. The takeover proof records `supplier_finance_cache_watermark / advertised / current` (SUPPLIER_FINANCE_CACHE_CURRENT).
  Stale or unknown → every payment / journal FAILS CLOSED with a business message; selling is never held hostage; the appliance
  never guesses a payable — it shows the position it holds, flagged stale.

## LOCAL AVAILABLE PAYABLE (no double application, ever)

`available = Cloud payable (as of the projection) + Σ payable_delta of the local events whose event_uuid is NOT in the projection's
applied set`. An ACK before the next refresh keeps subtracting the event (the Cloud balance in hand predates it); the refresh that
lists it as applied stops subtracting it exactly when the Cloud balance includes it. The same rule drives the provisional cash/bank
position and the per-bill pending allocation. Two terminals serialise on the projected supplier row: the `FOR UPDATE` on the
supplier is the FIRST statement of the transaction and the pending-effect reads are locking reads, so the second terminal computes
the payable the first one left behind (the §18 race is an independent-process test).

## The immutable events and their transport

- `edge-supplier-payment-envelope-v1` (`event_type=supplier_payment`): event_uuid, tenant/branch/device/epoch/config revision,
  supplier identity, payment/business date, amount, cash/bank identity, method, reference / bank / cheque details, optional
  Purchase Bill identity, notes, operator/terminal, finance watermark, created_at, `content_hash`.
- `edge-supplier-ap-journal-envelope-v1` (`event_type=supplier_ap_journal_adjustment`): entry date, description, reference, lines
  (account id + code, optional cash/bank id, `counterparty_type=supplier` + supplier id on AP lines, debit/credit), totals,
  operator, freshness, created_at, `content_hash`.
- Same append-only outbox as sales / returns (`EdgeSyncOutboxService::createForFinanceEvent`, `sale_uuid` = event_uuid), same
  lease / hash / verified-ACK / reconciliation; `EdgeSyncSender` routes both schemas to `edge.sync.supplier_finance_url`.
- Cloud `POST /api/edge/sync/supplier-finance` → `EdgeInboundSupplierFinanceIngestionService` → registry
  `edge_inbound_supplier_finance_ingestions` (event_uuid unique): same uuid + same hash → `already_applied`; different hash →
  `conflict` (no mutation). The official posting runs through the EXISTING authorities inside one transaction with the registry
  claim; `EdgeFinancePostingVerifier::verifyPostedSupplierPayment` / `verifyPostedManualJournal` prove subledger + AP control +
  cash/bank + balanced GL (and the bill) — or the whole transaction rolls back and the event is never APPLIED.
- Cloud permissions are authoritative at ingestion (`ACTOR_UNAUTHORIZED` is terminal). Business refusals answer 422 `refused`
  with the envelope identity: terminal codes (unknown / inactive supplier or account, would-be supplier advance, AP line without
  its supplier, unpermitted operator) become `failed_permanent` on the appliance — a supervisor matter; `FINANCE_*` verifier
  refusals stay retryable. The reconcile endpoint unions the registry.

## Local operator effect (LOCAL_OPERATIONAL vs CLOUD_OFFICIAL)

- `edge_local_supplier_finance_events` (+ `_effects`: payable delta per supplier, cash delta per account, bill allocation) —
  operational history with the sync state derived from the outbox + applied set: PENDING SYNC → POSTED AT CLOUD (awaiting ledger
  refresh) → OFFICIAL (the Cloud ledger row, labelled "Edge event", is the ONE visible transaction) / REFUSED BY CLOUD.
- LOCAL_CASH_EFFECT: the projected cash/bank position moves once per event. The POS shift drawer is untouched — exactly as
  Online, where a supplier payment never touches a shift. No local GL, AP or cash-bank ledger row is ever written.
- Offline payment methods: cash, bank transfer, cheque, other (internal bookkeeping records); **card** to a supplier needs a
  provider authorisation → ONLINE_REQUIRED (`edge.supplier_finance.offline_payment_methods`).
- Bill allocation offline is capped at the bill's outstanding after pending allocations and the bill must belong to the supplier
  (a safety on top of Online, which caps at supplier level only); payment on account never needs a bill.

## Operator surface (Online UX is the spec)

`Suppliers` and `Journal` in the cashier header (permission-gated, server-enforced) → `/edge/local/pos/suppliers` (supplier list
with Cloud payable / pending / available → Supplier Ledger: Date, Type, Reference, Description, Debit, Credit, Balance, User /
Status → Record Payment: Supplier, Against Bill (optional), Branch, Payment Date, Pay From Cash/Bank (required — the only empty
option is a disabled placeholder; no "none / no cash/bank effect"), Payment Method (card disabled "needs the Online POS"), Amount,
Reference No, bank / cheque details, Notes → result with PENDING SYNC) and `/edge/local/pos/finance/journal` (General Journal:
Account, Cash/Bank (only accounts mapped to the chosen chart account), Supplier (enabled + required on AP lines, disabled and not
sent otherwise), Description, Debit, Credit, balance check, recorded journals with sync state).

## Handback and reconciliation

Blockers `SUPPLIER_FINANCE_PENDING`, `SUPPLIER_FINANCE_PERMANENT_FAILURE`, `SUPPLIER_FINANCE_DIVERGENCE` (an event without an
outbox row; an acknowledged row without an applied verdict; an acknowledged event a later projection does not list as applied) join
`OUTBOX_PENDING` / `PERMANENT_SYNC_FAILURE` / `RECONCILIATION_NOT_CLEAN`. After the controlled handback the standby re-pulls the
projection: Cloud payable = subledger running balance = Edge position, pending markers gone, the AP control account moved exactly
as the subledger did (AP_CONTROL_RECONCILIATION).

## Executable proof

| Class | Proves |
|---|---|
| `EdgeSupplierFinanceAuthorityMySqlTest` (10) | payment on account without a bill (provisional payable / cash once, PENDING SYNC, immutable envelope with a self-consistent hash, no local GL); cash/bank required — ledger-only impossible, inactive / unmapped refused; overpayment fails closed before any mutation, the exact payable is allowed; two payments never exceed the boundary; bill allocation optional, capped, must belong to the supplier; inactive supplier / card refused, bank bookkeeping allowed; stale projection fails closed for payments and journals; manual AP journal raises / lowers the provisional payable, AP lines name their supplier, non-AP lines carry none, unbalanced / unknown account / advance / wrong cash-bank mapping refused; handback findings (pending / failed / divergent); a normal cashier is refused server-side |
| `EdgeSupplierFinanceSyncHttpMySqlTest` (3, two databases) | network-down end to end A–K: projection pulled on the heartbeat; SUPPLIER_FINANCE_CACHE_CURRENT at takeover; offline payment on account, payment against a bill, Dr Expense / Cr AP and Dr AP / Cr Cash journals; handback blocked while pending; reconnect with the appliance still the writer; the Cloud posts each OFFICIAL transaction exactly once (payment, subledger, bill, cash/bank, Dr 2100 / Cr 1110 GL, manual journals with the supplier dimension and the subledger mirror); the ACK re-applies nothing locally; LOST ACK for a payment and a journal → recovered once; replay → already_applied; different hash → conflict, no mutation; controlled handback; convergence (Cloud payable = Edge position, one visible ledger transaction per event, AP control = subledger). Stale projection: Cloud advertised a newer watermark the appliance could not pull → takeover records `supplier_finance_cache_current=false` → payments / journals refused, nothing recorded, no guessed balance. Cloud refusals: a payment that would create an advance after an Online payment during the partition → `PAYMENT_REFUSED`, terminal, nothing saved; the finance-incomplete seam rolls back atomically and the next tick applies once; an operator the Cloud no longer permits → `ACTOR_UNAUTHORIZED`, terminal; the permanent failures block the handback |
| `EdgeSupplierFinanceRaceTest` (1, independent processes) | payable 10,000, two terminals pay 7,000 at a barrier: exactly one accepted, the loser meets the canonical boundary message, one event per accepted payment, the remaining 3,000 still payable, one rupee more refused |
| `EdgeSupplierFinanceBackupRecoveryMySqlTest` (1) | a pending payment (against a bill) and a pending journal, their effects and the projection survive encrypted backup → loss → restore with the same bytes / hashes; positions identical; freshness intact |
| `EdgeSupplierFinanceHttpMySqlTest` (3, real HTTP on a branch_server-booted app) | the real pages carry the Online UX (header entry points, Supplier Ledger, Record Payment fields, no usable "no cash/bank effect", General Journal AP → supplier enable/require semantics); the operator records a payment and a journal through the real routes (422 without cash/bank, 422 for an advance, PENDING SYNC in the ledger, event view); a normal cashier gets 403 everywhere and no header entry points |
| `SupplierFinanceDirectMySqlTest` (29, canonical) | unchanged behaviour after the `ManualJournalService` extraction |
| `EdgeArtifactBootTest`, `EdgeArtifactTest`, `EdgeBranchServerRegistrationTest`, `EdgeBladeCompileGateTest` | the F2 routes register from the built artifact; the Cloud finance authority / projection / ingestion / manual-journal controller are physically absent; the appliance-side runtime and the two pages ship; the route census extended deliberately; both pages compile, lint and parse as JavaScript |

## Classification

- SUPPLIER_FINANCE_OFFLINE_PARITY: **FULL_OFFLINE_PARITY** for Direct Supplier Payment (with or without a Purchase Bill) and the
  supplier-aware General Journal, from a fresh warm projection, with cash / bank transfer / cheque / other. Card payment to a
  supplier: ONLINE_REQUIRED. Stale projection: refused offline (record on the Online POS).
- PURCHASE_RETURN_OFFLINE_PARITY: **FINANCIAL_PARITY_PENDING** — not implemented in F2 (no expansion into Purchasing).
- Cloud-side supplier finance is never fenced by the branch lease (a supplier's payable is tenant-wide): an Online payment during a
  partition can make an Edge payment unpostable; the Cloud then refuses it (terminal, nothing saved) and the supervisor resolves it.

## Observation carried forward (not changed in F2)

The canonical sale ingestion and the F1 return ingestion answer refusals without `content_hash` and record in-transaction refusals as
`exception` (HTTP 500); the appliance sender therefore never reaches its terminal branch for them (identity mismatch → `reject` /
5xx → `retry`), so a sale or return the Cloud can never accept keeps retrying with backoff instead of becoming `failed_permanent`.
F2's ingestion answers `refused` (422) with the envelope identity so terminal verdicts do become `PERMANENT_SYNC_FAILURE`. Aligning
the sale / return ingestion is a candidate for a later reliability tranche (money safety is unaffected: nothing is ever posted twice).
