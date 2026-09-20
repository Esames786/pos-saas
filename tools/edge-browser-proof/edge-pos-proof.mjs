// W7 — Edge cashier BROWSER proof (JS-executing), owner directive 20 Sep 2026 after the 149-record audit.
//
// Why: every Edge control is JS-rendered and no PHP test executes JavaScript (audit E-15). This script drives the real
// page in a real browser (the locally installed Microsoft Edge / Chrome via Playwright's channel — no download), logs in
// with the operator's Edge credential, and records:
//   1. the DOM census — which Online-control counterparts from tests/Fixtures/edge/online-pos-control-census.json
//      actually exist in the live DOM after the page booted (present / equivalent / partial rows), incl. inside the
//      dialogs it opens (View Tables, Shift, Returns, Quick Report, Recent Prints, Review & Pay, Preview Bill);
//   2. paired-viewport screenshots (1366×768 desktop, 1024×768 tablet, 800×600 small) of the main page and each dialog;
//   3. a JSON report with counts and denominators (never a percentage), every server refusal the page toasted, and
//      every step that could not be completed (with the reason) — silence is never reported as success.
//
// Read-only by default. With --allow-mutations (dev instance / LAB data ONLY, never a live tenant) it also walks the
// deeper operator flows that create disposable local records — open the shift, open a table, Hold on the table (a held
// check → KOT / Split / Cancel dialogs), a delivery-mode Review & Pay, the Quick Sale prompt, a manual discount that
// summons the manager prompt — and censuses/screenshots each. It NEVER completes a payment and never prints.
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
  page.on('dialog', d => d.accept()); // the page's confirm() on "leave check" — always accept in the proof
  page.on('response', async res => { // every server refusal the page received, with its business message
    const st = res.status();
    if (st < 400 || !res.url().includes('/edge/local/')) return;
    let body = null;
    try { body = (await res.text()).slice(0, 400); } catch { /* body already consumed / navigation */ }
    report.refusals.push({ url: res.url().replace(baseUrl, ''), status: st, body });
  });

  const posUrl = baseUrl + '/edge/local/pos';
  const seen = new Set();
  const censusNow = async (label, extra = {}) => {
    const found = await page.evaluate(ids => ids.filter(id => document.getElementById(id) !== null), [...idSelectors]);
    found.forEach(id => seen.add(id));
    report.steps[label] = { ...(report.steps[label] || {}), dom_ids_found: found.length, ...extra };
    return found;
  };
  const shot = (name) => page.screenshot({ path: path.join(outDir, `${name}.png`), fullPage: false });
  const heading = () => page.locator('#modal-body h2').first().textContent().catch(() => null);
  const toastText = async () => { // the page toasts every server refusal for ~3 s — capture whatever is showing
    const t = page.locator('#toast');
    if (await t.count() === 0) return null;
    const visible = await t.evaluate(el => el.style.display === 'block' && el.textContent.trim() !== '').catch(() => false);
    if (!visible) return null;
    const text = (await t.textContent()).trim();
    report.toasts.push(text);
    return text;
  };
  const modalOpen = () => page.locator('#modal.open').count().then(n => n > 0);
  const closeModal = async () => { await page.evaluate(() => window.EdgePOS && window.EdgePOS.closeModal()); await page.waitForTimeout(150); };
  const resetPage = async () => { // a clean cart between deep steps (the page has no Clear Cart — audit A37)
    await page.goto(posUrl, { waitUntil: 'networkidle' });
    await page.waitForSelector('#tiles .tile, #tiles p', { timeout: 15000 });
  };
  const step = async (label, fn) => {
    try { await fn(); } catch (e) { report.steps[label] = { ...(report.steps[label] || {}), error: String(e.message || e).split('\n')[0] }; report.skipped.push(`${label}: ${String(e.message || e).split('\n')[0]}`); }
  };
  const openDialog = async (label, trigger) => {
    if (await page.locator(trigger).count() === 0) { report.steps[label] = { trigger_missing: trigger }; return false; }
    if (await modalOpen()) await closeModal();
    await page.click(trigger);
    await page.waitForSelector('#modal.open', { timeout: 10000 }).catch(() => {});
    await page.waitForTimeout(600);
    await censusNow(label, { heading: await heading(), toast: await toastText() });
    await shot(`dialog-${label}`);
    return true;
  };
  const addFirstTile = async () => { if (await modalOpen()) await closeModal(); await page.locator('#tiles .tile').first().click(); await page.waitForTimeout(150); };
  const setOrderType = async (type) => {
    if (await page.locator(`#order-type option[value=${type}]`).count() === 0) return false;
    if (await page.locator('#order-type').isDisabled()) return false;
    await page.selectOption('#order-type', type);
    return true;
  };

  // ── login — the Edge local login form (employee code + credential) ──────────────────────────────────────
  await page.goto(baseUrl + '/edge/local/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name=employee_code]', user);
  await page.fill('input[type=password]', pass);
  await page.click('button[type=submit]');
  await page.waitForURL(/edge\/local\/pos/, { timeout: 15000 }).catch(() => {});
  await resetPage();

  // ── main page at each viewport ──────────────────────────────────────────────────────────────────────────
  for (const vp of viewports) {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    await page.waitForTimeout(250);
    const hasHorizontalScroll = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    const tile = await page.locator('#tiles .tile').first().boundingBox().catch(() => null);
    report.viewports[vp.name] = { horizontal_overflow: hasHorizontalScroll, first_tile_px: tile ? { w: Math.round(tile.width), h: Math.round(tile.height) } : null };
    await shot(`pos-main-${vp.name}`);
  }
  await page.setViewportSize(viewports[0]);
  await censusNow('main', { order_type_default: await page.locator('#order-type').inputValue().catch(() => null), terminal: await page.locator('#terminal option:checked').textContent().catch(() => null) });

  // ── read-only dialogs ───────────────────────────────────────────────────────────────────────────────────
  for (const [label, trigger] of [['view-tables', '#view-tables-btn'], ['shift', '#shift-btn'], ['returns', '#returns-btn'], ['quick-report', '#quick-report-btn'], ['recent-prints', '#recent-prints-btn']]) {
    await step(label, async () => { if (await openDialog(label, trigger)) await closeModal(); });
  }
  // table actions + reserve form are reachable without mutating anything: select a free table on the board
  await step('table-actions', async () => {
    if (!(await openDialog('view-tables-select', '#view-tables-btn'))) return;
    const free = page.locator('#modal .tbl.available').first();
    if (await free.count() === 0) { report.skipped.push('table-actions: no free table on the board'); await closeModal(); return; }
    await free.click();
    await page.waitForTimeout(400);
    await censusNow('table-actions', { table: await free.locator('strong').textContent().catch(() => null) });
    await shot('dialog-table-actions');
    if (await page.locator('#ta-reserve-toggle').count()) {
      await page.click('#ta-reserve-toggle');
      await page.waitForTimeout(200);
      await censusNow('reserve-form');
      await shot('dialog-reserve-form');
    }
    await closeModal();
  });
  // Preview Bill + Review & Pay need a cart line: add the first tile (no sale is made)
  await step('preview-and-pay', async () => {
    await addFirstTile();
    for (const [label, trigger] of [['preview-bill', '#preview-bill-btn'], ['review-pay', '#review-pay-btn']]) {
      if (await openDialog(label, trigger)) await closeModal();
    }
    if (await setOrderType('delivery')) { // delivery-mode Review & Pay shows the delivery panel (still no sale)
      if (await openDialog('review-pay-delivery', '#review-pay-btn')) await closeModal();
    } else report.skipped.push('review-pay-delivery: operator has no delivery order type');
  });

  // ── deeper flows that create disposable local records (dev instance / LAB only) ─────────────────────────
  if (allowMutations) {
    await step('shift-open', async () => { // every sale/table action needs an open shift on the selected terminal
      await resetPage();
      if (!(await openDialog('shift-before', '#shift-btn'))) return;
      if (await page.locator('#sh-open').count()) {
        await page.click('#sh-open');
        for (let i = 0; i < 40 && (await modalOpen()); i++) { // closes on success; stays open with an inline error on refusal
          if ((await page.locator('#sh-err').textContent().catch(() => '')).trim()) break;
          await page.waitForTimeout(250);
        }
        report.steps['shift-open'] = { opened: !(await modalOpen()), inline_error: (await page.locator('#sh-err').textContent().catch(() => '')).trim() || null, toast: await toastText() };
      } else report.steps['shift-open'] = { opened: false, already_open: await page.locator('#sh-close').count() > 0 };
      if (await modalOpen()) await closeModal();
    });

    await step('quick-sale-prompt', async () => { // Hold on a quick_sale cart asks vehicle + waiter; we cancel → nothing is held
      await resetPage();
      if (!(await setOrderType('quick_sale'))) { report.skipped.push('quick-sale-prompt: operator has no quick_sale order type'); return; }
      await addFirstTile();
      await page.click('#hold-sale-btn');
      await page.waitForSelector('#qs-vehicle', { timeout: 5000 }).catch(() => {});
      await censusNow('quick-sale-prompt', { heading: await heading(), toast: await toastText() });
      await shot('dialog-quick-sale-prompt');
      if (await page.locator('#qs-cancel').count()) await page.click('#qs-cancel');
    });

    await step('manager-prompt', async () => { // a manual discount the branch wants approved → Complete Sale refused → manager dialog
      await resetPage();
      if (!(await setOrderType('takeaway'))) report.skipped.push('manager-prompt: no takeaway order type — using the default');
      await addFirstTile();
      if (!(await openDialog('review-pay-discount', '#review-pay-btn'))) return;
      if (await page.locator('#cm-disc-type').count() === 0 || await page.locator('#rp-complete').count() === 0) {
        report.skipped.push('manager-prompt: no discount control or no Complete Sale button for this operator'); await closeModal(); return;
      }
      await page.locator('#modal-body details').first().evaluate(d => { d.open = true; });
      await page.selectOption('#cm-disc-type', 'fixed');
      await page.fill('#cm-disc-value', '10');
      await page.click('#cm-apply');
      await page.waitForTimeout(800);
      await page.click('#rp-complete');
      await page.waitForSelector('#ma-cred', { timeout: 8000 }).catch(() => {});
      const err = await page.locator('#rp-err').textContent().catch(() => '');
      await censusNow('manager-prompt', { heading: await heading(), inline_error: (err || '').trim() || null, toast: await toastText() });
      await shot('dialog-manager-prompt');
      if (await page.locator('#ma-cancel').count()) await page.click('#ma-cancel'); // NO approval, NO payment
      await page.waitForTimeout(400);
      if (await modalOpen()) await closeModal();
    });

    await step('table-open-hold', async () => { // open a free table → hold a round → held-check actions (KOT / Split / Cancel dialogs)
      await resetPage();
      if (!(await openDialog('view-tables-open', '#view-tables-btn'))) return;
      const free = page.locator('#modal .tbl.available').first();
      if (await free.count() === 0) { report.skipped.push('table-open: no free table'); await closeModal(); return; }
      await free.click();
      await page.waitForTimeout(300);
      if (await page.locator('#ta-open').count() === 0) { report.skipped.push('table-open: no Open table button'); await closeModal(); return; }
      await page.click('#ta-open');
      // the built-in dev server answers one request at a time — wait until the modal closed (success) or a toast showed (refusal)
      for (let i = 0; i < 40; i++) {
        if (!(await modalOpen())) break;
        if (await page.locator('#toast').evaluate(el => el.style.display === 'block').catch(() => false)) break;
        await page.waitForTimeout(250);
      }
      const stillOpen = await modalOpen();
      await censusNow('table-opened', { modal_still_open: stillOpen, toast: await toastText(), check_chip: await page.locator('#check-chip').textContent().catch(() => null) });
      await shot('pos-table-opened');
      if (stillOpen) { report.skipped.push('table-open: the server refused (see toast)'); await closeModal(); return; }
      await addFirstTile();
      await page.click('#hold-sale-btn');
      await page.waitForSelector('#kot-btn', { timeout: 10000 }).catch(() => {});
      await censusNow('held-check', { toast: await toastText(), check_chip: await page.locator('#check-chip').textContent().catch(() => null) });
      await shot('pos-held-check');
      if (await page.locator('#kot-btn').count() === 0) { report.skipped.push('held-check: Hold did not produce a held check (see toast)'); return; }
      if (await openDialog('split-bill', '#split-bill-btn')) await closeModal();
      if (await openDialog('cancel-order', '#cancel-order-btn')) await closeModal(); // dialog only — the order stays
      if (await page.locator('#leave-check-btn').count()) await page.click('#leave-check-btn');
    });

    await step('reserve-and-details', async () => { // reserve a free table → the board shows it reserved → its details + cancel-reservation controls
      await resetPage();
      if (!(await openDialog('view-tables-reserve', '#view-tables-btn'))) return;
      const free = page.locator('#modal .tbl.available').first();
      if (await free.count() === 0) { report.skipped.push('reserve: no free table'); await closeModal(); return; }
      await free.click();
      await page.waitForTimeout(300);
      if (await page.locator('#ta-reserve-toggle').count() === 0) { report.skipped.push('reserve: no Reserve… toggle'); await closeModal(); return; }
      await page.click('#ta-reserve-toggle');
      await page.fill('#rs-name', 'Proof Reservation');
      await page.fill('#rs-note', 'browser proof — disposable');
      await page.click('#ta-reserve');
      await page.waitForTimeout(1200); // the board re-renders after the reservation
      const reserved = page.locator('#modal .tbl.reserved').first();
      if (await reserved.count() === 0) { report.skipped.push('reserve: the board shows no reserved table after reserving (see refusals)'); await closeModal(); return; }
      await reserved.click();
      await page.waitForTimeout(300);
      await censusNow('reservation-details', { toast: await toastText(), table: await reserved.locator('strong').textContent().catch(() => null) });
      await shot('dialog-reservation-details');
      await closeModal();
    });

    await step('customer-address-pick', async () => { // a delivery order with a book customer who has saved addresses → the address picker
      await resetPage();
      if (!(await setOrderType('delivery'))) { report.skipped.push('customer-address-pick: no delivery order type'); return; }
      await addFirstTile();
      if (!(await openDialog('review-pay-customer', '#review-pay-btn'))) return;
      if (await page.locator('#cm-cust-q').count() === 0) { report.skipped.push('customer-address-pick: no customer search in Review & Pay'); await closeModal(); return; }
      await page.locator('#modal-body details').first().evaluate(d => { d.open = true; });
      await page.fill('#cm-cust-q', 'Ah');
      await page.waitForSelector('#cm-cust-results [data-cid]', { timeout: 8000 }).catch(() => {});
      if (await page.locator('#cm-cust-results [data-cid]').count() === 0) { report.skipped.push('customer-address-pick: no customer matched "Ah" in the synced book'); await closeModal(); return; }
      await page.locator('#cm-cust-results [data-cid]').first().click();
      // Review & Pay re-opens on the server's new totals with the customer attached — wait for the picked name to change
      for (let i = 0; i < 40; i++) {
        const picked = (await page.locator('#cm-cust-picked').textContent().catch(() => '')).trim();
        if (picked && picked !== 'Walk-in') break;
        await page.waitForTimeout(250);
      }
      await censusNow('customer-attached', { picked: await page.locator('#cm-cust-picked').textContent().catch(() => null), address_picker: await page.locator('#cm-addr-pick').count() > 0 });
      await shot('dialog-review-pay-customer-attached');
      await closeModal();
    });
  } else {
    report.skipped.push('deeper flows (shift open, quick-sale prompt, manager prompt, table open, held check, split, cancel) need --allow-mutations');
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
console.log(JSON.stringify({ evidence: outDir, viewports: report.viewports, dom_census: report.dom_census.by_state, not_seen: (report.dom_census.not_seen || []).map(x => x.id), toasts: report.toasts, refusals: report.refusals, skipped: report.skipped, console_errors: report.console_errors.length }, null, 2));
