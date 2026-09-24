// W7 — Edge cashier BROWSER proof (JS-executing), owner directive 20 Sep 2026 after the 149-record audit.
//
// Why: every Edge control is JS-rendered and no PHP test executes JavaScript (audit E-15). This script drives the real
// page in a real browser (the locally installed Microsoft Edge / Chrome via Playwright's channel — no download), logs in
// with the operator's Edge credential, and records:
//   1. the DOM census — which Online-control counterparts from tests/Fixtures/edge/online-pos-control-census.json
//      actually exist in the live DOM after the page booted (present / equivalent / partial rows), incl. inside the
//      dialogs it opens (View Tables, Shift, Returns, Quick Report, Recent Prints, Review & Pay, Preview Bill);
//   2. paired-viewport screenshots (1366×768 desktop, 1024×768 tablet, 800×600 small) of the main page and each dialog;
//   3. a JSON report with counts and denominators (never a percentage), every server refusal the page received, every
//      toast, and every step that could not be completed (with the reason) — silence is never reported as success.
//
// Read-only by default. With --allow-mutations (dev instance / LAB data ONLY, never a live tenant) it also walks the
// deeper operator flows that create disposable local records — open the shift, open a table, Hold on the table (a held
// check → KOT / Split / Cancel dialogs), a delivery-mode Review & Pay, the Quick Sale prompt, a manual discount that
// summons the manager prompt, a reservation, a book customer with saved addresses — and censuses/screenshots each.
// It NEVER completes a payment and never prints.
//
// Selectors: the parity work (W1–W5) adopted the Online element ids; the pre-parity Edge ids are kept as fallbacks so
// the same tool runs against the installed 0.6.0-edge LAB page and the parity tree.
//
// Usage (secrets NEVER on the command line — the credential is read from an environment variable):
//   set EDGE_PROOF_PASS_ENV=EDGE_LAB_CASHIER_PASS   (name of the variable holding the password)
//   node edge-pos-proof.mjs --base-url https://desktop-0024epm.local:8443 --user LAB2C5D [--ignore-tls] [--channel msedge|chrome] [--allow-mutations]
//
// Output: ./evidence/<timestamp>/…png + report.json
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const argv = process.argv.slice(2);
const args = {};
for (let i = 0; i < argv.length; i++) {
  if (!argv[i].startsWith('--')) continue;
  const key = argv[i].slice(2);
  const next = argv[i + 1];
  if (next && !next.startsWith('--')) { args[key] = next; i++; } else { args[key] = true; }
}
const baseUrl = String(args['base-url'] || '').replace(/\/$/, '');
const user = String(args.user || '');
const passEnv = process.env.EDGE_PROOF_PASS_ENV || 'EDGE_PROOF_PASS';
const pass = process.env[passEnv] || '';
const channel = String(args.channel || 'msedge');
const allowMutations = args['allow-mutations'] === true;
if (!baseUrl || !user || !pass) {
  console.error('need --base-url, --user and the password in the env var named by EDGE_PROOF_PASS_ENV (default EDGE_PROOF_PASS)');
  process.exit(2);
}

const census = JSON.parse(fs.readFileSync(path.resolve(here, '../../tests/Fixtures/edge/online-pos-control-census.json'), 'utf8'));
const rows = {};
for (const [group, g] of Object.entries(census.groups)) {
  for (const id of g.ids) {
    const row = { group, state: g.state, edge: g.edge || [], record: g.record, ...(g.overrides?.[id] || {}) };
    if (['planned', 'online_required'].includes(row.state) && !(g.overrides?.[id] && 'edge' in g.overrides[id])) row.edge = [];
    rows[id] = row;
  }
}
const idSelectors = new Set();
for (const row of Object.values(rows)) for (const sel of row.edge) if (sel.startsWith('#')) idSelectors.add(sel.slice(1));

// ── selector aliases (Online id first, pre-parity Edge id as fallback) ─────────────────────────────────────────
const TILES = '#product-grid .tile, #tiles .tile';
const SEL = {
  returns: '#pos-return-btn, #returns-btn',
  quickReport: '#pos-quick-report-btn, #quick-report-btn',
  recentPrints: '#last-print-btn, #recent-prints-btn',
  shift: '#pos-shift-open-link, #shift-btn',
  shiftOpen: '#sh-open, #open-shift-submit',
  shiftClose: '#sh-close, #close-shift-submit',
  shiftErr: '#sh-err',
  // W3 Table Workspace: each tile carries its own action buttons ([data-tile-act]); the pre-parity board needed a tile click first
  // (a selector LIST resolves in DOM order — the legacy tile container would win over its own button, so it is a separate fallback)
  freeTable: '#table-board-body .tbl.available [data-tile-act="open"], #modal .tbl.available [data-tile-act="open"]',
  freeTileLegacy: '#modal .tbl.available',
  reservedTable: '#table-board-body .tbl.reserved [data-tile-act="reservation"], #modal .tbl.reserved [data-tile-act="reservation"]',
  reservedTileLegacy: '#modal .tbl.reserved',
  openTable: '#open-table-submit, #ta-open',
  workspaceBack: '#table-workspace-back, [data-table-workspace-home]',
  reserveToggle: '#table-board-body .tbl.available [data-tile-act="reserve"], #modal .tbl.available [data-tile-act="reserve"], #ta-reserve-toggle',
  reserveName: '#reserve-name, #rs-name',
  reserveNote: '#reserve-note, #rs-note',
  reserveSave: '#reserve-save-btn, #ta-reserve',
  discType: '#manual-discount-type, #cm-disc-type',
  discValue: '#manual-discount-value, #cm-disc-value',
  discApply: '#apply-discount-btn, #cm-apply',
  complete: '#complete-sale-btn, #rp-complete',
  custSearch: '#cust-search-input, #cm-cust-q',
  custResults: '#cust-search-results [data-cid], #cm-cust-results [data-cid], #cust-search-results .list-row, #cust-search-results button',
  custPicked: '#sel-cust-name, #cm-cust-picked',
  addrPick: '#cust-address-list, #cm-addr-pick',
  managerCred: '#ma-cred, #swal-manager-pin',
  managerCancel: '#ma-cancel, .swal2-cancel',
  rpErr: '#rp-err, #manual-discount-feedback',
  qsVehicle: '#qs-vehicle, #vehicle_number',
  qsCancel: '#qs-cancel',
  kotBtn: '#kot-btn',
  leaveCheck: '#leave-check-btn, #start-fresh-btn',
};

const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const outDir = path.join(here, 'evidence', stamp);
fs.mkdirSync(outDir, { recursive: true });
const viewports = [{ name: 'desktop-1366x768', width: 1366, height: 768 }, { name: 'tablet-1024x768', width: 1024, height: 768 }, { name: 'small-800x600', width: 800, height: 600 }];
const report = { base_url: baseUrl, user, allow_mutations: allowMutations, started_at: new Date().toISOString(), viewports: {}, dom_census: {}, steps: {}, toasts: [], refusals: [], console_errors: [], skipped: [] };

const browser = await chromium.launch({ channel, headless: true });
try {
  const context = await browser.newContext({ ignoreHTTPSErrors: !!args['ignore-tls'], viewport: viewports[0] });
  const page = await context.newPage();
  page.on('console', m => { if (m.type() === 'error') report.console_errors.push(m.text()); });
  page.on('pageerror', e => report.console_errors.push('pageerror: ' + e.message));
  page.on('dialog', d => d.accept()); // a native confirm() — always accept in the proof
  page.on('response', async res => { // every server refusal the page received, with its business message
    const st = res.status();
    if (st < 400 || !res.url().includes('/edge/local/')) return;
    let body = null;
    try { body = (await res.text()).slice(0, 400); } catch { /* body already consumed / navigation */ }
    report.refusals.push({ url: res.url().replace(baseUrl, ''), status: st, body });
  });

  const posUrl = baseUrl + '/edge/local/pos';
  const seen = new Set();
  const first = (sel) => page.locator(sel).first();
  const has = async (sel) => (await page.locator(sel).count()) > 0;
  const visible = async (sel) => (await has(sel)) && (await first(sel).isVisible().catch(() => false));
  const censusNow = async (label, extra = {}) => {
    const found = await page.evaluate(ids => ids.filter(id => document.getElementById(id) !== null), [...idSelectors]);
    found.forEach(id => seen.add(id));
    report.steps[label] = { ...(report.steps[label] || {}), dom_ids_found: found.length, ...extra };
    return found;
  };
  const shot = (name) => page.screenshot({ path: path.join(outDir, `${name}.png`), fullPage: false });
  const heading = () => page.locator('#modal-body h2, .edge-dialog-box h2, .modal-title').first().textContent().catch(() => null);
  const toastText = async () => { // the page toasts every server refusal — capture whatever is showing (in-page or SweetAlert2)
    const candidates = ['#toast', '.swal2-toast .swal2-title', '.swal2-toast .swal2-html-container'];
    for (const c of candidates) {
      const t = page.locator(c);
      if (await t.count() === 0) continue;
      const shown = await t.first().evaluate(el => (el.style.display === 'block' || el.offsetParent !== null) && el.textContent.trim() !== '').catch(() => false);
      if (!shown) continue;
      const text = (await t.first().textContent()).trim();
      // the page keeps a toast on screen for ~3 s — two censuses inside that window read the SAME toast, not a second event
      const last = report.toasts[report.toasts.length - 1];
      if (!(last && last.text === text && Date.now() - last.at < 4000)) report.toasts.push({ text, at: Date.now() });
      return text;
    }
    return null;
  };
  const modalOpen = () => page.locator('#modal.open, .edge-dialog.open, .modal.show').count().then(n => n > 0);
  const closeModal = async () => {
    await page.evaluate(() => { if (window.EdgePOS && window.EdgePOS.closeModal) window.EdgePOS.closeModal(); document.querySelectorAll('.edge-dialog.open').forEach(d => d.classList.remove('open')); });
    if (await has('.swal2-cancel') && await visible('.swal2-cancel')) await first('.swal2-cancel').click().catch(() => {});
    await page.waitForTimeout(150);
  };
  const acceptConfirm = async () => { // an in-page / SweetAlert2 confirm (Team 1's confirmDialog) — accept it when it appears
    for (let i = 0; i < 12; i++) {
      if (await visible('.swal2-confirm')) { await first('.swal2-confirm').click(); return true; }
      if (await visible('#edge-confirm-ok')) { await first('#edge-confirm-ok').click(); return true; }
      await page.waitForTimeout(100);
    }
    return false;
  };
  const resetPage = async () => { // a clean cart between deep steps
    await page.goto(posUrl, { waitUntil: 'networkidle' });
    await page.waitForSelector(TILES + ', #product-grid p, #tiles p', { timeout: 15000 });
  };
  const step = async (label, fn) => {
    try { await fn(); } catch (e) { const msg = String(e.message || e).split('\n')[0]; report.steps[label] = { ...(report.steps[label] || {}), error: msg }; report.skipped.push(`${label}: ${msg}`); }
  };
  const firstVisible = async (sel) => { // among alias matches, the one the operator can actually see
    const loc = page.locator(sel);
    const n = await loc.count();
    for (let i = 0; i < n; i++) { if (await loc.nth(i).isVisible().catch(() => false)) return loc.nth(i); }
    return null;
  };
  const openDialog = async (label, trigger) => {
    if (!(await has(trigger))) { report.steps[label] = { trigger_missing: trigger }; return false; }
    if (await modalOpen()) await closeModal();
    const el = await firstVisible(trigger);
    if (!el) { report.steps[label] = { trigger_hidden: trigger }; return false; }
    await el.click();
    await page.waitForSelector('#modal.open, .edge-dialog.open, .modal.show', { timeout: 10000 }).catch(() => {});
    await page.waitForTimeout(600);
    await censusNow(label, { heading: await heading(), toast: await toastText() });
    await shot(`dialog-${label}`);
    return true;
  };
  const addFirstTile = async () => {
    if (await modalOpen()) await closeModal();
    await first(TILES).click();
    await page.waitForTimeout(250);
    // a qty / variant / modifier prompt (W2) may open for the first tile — confirm it with its defaults
    for (const confirmSel of ['#qty-modal-confirm', '#modifier-modal-confirm', '#variant-modal-confirm']) {
      if (await visible(confirmSel)) { await first(confirmSel).click(); await page.waitForTimeout(250); }
    }
  };
  const setOrderType = async (type) => {
    const tab = page.locator(`[data-mode-tab="${type}"]`);
    if (await tab.count()) { // W1: Online-style tabs; switching with a cart asks "Start a fresh … order?"
      await tab.first().click();
      await acceptConfirm();
      await page.waitForTimeout(250);
      return (await page.locator('#order-type').inputValue().catch(() => '')) === type;
    }
    if (!(await has(`#order-type option[value=${type}]`))) return false;
    if (await page.locator('#order-type').isDisabled()) return false;
    await page.selectOption('#order-type', type);
    return true;
  };
  const waitSettled = async (doneWhen) => { // the built-in dev server answers one request at a time — poll for a modal close / toast / condition
    for (let i = 0; i < 40; i++) {
      if (await doneWhen()) return true;
      if (await page.locator('#toast').evaluate(el => el.style.display === 'block').catch(() => false)) return false;
      await page.waitForTimeout(250);
    }
    return false;
  };

  // ── login — the Edge local login form (employee code + credential) ──────────────────────────────────────
  await page.goto(baseUrl + '/edge/local/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name=employee_code]', user);
  await page.fill('input[type=password]', pass);
  await page.click('button[type=submit]');
  await page.waitForURL(/edge\/local\/(pos|status)/, { timeout: 15000 }).catch(() => {});
  await resetPage();

  // ── main page at each viewport ──────────────────────────────────────────────────────────────────────────
  for (const vp of viewports) {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    await page.waitForTimeout(250);
    const hasHorizontalScroll = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    const tile = await first(TILES).boundingBox().catch(() => null);
    const tab = await page.locator('[data-mode-tab], #parent-category-strip .pill, #category-tabs .pill').first().boundingBox().catch(() => null);
    report.viewports[vp.name] = { horizontal_overflow: hasHorizontalScroll, first_tile_px: tile ? { w: Math.round(tile.width), h: Math.round(tile.height) } : null, first_tab_px: tab ? { w: Math.round(tab.width), h: Math.round(tab.height) } : null };
    await shot(`pos-main-${vp.name}`);
  }
  await page.setViewportSize(viewports[0]);
  await censusNow('main', { order_type_default: await page.locator('#order-type').inputValue().catch(() => null), terminal: await page.locator('#ctx-terminal-name, #terminal option:checked').first().textContent().catch(() => null) });

  // ── read-only dialogs ───────────────────────────────────────────────────────────────────────────────────
  for (const [label, trigger] of [['view-tables', '#view-tables-btn'], ['shift', SEL.shift], ['returns', SEL.returns], ['quick-report', SEL.quickReport], ['recent-prints', SEL.recentPrints], ['held-orders', '#held-orders-btn'], ['completed-orders', '#completed-orders-btn'], ['customer', '#pos-customer-btn'], ['context', '#pos-context-btn, [data-open-dialog="posContextModal"]']]) {
    await step(label, async () => { if (await openDialog(label, trigger)) await closeModal(); });
  }
  // the open-table form and the reserve form are reachable without mutating anything (nothing is submitted here)
  await step('table-actions', async () => {
    if (!(await openDialog('view-tables-select', '#view-tables-btn'))) return;
    const openBtn = (await firstVisible(SEL.freeTable)) || (await firstVisible(SEL.freeTileLegacy));
    report.steps['table-actions'] = { free_table_matches: await page.locator(SEL.freeTable).count(), visible: !!openBtn };
    if (!openBtn) { report.skipped.push('table-actions: no visible free-table Open control on the board'); await closeModal(); return; }
    await openBtn.click(); // "Open Table" on a free tile → the open-table form (not submitted)
    await page.waitForSelector('#open-table-form, #ta-open', { timeout: 5000 }).catch(() => {});
    await censusNow('open-table-form', { form_visible: await visible('#open-table-form, #ta-open') });
    await shot('dialog-open-table-form');
    const back = await firstVisible(SEL.workspaceBack);
    if (back) { await back.click(); await page.waitForTimeout(300); }
    const reserveBtn = await firstVisible(SEL.reserveToggle);
    if (reserveBtn) {
      await reserveBtn.click();
      await page.waitForSelector('#reserveTableModal, #reserve-name, #rs-name', { timeout: 5000 }).catch(() => {});
      await censusNow('reserve-form', { form_visible: await visible('#reserveTableModal, #reserve-name, #rs-name') });
      await shot('dialog-reserve-form');
    } else report.skipped.push('table-actions: no visible Reserve control');
    await closeModal();
  });
  // W2 prompts that open without any server write: weighted quantity entry and options (modifiers)
  await step('qty-and-options', async () => {
    const weighted = page.locator(TILES).filter({ hasText: /per kg|\/kg|kg\)/i }).first();
    if (await weighted.count()) {
      if (await modalOpen()) await closeModal();
      await weighted.click();
      await page.waitForSelector('#qtyEntryModal, #qty-modal-input', { timeout: 5000 }).catch(() => {});
      await censusNow('qty-entry', { opened: await visible('#qtyEntryModal, #qty-modal-input') });
      await shot('dialog-qty-entry');
      await closeModal();
    } else report.skipped.push('qty-entry: no weighted item on the grid');
    const optioned = page.locator(TILES).filter({ hasText: /Chicken Tikka|Malai Boti|Seekh/i }).first();
    if (await optioned.count()) {
      if (await modalOpen()) await closeModal();
      await optioned.click();
      await page.waitForSelector('#modifierEntryModal, #modifier-modal-groups', { timeout: 5000 }).catch(() => {});
      await censusNow('modifier-entry', { opened: await visible('#modifierEntryModal, #modifier-modal-groups') });
      await shot('dialog-modifier-entry');
      await closeModal();
    } else report.skipped.push('modifier-entry: no item with options on the grid');
    await resetPage();
  });
  // Preview Bill + Review & Pay need a cart line: add the first tile (no sale is made)
  await step('preview-and-pay', async () => {
    await addFirstTile();
    for (const [label, trigger] of [['preview-bill', '#preview-bill-btn'], ['review-pay', '#review-pay-btn']]) {
      if (await openDialog(label, trigger)) await closeModal();
    }
    await resetPage();
    if (await setOrderType('delivery')) { // delivery-mode: the delivery panel (W2: main pane) + Review & Pay (still no sale)
      await censusNow('delivery-mode', { delivery_panel_visible: await visible('#delivery-panel') });
      await shot('pos-delivery-mode');
      await addFirstTile();
      if (await openDialog('review-pay-delivery', '#review-pay-btn')) await closeModal();
    } else report.skipped.push('review-pay-delivery: operator has no delivery order type');
  });

  // ── deeper flows that create disposable local records (dev instance / LAB only) ─────────────────────────
  if (allowMutations) {
    await step('shift-open', async () => { // every sale/table action needs an open shift on the selected terminal
      await resetPage();
      if (!(await openDialog('shift-before', SEL.shift))) return;
      if (await has(SEL.shiftOpen)) {
        if (await has('#opening_cash')) await first('#opening_cash').fill('0');
        await first(SEL.shiftOpen).click();
        await waitSettled(async () => !(await modalOpen()) || ((await first(SEL.shiftErr).textContent().catch(() => '')).trim() !== ''));
        report.steps['shift-open'] = { opened: !(await modalOpen()), inline_error: (await first(SEL.shiftErr).textContent().catch(() => '')).trim() || null, toast: await toastText() };
      } else report.steps['shift-open'] = { opened: false, already_open: await has(SEL.shiftClose) };
      if (await modalOpen()) await closeModal();
    });

    await step('quick-sale-prompt', async () => { // quick sale attribution: vehicle + waiter (inline W2 row or the prompt)
      await resetPage();
      if (!(await setOrderType('quick_sale'))) { report.skipped.push('quick-sale-prompt: operator has no quick_sale order type'); return; }
      await addFirstTile();
      if (await visible('#vehicle-wrap')) { // W2 renders the Online inline fields; nothing to hold
        await censusNow('quick-sale-fields', { inline: true });
        await shot('pos-quick-sale-fields');
        return;
      }
      await first('#hold-sale-btn').click();
      await page.waitForSelector(SEL.qsVehicle, { timeout: 5000 }).catch(() => {});
      await censusNow('quick-sale-prompt', { heading: await heading(), toast: await toastText() });
      await shot('dialog-quick-sale-prompt');
      if (await has(SEL.qsCancel)) await first(SEL.qsCancel).click();
    });

    await step('manager-prompt', async () => { // a manual discount the branch wants approved → Complete Sale refused → manager dialog
      await resetPage();
      if (!(await setOrderType('takeaway'))) report.skipped.push('manager-prompt: no takeaway order type — using the default');
      await addFirstTile();
      if (!(await openDialog('review-pay-discount', '#review-pay-btn'))) return;
      if (!(await has(SEL.discType)) || !(await has(SEL.complete))) {
        report.skipped.push('manager-prompt: no discount control or no Complete Sale button for this operator'); await closeModal(); return;
      }
      await page.locator('#modal-body details, .modal.show details').first().evaluate(d => { d.open = true; }).catch(() => {});
      await first(SEL.discType).selectOption('fixed');
      await first(SEL.discValue).fill('10');
      // W2: the manager is asked AT APPLY (Online asks at apply too); the pre-parity page asked at Complete Sale
      if (await has(SEL.discApply)) await first(SEL.discApply).click();
      await page.waitForSelector(SEL.managerCred, { timeout: 8000 }).catch(() => {});
      if (!(await has(SEL.managerCred)) && await has(SEL.complete)) { await first(SEL.complete).click(); await page.waitForSelector(SEL.managerCred, { timeout: 8000 }).catch(() => {}); }
      const err = await first(SEL.rpErr).textContent().catch(() => '');
      await censusNow('manager-prompt', { heading: await heading(), prompt_visible: await visible(SEL.managerCred), inline_error: (err || '').trim() || null, toast: await toastText() });
      await shot('dialog-manager-prompt');
      if (await has(SEL.managerCancel)) await first(SEL.managerCancel).click(); // NO approval, NO payment
      await page.waitForTimeout(400);
      if (await modalOpen()) await closeModal();
    });

    await step('table-open-hold', async () => { // open a free table → hold a round → held-check actions (KOT / Split / Cancel dialogs)
      await resetPage();
      if (!(await openDialog('view-tables-open', '#view-tables-btn'))) return;
      const openBtn = (await firstVisible(SEL.freeTable)) || (await firstVisible(SEL.freeTileLegacy));
      if (!openBtn) { report.skipped.push('table-open: no visible free-table Open control'); await closeModal(); return; }
      await openBtn.click(); // W3: "Open Table" on the tile → the form; pre-parity: the tile → actions
      await page.waitForSelector(SEL.openTable, { timeout: 5000 }).catch(() => {});
      if (!(await has(SEL.openTable))) { report.skipped.push('table-open: no Open table submit control'); await closeModal(); return; }
      if (await has('#guest_count')) await first('#guest_count').fill('2');
      await first(SEL.openTable).click();
      await waitSettled(async () => !(await modalOpen()));
      const stillOpen = await modalOpen();
      await censusNow('table-opened', { modal_still_open: stillOpen, toast: await toastText(), session_bar: await page.locator('#pos-session-bar, #check-chip').first().textContent().catch(() => null) });
      await shot('pos-table-opened');
      if (stillOpen) { report.skipped.push('table-open: the server refused (see refusals/toast)'); await closeModal(); return; }
      await addFirstTile();
      await first('#hold-sale-btn').click();
      await page.waitForSelector(SEL.kotBtn, { timeout: 10000 }).catch(() => {});
      await censusNow('held-check', { toast: await toastText(), session_bar: await page.locator('#pos-session-bar, #check-chip').first().textContent().catch(() => null) });
      await shot('pos-held-check');
      if (!(await has(SEL.kotBtn))) { report.skipped.push('held-check: Hold did not produce a held check (see refusals/toast)'); return; }
      if (await openDialog('split-bill', '#split-bill-btn')) await closeModal();
      if (await openDialog('cancel-order', '#cancel-order-btn')) await closeModal(); // dialog only — the order stays
      if (await openDialog('change-order', '#edit-order-btn')) await closeModal();
      if (await has(SEL.leaveCheck)) { await first(SEL.leaveCheck).click(); await acceptConfirm(); }
    });

    await step('reserve-and-details', async () => { // reserve a free table → the board shows it reserved → its details
      await resetPage();
      if (!(await openDialog('view-tables-reserve', '#view-tables-btn'))) return;
      const reserveBtn = await firstVisible(SEL.reserveToggle);
      if (!reserveBtn) { report.skipped.push('reserve: no visible Reserve control on a free tile'); await closeModal(); return; }
      await reserveBtn.click(); // W3: "Reserve" on the tile → the reserve form
      await page.waitForSelector('#reserveTableModal, #reserve-name, #rs-name', { timeout: 5000 }).catch(() => {});
      await page.waitForTimeout(300);
      if (await has(SEL.reserveName)) await first(SEL.reserveName).fill('Proof Reservation');
      if (await has(SEL.reserveNote)) await first(SEL.reserveNote).fill('browser proof — disposable');
      if (!(await has(SEL.reserveSave))) { report.skipped.push('reserve: no save control'); await closeModal(); return; }
      await first(SEL.reserveSave).click();
      await page.waitForTimeout(1200);
      const reservedEl = (await firstVisible(SEL.reservedTable)) || (await firstVisible(SEL.reservedTileLegacy)); if (!reservedEl) { report.skipped.push("reserve: no reserved table on the board after reserving (see refusals)"); await closeModal(); return; }
      await reservedEl.click();
      await page.waitForTimeout(400);
      await censusNow('reservation-details', { toast: await toastText() });
      await shot('dialog-reservation-details');
      await closeModal();
    });

    await step('customer-address-pick', async () => { // a delivery order with a book customer who has saved addresses → the address picker
      await resetPage();
      if (!(await setOrderType('delivery'))) { report.skipped.push('customer-address-pick: no delivery order type'); return; }
      await addFirstTile();
      const trigger = (await has('#pos-customer-btn')) ? '#pos-customer-btn' : '#review-pay-btn';
      if (!(await openDialog('customer-search', trigger))) return;
      if (!(await has(SEL.custSearch))) { report.skipped.push('customer-address-pick: no customer search control'); await closeModal(); return; }
      await page.locator('#modal-body details').first().evaluate(d => { d.open = true; }).catch(() => {});
      await first(SEL.custSearch).fill('Ah');
      await page.waitForSelector(SEL.custResults, { timeout: 8000 }).catch(() => {});
      if (!(await has(SEL.custResults))) { report.skipped.push('customer-address-pick: no customer matched "Ah" in the synced book'); await closeModal(); return; }
      await first(SEL.custResults).click();
      await waitSettled(async () => { const p = (await first(SEL.custPicked).textContent().catch(() => '')).trim(); return p !== '' && p !== 'Walk-in'; });
      if (await has('#cust-attach-btn') && await visible('#cust-attach-btn')) { await first('#cust-attach-btn').click(); await page.waitForTimeout(500); }
      await censusNow('customer-attached', { picked: await first(SEL.custPicked).textContent().catch(() => null), address_picker: await has(SEL.addrPick), chip: await page.locator('#chip-cust-name, #customer-chip').first().textContent().catch(() => null) });
      await shot('dialog-customer-attached');
      await closeModal();
    });
  } else {
    report.skipped.push('deeper flows (shift open, quick-sale, manager prompt, table open, held check, split/cancel/change-order dialogs, reservation, customer attach) need --allow-mutations');
  }

  // ── DOM census: per census row, did every '#id' counterpart appear somewhere we looked? ─────────────────
  const byState = {};
  for (const [id, row] of Object.entries(rows)) {
    const idSels = row.edge.filter(s => s.startsWith('#')).map(s => s.slice(1));
    const verdict = idSels.length === 0 ? 'no-id-selector' : (idSels.every(s => seen.has(s)) ? 'seen' : 'NOT-seen');
    byState[row.state] ??= { rows: 0, seen: 0, not_seen: 0, no_id_selector: 0 };
    byState[row.state].rows++;
    byState[row.state][verdict === 'seen' ? 'seen' : verdict === 'NOT-seen' ? 'not_seen' : 'no_id_selector']++;
    if (verdict === 'NOT-seen') (report.dom_census.not_seen ??= []).push({ id, state: row.state, edge: row.edge, record: row.record });
  }
  report.dom_census.by_state = byState;
  report.dom_census.total_rows = Object.keys(rows).length;
  report.finished_at = new Date().toISOString();
} finally {
  await browser.close();
}
fs.writeFileSync(path.join(outDir, 'report.json'), JSON.stringify(report, null, 2));
console.log(JSON.stringify({ evidence: outDir, viewports: report.viewports, dom_census: report.dom_census.by_state, not_seen: (report.dom_census.not_seen || []).map(x => x.id), toasts: report.toasts.map(t => t.text), refusals: report.refusals, skipped: report.skipped, console_errors: report.console_errors.length }, null, 2));
