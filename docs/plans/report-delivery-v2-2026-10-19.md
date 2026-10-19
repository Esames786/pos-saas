# REPORT-DELIVERY-V2-1 — one "Daily report delivery" card, sent when the day's last shift closes

Date: 2026-10-19 · Branch: `feat/report-delivery-v2-20261019` · Design: https://claude.ai/artifact/1KnRxgdF85KcALYnhKfrNW (approved by owner)

## Problem (owner's words)
"Cron alag schedule hoti hai, WhatsApp ka checkbox alag hai, number daalna mushkil, email bhi." The owner could
not see in one place which service is on, what it costs, and when the report goes.

Found while building: Kashif Food's last counter closes between **01:17 and 02:58 PKT** (10 nights read from prod),
but its report left at a fixed **02:30** — on late nights (10 Oct 02:39, 17 Oct 02:58) the report missed the last
sales of the day.

## What changes
- **Report Center**: the "Report delivery" box and the "Scheduled sales reports" table become ONE card
  (no new screen, no new route): 1) How — WhatsApp / Email cards with on/off switch and price (Rs/message from
  Master billing rate; Email free), numbers with names checked as typed, emails as chips, monthly estimate;
  2) When — "the day's last shift closes" (recommended, with a safety time) or a fixed time; weekly / monthly
  summary switches; 3) What — Short / Full / Choose myself. Test sends, recent deliveries with per-number result.
- **Shift close** (single close and Close Branch): when no shift of that business day is still open, the daily
  report is sent — after the response, never blocking or failing the close.
- Behind Master switch **"New daily report delivery"** (`features.report_delivery_v2`), OFF for every tenant.

## Impact (from the system map)
| Table | Change | Read/written by |
|---|---|---|
| `report_schedules` (tenant) | + `send_trigger` varchar(20) default `time` | Report Center, POS Quick Report (reads recipients — untouched), nightly `reports:dispatch-scheduled` |
| `report_schedule_runs` (tenant) | + `via` varchar(20) nullable | ReportScheduleService (claim per channel, unchanged key) |
| `tenants` (master) | + `report_whatsapp_names` json nullable | only the new card |
| `shifts` | read only (open shifts of a business date) | ShiftController close / closeBranch |
| `whatsapp_messages` (master) | read only (delivery history) | — |

Screens: `tenant.reports.center.index` (card swap only when switch on AND user has `tenant.reports.center.channels`),
`tenant.reports.center.channels` (same route/permission, `v2=1` payload), `tenant.shifts.close`,
`tenant.shifts.close-branch`. No new route, no new permission, no queue.

## Safety
- Switch off → exact old cards and old fixed-time behaviour. Roles without the channels permission keep the old
  schedules card even when the switch is on.
- Same per-channel period key (business date) for both triggers → a day can never be sent twice.
- A failed shift-close send frees its claim → the safety-net time sends it.
- `via` is written only if the column exists (deploy pulls code before tenant migrations).

## Tests
`ReportDeliveryV2MySqlTest` (9): card visibility per switch/role + inline JS parses; save pairs names with
numbers; refused saves change nothing; weekly/monthly pause not delete; last shift close sends once on both
services (safety-net run then skips); Close Branch path; switch-off / fixed-time tenants untouched; a failing send
never fails the close; timed run records `via`. 8 sabotages, each turned the right test red.

## Kashif Food after deploy (owner asked)
Switch ON; daily schedule #1 → `send_trigger=shift_close`, safety time 06:00 PKT (after the latest close seen,
02:58); email + WhatsApp both stay on with the same recipients and sections.
