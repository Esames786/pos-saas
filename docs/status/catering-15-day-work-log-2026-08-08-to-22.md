# 15-Day Work Log — 8 to 22 August 2026

**Window:** 2026-08-08 → 2026-08-22
**Compiled:** 2026-08-22, from `git log` across every branch in this repository
**This worktree:** `D:\laragon2\www\pos-saas-catering` · `feat/catering-product-ux-v1` · head `446004e`

## How to read this

Part 1 is the **Catering vertical** — the line this session worked, commit by
commit, with what each one actually changed.

Part 2 is the **parallel catering branches** — audits, the catalogue transcription
and the operator-parity branch. Different sessions, same product.

Part 3 is **everything else that moved in the same 15 days** — Khatri go-live,
Edge, Report Center, POS. Those were other sessions; they are listed so the
window is complete, summarised from their own commit messages rather than
re-verified here.

Some commits appear twice under different hashes. That is not duplicated work —
the same change rebased onto a different base as branches were re-cut. Where it
matters the pair is named.

A note on dates: a handful of commits on canonical and the parity line carry
commit dates of 23–24 August, ahead of this machine's clock. They are recorded in
Part 2 under the date git holds, not moved.

---

# Part 1 — The Catering vertical

## 13 August — the vertical exists

Bingoo Catering & Events opens as a cloud vertical on a database-per-tenant POS
SaaS. Bookings and quotations are catering documents, never sales orders: no
stock, no GL, no shift interaction from these routes.

| Commit | What it did |
|---|---|
| `8799749` | Checkpoint before the Edge and Catering workstreams split apart |
| `0e85e02` | **CATERING-V1 slices 1–3** — the vertical itself: events, estimates, the module key, the permission set |
| `0ddddda` | Costing readiness fails **closed** on send and confirm — an unpriceable dish stops the document rather than quietly quoting zero |
| `5c747d5` | Agreed-event visibility, the advance contract, final invoice and closure |
| `3641900` | Production releases ride the existing PrintJob transport — no second print queue |
| `a34231b` | Multi-tenant reminder proof, friendly permission names, platform non-regression |
| `f67374b` | Demo closure, a final-invoice Blade fix, and the V1 checkpoint document |

## 14 August — go-live candidate 2

| Commit | What it did |
|---|---|
| `0e9c728` | **Rollout gate:** deploying code never broadens access — proven, not asserted |
| `855fbf7` | Finance and inventory integration closure |
| `482e208` | Demo acceptance and the complete gate run |
| `e473aca` | Dropped `material-issues.show` — a permission with no route behind it |
| `150661d` | Release notes: migration review, rollback strategy, deploy procedure |
| `626fa82` | Fixed a 403 on cached unnamed routes (the bare `/`) and exempted the server-time poll |
| `1e07356` `7abaeda` | Canonical integration of go-live candidate 2, and its re-certification record |

## 15 August — the UAT defects, and the lies removed

The first day real people used it. Most of what came back was not a crash.

| Commit | What it did |
|---|---|
| `1da0bc4` | **Entitlement is the plan, not `@can`.** Deploy grants Owner every `tenant.*` permission regardless of plan, so a permission check is not an entitlement decision — Sales and Reports were rendering for a catering-only tenant, and the dashboard itself was mapped to the `reports` module, giving a restricted tenant "Module Not Available" on its own landing page |
| `51367cc` | **Two views compiled to invalid PHP and 500'd in production.** Root cause after reproducing it: Blade matches a directive argument with a recursive paren regex, and on a long multi-line payload that match hits PCRE limits and **silently truncates**. Render coverage added |
| `5202c95` | Recorded the entitlement boundary in the shared platform changelog |
| `3fb2ae9` | Let a product list live at a path other than `/products` — links now built from a controller-resolved base, with the fallback byte-identical to before |
| `0a74301` `5cf34af` | **Froze the product-creation contract before touching anything near it** — field by field, for every archetype a tenant can create, so no change could disturb a live tenant that does not exist yet |
| `55949b2` | **The false finance labels.** The screen claimed "V1 posts no accounting entries" while `CateringAdvanceService` posted a journal entry and moved cash, and `CateringFinalInvoiceService` posted revenue and receivables. Anyone trusting those labels would misread their own books |
| `addd008` | Materials behave like a kitchen, not a factory — a caterer buys mutton; they do not author a BOM or book a WIP receipt |
| `2a4fb69` | **Every action says what it costs** before it is clicked: finance, stock, print/email, reversible or not |
| `cb7aa8e` | Made that guidance precise — "safe to repeat" asserted an idempotency these screens do not have, and a blanket "cannot be undone" overstated a screen carrying a harmless reprint beside an irreversible material issue |
| `bc234bf` | Quotations and invoices can reach a counter printer, not only A4 through the browser |
| `1e7b589` | A cancellation records **why**, by whom and when — the case someone reconstructs months later |
| `37010c8` | Proved the release-gate middleware against the router itself, not read out of the routes file |
| `498cd70` | Proved print **authorization** over real HTTP — asserting a middleware list proves decoration, not refusal |
| `f7ee214` `0761c70` | Session handoff, and the product-model defect list |

## 16 August — production breakage, then the rebuild

| Commit | What it did |
|---|---|
| `cb40f03` | **Six redirects that 500'd *after* doing their work.** Tenant routes live under `Route::domain('{subdomain}.…')`, so the first parameter `route()` fills is the subdomain — it consumed the model and left `{cateringEvent}` empty. Everything uses `url()` paths now |
| `ac09b61` | **Double submit, found in the client's own data** — four bookings created inside two seconds. Plus yearly numbering and Kashif's plan as code |
| `a1025e6` | Running the suite twice exposed three ways the new HTTP tests depended on what ran before them. Each looked green on a single pass |
| `f9e259d` | The booking diary on the dashboard — a caterer's two questions all day are "what is coming up" and "am I already booked that night" |
| `cbb480f` | **Freed the store from the order.** The kitchen man takes one sheet covering ten bookings, one, half of one, or tomorrow's prep with no booking at all. Also stopped showing POS to a caterer |
| `a22151c` | Customer credit and the booking financial position. `EV-20260816-0001` quoted 458,250, took 492,500, was revised down — the screen showed **0.00** and offered no action. The 34,250 held on the customer's behalf appeared nowhere |
| `a8a5f35` | **Priced a dish from named cost blocks** — chicken 200 + making 500 = 700/KG. A material block carries two numbers that must stay independent |
| `12fdddf` `3519f67` | Progress record, and the remaining work expanded into a plan |

## 17 August — money out, and the costing model

| Commit | What it did |
|---|---|
| `00ef190` | **Audited refunds.** Until now a booking could only take money in; when a quotation was revised below the advances received, every available action either edited history or refused |
| `e1c12ec` | The booking statement and settlement screens — a booking holding 34,250 of the customer's money read as fully settled |
| `9b4ad86` | Refunds require a mapped money-out account. The service *claimed* money could never leave the books without leaving the drawer; that half was a claim, not a fact |
| `e4f70be` | **The costing and operator-parity contract** — the document the rest of this workstream is written against |
| `a8069a9` | A dish names what decides its cost: recipe **or** cost blocks. Exactly one is the authority; the other may still be stored |
| `08fa111` | The screen where a dish is built from its parts |
| `90083d9` | **Costing dispatches per estimate line** — a document-level if-blocks-else-recipe would let one dish's arrangement decide another dish's fate |
| `458a9ae` | Configuring blocks and switching to them are separate acts; the guard sits on the move |
| `acaec76` | Store Issue materials searchable — a plain select is fine at fifteen and unusable at two hundred |
| `6b81399` | **One Store Issue may reference many bookings** — eighty kilos of chicken covering twelve weddings was previously either one booking with eleven unrecorded, or twelve fictional handovers |
| `f3b04fe` | The storeman's question is "what is going out tonight", so the date leads and search narrows within it |
| `af45f37` | Assert the permission **vocabulary**, not a snapshot — the old assertion was order-dependent and produced twelve names alone, thirteen in the suite |
| `c1d6d98` | **Taught the tenant reset about catering.** All nineteen catering tables were unclassified, which made the reset not merely incomplete but unsafe — it removed accounting and stock rows while leaving the documents behind |
| `7d7fc68` | Cost blocks in the storeman's language. "Charged per KG" and "Material per KG" are both accurate and read as two versions of one number to anyone meeting them first time |
| `1cda630` | Recorded the estimate-line costing UX contract |

## 18 August — a repeatable UAT tenant

| Commit | What it did |
|---|---|
| `0f2190a` | A guarded Cost-Block-first Kashif UAT dataset — twelve bookings and five costing scenarios built in one auditable place instead of an hour of clicking nobody can review |
| `80d1768` | Four defects in that seed, **found by review rather than by the tests** — which is itself the lesson in three of them. The lump sum never reached the estimate; the service test proved the service, not the seed |

## 19 August — Phase B: the estimate line tells the truth

| Commit | What it did |
|---|---|
| `80d9678` | `per_material_unit` pricing basis beside the legacy `per_dish_unit`. The same stored number means two different prices, so nothing already quoted may move |
| `b8c72fe` | Cost Block pricing **snapshotted** onto estimate lines — a sent document stops depending on a dish that may have changed since |
| `28cc48b` | Cost Details and the quoted-rate override, with a reason captured. Until then a discount was indistinguishable from a mistake |
| `c698df4` | Line costing decisions survive an unrelated draft edit |
| `1c33ab9` | **Customer-supplied materials** — charged 0, consumed 0, store issues nothing, making still charged, and the dish everyone else is quoted from is untouched |
| `2aea69c` | Platform: one collision-safe authority for print job numbers |

## 20 August — the Commercial Rate Book, and the races

| Commit | What it did |
|---|---|
| `21a12e0` | Commercial material rates and Rate Impact — a **second** book, saying what we *charge*, separate from what it costs |
| `af34603` | An operator can put a dish on the house rate. A linked block still never reads today's book when a quotation opens; the gap between applied and recommended is exactly what Rate Impact shows |
| `ee42b28` | Audited and hardened rate application: operator linking, apply audit, unit safety, server-side eligibility, future-dated rates, same-day history |
| `af1d6a3` | Rate changes reach sent documents **through a revision**, never by mutation |
| `13bd7f0` | **CAT-RATE-002** — Rate Impact serialized with the quotation lifecycle. The first fix looked right and still failed two of four cases: under REPEATABLE READ the locks were taken correctly but `$estimate->event` was an ordinary read, so waiting on a lock and then trusting a plain read was just a slower race |
| `5266997` | **The P1 that the audit reframed.** It was never a Rate Impact bug — *every* draft writer could mutate a document mid-send. All of them now serialize through one lock authority, in one lock order |

## 21 August — operations tell the truth

| Commit | What it did |
|---|---|
| `4bd28f2` | The recorded costing basis serializes with send |
| `42421c0` | **Operations run off quotation snapshots**, per line, snapshot first and recipe second |
| `d83ceaf` | Store requirements and issues reconciled — issued against required, per material, across selected bookings |
| `040efb7` | Pricing and document state made explicit: DRAFT banner, SUPERSEDED marker, position-driven totals (**CAT-RATE-011**) |
| `d44fa2a` | **The two numbers.** `physical_qty` is what the kitchen needs; `required_qty` is what our store issues. Only the second was ever shown, so a customer-supplied material read as zero and looked like nothing to plan for (**CAT-PROD-002**) |
| `446004e` | **Refused to invent allocations.** A store issue naming several bookings does not record how the quantity split. Those rows are marked shared and uncertain rather than guessed — and uncertainty is not allowed to become a stock refusal either, so over-issue stays possible behind an explicit tick (**CAT-STORE-002**) |

## 22 August — the master register

No code. `docs/status/catering-v1-master-acceptance.md` — 219 requirements across
25 groups, each with its source decision, a status and named evidence. Compiled
at `446004e`, then re-compared against parity head `9d55405`.

**Uncommitted, deliberately** — the parity branch is still advancing, and the
register gets one final update against the final parity head.

| Pass | Result |
|---|---|
| Compiled at `446004e` | DONE 162 · IN_PROGRESS 36 · OWNER_DATA 13 · POST_V1 7 · UNKNOWN 1 |
| Re-compared at `9d55405` | DONE 191 · IN_PROGRESS 10 · OWNER_DATA 11 · POST_V1 7 · UNKNOWN 0 |

Two classifications were corrected rather than coded: **Net Sale Today** is not
an owner-data question any more (canonical has a Report Center authority and
catering must use it, not invent a second formula), and **SMTP** is existing
platform infrastructure, not a catering blocker.

One new finding: the six new parity routes are grouped in `PermissionCatalogService`
but **no migration creates their permission rows or grants them to Owner**, and
`EnsureRoutePermission` fails closed. Reported as a reading of the code, not a
confirmed live failure — and cheap to disprove.

---

# Part 2 — Parallel catering branches

## Independent audits

| Commit | Branch | What it found |
|---|---|---|
| `f8fa220` | `audit/catering-e2e-qa-v1` | Independent architecture audit, preserved |
| `55ad8d5` | `audit/catering-rate-impact-cert-v1` | **Exposed the draft-writer send races** — five of them. This is the evidence that turned a Rate Impact concurrency bug into a general P1 |
| `ef184b6` | `audit/catering-product-completeness-v1` | Product completeness assessed at 72%, six blockers. Not dismissed because the technical tests were green |

## Catalogue transcription — `data/kashif-catalogue-prep-v1`

| Commit | Date | What it did |
|---|---|---|
| `73807eb` | 21 Aug | **Transcribed the client's catalogue without inventing prices.** 32 screenshots, 941 product rows, 888 unique items, four documents |

The finding that decided the plan: the export has `Code #`, `Description`,
`Sequence` — **no price, no unit, no category, no material list**. A perfect
importer would produce 888 names and nothing quotable. The importer was never
the bottleneck; the rate sheet is, and it does not exist in that export.

Two defects make bulk import unsafe even once prices arrive: 221 rows share a
sequence number, and six codes are each used by two unrelated items — neither
column is a usable key. 106 codes are physically cut off in the screenshots.

Three rows are not products at all (daily expenses, an SSGC gas charge glued to
a food item, transport fare for haleem). Kept as `REJECT_OR_REVIEW`, because
deleting them would hide that the client's item list is doubling as a cost ledger.

## Operator parity — `feat/catering-operator-completion-v1`

A separate session. Reviewed read-only from here; never checked out, merged or
cherry-picked.

| Commit | Date | What it did |
|---|---|---|
| `2d000fc` | 22 Aug | The draft workspace's Phase B truth, the managed instruction vocabulary, and bulk documents (quotation / kitchen / address) |
| `73114ed` | 22 Aug | The calendar dashboard — count pill per date, date modal with phone and next action, owner KPI cards, Next 7 Days — and event search over number, customer, phone, venue, address |
| `9d55405` | 22 Aug | Focused tests pinning all of it; **Making stops at its design gate** |
| `e600653` | 22 Aug | Pint the event controller; the lifecycle suite cleans its own `sale_payments` |

The Making design gate is worth keeping. A charge block is `block_type='charge'`
with a free-text `label`, and on Kashif's live data that same shape carries
*Making*, *Packing*, *Waiter*, *Decoration* and *Live Counter Setup*. A bulk
adjustment keyed on `label LIKE '%making%'` would miss `Mkg` and every Urdu
spelling and hit anything else containing the word — moving money on whichever
side of the guess was wrong. Stopping was the right call.

**The branch has since advanced past the point this register was compared
against** (23–24 Aug commit dates): explicit making-charge classification,
Making adjustment preview and apply, manual customer document resend, event
create/edit inside the workspace, the 55-label kitchen vocabulary seeded, the
888-item menu imported, and legacy-screen habits honoured on the new workspace.
Those are **not** yet folded into the master register — that is the final
comparison still to run.

---

# Part 3 — Everything else in the same window

Other sessions, same 15 days. Summarised from their commit messages, not
re-verified here.

## 8–9 August — Edge (branch-local runtime)

Branch-local database and bootstrap import with an immutable binding
(`ae1b41d`, `2595626`, `61faa2a`, `4acd91d`, `d1b94c7`); device-bound offline
user enrollment and local authentication (`34c2221`, `2ae8159`); immutable
cross-system operational identities (`e16065e`, `ce2b4ee`, `10d3e0d`); the
branch-local POS HTTP surface — terminals, shift, cash sale (`faa4939`,
`14a2b66`, `ec9a9d3`, `cc2b339`, `ca02646`); the restaurant layer — dine-in
tables, held orders, Add Round, KOT events, manager re-auth (`e0f099d`,
`5b965b9`); and lease-safe local printer delivery with FIFO ordering and worker
supervision (`5cf6966`, `cc65339`, `86822a8`, `3d1125a`).

## 9–10 August — Khatri Biryani goes live

Tenant contract, custom plan and menu seed (`13cd815`); network KOT printers
with order-type-aware category routing (`8dccc6f`); the Permission Center
(`473a50f`); the Report Center — shared engine, reconciliation proof, UI,
exports, Email Now, schedules (`7861b37`, `b88ca44`, `6331060`); the
customer-facing delivery charge across the whole money path (`f2cee3e`); closing
a terminal-limit activation bypass (`44654ae`); **`fd11b53` records Khatri
Biryani = LIVE** with go-live evidence and backup checksums. Then the guarded
transactional tenant reset (`74d9415`), cash shortage handling (`b4ef353`) and
the two-printer go-live setup (`3816596`).

## 11–12 August — the first live trading days

Roughly fifty commits, almost all found by real counter use: thermal reports
that fit 72mm and read as columns; every report total reconciling to NET SALES;
sales returns requiring a refund method; double-counted tax on category returns;
combo KOT routing; split cancellation approvals; the one-box customer flow;
delivery rider reassignment with an audit trail; sale times shown in the
timezone they happened; print jobs surviving a momentarily busy printer;
per-request permission caching that ended the cross-tenant 403s; and tenant
identification moving ahead of the session, closing the `/login` 500s.
`6ce7357` is the chronicle of Khatri's first live trading day.

## 19–21 August — reports and finance, alongside catering

Thermal print emphasis by weight only, because enlarging broke the columns
(`786a414`, `aae508a`, `deedd7e`); order counts made DISTINCT rather than
per-product or child sums (`bec1d16`, `78d6a6f`, `8903742`); a partial return
that could not post its remaining items (`820e76c`); a supplier's opening
balance posting to the GL, not just its ledger (`52b5c85`); returns and item
voids reported by **business day**, not calendar date (`dcd1ae4`); and the
delivery-charge bridge closing BY ORDER TYPE and GLOBAL totals to NET SALES
(`719b4bb`, `24f6c29`).

## 22–24 August — canonical continues

POS drafts parked separately from holds; the Kashif legacy alignment work — the
old software's ITEM screen, Complimentary in one click, a readable bill; the
guided order punch; per-item switches; the client's own database staged and
importable; local/USB fallback print when a network print fails; printer
isolation so one offline unit stops delaying every print; Edge alignment with
canonical on product archetypes, business-date returns, draft attribution and
print routing; and a brand-new restaurant tenant onboarding command.

---

# The 15 days in numbers

| | |
|---|---|
| Window | 2026-08-08 → 2026-08-22 |
| Commits on `feat/catering-product-ux-v1` | 194 total in the window |
| — of which catering | **65** (58 prefixed `CATERING:`, plus the closure, go-live and UAT tranches and their docs) |
| Catering test files | 44 MySQL suites |
| Catering services | 25 |
| Catering controllers | 18 |
| Catering tenant migrations | 16 |
| Independent audits | 3 branches |
| Catalogue rows transcribed | 941 product rows → 888 unique items |
| Master register | 219 requirements, 25 groups |

## What the window actually delivered

A catering vertical that went from **not existing on 12 August** to a 219-row
acceptance register at 191 DONE on 22 August — with a live restaurant tenant
(Khatri) going live inside the same fifteen days and never being touched by any
of it.

## What is still open

**Code — 3:** Making Adjustment (at its design gate, being implemented now),
manual Email/Resend, and permission rows plus the Owner grant for the six new
parity routes.

**Owner data — 11:** the ~55-entry kitchen instruction vocabulary (the framework
is built and seeded empty on purpose), unit / charge / ratios / making / stock
handling for the catalogue, which 40–80 items are actually on the menu, the
numbers for the 15 representative items, and settling `EV-20260816-0001` through
the supported Refund flow on production.

None of the owner-data items is a coding task, and none of the values may be
guessed.

## The rules that held for all fifteen days

- Khatri Biryani is live and was never reset, seeded or used for a test transaction.
- No global `system:reset`, `migrate:fresh`, `db:wipe` or global TRUNCATE.
- Stock moves only through `InventoryService`; money posts only through `JournalService`.
- Nothing was deployed from a build session, and production was never mutated from one.
- No credentials in git, logs, reports or chat.
