# Edge physical printer certification plan (EDGE-LOCAL-PRINT-1 §15 — GROUNDING ONLY)

**Status: NOT certified.** FakePrinter proves TRANSPORT correctness (exact stored bytes over TCP,
lease/FIFO/backoff semantics) — it proves NOTHING about a physical thermal printer. No ESC/POS
cut/codepage/capability commands may be added until a real printer requirement is grounded here.
Current payload reality (pinned): `raw_payload` is plain 42-column ASCII text with trailing `\n\n\n`;
the transport appends the same `\n\n\n` the Cloud agent appends.

## Deterministic procedure for the first real-hardware session
Record for EVERY step: printer make/model/firmware, paper width, interface, exact repo HEAD.

1. **Reachability**: printer on the branch LAN, static/reserved IP; `Test-NetConnection <ip> -Port 9100`
   accepts; document DHCP reservation.
2. **Baseline byte acceptance**: send a known stored `raw_payload` via the worker (`--once`); paper
   output legible? Feed length acceptable with the double trailing feed? (If double feed wastes paper,
   THAT is the grounded requirement that may change `TRAILING_FEED` — not before.)
3. **Width**: 80mm and 58mm papers vs the hardcoded 42-column layout — record truncation/wrap.
4. **Text/codepage**: item names with the tenant's real character set (Urdu/Arabic/latin-extended) —
   record mojibake; THIS grounds any future codepage/ESC-POS work.
5. **Long content**: 30+ char item names, qty > 9.99, 20+ line orders — wrap/clip behavior.
6. **Business documents in order**: original KOT → Add Round (delta KOT) → CANCEL KOT → receipt —
   verify kitchen-readable headings (`*** KOT #1 ***`, `*** ADDITION KOT #2 ***`, `*** CANCEL ... ***`)
   and per-printer FIFO on paper.
7. **Paper-out mid-job**: remove paper during a send — what does the printer do with buffered bytes on
   reload? Does the socket still complete (would have marked printed with no paper)? Record honestly —
   this is the known at-least-once/no-paper-sensor limitation over raw 9100.
8. **Offline/recovery**: unplug LAN → verify temporary-failure backoff ([5,15,30,60,120]s) on the
   appliance; replug before terminal → job prints; drive to terminal_failed → operator retry prints.
9. **Printer power-cycle** mid-queue: FIFO order on paper after recovery.
10. **Appliance reboot** with a queued backlog: Scheduled Task auto-start → backlog drains in order.
11. **Duplicate-on-ack-loss**: kill the worker process after the socket send, before markPrinted →
    lease expiry → redelivery → TWO physical copies expected BY DESIGN; verify the kitchen-operator
    guidance ("identical KOT # = duplicate, keep one") is workable.
12. Record every deviation as a grounded requirement for the payload/capability sprint.
