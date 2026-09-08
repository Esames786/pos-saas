# Catering V1 — Master Acceptance Register

**Purpose:** one final checklist for Bingoo Catering & Events V1. Every
requirement we have agreed to, in one place, so nothing is lost between sessions
again.

**Worktree:** `D:\laragon2\www\pos-saas-catering` · branch `feat/catering-product-ux-v1` · head `446004e`
**Compiled:** 2026-08-22
**Last comparison:** 2026-08-22 against operator-parity head `9d55405`
(`origin/feat/catering-operator-completion-v1`, base `fae23c6`) — inspected
read-only via `git log` / `git diff` / `git show`. **Not checked out, not merged,
not cherry-picked.**
**Application code changed by this document:** none.

> **Still uncommitted, deliberately.** The parity branch is expected to advance
> again with Making Adjustment and possibly manual Email/Resend. One final
> update follows the final parity head; this file is committed then, not before.

## How to read a row

| Status | Means |
|---|---|
| `DONE` | Verified present in this tree at `446004e`, with the evidence named. Not assumed from a file name. |
| `IN_PROGRESS` | Being implemented right now by the separate operator-parity session. Not started here, not to be duplicated here. |
| `OWNER_DATA_REQUIRED` | The code cannot be finished because the client has not supplied the data or the definition. Not a coding task. |
| `POST_V1` | Deliberately out of V1 scope by an earlier decision. Recorded so it is not silently re-scoped in. |
| `UNKNOWN` | The requirement is real; its current state was not established. Must be resolved before sign-off. |

**Evidence** names a file, service, route, migration, test or commit. Where a row
says `DONE` against a route or a blade line, that route or line was read while
compiling this register.

**Source/Decision** points at where the requirement was agreed:

- `COST§x` — `docs/status/catering-costing-and-parity-contract.md`, Part 1 (costing contract)
- `PARITY-2§x` — same file, Part 2 (operator parity backlog)
- `PARITY-3§x` — same file, Part 3 (estimate-line contract)
- `PROG§x` — `docs/status/catering-progress-15-16-aug-2026.md`
- `PLAN§x` — `docs/plans/catering-product-model-fix-2026-08-15.md`
- `UAT` — `docs/status/catering-uat-handoff-2026-08-15.md`
- `CHK` — `docs/status/catering-v1-checkpoint-2026-08-13.md`
- `AUDIT` — a Codex audit finding (`55ad8d5` rate impact, `ef184b6` product completeness)
- `COORD` — the coordinator instruction of 2026-08-22 that produced this register

---

## A. Dashboard

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| A1 | Booking calendar widget on the tenant dashboard, entitlement-gated | PARITY-2§F | DONE | `DashboardController::cateringEnabled()`, `index()`:149-163; `CateringCalendarService`; `CateringCalendarEntitlementHttpMySqlTest` |
| A2 | Calendar previous / next / current month navigation | PARITY-2§F, COORD | DONE | `catering-calendar.blade.php`:62-82 @ `73114ed` — one month at a time, with a Today button |  |
| A3 | Calendar refreshes as a fragment, without a full page reload | PARITY-2§F | DONE | route `tenant.dashboard.catering-calendar`; `DashboardController::cateringCalendar()`:41-53 |
| A4 | One indicator with a COUNT per date, not one dot per booking | PARITY-2§B, COORD | DONE | `catering-calendar.blade.php`:119-140 @ `73114ed` — one count pill per date, toned by the strongest state present; `CateringDashboardParityMySqlTest::test_a_busy_date_renders_one_count_pill_with_the_day_listing` |  |
| A5 | Clicking a date opens a modal listing that date's bookings | PARITY-2§B, COORD | DONE | `#calDayModal` — `catering-calendar.blade.php`:177-212 @ `73114ed` |  |
| A6 | Modal fields Event #, Customer, Time, Venue, PAX, status | PARITY-2§B, COORD | DONE | day-modal columns Booking / Customer / Phone / Time / Venue / PAX / Quotation / Booking / Next Action — `catering-calendar.blade.php`:190-201 |  |
| A7 | Modal field Phone | PARITY-2§B, COORD | DONE | `CateringCalendarService::present()` now emits `phone => customer_phone` @ `73114ed`; rendered as a modal column |  |
| A8 | Modal field next action | COORD | DONE | `CateringCalendarService::nextAction()` @ `73114ed` — a label read off lifecycle facts, transitions nothing; `CateringDashboardParityMySqlTest::test_next_action_walks_the_lifecycle` |  |
| A9 | Today's Events on the dashboard | COORD | DONE | `CateringCalendarService::kpis()[today]`; `partials/catering-kpis.blade.php` Today s Events card @ `73114ed` |  |
| A10 | Next 7 Days (upcoming, not last-7-days) | PARITY-2§F, COORD | DONE | `CateringCalendarService::nextDays(7)` + the Upcoming table in `partials/catering-kpis.blade.php`; `CateringDashboardParityMySqlTest::test_next_seven_days_lists_only_the_coming_week` |  |
| A11 | Past-date-but-still-open bookings called out for action | PARITY-2§F | DONE | `CateringCalendarService::present()` `needs_attention`; `catering-calendar.blade.php`:139-157 |
| A12 | Upcoming booking value shown | PARITY-2§F | DONE | `CateringCalendarService::totals()` |
| A13 | Owner KPI cards: today / tomorrow / next 7 / unconfirmed | COORD | DONE | `CateringCalendarService::kpis()` @ `73114ed` — today, next 7, awaiting finalization, production pending, outstanding balance; the balance uses `CateringFinancialPositionService`, not a dashboard-local formula |  |
| A14 | Net Sale Today only through the canonical Report Center authority | PARITY-2§F, COORD | DONE | the catering dashboard IS the tenant dashboard, whose Net Sales Today card is told by `SalesReportService::todayStats()` on the `currentBusinessDate` clock — `DashboardController`:58-64, `dashboard.blade.php`:59-61. `catering-kpis.blade.php` states explicitly that catering does not add a second competing figure |  |
| A15 | No catering widget on a non-catering tenant's dashboard | UAT | DONE | `DashboardController::cateringEnabled()`; `CateringDisabledPlatformNonRegressionMySqlTest` |

## B. Customer / Event

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| B1 | Booking holds customer name, phone, email, address | COORD | DONE | `CateringEvent::$fillable`:59-79 |
| B2 | Urdu customer name | UAT | DONE | `customer_name_ur` |
| B3 | Venue, PAX, event date, service time, event type | COORD | DONE | `CateringEvent::$fillable` |
| B4 | Date-clash warning while the operator is still typing the date | UAT | DONE | `CateringEventController::bookedDates()`:76-99 |
| B5 | Event lifecycle statuses through to closed and cancelled | CHK | DONE | `CateringEvent::STATUSES` |
| B6 | Confirm booking | CHK | DONE | route `tenant.catering.events.confirm` |
| B7 | Cancel booking with a recorded reason | UAT | DONE | route `…events.cancel`; migration `2026_08_15_000001_add_cancellation_reason_to_catering_events`; `CateringCancellationMySqlTest` |
| B8 | Free-text booking notes | COORD | DONE | `CateringEvent::$fillable` `notes` |
| B9 | Event list filters: today / tomorrow / next 7 / unconfirmed / status | COORD | DONE | `CateringEventController::index()`:24-62 |
| B10 | Link a booking to the platform customer record | CHK | DONE | `customer_id` |
| B11 | Customer-level dashboard and history across bookings | backlog | POST_V1 | explicitly deferred |

## C. Estimate / Quotation

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| C1 | One estimate per event, versioned revisions | CHK | DONE | `CateringEstimate.version_no`; route `…estimates.revise` |
| C2 | A draft is editable; a sent or accepted quotation is immutable | COST§7, PARITY-3§6 | DONE | `CateringDocumentLock::assertEditable()`; `CateringEstimateLifecycleMySqlTest` |
| C3 | Finalize validates costing, freezes the snapshot, moves to sent | PARITY-3§6 | DONE | route `…estimates.send`; `CateringEstimateService` |
| C4 | The button reads as a business act (Finalize Quotation), not Mark Sent / Lock | PARITY-3§6 | DONE | `events/show.blade.php`:207-208 @ `2d000fc`; `CateringOperatorUiMySqlTest::test_the_freeze_action_is_a_business_action_not_a_mechanism` asserts the old wording is gone |  |
| C5 | Previewing or printing must never finalize | PARITY-3§6 | DONE | `…documents.estimate` is GET; `…documents.estimate-print` queues a job only; `CateringDocumentPrintMySqlTest` |
| C6 | Revisions remain possible after finalization | PARITY-3§6 | DONE | routes `…estimates.revise`, `…commercial-rates.revise-and-apply` |
| C7 | A superseded revision is marked as superseded on the printed document | AUDIT (CAT-RATE-011) | DONE | `documents/estimate.blade.php` SUPERSEDED marker; `CateringDocumentTruthMySqlTest` |
| C8 | An unsent estimate prints with a DRAFT banner | AUDIT | DONE | `documents/estimate.blade.php`; `CateringDocumentTruthMySqlTest` |
| C9 | Every draft writer is serialized against Send | AUDIT P1 (`55ad8d5`) | DONE | `CateringDocumentLock`; `CateringDraftWriterRaceMySqlTest` |
| C10 | Reprice a draft against current rates | COST§10 | DONE | route `…estimates.reprice` |
| C11 | Accept a quotation | CHK | DONE | route `…estimates.accept` |
| C12 | Numbering is gap-safe and duplicate-safe | CHK | DONE | `CateringNumberService`; `CateringNumberingAndDuplicateMySqlTest` |
| C13 | Draft edits survive an unrelated save | UAT | DONE | `CateringDraftEditPreservationMySqlTest` |

## D. Cost Details

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| D1 | Cost Details opens inline per line, collapsed by default | PARITY-3§1 | DONE | `events/show.blade.php`:351 |
| D2 | Cost Details shows whichever source is active — never both | COST§3, PARITY-3§1 | DONE | `CateringEstimateCostingService::costBlockLine()` prefers snapshots |
| D3 | Block mode shows Charged, Consumed and Actual cost as three separate numbers | PARITY-3§2 | DONE | `CateringCostBlockService::snapshotMaterialBreakdown()`; `CateringCostBlockMySqlTest` |
| D4 | Actual cost always comes from the Material Rate Book, never the commercial block | COST§10, PARITY-3§2 | DONE | `CateringRecipeCostingService`; `CateringCostingMySqlTest` |
| D5 | Recipe mode shows consumption, expected cost, selling rate and margin | PARITY-3§3 | DONE | `CateringRecipeCostingService`; `CateringCostingModeMySqlTest` |
| D6 | Readiness dispatches per line and the orchestrator fails closed | COST§8 | DONE | `CateringCostBlockService::readinessForSnapshots()`; `CateringMixedCostingMySqlTest` |
| D7 | The snapshot is the authority for a sent document | COST§3 | DONE | `CateringCostSnapshot`; `CateringLineSnapshotMySqlTest` |
| D8 | Switching costing source is explicit and audited | COST§1 | DONE | `CateringCostingSwitchHttpMySqlTest` |
| D9 | Print the cost breakdown on a document | backlog | POST_V1 | explicitly deferred |

## E. Calculated vs Quoted Rate

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| E1 | Calculated rate is displayed | PARITY-3§5 | DONE | `events/show.blade.php`:512 |
| E2 | Quoted rate may override it, with a reason captured | PARITY-3§5 | DONE | route `…estimate-lines.quoted-rate`; `rate_override_reason` |
| E3 | Use Calculated restores the calculated rate | COORD | DONE | route `…estimate-lines.use-calculated-rate` |
| E4 | An overridden line is visibly marked as overridden | PARITY-3§5 | DONE | `events/show.blade.php`:360 `hasQuotedRateOverride()` |
| E5 | The override reason is shown on the line | PARITY-3§5 | DONE | `events/show.blade.php`:380-385 |
| E6 | Amount is quoted rate × quantity | PARITY-3§1 | DONE | `CateringEstimateService` line totals |
| E7 | A manual override survives a house rate change unless explicitly re-applied | COST§11 | DONE | `commercial_rate_source = manual`; `CateringCommercialRateHardeningMySqlTest` |
| E8 | Final labelling of Calculated vs Quoted on the line | COORD | DONE | `events/partials/line-cost-details.blade.php` @ `2d000fc`; `CateringOperatorUiMySqlTest::test_a_draft_shows_calculated_and_quoted_apart_with_cost_details` |  |

## F. Customer Supplied

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| F1 | Customer-supplied is a per-line decision on one material block | COST§6, PARITY-3§4 | DONE | route `…line-cost-blocks.customer-supplied`; `CateringCustomerSuppliedMySqlTest` |
| F2 | Supplied material is charged 0 and consumes 0 from our store | PARITY-3§4 | DONE | `CateringLineCostBlockService::setCustomerSupplied()` |
| F3 | Making is still charged | PARITY-3§4 | DONE | `CateringLineCostBlockService::setCustomerSupplied()` |
| F4 | The arrangement never edits the dish everyone else is quoted from | COST§6 | DONE | snapshot-level change only; `CateringSnapshotOperationsMySqlTest` |
| F5 | The kitchen sheet still shows the physical quantity the kitchen needs | AUDIT (CAT-PROD-002) | DONE | `releases/show.blade.php`:139-176; `documents/kitchen-sheet.blade.php` |
| F6 | Our store is asked to issue none of it | AUDIT (CAT-PROD-002) | DONE | `CateringRequirementService` `required_qty` vs `physical_qty` |
| F7 | Partially customer-supplied (part theirs, part ours) | backlog | POST_V1 | explicitly deferred — do not implement |

## G. Event material override

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| G1 | Override a material quantity for one booking line | COST§6 | DONE | route `…line-cost-blocks.update` |
| G2 | Reset to Default | COORD | DONE | route `…line-cost-blocks.reset` |
| G3 | The override never changes the product profile | COST§6 | DONE | `CateringLineCostBlockService::overrideMaterialQuantity()` writes the snapshot |
| G4 | The override recalculates the line and the document total | COST§4 | DONE | `CateringLineCostBlockService::recalculateDocumentLocked()` |
| G5 | The override is refused once the document is sent | COST§7 | DONE | `CateringDocumentLock::assertEditable()`; `CateringSnapshotOperationsMySqlTest` |
| G6 | Growing the order does not silently re-derive a quantity the operator fixed | COST§4 | DONE | `CateringLineCostBlockService`:223 |

## H. Material Cost Rates

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| H1 | The Material Rate Book is the single authority for what a material costs | COST§10 | DONE | routes `…material-rates.index/store`; `CateringMaterialRate` |
| H2 | Dated rates with an as-of lookup | COST§10 | DONE | `CateringMaterialRate`; `CateringCostingMySqlTest` |
| H3 | A cost rate change moves cost and margin, never the selling price, in recipe mode | COST§9 | DONE | `CateringRecipeCostingService`; `CateringCostingModeMySqlTest` |
| H4 | `per_dish_unit` legacy rows are preserved; `per_material_unit` is the caterer's way | COST§4 | DONE | migration `2026_08_19_000001_add_rate_basis_to_catering_cost_blocks`; `CateringMaterialRateBasisMySqlTest` |
| H5 | The screen speaks the storeman's language, not the accountant's | AUDIT (`ef184b6`) | DONE | `material-rates/index.blade.php`; commit `7d7fc68` |

## I. Commercial Charge Rates / Rate Impact

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| I1 | A second book that says what we CHARGE, separate from what it costs | COST§11 | DONE | routes `…commercial-rates.*`; `CateringMaterialCommercialRate` |
| I2 | The book is a recommendation, never an instruction | COST§11 | DONE | `CateringMaterialCommercialRate` docblock; `CateringCommercialRateImpactMySqlTest` |
| I3 | A block records whether its rate is `manual` or follows the `commercial_book` | COST§11 | DONE | `CateringProductCostBlock::RATE_SOURCES` |
| I4 | Impact preview before anything is applied | COST§11 | DONE | route `…commercial-rates.impact`; `commercial-rates/impact.blade.php` |
| I5 | Selective apply to products | COST§11 | DONE | route `…commercial-rates.apply-products` |
| I6 | Selective apply to eligible drafts | COST§11 | DONE | route `…commercial-rates.apply-drafts` |
| I7 | Create Revision and Apply for documents already sent | AUDIT | DONE | route `…commercial-rates.revise-and-apply` |
| I8 | A dedicated Rate Impact screen | COST§11 | DONE | routes `…rate-impact.index/apply` |
| I9 | Apply is audited — who, when, what moved | AUDIT | DONE | migration `2026_08_20_000003_create_catering_commercial_rate_applications` |
| I10 | Eligibility is decided server-side; the client cannot widen the scope | AUDIT | DONE | `CateringCommercialRateHardeningMySqlTest` |
| I11 | Rate Impact is serialized with the quotation lifecycle | AUDIT (CAT-RATE-002) | DONE | `CateringDocumentLock`; `CateringRateImpactRaceMySqlTest` |
| I12 | Unit safety — a rate never crosses into a different unit | AUDIT | DONE | `CateringCommercialRateIsolationMySqlTest` |
| I13 | Future-dated rates are not applied early | AUDIT | DONE | `CateringCommercialRateHardeningMySqlTest` |
| I14 | Same-day rate history is preserved | AUDIT | DONE | `CateringCommercialRateHardeningMySqlTest` |
| I15 | Operator linking and decision numbers on the impact screen | AUDIT | DONE | `commercial-rates/impact.blade.php`; `CateringCommercialRateImpactMySqlTest` |

## J. Making Adjustment

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| J1 | Making has an explicit identity, not just a free-text charge label | PARITY-3§8, COORD | IN_PROGRESS | NOT BUILT, deliberately — design gate `docs/status/catering-making-adjustment-design-gate-2026-08-21.md` @ `9d55405` names the missing piece: a `charge_role` column on the block and the line snapshot. Today `Making`, `Packing`, `Waiter`, `Decoration` and `Live Counter Setup` share one shape and differ only by typed label |  |
| J2 | Bulk adjustment previews the affected dishes before anything is applied | PARITY-3§8 | IN_PROGRESS | design gate `docs/status/catering-making-adjustment-design-gate-2026-08-21.md` @ `9d55405` — to reuse the Rate Impact preview architecture verbatim |  |
| J3 | Selective apply of the making change | PARITY-3§8 | IN_PROGRESS | design gate `docs/status/catering-making-adjustment-design-gate-2026-08-21.md` @ `9d55405` — selective apply to products and drafts |  |
| J4 | Eligible drafts are offered the change | PARITY-3§8, COORD | IN_PROGRESS | design gate `docs/status/catering-making-adjustment-design-gate-2026-08-21.md` @ `9d55405` — eligible draft snapshots |  |
| J5 | A sent document gets a revision, never a mutation | PARITY-3§8, COORD | IN_PROGRESS | design gate `docs/status/catering-making-adjustment-design-gate-2026-08-21.md` @ `9d55405` — Create-Revision-and-Apply for Sent, via `CateringDocumentLock` |  |
| J6 | A final invoice is immutable to a making change | COORD | IN_PROGRESS | design gate `docs/status/catering-making-adjustment-design-gate-2026-08-21.md` @ `9d55405` — Final immutable |  |
| J7 | Making adjustment posts no GL | PARITY-3§8, COORD | IN_PROGRESS | design gate `docs/status/catering-making-adjustment-design-gate-2026-08-21.md` @ `9d55405` — no GL, ever. Must be asserted by test, not assumed |  |
| J8 | Making adjustment moves no stock | PARITY-3§8, COORD | IN_PROGRESS | design gate `docs/status/catering-making-adjustment-design-gate-2026-08-21.md` @ `9d55405` — no stock, ever. Must be asserted by test, not assumed |  |

## K. Kitchen Instructions

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| K1 | Free-text instruction per estimate line | CHK | DONE | `CateringEstimateLine.instructions` |
| K2 | The instruction is carried onto the production release line | CHK | DONE | `CateringProductionReleaseLine.instructions` |
| K3 | The instruction prints per dish on the kitchen sheet | CHK | DONE | `documents/kitchen-sheet.blade.php`:124,142 |
| K4 | A managed instruction master | PARITY-2§A, PROG§4 | DONE | `catering_instructions` table — migration `2026_08_21_100001_create_catering_instructions` @ `2d000fc`; `CateringInstruction` model; `CateringInstructionController` |  |
| K5 | Roman-Urdu label on each instruction | PARITY-2§A | DONE | `catering_instructions.label` — the Roman-Urdu working label, unique |  |
| K6 | Urdu label on each instruction | PARITY-2§A | DONE | `catering_instructions.label_ur` |  |
| K7 | Active flag on each instruction | PARITY-2§A | DONE | `catering_instructions.is_active`; `CateringInstructionsMySqlTest::test_deactivation_hides_from_new_selection_but_deletes_nothing` |  |
| K8 | Multi-select — several instructions per dish | PARITY-2§A | DONE | pivot `catering_estimate_line_instruction`; `CateringEstimateLine::managedInstructions()`; `CateringInstructionsMySqlTest::test_a_line_multi_selects_and_keeps_its_free_note` |  |
| K9 | An optional free Additional Note alongside the selections | PARITY-2§A, PARITY-3§7 | DONE | the existing free-text `instructions` column survives as the note — `CateringEstimateLine::instructionSummary()`; `CateringInstructionsMySqlTest::test_a_historical_free_text_line_reads_unchanged` |  |
| K10 | The selections print per dish on the kitchen sheet | PARITY-2§A | DONE | `CateringProductionReleaseService::release()` snapshots `instructionSummary()` as TEXT onto the release line, so editing the vocabulary later cannot rewrite a printed sheet; `CateringInstructionsMySqlTest::test_the_kitchen_sheet_snapshot_prints_selections_and_note_as_text` |  |
| K11 | The authoritative vocabulary — roughly 55 entries from the old software | PARITY-2§A, COORD | OWNER_DATA_REQUIRED | the FRAMEWORK is DONE (K4-K10). The migration seeds the table EMPTY on purpose — see its docblock. The ~55-entry vocabulary must come from a client export; inventing it would put words in the kitchen s mouth |  |

## L. Advances / Refunds / Customer Credit

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| L1 | Advance receipt against a booking | CHK | DONE | route `…advances.store`; `CateringAdvanceService`; `CateringFinanceMySqlTest` |
| L2 | All money posts through the approved journal entry point only | CHK | DONE | `JournalPostingService`; `CateringFinanceMySqlTest` |
| L3 | Refund through the supported flow | PROG§3 | DONE | route `…refunds.store`; `CateringRefundMySqlTest`, `CateringRefundHttpMySqlTest` |
| L4 | A refund is refused when it would return money covering an outstanding bill | PROG§3 | DONE | `CateringRefundService` |
| L5 | Customer credit | PROG§3 | DONE | `CateringCustomerCreditMySqlTest` |
| L6 | Booking financial position / statement | PROG§3 | DONE | `CateringFinancialPositionService` |
| L7 | Advance applied to the final invoice | PROG§3 | DONE | migration `2026_08_17_000001_add_advance_applied_to_catering_final_invoices` |
| L8 | `EV-20260816-0001` (−34,250) settled through the supported Refund flow, never manual SQL | UAT, COORD | OWNER_DATA_REQUIRED | live-tenant action; not performed from this session |

## M. Production Release

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| M1 | A release freezes an immutable event snapshot | CHK | DONE | route `…production-releases.store`; `CateringProductionReleaseService` |
| M2 | Releasing and printing move no stock | CHK | DONE | `releases/show.blade.php`:120 |
| M3 | Kitchen tickets print to the mapped printers | CHK | DONE | route `…production-releases.print`; `CateringProductionPrintMySqlTest` |
| M4 | A reprint is marked as a copy and posts nothing | CHK | DONE | route `…production-releases.reprint`; `releases/show.blade.php`:32-39 |
| M5 | The requirement snapshot is stored on the release | AUDIT | DONE | `requirements_snapshot`; `releases/show.blade.php`:103 |
| M6 | No pricing appears on a production document | CHK | DONE | `releases/show.blade.php`:81 |

## N. Kitchen Needs vs Our Store

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| N1 | `physical_qty` — what the kitchen actually needs | AUDIT (CAT-PROD-002) | DONE | `CateringRequirementService` |
| N2 | `required_qty` — what our store must issue | AUDIT (CAT-PROD-002) | DONE | `CateringRequirementService` |
| N3 | `customer_supplied_qty` is the difference between them | AUDIT (CAT-PROD-002) | DONE | `CateringRequirementService` |
| N4 | Both numbers appear on the release screen and the kitchen sheet | AUDIT (CAT-PROD-002) | DONE | `releases/show.blade.php`:139-152; `documents/kitchen-sheet.blade.php` |
| N5 | Releases frozen before the distinction existed still render correctly | AUDIT | DONE | `releases/show.blade.php`:162 fallback |
| N6 | Requirements come from the line's own authority — snapshot first, recipe second | AUDIT | DONE | `CateringRequirementService::addFromSnapshot()` / `addFromRecipe()` |
| N7 | On-hand and shortfall shown per material | CHK | DONE | `releases/show.blade.php`:181-182 |
| N8 | Customer-supplied material is marked on the sheet rather than silently zero | AUDIT (CAT-PROD-002) | DONE | `releases/show.blade.php`:170-176 |

## O. Store Issue / reconciliation

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| O1 | A direct Store Issue screen, independent of the order | PROG | DONE | routes `…store-issues.index/store`; migration `2026_08_16_000001_free_catering_material_issue_from_the_order` |
| O2 | Select bookings by date and by search | AUDIT | DONE | route `…store-issues.bookings`; `CateringStoreIssueBookingsMySqlTest`; commit `f3b04fe` |
| O3 | Aggregate the requirement across the selected bookings | AUDIT | DONE | `CateringStoreRequirementService` |
| O4 | Issued and remaining shown per material | AUDIT | DONE | `CateringStoreRequirementService::remainingByMaterial()` |
| O5 | An issue naming several bookings is flagged shared, never silently allocated | AUDIT (CAT-STORE-002) | DONE | `remaining_is_certain`; `CateringStoreReconciliationMySqlTest`; commit `446004e` |
| O6 | Uncertainty must not become a stock refusal — over-issue is possible with an explicit tick | AUDIT (CAT-STORE-002) | DONE | `CateringMaterialIssueService::issueDirect($allowOverIssue)` |
| O7 | A use-remaining shortcut for the storeman | AUDIT | DONE | `store-issues/index.blade.php` |
| O8 | Stock moves only through the approved FEFO mutator | CHK | DONE | `InventoryService::postOutFefo()`; `CateringMaterialIssueMySqlTest` |
| O9 | Issuing posts COGS at real batch cost | CHK | DONE | `releases/show.blade.php`:196-198 |
| O10 | The issue document is immutable | CHK | DONE | `releases/show.blade.php`:194 |
| O11 | Materials are searchable when issuing | PROG | DONE | commit `af45f37` |
| O12 | Per-booking allocation of a shared issue | AUDIT (CAT-STORE-002) | POST_V1 | deliberately refused — a booking reference is not an allocation, and inventing one would be a fabricated number |

## P. Final Invoice / Settlement

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| P1 | Final invoice raised from the accepted quotation | CHK | DONE | route `…final-invoices.store`; `CateringFinalInvoiceService` |
| P2 | Advance applied against the final invoice | PROG§3 | DONE | migration `2026_08_17_000001_add_advance_applied…` |
| P3 | Settlement and closure of the booking | CHK | DONE | route `…events.close` |
| P4 | A closed booking is commercially immutable | COST§7 | DONE | `CateringDocumentLock::isCommerciallyOpen()` |
| P5 | Final invoice A4 document | CHK | DONE | route `…documents.final-invoice` |
| P6 | The printed final invoice tells the truth after a shrink-then-grow revision | AUDIT (CAT-RATE-011) | DONE | `CateringDocumentTruthMySqlTest` |

## Q. A4 documents

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| Q1 | Estimate / quotation A4 | CHK | DONE | route `…documents.estimate`; `documents/estimate.blade.php` |
| Q2 | Kitchen sheet A4 in English, Urdu or both | CHK | DONE | route `…documents.kitchen-sheet` with `?lang=en|ur|both` |
| Q3 | Final invoice A4 | CHK | DONE | route `…documents.final-invoice` |
| Q4 | Urdu localization of item names on documents | CHK | DONE | `CateringLocalizationService` |
| Q5 | Documents render without error across the module | UAT | DONE | `CateringViewRenderMySqlTest` |
| Q6 | Address / logistics A4 document | PARITY-2§D | DONE | route `…documents.bulk-address-sheet`; `documents/address-sheet.blade.php` — Booking, Date, Time, Customer, Phone, Venue/Delivery Address, PAX, and no prices |  |

## R. Email / Resend

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| R1 | Quotation email when the estimate is sent | PLAN§D9 | DONE | `CateringEstimateController`:97 |
| R2 | Advance receipt email | PLAN§D9 | DONE | `CateringAdvanceController`:45 |
| R3 | Booking confirmation email | PLAN§D9 | DONE | `CateringEventController`:226 |
| R4 | Email log with a dedupe key so a retry never double-sends | PROG§5 | DONE | `CateringEmailLog`; `CateringMailService`:37 |
| R5 | Courtesy reminder emails, per tenant | PROG§5 | DONE | `CateringReminderService`; `CateringMultiTenantReminderMySqlTest` |
| R6 | A manual Email / Resend action from the screen | COORD | IN_PROGRESS | still absent at `9d55405` — no resend route in `routes/tenant.php`, no controller action. Email is sent only as a side effect of Send / Confirm / Advance |  |
| R7 | Working SMTP credentials for the tenant | PROG§5, PLAN Phase 4 | DONE | existing platform infrastructure — production-wide SMTP, out of scope for this workstream and not to be changed by it |  |

## S. Printing

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| S1 | Printer mappings per catering document type | CHK | DONE | routes `…printer-mappings.*`; `CateringPrinterRoutingService` |
| S2 | Copy mappings from the POS setup | UAT | DONE | route `…printer-mappings.copy-from-pos` |
| S3 | Print jobs recorded with status, printer and copy number | CHK | DONE | `releases/show.blade.php`:58-78 |
| S4 | Print authority is separate from view authority | CHK | DONE | `CateringPrintAuthzHttpMySqlTest` |
| S5 | Printing is idempotent — one job per printer, retry never duplicates | CHK | DONE | `CateringDocumentPrintService`; `releases/show.blade.php`:27 |
| S6 | Local print race safety | platform | DONE | `EdgeLocalPrintRaceTest` — passes in isolation; a pre-existing platform race was observed under full-suite contention and reported, not buried |

## T. Bulk Printing / Logistics

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| T1 | Select several bookings by checkbox | PARITY-2§C | DONE | checkbox selection on `events/index.blade.php` @ `2d000fc` |  |
| T2 | Bulk quotation print for the selection | PARITY-2§C, COORD | DONE | route `…documents.bulk-quotations`; `CateringBulkDocumentController::quotations()` |  |
| T3 | Bulk kitchen sheet print for the selection | PARITY-2§C, COORD | DONE | route `…documents.bulk-kitchen-sheets`; refuses with 422 when nothing is released rather than inventing a sheet from a draft — `CateringDashboardParityMySqlTest::test_bulk_kitchen_sheets_refuse_when_nothing_is_released` |  |
| T4 | Bulk address / logistics print: booking no, date, time, customer, phone, venue or delivery address | PARITY-2§D, COORD | DONE | route `…documents.bulk-address-sheet`; `documents/address-sheet.blade.php` |  |
| T5 | Reuses the existing print transport — no second print queue | PARITY-2§C | DONE | GET + read-only composition for the browser A4 dialog; no `print_jobs` row is created — `CateringBulkDocumentController` docblock and body |  |
| T6 | Bulk printing moves no stock and posts nothing | PARITY-2§C | DONE | `CateringDashboardParityMySqlTest::test_bulk_quotations_and_address_sheet_render_without_mutating_anything` compares `journal_lines`, `stock_ledgers` and the draft-estimate count before and after |  |

## U. Search

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| U1 | Find a booking by event / booking number | PARITY-2§E, COORD | DONE | `CateringEventController::index()` `q` over `event_no` @ `73114ed`; `CateringDashboardParityMySqlTest::test_the_event_list_searches_number_customer_phone_and_venue` |  |
| U2 | Find a booking by customer name | PARITY-2§E, COORD | DONE | same — `customer_name` and `customer_name_ur`, LIKE-escaped |  |
| U3 | Find a booking by phone | PARITY-2§E, COORD | DONE | same — `customer_phone` |  |
| U4 | Find a booking by venue or address | PARITY-2§E, COORD | DONE | same — `venue` and `customer_address` |  |
| U5 | Booking lookup when raising a store issue | AUDIT | DONE | route `…store-issues.bookings`; `CateringBookingLookupHttpMySqlTest` |
| U6 | Material search on the materials screen | PROG | DONE | commit `af45f37` |
| U7 | Global cross-module search | PARITY-2§E | POST_V1 | not specified enough to build; explicitly out of scope |

## V. Non-food items

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| V1 | Waiter — Charge, per unit; quantity is head count; store issues nothing | COORD | DONE | `CateringProductCostBlock::TYPE_CHARGE` + `BASIS_PER_UNIT` |
| V2 | Decoration, including flowers — Charge, lump sum | COORD | DONE | `BASIS_LUMP_SUM` |
| V3 | Tissue box — Material, ratio 1:1; charged and drawn from stock | COORD | DONE | `TYPE_MATERIAL` with `quantity_per_unit = 1` |
| V4 | Raw mutton sold directly — Material, ratio 1:1 through a sellable wrapper product | COORD | DONE | `TYPE_MATERIAL` supports it; the wrapper products themselves are owner data — see W |
| V5 | Fan — Charge, per unit | COORD | DONE | `TYPE_CHARGE` + `BASIS_PER_UNIT` |
| V6 | Delivery — Charge | COORD | DONE | `TYPE_CHARGE` |
| V7 | `default_selling_price` is NOT the catering pricing authority | COORD, COST§5 | DONE | `profiles/index.blade.php`:179 pricing mode fixed; `CateringSeedUatCommand`:465 seeds 0 |
| V8 | Rental return semantics for equipment (fans, tables, trolleys) | COORD | POST_V1 | charged per unit in V1; no return tracking |

## W. Catalogue onboarding

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| W1 | The client's source catalogue is transcribed and reviewable | COORD | DONE | branch `data/kashif-catalogue-prep-v1` @ `73807eb`; `docs/data/kashif-catalogue-staging.csv` (941 product rows) |
| W2 | 888 unique items registered without a single invented price | COORD | DONE | `docs/data/kashif-active-menu-owner-input.csv` |
| W3 | Quote unit per item | COORD | OWNER_DATA_REQUIRED | absent from the client export entirely |
| W4 | Customer charge per item | COORD | OWNER_DATA_REQUIRED | absent from the client export entirely |
| W5 | Material ratios per item | COORD | OWNER_DATA_REQUIRED | absent from the client export entirely |
| W6 | Making / service charge per item | COORD | OWNER_DATA_REQUIRED | absent from the client export entirely |
| W7 | Stock-tracked flag per item | COORD | OWNER_DATA_REQUIRED | absent from the client export entirely |
| W8 | The active go-live catalogue — 40 to 80 actually-used items | COORD | OWNER_DATA_REQUIRED | owner marks Y/N in `kashif-active-menu-owner-input.csv` |
| W9 | A representative first setup — 10 to 15 mixed items covering every shape | COORD | OWNER_DATA_REQUIRED | 15 proposed in `docs/data/kashif-representative-products.md`; commercial fields still empty |
| W10 | No zero-price shell product goes live | COORD | OWNER_DATA_REQUIRED | satisfiable only once W3–W7 arrive |
| W11 | Nothing entered into Kashif production yet | COORD | DONE | no tenant mutation from this workstream |
| W12 | A bulk catalogue importer | COORD, backlog | POST_V1 | do not build; a clean re-export from the client should come first |
| W13 | A clean client re-export with price and unit columns and no horizontal truncation | COORD | OWNER_DATA_REQUIRED | 106 codes are physically cut off in the screenshots supplied |

## X. Permissions

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| X1 | Every catering route carries a permission | CHK | DONE | migration `2026_08_13_100003_catering_permissions` |
| X2 | The permission vocabulary is asserted, not snapshotted | AUDIT | DONE | commit `af45f37` |
| X3 | Unused permissions are dropped rather than left dangling | CHK | DONE | migration `2026_08_14_000002_drop_unused_catering_permission` |
| X4 | Entitlement is the plan, not `@can` | CHK, UAT§9 | DONE | `TenantSubscriptionAccessService`; `CateringEntitlementMySqlTest` |
| X5 | Sidebar entries are gated by the same authority | AUDIT | DONE | `partials/sidebar.blade.php` `@canany` |
| X6 | Permissions for the new operator features — bulk print, search, instruction master, making adjustment, resend | COORD | IN_PROGRESS | `PermissionCatalogService`:111-119 @ `2d000fc` groups the six new route names for the Permission Center, but NO migration creates the permission rows or grants them to Owner. `EnsureRoutePermission` fails closed (it aborts 403 unless the user can the route name), and neither new test exercises these routes over HTTP |
| X7 | The route catalog stays in sync with the catering routes | platform | DONE | `system:routes-sync` |

## Y. Tenant / reset safety

| # | Requirement | Source/Decision | Status | Evidence |
|---|---|---|---|---|
| Y1 | `tenant:reset-transactions` wipes catering business documents | UAT | DONE | `TenantResetTransactionsCommand`:76-101; `CateringTenantResetMySqlTest`; commit `c1d6d98` |
| Y2 | The same reset KEEPS catering configuration | UAT | DONE | `TenantResetTransactionsCommand`:233-238 — `catering_instructions` added to the KEPT master list @ `2d000fc` |  |
| Y3 | The Commercial Rate Book is kept as master data across a reset | AUDIT | DONE | `TenantResetTransactionsCommand`:99-101,232 |
| Y4 | No global `system:reset`, `migrate:fresh`, `db:wipe` or global TRUNCATE | COORD | DONE | standing rule; not used in any catering tranche |
| Y5 | Khatri Biryani is live and is never reset or used for test transactions | COORD | DONE | standing rule; no Khatri mutation in any catering tranche |
| Y6 | A tenant without catering is completely unaffected | UAT§9 | DONE | `CateringDisabledPlatformNonRegressionMySqlTest` |
| Y7 | Tenant redirects use `url()` paths, not `route()`, under `Route::domain` | PROG | DONE | `CateringRedirectContractMySqlTest`; `CateringEventController`:112-119 |
| Y8 | Every catering POST is guarded against double submit | PROG | DONE | `prevent.duplicate.submit` middleware; `CateringNumberingAndDuplicateMySqlTest` |
| Y9 | The UAT seed is guarded and idempotent | UAT | DONE | `CateringSeedUatCommand`; `CateringSeedUatMySqlTest` |
| Y10 | Store issue migration safety | AUDIT | DONE | `CateringStoreIssueMigrationMySqlTest` |
| Y11 | Never run route/config/view cache concurrently with the test suite | COORD | DONE | standing operating rule |

---

# Owner-data dependencies

These are not coding tasks. No amount of development closes them.

## A. The kitchen instruction vocabulary

**Status: OWNER_DATA_REQUIRED.** The old software carries a managed list of
roughly 55 instructions — *Mirch Kam*, *Chawal Dana Dana*, *Gosht Gala Hua Ho*,
*Oil Kam*, *Koyala* and the rest. The authoritative list must come from a client
export.

**Do not invent it.** A guessed vocabulary is worse than a free-text field,
because the kitchen would then be receiving instructions nobody at Kashif wrote.

The master table, the labels, the active flag and the multi-select can all be
built against a placeholder list — but nothing goes live on invented words.

## B. The client catalogue

**Status: OWNER_DATA_REQUIRED.** Roughly 888 unique items are transcribed from
the client's screenshots on `data/kashif-catalogue-prep-v1` @ `73807eb`.

What the export contains: `Code #`, `Description`, `Sequence`.

What it does not contain, for any of the 888:

- quote unit
- customer charge
- material ratios
- making or service charge
- stock handling

**Therefore the full catalogue cannot safely be made quotable.** A perfect
importer would produce 888 names and nothing sellable. The rate sheet is the
blocker, not the tooling.

Two further defects make a bulk import unsafe even once prices arrive: 221 rows
share a sequence number with another row, and six codes are each used by two
unrelated items — so neither export column is a usable key. 106 codes are
physically cut off in the screenshots.

## C. The active go-live catalogue

**Status: OWNER_DATA_REQUIRED.** Target 40–80 actually-used items. The owner
marks Y/N in `docs/data/kashif-active-menu-owner-input.csv` on the catalogue
branch.

## D. The representative initial setup

**Status: OWNER_DATA_REQUIRED.** 10–15 mixed items that exercise every shape.
Fifteen are proposed in `docs/data/kashif-representative-products.md`, chosen for
shape coverage — dish, count-based dish, platter, bought-in item, labour charge,
lump-sum decoration, disposable material, packing, equipment charge, raw material
sold directly, customer-supplied, live counter.

They are a proposal about *shape*. The owner still supplies every commercial
number, and still decides what is actually on the menu.

## E. SMTP — NOT an owner-data blocker

**Status: DONE — existing platform infrastructure.**

`SMTP_CONFIG = EXISTING_PLATFORM_INFRASTRUCTURE`

SMTP is production-wide infrastructure that already exists and **must not be
changed by this Catering workstream**. It was previously mis-filed here as an
owner-data blocker; it is not one, and it is not a catering code gap either.

The separate, still-open question is R6 — a **manual Email / Resend action from
the screen**. That is a code gap, not a credentials gap.

## F. `EV-20260816-0001`

**Status: OWNER_DATA_REQUIRED.** The −34,250 position must be settled through the
supported Refund flow on the live tenant, by whoever owns production. Never by
manual SQL, and not from a build session.

**Nothing is to be entered into Kashif production until the owner data above
arrives and the operator-completion release is deployed.**

---

# Non-food mappings — accepted, closed

Recorded so this is not reopened. All six map onto the Cost Block grammar that
already exists. **No new pricing model, and `default_selling_price` is not
reopened as the pricing authority.**

| Item | Block type | Basis | What the storeman sees |
|---|---|---|---|
| Waiter | Charge | per unit | nothing — quantity is head count, store issues none |
| Decoration (incl. flowers) | Charge | lump sum | nothing — one price whatever the PAX |
| Tissue box | Material | 1:1 | a real box leaves the store |
| Raw mutton | Material | 1:1 | real meat leaves the store, via a sellable wrapper product |
| Fan | Charge | per unit | nothing — charged per fan, no stock movement |
| Delivery | Charge | — | nothing |

The distinction that matters most is **tissue box versus waiter**. Both are
"non-food". One is a material — charged *and* drawn from stock. The other is a
charge — charged and drawn from nothing. Getting those two right is what proves
the model can express the client's whole 2000-series.

One friction the UI does not teach: a directly sold raw material needs a
*sellable wrapper product* whose single material block points at the stock
material at ratio 1, because Catering Materials are deliberately non-sellable.
Worth walking the owner through once.

---

# Explicitly NOT to be started

Recorded so a future session does not quietly re-scope them in:

- a large catalogue importer
- partial Customer Supplied
- any new finance subsystem
- any new stock subsystem
- global cross-module search
- a new pricing model
- per-booking allocation of a shared store issue
- rental-return semantics for equipment
- a customer-level dashboard
- printing the cost breakdown on a document

---

# Register summary

Counts are derived from the status column of the tables above, after the
comparison against parity head `9d55405` (read-only; not merged, not checked out).

| Status | Was, at `446004e` | Now, against `9d55405` |
|---|---|---|
| DONE | 162 | **191** |
| IN_PROGRESS | 36 | **10** |
| OWNER_DATA_REQUIRED | 13 | **11** |
| POST_V1 | 7 | **7** |
| UNKNOWN | 1 | **0** |
| **TOTAL** | **219** | **219** |

Per group: A 15 · B 11 · C 13 · D 9 · E 8 · F 7 · G 6 · H 5 · I 15 · J 8 · K 11 ·
L 8 · M 6 · N 8 · O 12 · P 6 · Q 6 · R 7 · S 6 · T 6 · U 7 · V 8 · W 13 · X 7 · Y 11.

The three subsystem prohibitions in the section above (no new finance, no new
stock subsystem, no new pricing model) are constraints rather than requirements
and carry no row of their own.

## What `9d55405` closed

Twenty-nine rows moved to `DONE` on evidence, in three commits:

| Commit | Closed |
|---|---|
| `2d000fc` | inline Cost Details and the Calculated/Quoted split in the draft workspace (E8), Finalize Quotation wording (C4), the managed instruction vocabulary framework (K4–K10), bulk quotation / kitchen / address documents (T1–T6, Q6) |
| `73114ed` | calendar count pill and date modal with phone and next action (A4–A8), dashboard Today's Events and Next 7 Days with owner KPI cards (A9, A10, A13), event search over number / customer / phone / venue / address (U1–U4) |
| `9d55405` | the focused tests that pin all of the above, and the Making design gate |

Two rows changed status because the *classification* was wrong, not because code
moved: **A14** (Net Sale Today) and **R7** (SMTP). Both are covered below.

## Known code gaps after `9d55405`

Three, down from nine.

1. **Making Adjustment** (J1–J8) — deliberately stopped at its design gate. A
   separate parity session is implementing it now.

   The gate's reasoning is worth keeping: a charge block is `block_type =
   'charge'` with a free-text `label`, and on Kashif's live data that same shape
   carries *Making*, *Packing*, *Waiter*, *Decoration* and *Live Counter Setup*.
   A bulk adjustment keyed on `label LIKE '%making%'` would miss `Mkg` and every
   Urdu spelling, and would hit anything else with "making" in its name — moving
   money on whichever side of the guess was wrong. Stopping was the right call.
   The additive fix is a `charge_role` column plus operator classification, not
   a migration that guesses.

2. **Manual Email / Resend** (R6) — still absent. There is no resend route in
   `routes/tenant.php` at `9d55405`; email is only ever a side effect of Send,
   Confirm or Advance. If a quotation email bounces or the customer loses it,
   the operator has no supported way to send it again.

3. **Permissions for the new features** (X6) — see the finding below. This one
   is new information and matters more than its size suggests.

### X6 — the permission finding

`2d000fc` added six new routes:

```text
tenant.catering.instructions.index / store / update
tenant.catering.documents.bulk-quotations
tenant.catering.documents.bulk-kitchen-sheets
tenant.catering.documents.bulk-address-sheet
```

It also added all six to `PermissionCatalogService`, which is the *presentation*
layer — it decides which grant family a permission is shown under in the
Permission Center. It does not create the permission.

On this platform a permission row is created by an explicit migration, which
also grants it to the Owner role — that is what
`2026_08_13_100003_catering_permissions` does, and its docblock says so. The
parity branch adds **no such migration**: its only migration creates the
instruction tables.

`EnsureRoutePermission` fails closed — it aborts 403 unless the signed-in user
holds a permission named exactly after the route. A permission row that does not
exist cannot be held by anyone, Owner included.

Neither new test exercises these routes over HTTP. `CateringDashboardParityMySqlTest`
calls `CateringBulkDocumentController` directly and
`CateringInstructionsMySqlTest` works at the model layer, so both bypass the
middleware entirely. Nothing in the suite would catch this.

**Expected symptom on a real tenant:** every one of the six new screens returns
403 for every role, including Owner, until the permissions are created and
granted.

**This is a report, not a diagnosis of a live failure** — no tenant was migrated
or browsed to confirm it. It is stated as the reading of the code, and it is
cheap to disprove: run the six routes through HTTP as an Owner on a migrated
test tenant.

## Known owner-data gaps

Eleven rows, and none of them is a coding task.

1. The ~55-entry kitchen instruction vocabulary (K11). **The framework is now
   DONE** — table, bilingual labels, active flag, multi-select, additional note,
   printed per dish. The migration seeds it **empty on purpose**. Only the words
   are missing, and they must come from a client export.
2. Quote unit per item (W3).
3. Customer charge per item (W4).
4. Material ratios per item (W5).
5. Making / service charge per item (W6).
6. Stock-tracked flag per item (W7).
7. Which 40–80 items are actually on the menu (W8).
8. The commercial numbers for the 10–15 representative items (W9).
9. No zero-price shell product goes live (W10) — satisfiable only once 2–6 arrive.
10. A clean client re-export without truncation (W13) — desirable, not blocking.
11. Settling `EV-20260816-0001` through the Refund flow on production (L8).

**None of these blocks code.** Do not guess any of the values.

## Two classifications corrected

**A14 — Net Sale Today: `OWNER_DATA_REQUIRED` → `DONE`.**

The old contract said the accounting meaning was undefined and warned against
guessing. That was true when it was written; it is stale now. Canonical has an
established authority — `SalesReportService::todayStats()` on the
`currentBusinessDate` clock, the same figure the Report Center reports.

The requirement is that catering uses **that** authority, not that catering
defines a formula. It does: the catering dashboard *is* the tenant dashboard,
whose Net Sales Today card is already told by that service
(`DashboardController`:58-64, `dashboard.blade.php`:59-61). The parity work made
this an explicit decision rather than an accident — `catering-kpis.blade.php`
states in its header that catering does not add a second, competing version of
that number, and its own money card (outstanding balance) routes through
`CateringFinancialPositionService`, the same refund-aware authority the booking
workspace uses.

So the right answer was neither "owner must define it" nor "build a catering
formula". It was "use the one that already exists", and that is what the code does.

**R7 — SMTP: `OWNER_DATA_REQUIRED` → `DONE` (existing platform infrastructure).**

```text
SMTP_CONFIG = EXISTING_PLATFORM_INFRASTRUCTURE
```

Production-wide infrastructure that already exists and must not be touched by
this workstream. Filing it as a catering owner-data blocker was wrong. The real
open question is R6, the manual resend action — a code gap, not a credentials gap.

## The six previously-flagged possible gaps — resolved

| # | Flagged as | Outcome at `9d55405` | Evidence |
|---|---|---|---|
| A7 | Phone missing from the calendar modal | **CLOSED** | `CateringCalendarService::present()` emits `phone => customer_phone`; rendered as a column in both the day modal and the Next 7 Days table |
| A8 | Next action unowned | **CLOSED** | `CateringCalendarService::nextAction()` — a label read off lifecycle facts that transitions nothing; `test_next_action_walks_the_lifecycle` |
| A9 | Today's Events not on the dashboard | **CLOSED** | `kpis()['today']` + the Today's Events card in `catering-kpis.blade.php` |
| T6 | Bulk print's zero-effect unasserted | **CLOSED** | `test_bulk_quotations_and_address_sheet_render_without_mutating_anything` compares `journal_lines`, `stock_ledgers` and the draft-estimate count across the render |
| J7/J8 | Making's no-GL / no-stock unasserted | **STILL OPEN, correctly** | Making is not built; the design gate commits to no stock and no GL, and both must be asserted by test when it lands |
| K11 | Vocabulary would be invented | **AVOIDED** | the migration seeds the table empty and says why; framework DONE, words still owner-supplied |
| X6 | Permissions for the new features | **STILL OPEN, and now specific** | see the finding above |

Four closed, one correctly still open, one avoided by design — and the sixth
turned out to be a concrete, nameable defect rather than a worry.

---

# Comparison readiness

```text
READY_TO_COMPARE_AGAINST_PARITY_RESULT=yes
```

`9d55405` has been compared and folded in. The next comparison is the final
one, and it has a short list: **J1-J8** (Making Adjustment), **R6** (manual Email
/ Resend) and **X6** (permission rows and the Owner grant for the six new
routes).

Everything else in this register is either DONE on named evidence, owner data, or
deliberately POST_V1. If the final parity head closes those three, V1 is
code-complete and the only thing between it and go-live is the owner data in
section W.

**This document changed no application code, ran no migration, touched no
tenant, and deployed nothing.**
