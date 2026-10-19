# Product screen in tabs — one screen, no scrolling

Owner, 19 Oct 2026 (after step 1 of packs went live): *"acha hai lekin scroll karne ki bajaye tab switching
wala option acha hoga … same for restaurant, industry, catering … ultra smooth … high-tech solution dhoondo,
MD bana lo, aur continue karo."*

## Decision

**A "tabbed form" (non-linear stepper): one form, its sections as tabs in one row at the top, every section
already on the page.** Clicking a tab switches instantly; "Next" walks a new user through in order; Save is
always visible. Nothing is fetched or saved while switching, so no typed value can be lost and the server's
save stays exactly as it is today (one form, one Save).

Why not the alternatives:

| Option | Why not |
| --- | --- |
| Long page (today) | The owner's complaint: scrolling past 5 sections to reach Packs or Barcode. |
| Wizard (one page per step, Next only) | Edit becomes slow (to change a price you walk every step); the server would need per-step saving. Wizards suit strictly dependent steps — ours are mostly independent. |
| Accordion | Good on phones, but on a desktop it looks empty when closed and hides what is open (NN/g). |
| Vertical tabs on the side | Users overlook tabs that are not above the panel (NN/g). |

## How it behaves

1. **Tabs, one row, above the panel** (NN/g "Tabs, Used Right"): number + 1–2 word label + Urdu, e.g.
   `1 Type · 2 Details · 3 Selling & stock · 4 Packs · 5 Code · 6 Review`. The tab list is built from what this
   tenant has: **Packs** only where Master switched packs on, **Website** only with online ordering. The same
   screen and tabs serve every business — pharmacy, restaurant, catering, industry, mart; the cards inside
   "Type" are the business's own.
2. **The selected tab stands out with two signals** (gold underline + filled background, bold); the others
   stay readable.
3. **Each tab says its state**: ✓ when its required answers are there, a red dot + number when it has an
   error. Screen readers hear "(has 2 errors)" / "(done)".
4. **After a failed Save** the screen opens on the first tab with an error, and every tab with errors carries
   its count — a hidden-tab error can never be missed (the common failure of tabbed forms).
5. **Back / Next** at the bottom of every tab ("Next: Packs →"); the last tab, **Review**, lists every answer
   in plain words with a "Change" link to its tab (the GOV.UK "check your answers" pattern).
6. **Save bar always visible** (sticky at the bottom, like Shopify's save bar): Save · Save and add another ·
   Cancel. Save works from any tab.
7. **Keyboard** (W3C ARIA tabs pattern): Tab moves into the tab list, ←/→ move between tabs (switching at once,
   allowed because every panel is already on the page), Home/End jump, Tab again goes into the panel.
   **Enter never submits by accident** — a barcode scanner ends with Enter; on this screen Enter moves on, and
   **Ctrl+Enter saves**.
8. **The address remembers the tab** (`…/edit#packs`), so a reload, the browser's Back, or a link such as
   "Change packs" on the product page opens the right tab.
9. **Leaving with unsaved changes asks first.**
10. **Phones**: the tab row scrolls sideways with the current tab kept in view; the save bar stays at the bottom.
    Motion is a 150 ms fade, and none for people who ask their device for reduced motion.

## Accessibility (the "blind or deaf person" rule)

`role="tablist"` labelled "Sections of this product"; each tab `role="tab"` with `aria-selected`,
`aria-controls`, roving `tabindex`; each panel `role="tabpanel"` + `aria-labelledby`. A polite live region
announces "Step 3 of 6: Selling & stock". Errors are text, not colour alone. No sound is ever needed.

## What does not change

The fields, the yes/no questions, the picture cards, the server rules (`ProductCards::resolve`,
`PackUnits`), the URLs and the permissions. Live tenants still have the new screen switched off.

## Sources

- [NN/g — Tabs, Used Right](https://www.nngroup.com/articles/tabs-used-right/): tabs for clear groupings,
  1–2 word labels, two selection indicators, tab list above the panel, one row, fewer tabs.
- [W3C ARIA Authoring Practices — Tabs pattern](https://www.w3.org/WAI/ARIA/apg/patterns/tabs/) and its
  [automatic activation example](https://w3.org/WAI/ARIA/apg/patterns/tabs/examples/tabs-automatic):
  roles, keyboard, automatic activation only when panels are already in the page.
- Errors in hidden tabs: [Craft CMS issue 12971](https://redirect.github.com/craftcms/cms/issues/12971),
  [Pega — error indicators on tabs](https://support.pega.com/node/462976),
  [Drupal — vertical tabs with errors](https://www.drupal.org/comment/16697564).
- [Shopify app design guidelines — forms](https://shopify.dev/docs/apps/design-guidelines/user-experience/forms):
  one page per object, sections, progressive disclosure, a save bar.
- [Scottish Government design system — check answers](https://designsystem.gov.scot/patterns/check-answers)
  and [GOV.UK task list pattern](https://designnotes.blog.gov.uk/2017/04/04/weve-published-the-task-list-pattern).
- Wizard vs tabs: [Microsoft InfoPath team — tabs](https://learn.microsoft.com/en-us/archive/blogs/infopath/tabs),
  [Oracle — tabbed pages](https://docs.oracle.com/en/industries/retail/retail-supply-chain-collaboration-cloud/latest/revwu/tabbed_pages.htm).

## Round 2 — the owner's checks on a live tenant (19 Oct, afternoon)

The owner switched the new screen on for one live restaurant and tested it. What he found, and what changed:

1. **"Hidden from POS" was lost.** An item sold inside deals, as an add-on or online is *sellable* but has
   no counter button. The screen read "Do you sell it?" as "has a button", so saving such an item would
   have put it back on the counter. The tenant had 15 such items; **none was saved** with the screen on
   (checked on production). Now:
   - a separate question under "Do you sell it?": **"Show its button on the counter (POS)?"**;
   - the server keeps the saved answer when a request does not carry the question;
   - a test fails on the old rule.
2. **Every box of the old form is on the new screen, or kept untouched.**

   | Old form box | New screen |
   | --- | --- |
   | POS Visible | Selling & stock → "Show its button on the counter?" |
   | Sellable / Purchasable / Track stock / Recipe / Tax / prices / reorder | Selling & stock questions |
   | Has expiry + Requires batch | one question; a saved item where they differ keeps both |
   | Item Kind | More settings → "Used as" (follows the card until changed) |
   | Perishable, Storage type, Shelf life, Default wastage % | More settings (only the ones this business uses; pharmacy sees "Kept in") |
   | Purchase unit + pack size (recipe costing) | More settings → "For recipe cost: bought in … holding …" |
   | Product type / kind / consumption | decided by the card + answers; shown in Review |
   | Variants | kept as saved; edited on the product page as before |
   | Manufacturing BOM boxes | decided by the industry cards; kept as saved otherwise |

   A box that is not shown is never cleared: the server reads only what the screen posts.
3. **Review shows everything:**
   - the counter button;
   - **"In the old screen"** — POS Sale Item / Recipe / Kitchen Item / Ingredient / Packing / Service Item;
   - **"In the system"** — e.g. "Service · service, no stock · no stock moves";
   - every More-settings value.
4. **The products list speaks the same words** (only where the new screen is on; other tenants see the old
   list):
   - "What it is · where it shows";
   - kinds in the business's words, e.g. "Service — sold, no stock (delivery, decoration)";
   - "Has a button on the counter" / "No button on the counter";
   - each row's card name, with "old name: …" under it;
   - badges "On the counter / Sold, no counter button / Not sold — used inside / Stock counted / Bought / Recipe";
   - the filters underneath are unchanged.

## Next: purchasing (owner's direction, 19 Oct)

PO and GRN move to the catering event screen's pattern:
- one entry area on top: scan or search an item, and its own settings appear (Box | Strip | Tab, batch,
  expiry, bonus, price);
- Enter moves on, Ctrl+Enter puts the line in the grid, and a grid line opens back for editing;
- the same for every business.

"Internet connected" is read as one order page from PO to payment, with the supplier confirming by a
WhatsApp link — **to be confirmed by the owner**.

Tentative boards (no code yet): https://claude.ai/artifact/LBfacrEPnNJLCHki3K8chw
