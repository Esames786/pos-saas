# Printing without the dialog (and without choosing A4/A5 each time)

Audience: whoever sets up the counter PC at Kashif Kitchen.
Date: 2026-09-27

## What cannot be done

No web page can print without showing the print dialog. `window.print()` always opens it, in every
browser, on purpose — a page that could print silently could also waste a ream of paper without
the user knowing. There is no setting in our software that changes this.

What *can* be done is tell **the browser** to skip the dialog. That is one switch on the Chrome
shortcut, set once per PC.

## Step 1 — Chrome prints without asking

Right-click the Chrome shortcut the staff use → **Properties** → **Target**, and add
`--kiosk-printing` at the end, outside the quotes:

```
"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk-printing
```

Close every Chrome window and reopen it **from that shortcut** (the flag only applies to a freshly
started Chrome — if a Chrome process is already running, the new window joins the old one and the
flag is ignored).

From then on, pressing **Print** sends the job straight to the Windows default printer. No dialog,
no preview.

On Edge it is the same flag: `msedge.exe --kiosk-printing`.

To undo it, remove the flag from the shortcut.

### Or: one file on the desktop

Editing a shortcut is easy to get wrong, and staff can still open Chrome the ordinary way and lose
the flag. Saving this as **`Kashif Kitchen.bat`** on the desktop and telling everyone to open the
system from that icon avoids both:

```bat
@echo off
REM Kashif Kitchen — Chrome jo bina dialog ke chhapta hai.
REM Pehle mojooda Chrome band karna zaroori hai: agar Chrome pehle se chal raha ho
REM to naya window usi purane process se jurta hai aur ye switch bekaar ho jata hai.
taskkill /IM chrome.exe /F >nul 2>&1
start "" "C:\Program Files\Google\Chrome\Application\chrome.exe" ^
  --kiosk-printing ^
  "https://kashifkitchen.bingoopos.com/catering/events"
```

The `taskkill` line is the part people miss. Without it the flag silently does nothing whenever a
Chrome window happens to be open already, and the dialog keeps appearing for no visible reason.

## Step 2 — the printer picks the right paper by itself

The software already tells the printer which paper each document needs:

| Document | Paper it declares |
| --- | --- |
| Kitchen sheet | A5 portrait |
| Quotation / estimate | A4 portrait |
| Final invoice | A4 portrait |
| Address sheet | A4 portrait |

(The first two come from **Catering Settings**, so they can be changed there.)

For the HP to act on that instead of using one fixed tray:

1. **Windows** → Settings → Printers → the HP → *Printing preferences* → **Paper Source** =
   **Automatically Select** (some drivers call it *Auto Select* or *Printer auto select*).
2. **On the printer's own control panel**, tell it what is in each tray — the upper tray **A5**,
   the lower tray **A4**. The driver can only match a size it knows a tray holds.

With both done, a kitchen sheet goes to the A5 tray and a quotation to the A4 tray, with nobody
choosing anything.

If the printer will not accept "A5" for the upper tray, define a **custom size of 148 × 210 mm**
there instead — that is exactly what half an A4 sheet is.

## If the paper still comes out wrong

Check in this order:

1. Was Chrome restarted **from the edited shortcut**, with no other Chrome window already open?
2. Is *Paper Source* really **Automatically Select**, not a fixed tray?
3. Does the printer's control panel list the right size for each tray?
4. Print one kitchen sheet with the flag **removed** and look at what the dialog preselects. If the
   dialog itself shows A5, the document is fine and the problem is in the driver or the tray
   configuration, not in our software.

Step 4 is the one worth doing first when something looks wrong — it separates "the page is asking
for the wrong paper" from "the printer is ignoring what the page asked for", and those have
completely different fixes.

## Why we do not print through the Print Agent instead

The Print Agent installed for KOTs and receipts speaks raw ESC/POS over TCP port 9100. That is a
thermal-printer protocol: it cannot render an A5 page, and it cannot render Nastaliq. Driving the
HP would mean teaching the agent to render HTML to PDF and spool it through the Windows driver —
a real piece of work, not a setting. The Chrome flag above gives the same result today.
