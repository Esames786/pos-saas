// W7 — Edge cashier BROWSER proof (JS-executing), owner directive 20 Sep 2026 after the 149-record audit.
//
// Why: every Edge control is JS-rendered and no PHP test executes JavaScript (audit E-15). This script drives the real
// page in a real browser (the locally installed Microsoft Edge / Chrome via Playwright's channel — no download), logs in
// with the operator's Edge credential, and records:
//   1. the DOM census — which Online-control counterparts from tests/Fixtures/edge/online-pos-control-census.json
//      actually exist in the live DOM after the page booted (present / equivalent / partial rows), incl. inside the
//      dialogs it opens (View Tables, Shift, Returns, Quick Report, Recent Prints, Review & Pay, Preview Bill);
//   2. paired-viewport screenshots (1366×768 desktop, 1024×768 tablet, 800×600 small) of the main page and each dialog;
//   3. a JSON report with counts and denominators (never a percentage).
//
// Usage (secrets NEVER on the command line — the credential is read from an environment variable):
//   set EDGE_PROOF_PASS_ENV=EDGE_LAB_CASHIER_PASS   (name of the variable holding the password)
//   node edge-pos-proof.mjs --base-url https://desktop-0024epm.local:8443 --user LAB2C5D [--ignore-tls] [--channel msedge|chrome]
//
// Runs against the LAB appliance (after the owner-approved signed update) or a dev Edge instance — NEVER a live tenant.
// Output: ./evidence/<timestamp>/…png + report.json
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const args = Object.fromEntries(process.argv.slice(2).map((a, i, all) => a.startsWith('--') ? [a.slice(2), all[i + 1] && !all[i + 1].startsWith('--') ? all[i + 1] : true] : []).filter(Boolean));
const baseUrl = String(args['base-url'] || '').replace(/\/$/, '');
const user = String(args.user || '');
const passEnv = process.env.EDGE_PROOF_PASS_ENV || 'EDGE_PROOF_PASS';
const pass = process.env[passEnv] || '';
const channel = String(args.channel || 'msedge');
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
const report = { base_url: baseUrl, user, started_at: new Date().toISOString(), viewports: {}, dom_census: {}, dialogs: {}, console_errors: [] };

const browser = await chromium.launch({ channel, headless: true });
try {
  const context = await browser.newContext({ ignoreHTTPSErrors: !!args['ignore-tls'], viewport: viewports[0] });
  const page = await context.newPage();
  page.on('console', m => { if (m.type() === 'error') report.console_errors.push(m.text()); });
  page.on('pageerror', e => report.console_errors.push('pageerror: ' + e.message));

  // login — the Edge local login form (employee code + credential)
  await page.goto(baseUrl + '/edge/local/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name=employee_code], input[name=username], input[name=login]', user).catch(() => {});
  await page.fill('input[type=password]', pass);
  await page.click('button[type=submit], button:has-text("Log in"), button:has-text("Login")');
  await page.waitForURL(/edge\/local\/pos/, { timeout: 15000 }).catch(() => {});
  await page.goto(baseUrl + '/edge/local/pos', { waitUntil: 'networkidle' });
  await page.waitForSelector('#tiles .tile, #tiles p', { timeout: 15000 });

  const seen = new Set();
  const collect = async (label) => {
    const found = await page.evaluate(ids => ids.filter(id => document.getElementById(id) !== null), [...idSelectors]);
    found.forEach(id => seen.add(id));
    report.dialogs[label] = { dom_ids_found: found.length };
  };

  // main page at each viewport
  for (const vp of viewports) {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    await page.waitForTimeout(250);
    const hasHorizontalScroll = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    const tile = await page.locator('#tiles .tile').first().boundingBox().catch(() => null);
    report.viewports[vp.name] = { horizontal_overflow: hasHorizontalScroll, first_tile_px: tile ? { w: Math.round(tile.width), h: Math.round(tile.height) } : null };
    await page.screenshot({ path: path.join(outDir, `pos-main-${vp.name}.png`), fullPage: false });
  }
  await page.setViewportSize(viewports[0]);
  await collect('main');

  // dialogs the operator reaches from the main page (each is opened, censused, shot, closed)
  const dialogs = [
    ['view-tables', '#view-tables-btn'], ['shift', '#shift-btn'], ['returns', '#returns-btn'],
    ['quick-report', '#quick-report-btn'], ['recent-prints', '#recent-prints-btn'],
  ];
  for (const [label, trigger] of dialogs) {
    if (await page.locator(trigger).count() === 0) { report.dialogs[label] = { trigger_missing: trigger }; continue; }
    await page.click(trigger);
    await page.waitForSelector('#modal.open', { timeout: 10000 }).catch(() => {});
    await page.waitForTimeout(600);
    await collect(label);
    report.dialogs[label].heading = await page.locator('#modal-body h2').first().textContent().catch(() => null);
    await page.screenshot({ path: path.join(outDir, `dialog-${label}.png`) });
    await page.evaluate(() => window.EdgePOS && window.EdgePOS.closeModal());
  }
  // Review & Pay + Preview Bill need a cart line: add the first tile, open each, then leave the cart untouched (no sale).
  if (await page.locator('#tiles .tile').count() > 0) {
    await page.locator('#tiles .tile').first().click();
    for (const [label, trigger] of [['preview-bill', '#preview-bill-btn'], ['review-pay', '#review-pay-btn']]) {
      if (await page.locator(trigger).count() === 0) { report.dialogs[label] = { trigger_missing: trigger }; continue; }
      await page.click(trigger);
      await page.waitForSelector('#modal.open', { timeout: 10000 }).catch(() => {});
      await page.waitForTimeout(600);
      await collect(label);
      report.dialogs[label].heading = await page.locator('#modal-body h2').first().textContent().catch(() => null);
      await page.screenshot({ path: path.join(outDir, `dialog-${label}.png`) });
      await page.evaluate(() => window.EdgePOS && window.EdgePOS.closeModal());
    }
    // NO payment is ever taken by this script.
  }

  // DOM census: per census row, did every '#id' counterpart appear somewhere we looked?
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
console.log(JSON.stringify({ evidence: outDir, viewports: report.viewports, dom_census: report.dom_census.by_state, console_errors: report.console_errors.length }, null, 2));
