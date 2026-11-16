# SLIP-TOP-GAP-1: blank paper on top of every thermal slip (16 Nov 2026)

## The owner's ask
On 16 Nov the owner sent a photo of a Kashif Food reminder (HS-20261116152430-206, T4 counter printer). It had about 2.4 cm of
blank paper above the first line. He asked: "Itna gap kyun? Fix karo to kya hoga?" Then: "Hata do, saray tenants pe, bina
kisi switch ke." He will run the new setup himself, and it must not ask for a new pairing code.

## Cause (from prod bytes, read-only)
- Reminder job 200348 printed on printer #4 at 15:24:32 UTC. The job just before it on the same printer was KOT 200344, at
  15:24:31. That KOT's bytes end with `… 2D 0A 1D 56 42 00`, i.e. `GS V 66 0`: feed the paper to the cutter, then cut.
- `tools/print-agent/print-agent.js` `sendToNetworkPrinter` then wrote `'\n\n\n'` **after** the payload. That line has been
  there since the first agent (`b26b360d`, 20 May). Feeds sent after a cut cannot reach the slip that was just cut, so they
  became blank paper on top of the **next** slip.
- So the top gap is the printer's head-to-cutter distance (hardware, ~1–1.3 cm, cannot be removed) plus 3 lines
  (~1.1–1.3 cm). This happened on every slip (KOT, bill, reminder) at every tenant.
- The reminder also had 3 blank lines of its own before its cut (`buildReminder`). The KOT and bill never had them.
- Kashif Food prints about 17.3k thermal slips a week (KOT 6,875 / bill 6,174 / reminder 4,208).

## Impact (system map)
- **Tables:** none. No migration, no data.
- **Screens:** Printing › Print Agents. The download shelf's newest build becomes **2.7.1**, the default download for every
  tenant.
- **Prints:** the bytes on the wire for every network thermal job. The reminder's own end changes (last line, then the cut).
- **Tests:** `tools/print-agent/test/slip-top-gap-test.js` (new); `exe-thermal-test.js` (asserts exact bytes);
  `tests/Unit/Printing/EscPosReminderPayloadTest.php` (new test).

## Change
1. **Agent 2.7.1.** `trailingFeedFor(payload)` returns `''` when the ticket ends with a cut (`GS V` in any form, or `ESC i` /
   `ESC m`); otherwise it returns the old `'\n\n\n'`. A ticket without a cut (e.g. the `ESC @` wake-up job) keeps the 3 lines,
   so its last line still comes out of the printer. Only a cut at the very end counts.
2. **Reminder.** `EscPosPayloadService::buildReminder` now ends with `rule . "\n" . CUT`, like the KOT.
3. Version 2.7.1 in `print-agent.js`, `.iss` and `package.json`. Setup built and placed on the shelf:
   `dist/BingooPrintAgent-Setup.exe` = `dist/releases/BingooPrintAgent-Setup-2.7.1.exe`, byte-identical (sha256 `658b3058…`).
4. Test harness only: the exe tests run with their own empty `LOCALAPPDATA`. This laptop now runs a per-user 2.7.0 agent whose
   lock file stopped the test copy; `BINGOO_AGENT_ALLOW_OTHER` only skips the process scan, not the lock.

No Master switch. The server half reaches every tenant on deploy. The agent half reaches each PC when its agent is upgraded.

## Results
- **Agent JS:** all source tests green (autostart, document, elevation, health, isolation, lane-lock, prepare, slip-top-gap,
  version-match).
- **Exe tests (the 2.7.1 binary itself):**
  - thermal: the printer received exactly the ticket and nothing after the cut;
  - document e2e: green.
- **PHP:**
  - reminder unit test green;
  - 10 MySQL files that print reminders: **54/54 OK**;
  - PrintAgentDownloadTruth + PrintPrepare: **15/15 OK**.
- **Sabotage (each restored with `git checkout`), all RED:**
  - A: the 3 lines back under the reminder;
  - B: agent always sends `\n\n\n`;
  - C: a cut anywhere, not only at the end, counts.
- **Red before this work too (not touched by it), on the unchanged prod code:**
  - `EscPosReportPayloadTest` (two decimals);
  - `AgentDownloadIsVersionedRegressionTest`: 3 red on prod code, 2 red with the 2.7.1 shelf. It is a source-text test that
    is stale since AGENT-UPGRADE-1.

## Upgrade proof on this laptop (2.7.0 per-user → 2.7.1)
- Silent run of the built setup, `/LOG`: "Installation process succeeded", **no pairing step**, "Agent running after
  install: 1".
- `config.json` sha256 unchanged (the pairing to demo `AG-DEMO-LOCAL` was kept).
- Old agent PID 20472 and watchdog 9600 are gone. Now one agent (63764) and one watchdog (55704).
- The installed exe is byte-identical to the 2.7.1 build. Its log shows it polling demo.
- The 2.6.x → 2.7.x path is the same installer code that ran at Kashif Kitchen on 12 Nov:
  1. the old pairing is copied first;
  2. the old machine-wide agent is removed with **one** Windows admin "Yes";
  3. then the new agent is installed and started.

## Rollout, per PC (owner)
- Use Printing › Print Agents › the normal **Download** button. Do **not** use "Create Agent": that download carries a new
  pairing code and makes a new agent record.
- Run the setup and click Next.
- On a 2.6.x PC, Windows asks for admin permission **once**. Click Yes (or enter the admin password).
- The old agent stops, the new one starts by itself, and the pairing stays.
- From then on, every slip on that PC's printers has ~1.2 cm less blank paper on top.

## Done on prod (16 Nov, ~16:25 UTC / 21:25 PKT)
- Merge `3adc2430` (prod `fbb811a5` → `3adc2430`). `git diff` between the tested tree and the merge was empty; origin == HEAD
  before deploy. `deploy.sh` exited 0.
- Checks:
  - PERMISSIONS OK;
  - ACCOUNTS OK (every chart unchanged);
  - smoke 13/13 identical to before (tb_diff 0, no negatives);
  - log ERROR count 10 → 10. All 10 are the known Kashif Kitchen `jobs`-table catering mail error (open item), none new.
- Live proof:
  - **Shelf:** newest = **2.7.1** (builds 2.7.1 / 2.6.6 / 2.6.5); `Setup.exe` and `releases/…-2.7.1.exe` sha256 `658b3058…`.
  - **The photo's reminder (job 200348) rebuilt by the live code:** it ends `2D 2D 2D 2D 2D 0A 1D 56 42 00` (last line,
    then the cut). The stored copy from before the fix ends `… 0A 0A 0A 1D 56 42 00`.
  - **Demo agent `AG-DEMO-LOCAL`** (this laptop, upgraded 2.7.0 → 2.7.1 without pairing) reports `agent_version 2.7.1`.
- **Next is the owner:** run the 2.7.1 setup on each restaurant PC (normal Download button, see Rollout). 2.6.x PCs do not
  report a version; after the upgrade each one shows `2.7.1` on Print Agents.
