// PHASE 3 — EDGE-vs-ONLINE PAIRED GEOMETRY COMPARISON of the SHARED cashier view (tenant.pos.index), both runtimes.
//
//   measure:  node geometry-compare.mjs --mode edge  --base-url http://127.0.0.1:8095 --user DEVCASH1 --out <dir>
//             node geometry-compare.mjs --mode cloud --base-url http://edgehomelab.localhost:9704 --user lab.cashier@edgehomelab.test --out <dir>
//             (password ONLY in env POS_SHOT_PASS — never argv, never printed; --path overrides the POS page:
//              Edge default /edge/local/pos (the Phase 2 alias /edge/local/pos/shared is a 301 to it since Stage B), Cloud default /pos; --settle <ms> default 6000; --channel msedge)
//   compare:  node geometry-compare.mjs --compare --a <edgeDir>/report.json --b <onlineDir>/report.json --out <compare.json> [--tolerance 1]
//
// For every state (main, category, customer modal, context modal, held orders, recent orders, recent prints, quick report,
// table workspace, cart with two lines, review & pay, qty entry, modifier entry — the pos-reference-shots.mjs list) and both
// viewports (1366x768, 1024x768) it records getBoundingClientRect() of a FIXED selector set: the title row, the status slot,
// the mode tabs, the search box, the category strip, the tile grid, the cart panel, Review & Pay, the Hold/Draft/Bill/Recent/
// Cancel block, and — for a modal state — the open dialog, its header/body/footer and primary buttons (inner boxes measured
// RELATIVE to the `.modal-content` box, so a centred dialog whose body height is data-driven does not shift every inner box;
// page boxes are measured in DOCUMENT coordinates, so a tile click that scrolls the window cannot shift them).
// Text / dataset content is irrelevant: geometry only. Dimensions that content legitimately drives (a wrapping category strip,
// a list's height, the footer below a data-driven body) are declared per selector as `data` and reported in a separate
// DATA bucket, never as geometry. The compare exits 1 on ANY geometry difference above the tolerance (default 1 px) and prints
// EDGE_VS_ONLINE_GEOMETRY=NONE | DIFF(n). States missing on one side (a tile the dataset lacks, a permission the cashier
// lacks) are recorded as SKIPPED on that side — never faked, never counted as geometry.
//
// Safety: loopback hosts only (every other host is aborted at the network layer); the tool never completes a sale, never prints,
// never touches a live tenant — point it at the dev Edge instance and the dev Cloud clone only.
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

// ── args ──────────────────────────────────────────────────────────────────────────────────────────────────────────
const argv = process.argv.slice(2);
const args = {};
for (let i = 0; i < argv.length; i++) {
  if (!argv[i].startsWith('--')) continue;
  const key = argv[i].slice(2); const next = argv[i + 1];
  if (!next || next.startsWith('--')) { args[key] = true; } else { args[key] = next; i++; }
}
const tolerance = Number(args.tolerance || 1);
const VIEWPORTS = [{ name: '1366x768', width: 1366, height: 768 }, { name: '1024x768', width: 1024, height: 768 }];
const ALLOWED_HOSTS = ['127.0.0.1', 'localhost', 'edgehomelab.localhost'];

// ── the fixed selector set ─────────────────────────────────────────────────────────────────────────────────────────
// dims: which of x,y,w,h are compared; data: the subset that content legitimately drives (reported as DATA, not geometry).
const PAGE_SELECTORS = [
  { name: 'title-row', sel: 'div:has(> #pos-sidebar-toggle)' },
  { name: 'sidebar-toggle', sel: '#pos-sidebar-toggle' },
  { name: 'view-tables-btn', sel: '#view-tables-btn' },
  { name: 'status-slot', sel: '#pos-runtime-slot' },
  { name: 'mode-tabs', sel: '#mode-tabs-wrapper' },
  { name: 'customer-slot', sel: '#pos-customer-slot' },
  { name: 'report-btn', sel: '#pos-report-btn' },
  { name: 'return-btn', sel: '#pos-return-btn' },
  { name: 'quick-report-btn', sel: '#pos-quick-report-btn' },
  { name: 'customer-btn', sel: '#pos-customer-btn' },
  { name: 'products-panel', sel: '.pos-products-panel' },
  { name: 'order-controls-row', sel: '#order-controls-row' },
  { name: 'search', sel: '#pos_search' },
  // the strip WRAPS (flex-wrap) — its height, and everything below it, follows the number of categories = DATA
  { name: 'category-strip', sel: '#parent-category-strip', data: ['h'] },
  { name: 'products-heading', sel: '#products_heading', data: ['y'] },
  { name: 'tile-grid', sel: '#product-grid', data: ['y', 'h'] },
  { name: 'cart-panel', sel: '.cart-panel' },
  { name: 'cart-section', sel: '.cart-section' },
  { name: 'cart-heading', sel: '#cart_heading' },
  { name: 'toggle-calc-btn', sel: '#toggle-calc-btn' },
  { name: 'clear-cart-btn', sel: '#clear-cart-btn' },
  { name: 'start-fresh-btn', sel: '#start-fresh-btn' },
  { name: 'new-sale-btn', sel: '#new-sale-btn' },
  { name: 'cart-items', sel: '#cart-items' },
  { name: 'charge-bar', sel: '.pos-charge-bar' },
  { name: 'charge-total', sel: '#pos-charge-total', data: ['w'] },   // the amount's own width follows the digits
  { name: 'review-pay-btn', sel: '#review-pay-btn', data: ['x', 'w'] }, // flex-grow next to the data-wide amount
  { name: 'actions-block', sel: '.pos-actions' },
  { name: 'hold-sale-btn', sel: '#hold-sale-btn' },
  { name: 'save-draft-btn', sel: '#save-draft-btn' },
  { name: 'held-orders-btn', sel: '#held-orders-btn' },
  { name: 'bill-preview-btn', sel: '#bill-preview-btn' },
  { name: 'completed-orders-btn', sel: '#completed-orders-btn' },
  { name: 'cancel-order-btn', sel: '#cancel-order-btn' },
  { name: 'last-print-btn', sel: '#last-print-btn' },
];
// Modal boxes: the dialog is absolute (x/w fixed by the viewport; y/h follow a centred, data-high body = DATA); everything
// inside is RELATIVE to the dialog origin. Body height and whatever sits below the body are DATA.
const MODAL_SELECTORS = [
  { name: 'dialog', sel: '.modal.show .modal-dialog', data: ['y', 'h'] },
  { name: 'content', sel: '.modal.show .modal-content', data: ['y', 'h'] },   // centred dialogs: y/h follow the body content
  { name: 'header', sel: '.modal.show .modal-header', rel: true },
  { name: 'title', sel: '.modal.show .modal-header .modal-title', rel: true, data: ['w'] },
  { name: 'close', sel: '.modal.show .modal-header .btn-close', rel: true },
  { name: 'body', sel: '.modal.show .modal-body', rel: true, data: ['h'] },
  { name: 'footer', sel: '.modal.show .modal-footer', rel: true, data: ['y'] },
  { name: 'footer-primary', sel: '.modal.show .modal-footer .btn-primary', rel: true, data: ['y'] },
  { name: 'footer-secondary', sel: '.modal.show .modal-footer .btn-secondary, .modal.show .modal-footer .btn-light, .modal.show .modal-footer .btn-outline-secondary', rel: true, data: ['y'] },
];
// State-specific controls (relative to the dialog) that sit ABOVE any data-driven list — pure layout.
const STATE_SELECTORS = {
  '03-customer-modal': [{ name: 'cust-search-input', sel: '#cust-search-input', rel: true }],
  '04-context-modal': [{ name: 'branch-select', sel: '#branch_id', rel: true }, { name: 'terminal-select', sel: '#terminal_id', rel: true, data: ['y'] }],
  '05-held-orders': [{ name: 'held-type-filters', sel: '#held-type-filters', rel: true }],
  '06-recent-orders': [{ name: 'recent-type-filters', sel: '#recent-type-filters', rel: true }],
  '08-quick-report': [{ name: 'qr-date', sel: '#qr-date', rel: true }, { name: 'qr-branch', sel: '#qr-branch', rel: true },
    { name: 'qr-print', sel: '#qr-print', rel: true, data: ['y'] }, { name: 'qr-network', sel: '#qr-network', rel: true, data: ['y'] },
    { name: 'qr-email', sel: '#qr-email', rel: true, data: ['y'] }, { name: 'qr-save', sel: '#qr-save', rel: true, data: ['y'] }],
  '09-table-workspace': [{ name: 'tw-toolbar', sel: '.table-workspace-toolbar', rel: true }, { name: 'tw-board', sel: '#table-workspace-board', rel: true, data: ['h'] }],
  '11-review-pay': [{ name: 'payment-method', sel: '#payment_method_id', rel: true }, { name: 'tendered', sel: '#tendered_amount', rel: true },
    { name: 'quick-cash', sel: '#quick-cash-buttons', rel: true }, { name: 'promo-row', sel: '#promo-row', rel: true, data: ['y'] },
    { name: 'manual-discount-panel', sel: '#manual-discount-panel', rel: true, data: ['y'] }, { name: 'print-pref-panel', sel: '#print-pref-panel', rel: true, data: ['y'] },
    { name: 'complete-sale-btn', sel: '#complete-sale-btn', rel: true, data: ['y'] }],
  '12-qty-entry': [{ name: 'qty-input', sel: '#qty-modal-input', rel: true }],
  '13-modifier-entry': [{ name: 'modifier-groups', sel: '#modifier-modal-groups', rel: true, data: ['h'] }, { name: 'modifier-confirm', sel: '#modifier-modal-confirm', rel: true, data: ['y'] }],
};

const r2 = (v) => Math.round(v * 100) / 100;

// ── COMPARE ───────────────────────────────────────────────────────────────────────────────────────────────────────
if (args.compare) {
  const A = JSON.parse(fs.readFileSync(path.resolve(String(args.a)), 'utf8'));
  const B = JSON.parse(fs.readFileSync(path.resolve(String(args.b)), 'utf8'));
  const out = { a: { mode: A.mode, base: A.base, report: path.resolve(String(args.a)) }, b: { mode: B.mode, base: B.base, report: path.resolve(String(args.b)) },
    tolerance_px: tolerance, compared_at: new Date().toISOString(), states: {}, geometry_differences: [], data_differences: [], presence_differences: [], skipped: [] };
  let boxes = 0; let maxDev = 0;
  for (const vp of VIEWPORTS) {
    const sa = A.viewports?.[vp.name]?.states || {}; const sb = B.viewports?.[vp.name]?.states || {};
    for (const state of new Set([...Object.keys(sa), ...Object.keys(sb)])) {
      const key = `${vp.name}/${state}`; const a = sa[state]; const b = sb[state];
      const rec = { boxes: 0, geometry: 0, data: 0, presence: 0, max_deviation_px: 0 };
      out.states[key] = rec;
      if (!a || !b || a.skipped || b.skipped) { out.skipped.push({ state: key, a: a ? (a.skipped || 'ok') : 'absent', b: b ? (b.skipped || 'ok') : 'absent' }); rec.skipped = true; continue; }
      for (const name of new Set([...Object.keys(a.boxes), ...Object.keys(b.boxes)])) {
        const ba = a.boxes[name]; const bb = b.boxes[name];
        if (!ba || !bb || !ba.present !== !bb.present) {
          rec.presence++; out.presence_differences.push({ state: key, box: name, a: ba ? (ba.present ? 'present' : ba.reason) : 'unmeasured', b: bb ? (bb.present ? 'present' : bb.reason) : 'unmeasured' });
          continue;
        }
        if (!ba.present) continue; // absent on both (a control the dataset / permissions do not show) — not a difference
        rec.boxes++; boxes++;
        const dims = ba.dims || ['x', 'y', 'w', 'h']; const data = new Set(ba.data || []);
        for (const d of dims) {
          const dev = r2(Math.abs((ba.rect[d] ?? 0) - (bb.rect[d] ?? 0)));
          if (dev <= tolerance) continue;
          const entry = { state: key, box: name, selector: ba.sel, dim: d, a: ba.rect[d], b: bb.rect[d], delta_px: r2(ba.rect[d] - bb.rect[d]), relative_to_dialog: !!ba.rel };
          if (data.has(d)) { rec.data++; out.data_differences.push(entry); }
          else { rec.geometry++; out.geometry_differences.push(entry); rec.max_deviation_px = Math.max(rec.max_deviation_px, dev); maxDev = Math.max(maxDev, dev); }
        }
      }
    }
  }
  out.boxes_compared = boxes; out.max_geometry_deviation_px = maxDev;
  out.verdict = out.geometry_differences.length === 0 ? 'EDGE_VS_ONLINE_GEOMETRY=NONE' : `EDGE_VS_ONLINE_GEOMETRY=DIFF(${out.geometry_differences.length})`;
  const file = path.resolve(String(args.out || 'compare.json'));
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, JSON.stringify(out, null, 2));
  console.log(JSON.stringify({ verdict: out.verdict, boxes_compared: boxes, max_geometry_deviation_px: maxDev, geometry: out.geometry_differences.length, data: out.data_differences.length, presence: out.presence_differences.length, skipped: out.skipped.length, file }));
  process.exit(out.geometry_differences.length === 0 ? 0 : 1);
}

// ── MEASURE ───────────────────────────────────────────────────────────────────────────────────────────────────────
const base = String(args['base-url'] || '').replace(/\/$/, '');
const mode = String(args.mode || '');
const user = String(args.user || '');
const pass = String(process.env.POS_SHOT_PASS || '').trim();
const settle = Number(args.settle || 6000);
if (!['cloud', 'edge'].includes(mode) || !base || !user || !pass) {
  console.error('need --mode cloud|edge, --base-url, --user and POS_SHOT_PASS in the environment (or --compare --a --b)');
  process.exit(2);
}
if (!ALLOWED_HOSTS.includes(new URL(base).hostname.toLowerCase())) { console.error('refusing a non-loopback base url'); process.exit(2); }
const posPath = String(args.path || (mode === 'cloud' ? '/pos' : '/edge/local/pos'));
const out = path.resolve(String(args.out || `./evidence/phase3/geometry/${mode}`));
fs.mkdirSync(out, { recursive: true });
const report = { mode, base, user, path: posPath, settle_ms: settle, started_at: new Date().toISOString(), viewports: {}, errors: [], blocked_hosts: [] };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = await chromium.launch({ channel: String(args.channel || 'msedge'), headless: !args.headed });
for (const vp of VIEWPORTS) {
  const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, deviceScaleFactor: 1, ignoreHTTPSErrors: true });
  await ctx.route('**/*', (route) => {
    const u = new URL(route.request().url());
    if (!ALLOWED_HOSTS.includes(u.hostname.toLowerCase())) { if (!report.blocked_hosts.includes(u.hostname)) report.blocked_hosts.push(u.hostname); return route.abort(); }
    return route.continue();
  });
  await ctx.addInitScript(() => { window.print = function () { window.__printed = (window.__printed || 0) + 1; }; });
  const page = await ctx.newPage();
  // generous: the dev instances are single-threaded artisan serve processes often shared with another proof run
  page.setDefaultNavigationTimeout(180000); page.setDefaultTimeout(90000);
  page.on('pageerror', (e) => report.errors.push({ vp: vp.name, error: String(e).slice(0, 200) }));
  page.on('dialog', (d) => d.accept().catch(() => {}));
  const vpRec = { states: {} }; report.viewports[vp.name] = vpRec;
  const dir = path.join(out, vp.name); fs.mkdirSync(dir, { recursive: true });

  // ── login (same flows as pos-reference-shots.mjs / shared-pos-workflows.mjs); verified, retried once, recorded ──
  const login = async () => {
    if (mode === 'cloud') {
      await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
      await page.fill('input[name="email"]', user);
      await page.fill('input[name="password"]', pass);
      await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('button[type="submit"]')]);
    } else {
      await page.goto(`${base}/edge/local/login`, { waitUntil: 'domcontentloaded' });
      await page.fill('input[name="employee_code"]', user);
      await page.fill('input[name="credential"], input[name="password"]', pass);
      await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('button[type="submit"]')]);
    }
    await sleep(500);
    return !/\/login(\?|$)/.test(page.url());
  };
  let loggedIn = await login();
  if (!loggedIn) { report.errors.push({ vp: vp.name, error: 'login: still on the login page after submit — retrying once' }); loggedIn = await login(); }
  vpRec.login = { ok: loggedIn, landed: page.url().replace(base, '') };
  // domcontentloaded (not load): the dev instances are single-threaded `artisan serve` processes — a long poll from another
  // tab/run must not hold the load event; the grid + the settle wait are what the measurement needs.
  await page.goto(`${base}${posPath}`, { waitUntil: 'domcontentloaded' });
  if (/\/login(\?|$)/.test(page.url())) { report.errors.push({ vp: vp.name, error: `POS page redirected to login (${page.url()})` }); }
  await page.locator('#product-grid .product-tile').first().waitFor({ state: 'visible', timeout: 60000 }).catch(() => {});
  // Measurement normalisation applied to BOTH runtimes: a vertical scrollbar that only one dataset's page height produces would
  // narrow every box by the scrollbar width — reserve the gutter on both. Then wait for the self-hosted fonts (metrics!).
  await page.addStyleTag({ content: 'html { scrollbar-gutter: stable; }' }).catch(() => {});
  await page.evaluate(() => document.fonts && document.fonts.ready).catch(() => {});
  await sleep(settle);
  vpRec.runtime_mode = await page.evaluate(() => (window.POS_RUNTIME && window.POS_RUNTIME.mode) || null);
  vpRec.view_marker = await page.evaluate(() => !!document.getElementById('pos-runtime-slot') && !!document.getElementById('pos-sale-form'));

  // ── measurement ──
  // Names of the page boxes that sit BELOW the products panel when the shell is stacked (one column, < 1200px wide): their
  // vertical position then follows the content-sized panel above them (tile count = DATA).
  const CART_GROUP = new Set(['cart-panel', 'cart-section', 'cart-heading', 'toggle-calc-btn', 'clear-cart-btn', 'start-fresh-btn', 'new-sale-btn', 'cart-items',
    'charge-bar', 'charge-total', 'review-pay-btn', 'actions-block', 'hold-sale-btn', 'save-draft-btn', 'held-orders-btn', 'bill-preview-btn', 'completed-orders-btn', 'cancel-order-btn', 'last-print-btn']);
  const measure = async (specs) => {
    return page.evaluate(({ specs, cartGroup }) => {
      const content = document.querySelector('.modal.show .modal-content');
      const cr = content ? content.getBoundingClientRect() : null;
      const sy = window.scrollY || 0; const sx = window.scrollX || 0;
      const products = document.querySelector('.pos-products-panel'); const cart = document.querySelector('.cart-panel');
      const stacked = !!(products && cart) && Math.abs(products.getBoundingClientRect().left - cart.getBoundingClientRect().left) < 2;
      const boxes = {};
      for (const s of specs) {
        let el = null;
        try { el = document.querySelector(s.sel); } catch (e) { boxes[s.name] = { sel: s.sel, present: false, reason: 'bad selector' }; continue; }
        if (!el) { boxes[s.name] = { sel: s.sel, present: false, reason: 'absent' }; continue; }
        const r = el.getBoundingClientRect();
        const style = getComputedStyle(el);
        if (r.width === 0 && r.height === 0 || style.display === 'none' || style.visibility === 'hidden') { boxes[s.name] = { sel: s.sel, present: false, reason: 'hidden' }; continue; }
        const rel = !!s.rel && !!cr;
        const modalBox = /\.modal\.show/.test(s.sel) || rel;
        const data = new Set(s.data || []);
        if (stacked && cartGroup.includes(s.name)) data.add('y');
        if (stacked && s.name === 'products-panel') data.add('h');
        boxes[s.name] = { sel: s.sel, present: true, rel, dims: ['x', 'y', 'w', 'h'], data: [...data],
          // modal boxes: viewport (fixed-position) or content-relative; page boxes: document coordinates
          rect: { x: Math.round((r.left - (rel ? cr.left : (modalBox ? 0 : -sx))) * 100) / 100, y: Math.round((r.top - (rel ? cr.top : (modalBox ? 0 : -sy))) * 100) / 100,
            w: Math.round(r.width * 100) / 100, h: Math.round(r.height * 100) / 100 } };
      }
      return { boxes, facts: { scroll_y: sy, stacked_layout: stacked, client_width: document.documentElement.clientWidth, scroll_height: document.documentElement.scrollHeight,
        fonts: document.fonts ? document.fonts.status : 'n/a', fonts_loaded: document.fonts ? [...document.fonts].filter((f) => f.status === 'loaded').length : null } };
    }, { specs, cartGroup: [...CART_GROUP] });
  };
  const record = async (state, specs, extra = {}) => {
    await sleep(settle);
    await page.mouse.move(0, 0);
    await page.evaluate(() => document.fonts && document.fonts.ready).catch(() => {});
    const { boxes, facts } = await measure(specs);
    const open = await page.evaluate(() => { const m = document.querySelector('.modal.show'); return m ? m.id : null; });
    await page.screenshot({ path: path.join(dir, `${state}.png`), fullPage: false }).catch(() => {});
    vpRec.states[state] = { boxes, open_modal: open, facts, ...extra };
    console.log(`${vp.name} ${state}: ${Object.values(boxes).filter((b) => b.present).length}/${specs.length} boxes${open ? ' (modal ' + open + ')' : ''}`);
  };
  const skip = (state, reason) => { vpRec.states[state] = { skipped: reason, boxes: {} }; console.log(`${vp.name} ${state}: SKIPPED — ${reason}`); };
  const closeModals = async () => {
    await page.evaluate(() => { document.querySelectorAll('.modal.show').forEach((m) => { const i = window.bootstrap && bootstrap.Modal.getInstance(m); if (i) i.hide(); }); });
    await sleep(800);
    await page.keyboard.press('Escape'); await sleep(400);
  };
  const openModal = async (state, clickSel, modalId, specsExtra = []) => {
    try {
      const el = page.locator(clickSel).first();
      if (!(await el.count()) || !(await el.isVisible())) { skip(state, `${clickSel} not visible`); return; }
      await el.click();
      await page.locator(`#${modalId}.show`).first().waitFor({ state: 'visible', timeout: 25000 });
      await record(state, [...PAGE_SELECTORS, ...MODAL_SELECTORS, ...specsExtra], { expected_modal: modalId });
    } catch (e) { skip(state, String(e.message || e).split('\n')[0].slice(0, 160)); }
    await closeModals();
  };

  await record('01-main', PAGE_SELECTORS);
  // second category pill (if any)
  const pills = page.locator('#parent-category-strip .category-pill');
  if ((await pills.count()) > 1) { await pills.nth(1).click(); await record('02-category', PAGE_SELECTORS); await pills.nth(0).click(); await sleep(500); }
  else skip('02-category', 'fewer than 2 category pills');
  await openModal('03-customer-modal', '#pos-customer-btn', 'customerModal', STATE_SELECTORS['03-customer-modal']);
  await openModal('04-context-modal', '[data-bs-target="#posContextModal"]', 'posContextModal', STATE_SELECTORS['04-context-modal']);
  await openModal('05-held-orders', '#held-orders-btn', 'heldSalesModal', STATE_SELECTORS['05-held-orders']);
  await openModal('06-recent-orders', '#completed-orders-btn', 'completedOrdersModal', STATE_SELECTORS['06-recent-orders']);
  await openModal('07-recent-prints', '#last-print-btn', 'lastPrintModal');
  await openModal('08-quick-report', '#pos-quick-report-btn', 'quickReportModal', STATE_SELECTORS['08-quick-report']);
  await openModal('09-table-workspace', '#view-tables-btn', 'tableWorkspaceModal', STATE_SELECTORS['09-table-workspace']);
  // two PLAIN tiles (no qty / modifier / variant prompt) → cart with two lines → review & pay
  const tiles = page.locator('#product-grid .product-tile');
  const plainExclude = /kg\b|\/kg|per kg|tikka|boti|seekh|half|full|small|large|deal|combo|platter|customi|variant/i;
  let added = 0;
  const n = await tiles.count();
  for (let i = 0; i < n && added < 2; i++) {
    const t = tiles.nth(i); const txt = ((await t.textContent().catch(() => '')) || '').trim();
    if (plainExclude.test(txt)) continue;
    await t.click(); await sleep(700);
    if (await page.evaluate(() => !!document.querySelector('.modal.show'))) { await closeModals(); continue; }
    added++;
  }
  if (added >= 2) {
    await record('10-cart-two-lines', PAGE_SELECTORS);
    await openModal('11-review-pay', '#review-pay-btn', 'paymentModal', STATE_SELECTORS['11-review-pay']);
  } else { skip('10-cart-two-lines', `only ${added} plain tiles could be added`); skip('11-review-pay', 'no cart'); }
  await openModal('12-qty-entry', '#product-grid .product-tile:has-text("kg")', 'qtyEntryModal', STATE_SELECTORS['12-qty-entry']);
  await openModal('13-modifier-entry', '#product-grid .product-tile:has-text("Tikka")', 'modifierEntryModal', STATE_SELECTORS['13-modifier-entry']);
  await ctx.close();
}
await browser.close();
report.finished_at = new Date().toISOString();
fs.writeFileSync(path.join(out, 'report.json'), JSON.stringify(report, null, 2));
const measured = Object.values(report.viewports).flatMap((v) => Object.values(v.states)).filter((s) => !s.skipped).length;
const skipped = Object.values(report.viewports).flatMap((v) => Object.entries(v.states)).filter(([, s]) => s.skipped).map(([k, s]) => `${k}: ${s.skipped}`);
console.log(JSON.stringify({ out, mode, states_measured: measured, skipped, errors: report.errors.length, blocked_hosts: report.blocked_hosts }));
