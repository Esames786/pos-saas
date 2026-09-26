// PHASE 2 — reference / paired screenshots of the cashier POS (Online or Edge) at fixed viewports.
//   node pos-reference-shots.mjs --base-url http://edgehomelab.localhost:9702 --mode cloud --out ./evidence/phase2/online-before
//        [--email lab.cashier@edgehomelab.test] (password in env POS_SHOT_PASS)             ← Online: tenant e-mail login
//   node pos-reference-shots.mjs --base-url https://desktop-0024epm.local:8443 --mode edge --user LAB2C5D --ignore-tls --out …
//        (password in env POS_SHOT_PASS)                                                    ← Edge: employee-code login
// States captured (same list in both modes, same viewport): main POS, categories tab, customer modal, context modal,
// held orders, recent orders, recent prints, quick report, table workspace, review & pay (cart with 2 lines), qty entry,
// modifier entry. Missing states are recorded as skipped, never faked. Output: <out>/<viewport>/<state>.png + report.json.
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const argv = process.argv.slice(2);
const args = {};
for (let i = 0; i < argv.length; i++) {
  if (!argv[i].startsWith('--')) continue;
  const key = argv[i].slice(2); const next = argv[i + 1];
  if (!next || next.startsWith('--')) { args[key] = true; } else { args[key] = next; i++; }
}
const base = (args['base-url'] || '').replace(/\/$/, '');
const mode = args.mode || 'cloud';
const out = path.resolve(args.out || `./evidence/phase2/${mode}-${Date.now()}`);
const pass = process.env.POS_SHOT_PASS || '';
if (!base || !pass) { console.error('need --base-url and POS_SHOT_PASS in the environment'); process.exit(2); }
const viewports = [{ name: '1366x768', width: 1366, height: 768 }, { name: '1024x768', width: 1024, height: 768 }];
const report = { base, mode, started: new Date().toISOString(), shots: [], skipped: [], errors: [] };
fs.mkdirSync(out, { recursive: true });

const browser = await chromium.launch({ channel: args.channel || 'msedge', headless: true });
for (const vp of viewports) {
  const ctx = await browser.newContext({ ignoreHTTPSErrors: !!args['ignore-tls'], viewport: { width: vp.width, height: vp.height }, deviceScaleFactor: 1 });
  // Offline discipline: abort every request that is not same-host loopback (e.g. the theme's Google Fonts @import) — it also
  // stops the load event from hanging on an unreachable CDN. Blocked hosts are recorded in the report.
  await ctx.route("**/*", (route) => { const u = new URL(route.request().url()); const ok = ["127.0.0.1", "localhost", "edgehomelab.localhost", "desktop-0024epm.local"].includes(u.hostname.toLowerCase()); if (!ok) { report.blocked = report.blocked || []; if (!report.blocked.includes(u.hostname)) report.blocked.push(u.hostname); return route.abort(); } return route.continue(); });
  const page = await ctx.newPage();
  page.setDefaultNavigationTimeout(90000); page.setDefaultTimeout(20000);
  page.on('pageerror', (e) => report.errors.push({ vp: vp.name, error: String(e).slice(0, 200) }));
  const dir = path.join(out, vp.name); fs.mkdirSync(dir, { recursive: true });
  const shot = async (name) => { await page.waitForTimeout(400); await page.screenshot({ path: path.join(dir, `${name}.png`), fullPage: false }); report.shots.push(`${vp.name}/${name}`); };
  const open = async (name, clickSel, waitSel) => {
    try {
      const el = page.locator(clickSel).first();
      if (!(await el.count()) || !(await el.isVisible())) { report.skipped.push(`${vp.name}/${name}: ${clickSel} not visible`); return false; }
      await el.click();
      if (waitSel) await page.locator(waitSel).first().waitFor({ state: 'visible', timeout: 8000 });
      await shot(name); return true;
    } catch (e) { report.skipped.push(`${vp.name}/${name}: ${String(e).slice(0, 120)}`); return false; }
  };
  const closeModals = async () => { await page.keyboard.press('Escape'); await page.waitForTimeout(300); await page.keyboard.press('Escape'); await page.waitForTimeout(300); };

  // login
  if (mode === 'cloud') {
    await page.goto(`${base}/login`, { waitUntil: 'load' });
    await page.fill('input[name="email"]', args.email || 'lab.cashier@edgehomelab.test');
    await page.fill('input[name="password"]', pass);
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('button[type="submit"]')]);
    await page.goto(`${base}/pos`, { waitUntil: 'load' });
  } else {
    await page.goto(`${base}/edge/local/login`, { waitUntil: 'load' });
    await page.fill('input[name="employee_code"]', args.user || 'LAB2C5D');
    await page.fill('input[name="credential"], input[name="password"]', pass);
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('button[type="submit"]')]);
    await page.goto(`${base}/edge/local/pos`, { waitUntil: 'load' });
  }
  await page.waitForTimeout(1200);
  await shot('01-main');
  // second category tab (if any)
  const tabs = page.locator('.category-pill, .pill');
  if ((await tabs.count()) > 1) { await tabs.nth(1).click(); await shot('02-category'); await tabs.nth(0).click(); }
  // modals
  await open('03-customer-modal', '#pos-customer-btn, [data-bs-target="#customerModal"], #customer-btn', '#customerModal, #modal'); await closeModals();
  await open('04-context-modal', '[data-bs-target="#posContextModal"], #pos-context-btn, #ctx-change', '#posContextModal'); await closeModals();
  await open('05-held-orders', '#held-orders-btn', '#heldSalesModal, #modal'); await closeModals();
  await open('06-recent-orders', '#completed-orders-btn, #recent-orders-btn', '#completedOrdersModal, #modal'); await closeModals();
  await open('07-recent-prints', '#last-print-btn, #recent-prints-btn', '#lastPrintModal, #modal'); await closeModals();
  await open('08-quick-report', '#quick-report-btn, [data-bs-target="#quickReportModal"]', '#quickReportModal, #modal'); await closeModals();
  await open('09-table-workspace', '#view-tables-btn, #pos-view-tables-btn, [data-bs-target="#tableWorkspaceModal"]', '#tableWorkspaceModal, #modal'); await closeModals();
  // add two tiles then review & pay
  const tiles = page.locator('.product-tile, #product-grid .tile, #tiles .tile');
  if ((await tiles.count()) >= 2) {
    await tiles.nth(0).click(); await page.waitForTimeout(300); await closeModals();
    await tiles.nth(1).click(); await page.waitForTimeout(300); await closeModals();
    await shot('10-cart-two-lines');
    await open('11-review-pay', '#review-pay-btn, #pos-review-pay-btn, [data-bs-target="#paymentModal"]', '#paymentModal, #modal'); await closeModals();
  } else { report.skipped.push(`${vp.name}/10-11: fewer than 2 tiles`); }
  await open('12-qty-entry', '.product-tile:has-text("per kg"), #product-grid .tile:has-text("per kg")', '#qtyEntryModal, #modal'); await closeModals();
  await open('13-modifier-entry', '.product-tile:has-text("Tikka"), #product-grid .tile:has-text("Tikka")', '#modifierEntryModal, #modal'); await closeModals();
  await ctx.close();
}
await browser.close();
report.finished = new Date().toISOString();
fs.writeFileSync(path.join(out, 'report.json'), JSON.stringify(report, null, 2));
console.log(JSON.stringify({ out, shots: report.shots.length, skipped: report.skipped, errors: report.errors.length }));
