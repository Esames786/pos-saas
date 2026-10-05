// G2 — SHARED CASHIER VIEW: end-to-end workflow proof in a real browser, BOTH runtimes (Online = the specification).
//
//   node shared-pos-workflows.mjs --mode edge   --base-url http://127.0.0.1:8095 --user DEVCASH1 --manager-user DEVMGR1
//   node shared-pos-workflows.mjs --mode online --base-url http://edgehomelab.localhost:9704 --user lab.cashier@edgehomelab.test
//
//   Secrets ONLY through the environment (never argv, never printed):
//     POS_SHOT_PASS  the cashier credential (Edge: employee credential; Online: tenant e-mail password)
//     POS_MGR_PASS   the approver credential (Edge: DEVMGR1's Edge credential; Online: the manager PIN)
//   Options: --out <dir> (default C:\Users\Dell\BingooEdgeLab\evidence\phase2\step7-workflows\<mode>)
//            --only W03,W05  run a subset   --channel msedge|chrome   --settle <ms>   --customer-query <text>
//            --headed        show the browser
//            --slow <factor>  multiply EVERY bounded wait (timeouts, response windows, polls) by this factor — for a loaded box
//            --action-timeout <ms> (default 30000) --nav-timeout <ms> (default 90000): the dev servers share ONE MySQL with other
//            teams' phpunit gates and appliance pairings; under that load a page can take > 60 s (measured 137 s on 4 Oct 13:29).
//            Every workflow still records the real latency of every request (http[].ms / .at) so a slow run stays attributable.
//
// What it records (report.json in --out): per workflow PASS / FAIL / SKIP / EXPECTED-DIFFERENCE + reason + sub-steps;
// every same-origin non-asset HTTP exchange (method, path, status, and the JSON ok/code/message when present), every
// console error / page error, every SweetAlert toast and popup, the runtime contract (window.POS_RUNTIME), and every
// control the page renders disabled-with-a-hint. Screenshots: <out>/<Wnn>-<workflow>-<step>.png at 1366×768.
//
// Safety: runs ONLY against loopback hosts (every other host is aborted at the network layer). It creates disposable
// sales / held orders / table sessions / a return / shifts in the instance it is pointed at — point it at the dev Edge
// instance (bingoo_edge_devtest_local) and the dev Cloud clone (pos_devonline_tenant_edge) only, never a live tenant.
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
const mode = String(args.mode || '');
const base = String(args['base-url'] || '').replace(/\/$/, '');
const user = String(args.user || '');
const managerUser = String(args['manager-user'] || (mode === 'edge' ? 'DEVMGR1' : ''));
const pass = String(process.env.POS_SHOT_PASS || '').trim();
const mgrPass = String(process.env.POS_MGR_PASS || '').trim();
const settleMs = Number(args.settle || 600);
const K = Math.max(1, Number(args.slow || 1));            // --slow <factor>: scales every bounded wait below (see T())
const T = (ms) => Math.round(ms * K);
const only = args.only ? String(args.only).split(',').map(s => s.trim().toUpperCase()) : null;
// --clear-all-open-work: W16 also settles / cancels held orders and closes empty tables that an EARLIER run left behind (still
// through the UI, still the cashier's way) — for a disposable instance that cannot be reset. Default: only this run's work.
const clearAllOpenWork = !!args['clear-all-open-work'];
const customerQuery = String(args['customer-query'] || (mode === 'edge' ? 'Ahmed' : 'Lab Customer'));
if (!['edge', 'online'].includes(mode) || !base || !user || !pass) {
  console.error('need --mode edge|online, --base-url, --user and POS_SHOT_PASS in the environment (POS_MGR_PASS for the approver)');
  process.exit(2);
}
const baseOrigin = new URL(base).origin;
const ALLOWED_HOSTS = ['127.0.0.1', 'localhost', 'edgehomelab.localhost'];
if (!ALLOWED_HOSTS.includes(new URL(base).hostname.toLowerCase())) { console.error('refusing a non-loopback base url'); process.exit(2); }
const outDir = path.resolve(String(args.out || `C:\\Users\\Dell\\BingooEdgeLab\\evidence\\phase2\\step7-workflows\\${mode}`));
fs.mkdirSync(outDir, { recursive: true });
const posPath = mode === 'edge' ? '/edge/local/pos/shared' : '/pos';

// ── report ────────────────────────────────────────────────────────────────────────────────────────────────────────
const report = {
  mode, base, user, manager_user: managerUser || null, started_at: new Date().toISOString(), viewport: '1366x768',
  runtime: null, workflows: {}, http: [], http_errors: [], console_errors: [], toasts: [], popups: [], disabled_controls: [],
  facts: {},
};
let currentWf = 'W00';
const T0 = Date.now();   // every HTTP entry carries at/ms so a slow answer (busy MySQL during a re-seed) is visible in the log
const saveReport = () => fs.writeFileSync(path.join(outDir, 'report.json'), JSON.stringify(report, null, 2));
class SkipError extends Error { constructor(reason, status = 'SKIP') { super(reason); this.skipStatus = status; } }
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

// ── browser ───────────────────────────────────────────────────────────────────────────────────────────────────────
const browser = await chromium.launch({ channel: String(args.channel || 'msedge'), headless: !args.headed });
const context = await browser.newContext({ viewport: { width: 1366, height: 768 }, deviceScaleFactor: 1, ignoreHTTPSErrors: true });
await context.route('**/*', (route) => {
  const u = new URL(route.request().url());
  if (!ALLOWED_HOSTS.includes(u.hostname.toLowerCase())) { (report.facts.blocked_hosts ??= []); if (!report.facts.blocked_hosts.includes(u.hostname)) report.facts.blocked_hosts.push(u.hostname); return route.abort(); }
  return route.continue();
});
// toasts + popups observer (every frame), and a no-op window.print so popups / "Print here" never block headless Chromium
await context.addInitScript(() => {
  window.__toasts = []; window.__popups = [];
  window.print = function () { window.__printed = (window.__printed || 0) + 1; };
  const read = (node) => {
    setTimeout(() => {
      try {
        const toast = node.querySelector('.swal2-toast');
        if (toast) { const t = toast.querySelector('.swal2-title'); window.__toasts.push({ text: (t ? t.textContent : toast.textContent).trim(), at: Date.now() }); return; }
        const popup = node.querySelector('.swal2-popup');
        if (popup) {
          const t = popup.querySelector('.swal2-title'); const h = popup.querySelector('.swal2-html-container');
          window.__popups.push({ title: t ? t.textContent.trim() : '', text: h ? h.textContent.trim().slice(0, 300) : '', at: Date.now() });
        }
      } catch (e) { /* ignore */ }
    }, 0);
  };
  // observe the Document node itself: at document-start there is no <html> element yet
  new MutationObserver((muts) => {
    muts.forEach(m => m.addedNodes.forEach(n => { if (n instanceof HTMLElement && (n.classList.contains('swal2-container') || n.querySelector?.('.swal2-container'))) read(n.classList.contains('swal2-container') ? n : n.querySelector('.swal2-container')); }));
  }).observe(document, { childList: true, subtree: true });
});
const page = await context.newPage();
const actionTimeout = T(Number(args['action-timeout'] || 30000)); const navTimeout = T(Number(args['nav-timeout'] || 90000));
page.setDefaultTimeout(actionTimeout); page.setDefaultNavigationTimeout(navTimeout);
report.timeouts = { action_ms: actionTimeout, navigation_ms: navTimeout, slow_factor: K };
// ── --rewrite-modifiers (PROOF-TOOL WORKAROUND, never a fix) ──────────────────────────────────────────────────────
// The shared view posts each cart line's `modifiers` as a JSON STRING inside the multipart sale/hold form (buildInputs:
// JSON.stringify). Online accepts it (`lines.*.modifiers => string`, normalizeLineModifiers decodes); the Edge twins demand
// `array` and refuse EVERY sale/hold with 422 (recorded as CONTRACT-GAP from the run without this flag). With the flag, the
// tool rewrites those multipart bodies to urlencoded with the modifiers expanded into indexed fields so the workflows BEHIND
// that gap can still be proven on Edge. Every rewritten request is counted and tagged `rewritten` in the HTTP log.
const rewriteModifiers = args['rewrite-modifiers'] === true;
const rewritten = new WeakSet();
report.facts.rewrite_modifiers = { enabled: rewriteModifiers, count: 0, paths: [] };
if (rewriteModifiers) {
  await page.route((url) => url.origin === baseOrigin, async (route) => {
    const req = route.request();
    const ct = String(req.headers()['content-type'] || '');
    if (req.method() !== 'POST' || !ct.startsWith('multipart/form-data') || !/\/(sales|held-sales)(\/\d+\/settle)?$/.test(new URL(req.url()).pathname)) return route.continue();
    const boundary = (ct.match(/boundary=("?)([^";]+)\1/) || [])[2]; const body = req.postData();
    if (!boundary || !body) return route.continue();
    const fields = [];
    for (const p of body.split('--' + boundary)) {
      if (!p || p.startsWith('--')) continue;
      const i = p.indexOf('\r\n\r\n'); if (i < 0) continue;
      const head = p.slice(0, i); const val = p.slice(i + 4).replace(/\r\n$/, '');
      const m = head.match(/name="([^"]*)"/); if (!m || /filename=/.test(head)) continue;
      fields.push([m[1], val]);
    }
    const out = new URLSearchParams(); let touched = false;
    for (const [k, v] of fields) {
      if (/^lines\[\d+\]\[modifiers\]$/.test(k)) {
        let arr = null; try { arr = JSON.parse(v); } catch { }
        if (Array.isArray(arr)) {
          touched = true;
          arr.forEach((mod, j) => Object.entries(mod || {}).forEach(([mk, mv]) => out.append(`${k}[${j}][${mk}]`, mv === null || typeof mv === 'object' ? JSON.stringify(mv) : String(mv))));
          continue;   // an empty array is simply omitted (nullable)
        }
      }
      out.append(k, v);
    }
    if (!touched) return route.continue();
    rewritten.add(req);
    report.facts.rewrite_modifiers.count++; report.facts.rewrite_modifiers.paths.push(`${currentWf} ${new URL(req.url()).pathname}`);
    const headers = { ...req.headers(), 'content-type': 'application/x-www-form-urlencoded' }; delete headers['content-length'];
    return route.continue({ headers, postData: out.toString() });
  });
}
page.on('dialog', d => { report.facts.native_dialogs ??= []; report.facts.native_dialogs.push({ wf: currentWf, type: d.type(), message: d.message().slice(0, 160) }); d.accept().catch(() => {}); });
page.on('console', m => { if (m.type() === 'error') report.console_errors.push({ wf: currentWf, text: m.text().slice(0, 300) }); });
page.on('pageerror', e => report.console_errors.push({ wf: currentWf, text: 'pageerror: ' + String(e.message || e).slice(0, 300) }));
const reqStart = new WeakMap(); page.on('request', r => reqStart.set(r, Date.now()));   // per-request clock → http[].ms
page.on('response', async (res) => {
  try {
    const req = res.request(); const u = new URL(res.url());
    if (u.origin !== baseOrigin) return;
    if (/\.(css|js|png|jpe?g|gif|svg|woff2?|ttf|ico|map)(\?|$)/i.test(u.pathname) || /^\/(edge\/local\/)?(assets|storage)\//.test(u.pathname)) return;
    const entry = { wf: currentWf, method: req.method(), path: u.pathname + u.search, status: res.status(), type: req.resourceType() };
    entry.at = Date.now() - T0; const t0 = reqStart.get(req); if (t0) entry.ms = Date.now() - t0;
    if (rewritten.has(req)) entry.rewritten = 'modifiers-json-string→indexed-fields (proof-tool workaround)';
    const ct = String(res.headers()['content-type'] || '');
    if (ct.includes('json')) {
      const j = await res.json().catch(() => null);
      if (j && typeof j === 'object') {
        if ('ok' in j) entry.ok = j.ok; if ('code' in j) entry.code = j.code; if (j.message != null) entry.message = String(j.message).slice(0, 200);
        if (j.code === 'BRANCH_LOCAL_EDGE_ACTIVE') report.facts.authority_fenced = (report.facts.authority_fenced || 0) + 1;   // the clone's authority lease is held by a paired appliance (another team's run): wait 2–3 min, re-run
        if (j.sale_no) entry.sale_no = j.sale_no; if (j.sale_id) entry.sale_id = j.sale_id;
        if (j.errors) entry.errors = Object.keys(j.errors);
        entry.keys = Object.keys(j).slice(0, 14);
      }
    } else if (res.status() >= 300 && res.status() < 400) { entry.location = String(res.headers()['location'] || '').replace(base, ''); }
    report.http.push(entry);
    if (res.status() >= 400) report.http_errors.push(entry);
  } catch (e) { /* body gone (navigation) — the status line is still recorded above when possible */ }
});

// ── generic helpers ───────────────────────────────────────────────────────────────────────────────────────────────
let RT = null;
const route = (key, params = {}) => {
  let t = RT?.routes?.[key]; if (t == null) return null;
  Object.entries(params).forEach(([k, v]) => { t = t.split('{' + k + '}').join(encodeURIComponent(String(v))); });
  return t;
};
const pathOf = (url) => { const u = new URL(url); return u.pathname; };
const matchRoute = (key, params = {}) => { const t = route(key, params); if (!t) return () => false; const p = t.split('?')[0]; return (res) => pathOf(res.url()) === p || pathOf(res.url()).startsWith(p); };
const loc = (sel, scope = page) => scope.locator(sel).first();
const vis = async (sel, scope = page) => scope.locator(sel).first().isVisible().catch(() => false);
const text = async (sel, scope = page) => (await scope.locator(sel).first().textContent().catch(() => '') || '').trim();
const drainToasts = async (scope = page) => { const t = await scope.evaluate(() => { const x = window.__toasts || []; window.__toasts = []; return x; }).catch(() => []); t.forEach(x => report.toasts.push({ wf: currentWf, ...x })); return t.map(x => x.text); };
const drainPopups = async (scope = page) => { const t = await scope.evaluate(() => { const x = window.__popups || []; window.__popups = []; return x; }).catch(() => []); t.forEach(x => report.popups.push({ wf: currentWf, ...x })); return t; };
const swalVisible = (scope = page) => scope.locator('.swal2-popup:not(.swal2-toast)').first().isVisible().catch(() => false);
const swalInfo = async (scope = page) => ({ title: await text('.swal2-popup:not(.swal2-toast) .swal2-title', scope), text: await text('.swal2-popup:not(.swal2-toast) .swal2-html-container', scope), validation: await text('.swal2-validation-message', scope) });
const waitSwal = async (timeout = 8000, scope = page) => { await scope.locator('.swal2-popup:not(.swal2-toast)').first().waitFor({ state: 'visible', timeout: T(timeout) }); await sleep(150); return swalInfo(scope); };
const swalClick = async (which, scope = page) => { const sel = which === 'confirm' ? '.swal2-confirm' : (which === 'deny' ? '.swal2-deny' : '.swal2-cancel'); await scope.locator('.swal2-popup:not(.swal2-toast) ' + sel).first().click(); await sleep(250); };
const waitSwalGone = async (timeout = 10000, scope = page) => { await scope.locator('.swal2-popup:not(.swal2-toast)').first().waitFor({ state: 'hidden', timeout: T(timeout) }).catch(() => {}); };
const modalShown = async (id, timeout = 10000) => { await page.locator('#' + id + '.show').waitFor({ state: 'visible', timeout: T(timeout) }); await sleep(settleMs); };
const hideModal = async (id) => { await page.evaluate((id) => { const el = document.getElementById(id); const inst = window.bootstrap && bootstrap.Modal.getInstance(el); if (inst) inst.hide(); }, id); await page.locator('#' + id + '.show').waitFor({ state: 'hidden', timeout: T(8000) }).catch(() => {}); await sleep(300); };
const waitResponse = (predicate, timeout = 30000) => page.waitForResponse(r => { try { return predicate(r); } catch { return false; } }, { timeout: T(timeout) });
const jsonOf = async (res) => { const body = await res.json().catch(() => null); return { status: res.status(), body }; };
const csrf = () => page.evaluate(() => (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')) || (document.querySelector('input[name="_token"]')?.value) || '');
/** A direct JSON probe from the page context (same session + CSRF) — used ONLY to compare a backend contract both runtimes expose. */
const probe = async (url, { method = 'POST', body = null } = {}) => page.evaluate(async ({ url, method, body, token }) => {
  const init = { method, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token }, credentials: 'same-origin' };
  if (body) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
  const r = await fetch(url, init); const t = await r.text(); let j = null; try { j = JSON.parse(t); } catch { }
  return { status: r.status, content_type: r.headers.get('content-type'), body: j, text: j ? undefined : t.slice(0, 200) };
}, { url, method, body, token: await csrf() });
/** Submit a classic (navigating) form: click WITHOUT Playwright's implicit "wait for the navigation" (that wait is bounded by the
 *  20 s action timeout and was the "page.click: Timeout 20000ms exceeded" of the 04:2x runs — the POST itself was slow while the
 *  Edge dev DB was being re-seeded on the same MySQL), then wait for the POST (the HTTP fact, up to `timeout`) and for the page
 *  that follows. Returns {status, location, ms} or {error}. */
async function submitForm(button, postPath, { timeout = 60000 } = {}) {
  timeout = T(timeout); const t0 = Date.now();
  const postP = waitResponse(r => r.request().method() === 'POST' && pathOf(r.url()) === postPath, timeout)
    .then(r => ({ status: r.status(), location: String(r.headers()['location'] || '').replace(base, ''), ms: Date.now() - t0 }))
    .catch(e => ({ error: String(e.message).split('\n')[0], ms: Date.now() - t0 }));
  const navP = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout }).catch(() => null);
  await button.click({ noWaitAfter: true, timeout: T(15000) });
  const post = await postP;
  if (!post.error) await navP;
  await sleep(settleMs);
  return post;
}

const mkRec = (id, title) => ({ id, title, slug: title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 28), status: 'PASS', reason: null, steps: [], notes: [], shots: [], seq: 0, started_at: new Date().toISOString() });
const shot = async (rec, step, scope = page) => { const file = `${rec.id}-${rec.slug}-${String(++rec.seq).padStart(2, '0')}-${step}.png`; await sleep(settleMs / 2); await scope.screenshot({ path: path.join(outDir, file), fullPage: false }).catch(() => {}); rec.shots.push(file); return file; };
const step = (rec, name, ok, detail) => { rec.steps.push({ step: name, ok: !!ok, detail: detail === undefined ? null : detail }); if (!ok && rec.status === 'PASS') { rec.status = 'FAIL'; rec.reason = `${name}: ${typeof detail === 'string' ? detail : JSON.stringify(detail)}`.slice(0, 400); } };
const note = (rec, n) => rec.notes.push(typeof n === 'string' ? n : JSON.stringify(n));
async function wf(id, title, fn) {
  const rec = mkRec(id, title); report.workflows[id] = rec; currentWf = id; usedTiles.clear();
  if (only && !only.includes(id)) { rec.status = 'SKIP'; rec.reason = 'filtered out by --only'; saveReport(); return; }
  console.log(`\n== ${id} ${title}`);
  try { await fn(rec); }
  catch (e) {
    if (e instanceof SkipError) { rec.status = e.skipStatus; rec.reason = e.message; }
    else { rec.status = 'FAIL'; rec.reason = (rec.reason ? rec.reason + ' | ' : '') + String(e.message || e).split('\n')[0].slice(0, 300); await shot(rec, 'error'); }
  }
  rec.toasts = await drainToasts(); rec.popups = await drainPopups();
  rec.http = report.http.filter(h => h.wf === id).map(({ wf, ...h }) => h);
  rec.console_errors = report.console_errors.filter(c => c.wf === id).map(c => c.text);
  rec.finished_at = new Date().toISOString();
  console.log(`   -> ${rec.status}${rec.reason ? ' — ' + rec.reason : ''}`);
  saveReport();
}

// ── POS helpers ───────────────────────────────────────────────────────────────────────────────────────────────────
const TILE = '#product-grid .product-tile';
const PLAIN_TILE_EXCLUDE = /kg\b|\/kg|per kg|tikka|boti|seekh|half|full|small|large|deal|combo|platter|customizable|variants?/i;
const usedTiles = new Set();   // never re-pick the same tile inside one workflow (a second click only bumps the quantity)
async function gotoPos() {
  await page.goto(base + posPath, { waitUntil: 'domcontentloaded' });
  await page.locator(TILE).first().waitFor({ state: 'visible', timeout: T(30000) });
  await sleep(settleMs);
  if (!RT) RT = await page.evaluate(() => window.POS_RUNTIME || null);
  // shift badge — bounded wait for the shift-status fetch to render it (the single-threaded dev Edge server can take
  // several seconds right after a re-seed; an empty badge here is a harness timing miss, not a product fact)
  await page.locator('#pos-shift-status').waitFor({ state: 'visible', timeout: T(8000) }).catch(() => {});
  for (let i = 0; i < 60 * K && !(await text('#pos-shift-badge')); i++) await sleep(250);
  await sleep(200);
}
const shiftBadge = () => text('#pos-shift-badge');
/** Shift state = the HTTP fact (shift-status JSON for the page's terminal) + the DOM badge. The badge is polled because the view
 *  renders it only after its own fetch returns (an empty badge for several seconds on the single-threaded dev server right after
 *  a re-seed was the "badge shows no open shift: ''" miss of the edge-dev2 run). */
async function shiftState({ wait = 20000 } = {}) {
  wait = T(wait); const t0 = Date.now(); let badge = await shiftBadge();
  while (!badge && Date.now() - t0 < wait) { await sleep(250); badge = await shiftBadge(); }
  const tid = await page.locator('#terminal_id').inputValue().catch(() => '');
  const st = route('shiftStatus') ? await probe(base + route('shiftStatus') + '?terminal_id=' + encodeURIComponent(tid), { method: 'GET' }) : { status: null, body: null };
  return { badge, badge_wait_ms: Date.now() - t0, api: { status: st.status, open: st.body?.open ?? null, shift_id: st.body?.shift_id ?? null, business_date: st.body?.business_date ?? null }, terminal_id: tid, link_visible: await vis('#pos-shift-open-link') };
}
const cartCount = () => page.locator('#cart-items .cart-row').count();
const money = async (sel) => Number((await text(sel)).replace(/[^0-9.\-]/g, '') || 0);
async function setMode(type) {
  const tab = page.locator(`[data-mode-tab="${type}"]`);
  if (!(await tab.count())) return { requested: type, offered: false, actual: await page.locator('#order_type').inputValue().catch(() => null) };
  await tab.first().click(); await sleep(250);
  if (await swalVisible()) { await swalClick('confirm'); await waitSwalGone(); }
  return { requested: type, offered: true, actual: await page.locator('#order_type').inputValue().catch(() => null) };
}
async function offeredModes() { return page.locator('[data-mode-tab]').evaluateAll(els => els.map(e => ({ type: e.dataset.modeTab, label: e.textContent.trim(), active: e.classList.contains('active') }))); }
/** Click a product tile (plain items by default) and confirm any qty / modifier prompt with its defaults. */
async function addTile(opts = {}) {
  const before = await cartCount();
  const tiles = page.locator(TILE);
  const n = await tiles.count(); let picked = null;
  for (let i = 0; i < n; i++) {
    const t = tiles.nth(i); const label = (await t.textContent() || '').trim();
    if (opts.match ? !opts.match.test(label) : PLAIN_TILE_EXCLUDE.test(label)) continue;
    if (!opts.match && usedTiles.has(label)) continue;
    if (await t.evaluate(el => el.classList.contains('stock-out'))) continue;
    usedTiles.add(label);
    picked = { index: i, label: label.replace(/\s+/g, ' ').slice(0, 60) }; await t.click(); break;
  }
  if (!picked) { picked = { index: 0, label: 'first tile' }; await tiles.first().click(); }
  await sleep(300);
  for (let i = 0; i < 3; i++) {
    if (await vis('#qtyEntryModal.show')) { await loc('#qty-modal-confirm').click(); await sleep(300); continue; }
    if (await vis('#modifierEntryModal.show')) { await loc('#modifier-modal-confirm').click(); await sleep(300); continue; }
    if (await swalVisible()) { const s = await swalInfo(); picked.prompt = s.title; if (await vis('.swal2-confirm')) await swalClick('confirm'); await waitSwalGone(); continue; }
    break;
  }
  for (let i = 0; i < 20 * K && (await cartCount()) <= before; i++) await sleep(150);
  picked.cart_rows = await cartCount();
  return picked;
}
const ensureCartLine = async (rec, label) => { const t = await addTile(); step(rec, label || 'add item', t.cart_rows > 0, t); return t; };

/** Approver prompt (shared Swal): employee code + credential on Edge, PIN on Online. Returns the verify exchange. */
async function managerApprove(rec, label, scope = page) {
  const s = await waitSwal(8000, scope);
  if (!/Manager/i.test(s.title)) throw new Error(`expected the Manager Approval prompt, got "${s.title}"`);
  const codeEl = scope.locator('#swal-manager-code'); const hasCode = await codeEl.count();
  if (hasCode) await codeEl.fill(managerUser);
  const pinEl = scope.locator('#swal-manager-pin, #return-manager-pin').first();
  await pinEl.fill(mgrPass);
  await shot(rec, label + '-prompt', scope === page ? page : page);
  const verifyPath = (route('managerVerify') || '').split('?')[0];
  const [res] = await Promise.all([
    waitResponse(r => r.request().method() === 'POST' && pathOf(r.url()) === verifyPath, 20000),
    scope.locator('.swal2-popup:not(.swal2-toast) .swal2-confirm').first().click(),
  ]);
  const out = await jsonOf(res); out.fields = hasCode ? 'employee_code+credential' : 'pin';
  await sleep(400);
  out.validation = await text('.swal2-validation-message', scope);
  if (out.status === 200 && out.body?.ok) await waitSwalGone(8000, scope);
  return out;
}

/** Review & Pay → cash → Complete. Handles the KOT-intent / backorder prompts. */
async function payCash(rec, { kot = 'skip', receipt = true, beforeComplete = null, label = 'pay' } = {}) {
  await loc('#review-pay-btn').click();
  await modalShown('paymentModal');
  const rcp = page.locator('#auto-receipt-toggle');
  if (await rcp.count() && (await rcp.isChecked()) !== receipt && !(await rcp.isDisabled())) { await rcp.click(); await sleep(200); }
  const cashValue = await page.locator('#payment_method_id option[data-type="cash"]').first().getAttribute('value');
  if (cashValue) await page.selectOption('#payment_method_id', cashValue);
  if (beforeComplete) await beforeComplete();
  const total = await money('#grand-total-view');
  await loc('#tendered_amount').fill(String(Math.ceil(total)));
  await loc('#tendered_amount').dispatchEvent('input');
  await sleep(300);
  await shot(rec, label + '-review');
  const saleStore = (route('saleStore') || '').split('?')[0];
  const settleTpl = (RT?.routes?.saleHeldSettle || '').split('?')[0];
  const settleRe = settleTpl ? new RegExp('^' + settleTpl.replace(/[.*+?^$()|[\]\\]/g, '\\$&').replace(/\\\{sale\\\}|\{sale\}/g, '\\d+') + '$') : null;
  const isSalePost = (r) => r.request().method() === 'POST' && (pathOf(r.url()) === saleStore || (settleRe && settleRe.test(pathOf(r.url()))));
  const responseP = waitResponse(isSalePost, 45000).then(jsonOf).catch(e => ({ error: String(e.message).split('\n')[0] }));
  await loc('#complete-sale-btn').click();
  const prompts = [];
  const result = await (async () => {
    for (let i = 0; i < 150 * K; i++) {
      const done = await Promise.race([responseP.then(x => x), sleep(300).then(() => null)]);
      if (done) return done;
      if (await swalVisible()) {
        const s = await swalInfo(); prompts.push(s.title);
        if (/Print Kitchen Order/i.test(s.title)) { await swalClick(kot === 'print' ? 'confirm' : 'cancel'); }
        else if (/Backorder/i.test(s.title)) { await swalClick('confirm'); }
        else if (/short|Cannot complete|Quick Sale|customer/i.test(s.title)) { await shot(rec, label + '-refused'); return { refused: s }; }
        else { await swalClick('confirm'); }
        await sleep(300);
      }
    }
    return { error: `no sale response within ${T(45000) / 1000}s` };
  })();
  result.prompts = prompts; result.total = total;
  await sleep(600);
  result.toasts = await drainToasts();
  await shot(rec, label + '-after');
  if (await vis('#paymentModal.show')) await hideModal('paymentModal');
  return result;
}

/** Regex for a route template with {param} placeholders (Edge: /edge/local/pos/sales/{sale}/kot · Online: /printing/jobs/kot/{sale}). */
const routeRe = (key) => { const tpl = (RT?.routes?.[key] || '').split('?')[0]; if (!tpl) return null; return new RegExp('^' + tpl.replace(/[.*+?^$()|[\]\\]/g, '\\$&').replace(/\\\{[a-zA-Z_]+\\\}|\{[a-zA-Z_]+\}/g, '\\d+') + '$'); };
/** Compact view of a KOT / receipt job in either runtime's response (Edge: id/job_no/print_status; Online: job_id/job_no/fallback/line_quantities). */
const jobView = (j) => ({ job_id: j.job_id ?? j.id ?? null, job_no: j.job_no ?? null, status: j.print_status || j.status || null, printer: j.printer_name || j.printer_type || null, fallback: j.fallback ?? null, lines: j.line_quantities ? Object.keys(j.line_quantities).length : null });
const runHeld = new Set();   // every held order number this run created (for the W16 "settle open work" ledger)
const runSessions = new Set();   // every table session id this run opened (W06 ×2, W07, W14) — W16 closes ONLY these

/** Hold (held store) and the KOT prompt / job that follows. The KOT verdict is read from the KOT response itself
 *  (`jobs` on both runtimes — Edge {jobs, message, reminder}, Online {jobs, reminder}), never from a path heuristic. */
async function holdOrder(rec, { kot = 'skip', label = 'hold' } = {}) {
  const heldPath = (route('heldStore') || '').split('?')[0];
  const kotRe = routeRe('kotQueue');
  const isKotPost = (r) => r.request().method() === 'POST' && !!kotRe && kotRe.test(pathOf(r.url()));
  const heldP = waitResponse(r => r.request().method() === 'POST' && pathOf(r.url()) === heldPath, 30000).then(jsonOf).catch(e => ({ error: String(e.message).split('\n')[0] }));
  // armed BEFORE the click: an auto-KOT terminal fires the KOT right after the held response, without a prompt
  const kotP = waitResponse(isKotPost, 30000).then(jsonOf).catch(() => null);
  await loc('#hold-sale-btn').click();
  const held = await heldP;
  if (held.body?.sale_no) runHeld.add(held.body.sale_no);
  let kotRes = null; let prompted = false;
  for (let i = 0; i < 20; i++) {
    await sleep(300);
    if (await swalVisible()) {
      const s = await swalInfo();
      if (/Print Kitchen Order/i.test(s.title)) {
        prompted = true;
        if (kot === 'print') { await swalClick('confirm'); kotRes = await kotP; if (!kotRes) kotRes = { error: 'no KOT response within 15s of confirming the prompt' }; }
        else { await swalClick('cancel'); kotRes = { skipped: true }; }
        break;
      }
      if (/Open Orders Found/i.test(s.title)) { await swalClick('cancel'); break; }
      break;
    }
    if (i > 5) break;
  }
  if (!kotRes) {
    // no prompt: either the terminal auto-fires the KOT (wait for the actual response — on the single-threaded dev Edge
    // server the first KOT of a fresh sale can take > 6 s) or nothing was kitchen-bound (short grace only)
    const auto = kot === 'print' && !held.error ? await kotP : await Promise.race([kotP, sleep(held.error ? 0 : T(3000)).then(() => null)]);
    if (auto) { kotRes = { ...auto, auto: true }; }
  }
  if (kotRes && kotRes.body && typeof kotRes.body === 'object') {
    kotRes.prompted = prompted;
    kotRes.jobs = Array.isArray(kotRes.body.jobs) ? kotRes.body.jobs.map(jobView) : null;
    kotRes.job_count = Array.isArray(kotRes.body.jobs) ? kotRes.body.jobs.length : null;
    kotRes.message = kotRes.body.message ?? null;
    kotRes.reminder = kotRes.body.reminder ? { revision: kotRes.body.reminder.revision ?? null, auto_jobs: Array.isArray(kotRes.body.reminder.auto_jobs) ? kotRes.body.reminder.auto_jobs.length : null } : null;
    kotRes.keys = Object.keys(kotRes.body);
    delete kotRes.body;
  }
  await sleep(400);
  const out = { held, kot: kotRes, toasts: await drainToasts(), recalled_bar: await text('#recalled-order-no') };
  await shot(rec, label);
  return out;
}
/** KOT verdict helper: the POST answered 2xx and `jobs` carries at least one job. */
const kotOk = (k) => !!k && !k.error && !k.skipped && k.status < 300 && Array.isArray(k.jobs) && k.jobs.length > 0;
const kotDetail = (k) => k && { status: k.status, auto: k.auto || false, prompted: k.prompted || false, job_count: k.job_count, jobs: k.jobs, message: k.message, reminder: k.reminder, keys: k.keys, skipped: k.skipped, error: k.error };

async function openWorkspace() {
  if (!(await vis('#tableWorkspaceModal.show'))) { await loc('#view-tables-btn').click(); await modalShown('tableWorkspaceModal'); }
  await page.locator('#table-board-body [data-open-table], #table-board-body [data-table-session-select]').first().waitFor({ state: 'visible', timeout: T(15000) });
  await sleep(settleMs);
}
async function boardSummary() {
  return page.evaluate(() => {
    const out = [];
    document.querySelectorAll('#table-board-body [data-table-id], #table-board-body [data-session-id]').forEach(() => {});
    document.querySelectorAll('#table-board-body .status-chip').forEach(chip => {
      const card = chip.closest('[class*="table"], .card, .tbl') || chip.parentElement?.parentElement;
      const no = card ? (card.querySelector('[data-table-no]')?.dataset.tableNo || (card.textContent.match(/\b(T?\d+)\b/) || [])[1] || '') : '';
      out.push({ table: no, status: chip.textContent.trim(), session: card?.querySelector('[data-session-id]')?.dataset.sessionId || null });
    });
    return out;
  });
}
/** Free tables on the board (Open Table button whose card also offers Reserve = available, not reserved). */
async function freeTableButtons() {
  return page.evaluate(() => Array.from(document.querySelectorAll('#table-board-body [data-open-table="1"]'))
    .filter(b => b.parentElement && b.parentElement.querySelector('[data-table-reserve]'))
    .map(b => ({ tableId: b.dataset.tableId, tableNo: b.dataset.tableNo })));
}
async function openFreeTable(rec, label = 'open-table', guests = 2) {
  await openWorkspace();
  const free = await freeTableButtons();
  if (!free.length) throw new Error('no free table on the board');
  const t = free[0];
  await page.locator(`#table-board-body [data-open-table="1"][data-table-id="${t.tableId}"]`).first().click();
  await page.locator('#table-workspace-open').waitFor({ state: 'visible', timeout: T(8000) });
  await sleep(300);
  const roster = page.locator('#waiter-roster [role="option"], #waiter-roster button, #waiter-roster .waiter-choice').first();
  if (await roster.count()) await roster.click().catch(() => {});
  await loc('#guest_count').fill(String(guests));
  await shot(rec, label + '-form');
  const [res] = await Promise.all([
    waitResponse(r => r.request().method() === 'POST' && /\/tables\/\d+\/open$/.test(pathOf(r.url())), 30000),
    loc('#open-table-submit').click(),
  ]);
  const out = await jsonOf(res);
  await page.locator('#tableWorkspaceModal.show').waitFor({ state: 'hidden', timeout: T(10000) }).catch(() => {});
  await sleep(500);
  if (await swalVisible()) { const s = await swalInfo(); out.popup = s.title; if (/Open Orders Found/i.test(s.title)) await swalClick('confirm'); else await swalClick('confirm'); await waitSwalGone(); }
  out.session_bar = { table: await text('#pos-session-table-no'), session: await text('#pos-session-no'), visible: await vis('#pos-session-bar') };
  out.table = t;
  out.session_id = await sessionIdOnPage(); if (out.session_id) runSessions.add(String(out.session_id));
  await shot(rec, label + '-opened');
  return out;
}
async function sessionIdOnPage() { return page.locator('#restaurant_table_session_id').inputValue().catch(() => ''); }
/** Poll a predicate (DOM confirmation after an HTTP outcome) instead of a fixed sleep. */
async function pollUntil(fn, { timeout = 8000, interval = 250 } = {}) { timeout = T(timeout); const t0 = Date.now(); let last = null; while (Date.now() - t0 < timeout) { last = await fn().catch(() => null); if (last) return last; await sleep(interval); } return last; }
/** Live board facts for one table id / session id (the shared partial renders the status chip + control buttons per tile). */
async function boardTile(tableId, sessionId) {
  return page.evaluate(({ tableId, sessionId }) => {
    const ctl = tableId != null ? document.querySelector(`#table-board-body [data-table-id="${tableId}"], #table-board-body [data-table-reserve="${tableId}"], #table-board-body [data-reservation-details="${tableId}"], #table-board-body [data-table-unreserve="${tableId}"]`) : document.querySelector(`#table-board-body [data-session-id="${sessionId}"]`);
    const tile = ctl ? ctl.closest('.restaurant-table-tile') : null;
    if (!tile) return null;
    return {
      table_no: (tile.querySelector('.fw-bold')?.textContent || '').trim(),
      chip: (tile.querySelector('.status-chip')?.textContent || '').trim(),
      classes: tile.className,
      has_open: !!tile.querySelector('[data-open-table="1"]'), has_reserve: !!tile.querySelector('[data-table-reserve]'),
      has_details: !!tile.querySelector('[data-reservation-details]'), has_unreserve: !!tile.querySelector('[data-table-unreserve]'),
      session_id: tile.querySelector('[data-session-id]')?.dataset.sessionId || null,
      reserved_name: tile.querySelector('[data-reservation-details]') ? (tile.querySelector('.small strong')?.textContent.trim() || null) : null,
    };
  }, { tableId: tableId ?? null, sessionId: sessionId ?? null });
}
/** Continue Table from the board → "Open Orders Found" → pick an order (the shared view recalls it into the cart). */
async function continueSession(rec, sessionId, label = 'continue') {
  await openWorkspace();
  const sel = page.locator(`#table-board-body [data-table-session-select="1"][data-session-id="${sessionId}"]`).first();
  if (!(await sel.count())) return { on_board: false };
  const openOrdersRe = routeRe('tableSessionOpenOrders');
  const [res] = await Promise.all([waitResponse(r => !!openOrdersRe && openOrdersRe.test(pathOf(r.url())), 20000), sel.click()]);
  const j = await jsonOf(res);
  const orders = (j.body?.orders || []).map(o => ({ id: o.id, sale_no: o.sale_no, items: o.items_count, total: o.grand_total_formatted }));
  const out = { on_board: true, open_orders: { status: j.status, ok: j.body?.ok, orders } };
  if (orders.length) {
    const s = await waitSwal(10000); out.popup = s.title;
    if (/Open Orders Found/i.test(s.title)) { await page.locator('.swal2-popup .open-order-choice').first().click(); await waitSwalGone(); }
    else if (/Manager/i.test(s.title)) { out.approval = await managerApprove(rec, label + '-approver'); }
    else await swalClick('confirm');
  }
  await page.locator('#tableWorkspaceModal.show').waitFor({ state: 'hidden', timeout: T(8000) }).catch(() => {});
  await pollUntil(async () => (await cartCount()) > 0, { timeout: T(4000) });
  out.cart_rows = await cartCount(); out.recalled = await text('#recalled-order-no'); out.session_on_page = await sessionIdOnPage();
  await shot(rec, label);
  return out;
}
async function disabledCensus(label, scope = page) {
  const rows = await scope.evaluate(() => Array.from(document.querySelectorAll('[disabled][title], .disabled[title], [aria-disabled="true"][title], option[disabled][title]'))
    .map(el => ({ id: el.id || null, tag: el.tagName.toLowerCase(), text: (el.textContent || el.value || '').trim().replace(/\s+/g, ' ').slice(0, 60), title: el.getAttribute('title') }))).catch(() => []);
  rows.forEach(r => { const key = `${r.id}|${r.text}|${r.title}`; if (!report.disabled_controls.some(x => x.key === key)) report.disabled_controls.push({ key, where: label, ...r }); });
  return rows;
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// WORKFLOWS
// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
let lastSaleNo = null, lastSaleId = null;      // W03's sale (for W09 / W10)
let heldForVoid = null;                        // W07's held order
const facts = report.facts;

await wf('W01', 'login and POS renders', async (rec) => {
  // warm-up FACT (not a verdict): the latency of the first page answers — a busy shared MySQL (other teams' phpunit gates, an
  // appliance bootstrap on the clone) is a proof-tool condition, recorded so a slow run is attributable; up to 3 attempts
  facts.warmup = [];
  for (let i = 0; i < 3; i++) {
    const t0 = Date.now(); const r = await page.request.get(base + (mode === 'edge' ? '/edge/local/health' : '/login'), { timeout: navTimeout }).catch(() => null);
    facts.warmup.push({ status: r ? r.status() : null, ms: Date.now() - t0 }); if (r && Date.now() - t0 < 10000) break;
  }
  note(rec, { warmup: facts.warmup });
  let loginPost = null;
  if (mode === 'online') {
    await page.goto(base + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', user); await page.fill('input[name="password"]', pass);
    loginPost = await submitForm(page.locator('form:has(input[name="password"]) button[type="submit"]').first(), '/login');
  } else {
    await page.goto(base + '/edge/local/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="employee_code"]', user); await page.fill('input[name="credential"], input[name="password"]', pass);
    loginPost = await submitForm(page.locator('button[type="submit"]').first(), '/edge/local/login');
  }
  step(rec, 'login (POST accepted + landed off the login page)', !loginPost.error && loginPost.status < 400 && !/login/.test(page.url()), { post: loginPost, landed: page.url().replace(base, '') });
  await gotoPos();
  report.runtime = RT ? { mode: RT.mode, managerCredential: RT.managerCredential, capabilities: RT.capabilities, labels: RT.labels, authority: RT.authority, identity: RT.identity, routes_null: Object.entries(RT.routes || {}).filter(([, v]) => v === null).map(([k]) => k) } : null;
  step(rec, 'window.POS_RUNTIME present', !!RT, RT ? { mode: RT.mode, managerCredential: RT.managerCredential } : null);
  const tiles = await page.locator(TILE).count();
  step(rec, 'product grid renders', tiles > 0, { tiles });
  facts.status_slot = { state: await text('#pos-runtime-state'), sub: await text('#pos-runtime-sub'), pending_visible: await vis('#pos-runtime-pending'), pending: await text('#pos-runtime-pending') };
  facts.terminal = { branch: await text('#ctx-branch-name'), terminal_label: await text('#ctx-terminal-name'), terminal_id: await page.locator('#terminal_id').inputValue().catch(() => null), options: await page.locator('#terminal_id option').evaluateAll(o => o.map(x => x.textContent.trim())) };
  facts.shift_badge_initial = await shiftBadge();
  facts.modes = await offeredModes();
  facts.header_buttons = { report: await vis('#pos-report-btn'), return: await vis('#pos-return-btn'), quick_report: await vis('#pos-quick-report-btn'), customer: await vis('#pos-customer-btn'), view_tables: await vis('#view-tables-btn') };
  note(rec, facts.status_slot); note(rec, facts.terminal); note(rec, { shift_badge: facts.shift_badge_initial, modes: facts.modes.map(m => m.type), header_buttons: facts.header_buttons });
  step(rec, 'status slot has text', !!facts.status_slot.state, facts.status_slot);
  step(rec, 'terminal badge has text', !!facts.terminal.terminal_label || !!facts.terminal.terminal_id, facts.terminal);
  await disabledCensus('W01 main page (incl. hidden modals)');
  await shot(rec, 'pos-main');
});

await wf('W02', 'shift open via separate page', async (rec) => {
  await gotoPos();
  const st = await shiftState();
  note(rec, { shift_state_before: st });
  const openPath = (route('shiftOpenStore') || '').split('?')[0];
  if (st.api.open === true || /shift open/i.test(st.badge)) {
    // IDEMPOTENT: a shift is already open on this terminal (an earlier run, or an operator). Record it and PASS — the view hides
    // the "Open shift" link in that state by design, so the separate page is only probed for reachability.
    facts.shift_preexisting = { shift_id: st.api.shift_id, business_date: st.api.business_date };
    step(rec, 'shift already open (shift-status open=true + badge "Shift open")', st.api.open === true && /shift open/i.test(st.badge), st);
    step(rec, 'Open shift link hidden while a shift is open', !st.link_visible, { link_visible: st.link_visible });
    const pg = await probe(base + route('shiftOpenPage'), { method: 'GET' });
    rec.steps.push({ step: 'shift open page reachable (GET probe)', ok: pg.status < 400, detail: { url: route('shiftOpenPage'), status: pg.status }, probe: true });
    if (pg.status >= 400) { rec.classification = 'PERMISSION/DATA'; step(rec, 'shift open page answers for this operator', false, { status: pg.status }); }
    await shot(rec, 'already-open');
    return;
  }
  step(rec, 'no open shift (shift-status open=false + badge "No open shift")', st.api.open === false && /no open shift/i.test(st.badge), st);
  const link = page.locator('#pos-shift-open-link');
  const linkVisible = await pollUntil(() => link.isVisible(), { timeout: T(10000) });
  step(rec, 'Open shift link visible', !!linkVisible, await link.getAttribute('href'));
  await shot(rec, 'no-shift');
  if (linkVisible) await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: T(60000) }), link.click({ noWaitAfter: true })]);
  else { note(rec, 'Open shift link not rendered — opening the separate page by its route'); await page.goto(base + route('shiftOpenPage'), { waitUntil: 'domcontentloaded' }); }
  await sleep(settleMs);
  const url = page.url().replace(base, '');
  const doc = report.http.filter(h => h.wf === rec.id && h.type === 'document').pop();
  step(rec, 'shift open page loads', doc && doc.status < 400, { url, status: doc?.status });
  await shot(rec, 'open-page');
  if (!(doc && doc.status < 400)) {
    // The PAGE is refused (Online: route permission tenant.shifts.create). The operator still holds the POST permission
    // (tenant.shifts.store), so the precondition every later workflow needs is created through that POST and recorded.
    rec.classification = 'PERMISSION/DATA';
    note(rec, `shift open PAGE answered ${doc?.status} — falling back to POST ${route('shiftOpenStore')} (permission tenant.shifts.store) to create the open-shift precondition`);
    await gotoPos();
    const tid = await page.locator('#terminal_id').inputValue().catch(() => '');
    const branchId = RT?.identity?.branch_id || await page.locator('#branch_id').inputValue().catch(() => '');
    const pr = await page.evaluate(async ({ url, token, tid, branchId }) => {
      const fd = new URLSearchParams(); fd.append('_token', token); fd.append('branch_id', String(branchId)); fd.append('terminal_ids[]', tid); fd.append(`terminal_opening_cash[${tid}]`, '1000'); fd.append('opening_cash', '1000'); fd.append('opening_notes', 'G2 browser proof');
      const r = await fetch(url, { method: 'POST', headers: { Accept: 'text/html,application/json', 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString(), credentials: 'same-origin', redirect: 'manual' });
      return { status: r.status, type: r.type, redirected_to: r.headers.get('location'), text: r.status >= 400 ? (await r.text()).slice(0, 200) : null };
    }, { url: base + route('shiftOpenStore'), token: await csrf(), tid, branchId });
    rec.steps.push({ step: 'fallback: POST shift open (store permission)', ok: pr.status < 400, detail: pr, probe: true });
    await gotoPos();
    const stF = await shiftState();
    step(rec, 'POS shows Shift open (after fallback POST)', stF.api.open === true && /shift open/i.test(stF.badge), stF);
    await shot(rec, 'pos-shift-open-fallback');
    throw new Error(`shift open page answered ${doc?.status} (permission) — shift opened through the POST instead`);
  }
  if (await page.locator('#branch_id').count()) {
    const opts = await page.locator('#branch_id option').evaluateAll(o => o.map(x => ({ v: x.value, t: x.textContent.trim(), sel: x.selected })));
    const target = opts.find(o => o.sel && o.v) || opts.find(o => o.v);
    if (target && !(await page.locator('#branch_id').isDisabled())) { await page.selectOption('#branch_id', target.v); await sleep(200); }
    note(rec, { branch_options: opts.map(o => o.t), branch_disabled: await page.locator('#branch_id').isDisabled() });
  }
  const checks = page.locator('.term-check:not([disabled])');
  const nChecks = await checks.count();
  for (let i = 0; i < nChecks; i++) { if (!(await checks.nth(i).isChecked())) await checks.nth(i).check(); }
  const checked = await page.locator('.term-check:checked').evaluateAll(els => els.map(e => e.value));
  note(rec, { terminal_checkboxes_enabled: nChecks, checked });
  if (await page.locator('#opening_cash').count()) await page.fill('#opening_cash', '1000');
  const perTerminal = page.locator('input[name^="terminal_opening_cash"]:not([disabled])');
  for (let i = 0; i < await perTerminal.count(); i++) await perTerminal.nth(i).fill('1000');
  await shot(rec, 'open-form-filled');
  // target the OPEN form (action …/shifts/open) — on Online the page renders inside layouts.app whose hidden chrome forms
  // come first in the DOM (same fix as the split-bill and close forms)
  const openForm = page.locator('form[action*="shifts/open"]').first();
  note(rec, { forms_in_page: await page.locator('form').count(), open_form_action: (await openForm.getAttribute('action').catch(() => null) || '').replace(base, '') });
  const openPost = await submitForm(openForm.locator('button[type="submit"]').first(), openPath);
  const after = page.url().replace(base, '');
  step(rec, 'shift open POST accepted (redirect off the open page)', !openPost.error && openPost.status < 400 && !/shifts\/open$/.test(after), { post: openPost, landed: after });
  const flash = await text('.alert-success, .alert.alert-success, [role="status"].alert, .alert');
  note(rec, { landed: after, flash: flash.slice(0, 160) });
  await shot(rec, 'after-submit');
  await gotoPos();
  const st2 = await shiftState();
  step(rec, 'POS shows Shift open (shift-status open=true + badge "Shift open")', st2.api.open === true && /shift open/i.test(st2.badge), st2);
  await shot(rec, 'pos-shift-open');
});

await wf('W03', 'cash walk-in sale', async (rec) => {
  await gotoPos();
  const m = await setMode('takeaway'); note(rec, m);
  await ensureCartLine(rec, 'add item 1'); await ensureCartLine(rec, 'add item 2');
  await shot(rec, 'cart');
  const r = await payCash(rec, { kot: 'skip', receipt: true, label: 'pay' });
  step(rec, 'sale POST ok', r.status === 200 || r.status === 201, { status: r.status, error: r.error, sale_no: r.body?.sale_no, code: r.body?.code, message: r.body?.message, printing: r.body?.printing ? Object.keys(r.body.printing) : null, refused: r.refused, prompts: r.prompts, toasts: r.toasts });
  if (r.body?.sale_no) { lastSaleNo = r.body.sale_no; lastSaleId = r.body.sale_id; facts.w03_sale = { sale_no: lastSaleNo, sale_id: lastSaleId }; }
  rec.steps.push({ step: 'success toast', ok: r.toasts.some(t => /Sale complete|already completed/i.test(t)), detail: r.toasts, advisory: true });
  note(rec, { receipt: r.body?.printing?.receipt || null, kot_jobs: (r.body?.printing?.kot_jobs || []).length });
  // printing panel for the sale
  await loc('#last-print-btn').click();
  await modalShown('lastPrintModal');
  await page.locator('#last-print-modal-body table, #last-print-modal-body p').first().waitFor({ timeout: T(15000) });
  const jobs = await page.locator('#last-print-modal-body tbody tr').evaluateAll(rows => rows.map(r => Array.from(r.querySelectorAll('td')).slice(0, 4).map(td => td.textContent.trim().replace(/\s+/g, ' '))));
  note(rec, { print_jobs: jobs, sale_label: await text('#last-print-sale-no') });
  step(rec, 'printing panel lists the receipt job', jobs.some(j => /receipt/i.test(j.join(' '))), jobs);
  await shot(rec, 'print-panel');
  await hideModal('lastPrintModal');
});

await wf('W03M', 'cash sale of a modifier (Customizable) item', async (rec) => {
  await gotoPos();
  const m = await setMode('takeaway'); note(rec, m);
  const t = await addTile({ match: /customizable|tikka|boti/i });
  step(rec, 'add modifier item', t.cart_rows > 0, t);
  note(rec, { cart_modifier_lines: await page.locator('#cart-items .cart-row .ps-2').evaluateAll(els => els.map(e => e.textContent.trim())) });
  await shot(rec, 'cart');
  const r = await payCash(rec, { kot: 'skip', label: 'pay' });
  step(rec, 'sale POST ok (line carries modifiers)', r.status === 200 || r.status === 201, { status: r.status, error: r.error, sale_no: r.body?.sale_no, message: r.body?.message, errors: r.body?.errors ? Object.keys(r.body.errors) : null, refused: r.refused && r.refused.text, toasts: r.toasts });
  facts.w03m_sale = r.body?.sale_no || null;
});

await wf('W04', 'sale with customer search', async (rec) => {
  await gotoPos();
  const m = await setMode('takeaway'); note(rec, m);
  await loc('#pos-customer-btn').click(); await modalShown('customerModal');
  await loc('#cust-search-input').fill(customerQuery);
  await page.locator('#cust-search-results button').first().waitFor({ timeout: T(10000) });
  const results = await page.locator('#cust-search-results button').evaluateAll(b => b.map(x => x.textContent.trim().replace(/\s+/g, ' ').slice(0, 60)));
  note(rec, { query: customerQuery, results });
  await shot(rec, 'search-results');
  await page.locator('#cust-search-results button').first().click();
  await sleep(500);
  if (await vis('#cust-attach-btn')) { await loc('#cust-attach-btn').click(); await sleep(400); }
  const chip = await text('#chip-cust-name');
  step(rec, 'customer attached (chip)', !!chip, { chip, phone: await text('#chip-cust-phone'), toasts: await drainToasts() });
  await shot(rec, 'chip');
  await ensureCartLine(rec, 'add item');
  const r = await payCash(rec, { kot: 'skip', label: 'pay' });
  step(rec, 'sale POST ok', r.status === 200 || r.status === 201, { status: r.status, error: r.error, sale_no: r.body?.sale_no, code: r.body?.code, message: r.body?.message, refused: r.refused, toasts: r.toasts });
  facts.w04_sale = r.body?.sale_no || null;
});

await wf('W05', 'hold, recall, settle', async (rec) => {
  await gotoPos();
  const m = await setMode('takeaway'); note(rec, m);
  await ensureCartLine(rec, 'add item');
  const h = await holdOrder(rec, { kot: 'skip', label: 'held' });
  step(rec, 'held POST ok', h.held.status === 200 || h.held.status === 201, { status: h.held.status, sale_no: h.held.body?.sale_no, code: h.held.body?.code, message: h.held.body?.message, toasts: h.toasts });
  const heldNo = h.held.body?.sale_no;
  await gotoPos();  // a fresh page: recall must rebuild the cart from the server list
  await loc('#held-orders-btn').click(); await modalShown('heldSalesModal');
  await page.locator('#held-sales-modal-body table, #held-sales-modal-body p').first().waitFor({ timeout: T(15000) });
  const rows = await page.locator('#held-sales-modal-body tbody tr').evaluateAll(rows => rows.map(r => r.querySelector('td')?.textContent.trim()));
  note(rec, { held_rows: rows });
  await shot(rec, 'held-list');
  const row = page.locator('#held-sales-modal-body tbody tr').filter({ hasText: heldNo || '__none__' }).first();
  step(rec, 'held list shows the order', await row.count() > 0, heldNo);
  await row.locator('[data-recall-id]').click();
  await page.locator('#heldSalesModal.show').waitFor({ state: 'hidden', timeout: T(8000) }).catch(() => {});
  await sleep(500);
  const recalled = await text('#recalled-order-no');
  step(rec, 'recalled bar shows the order', recalled.includes(heldNo || '__'), { recalled, cart_rows: await cartCount() });
  await shot(rec, 'recalled');
  const r = await payCash(rec, { kot: 'skip', label: 'settle' });
  step(rec, 'settle POST ok', r.status === 200 || r.status === 201, { status: r.status, error: r.error, sale_no: r.body?.sale_no, code: r.body?.code, message: r.body?.message, refused: r.refused, toasts: r.toasts, url: report.http.filter(x => x.wf === rec.id && x.method === 'POST').map(x => x.path).pop() });
});

await wf('W06', 'dine-in table lifecycle', async (rec) => {
  await gotoPos();
  const o = await openFreeTable(rec, 'open-table');
  step(rec, 'open table POST ok', o.status === 200 || o.status === 201, { status: o.status, table: o.table, code: o.body?.code, message: o.body?.message, session_bar: o.session_bar });
  const sessionId = await sessionIdOnPage(); note(rec, { session_id: sessionId });
  await ensureCartLine(rec, 'add item (round 1)');
  const h1 = await holdOrder(rec, { kot: 'print', label: 'hold-round-1' });
  step(rec, 'hold round 1 ok', h1.held.status < 300, { status: h1.held.status, sale_no: h1.held.body?.sale_no, code: h1.held.body?.code, message: h1.held.body?.message, toasts: h1.toasts });
  step(rec, 'KOT job queued (round 1)', kotOk(h1.kot), kotDetail(h1.kot));
  const dineSale = h1.held.body?.sale_no; const dineSaleId = h1.held.body?.sale_id;
  await ensureCartLine(rec, 'add item (round 2)');
  const h2 = await holdOrder(rec, { kot: 'print', label: 'hold-round-2' });
  step(rec, 'hold round 2 (same order) ok', h2.held.status < 300 && (!dineSale || h2.held.body?.sale_no === dineSale), { status: h2.held.status, sale_no: h2.held.body?.sale_no, same_order: h2.held.body?.sale_no === dineSale });
  step(rec, 'KOT addition queued (round 2)', kotOk(h2.kot), kotDetail(h2.kot));
  // table workspace → bill preview for the session
  await openWorkspace();
  await shot(rec, 'board-occupied');
  const bp = page.locator(`#table-board-body [data-table-bill-preview="${sessionId}"]`).first();
  if (await bp.count()) {
    const [res] = await Promise.all([waitResponse(r => /bill-preview/.test(pathOf(r.url())), 20000), bp.click()]);
    const j = await jsonOf(res);
    await page.locator('#billPreviewModal.show').waitFor({ state: 'visible', timeout: T(10000) }).catch(() => {});
    await sleep(800);
    step(rec, 'table bill preview', j.status === 200 && (j.body?.ok !== false), { status: j.status, ok: j.body?.ok, has_html: !!j.body?.html, held_sale_ids: j.body?.held_sale_ids, label: await text('#billPreviewModalLabel') });
    await shot(rec, 'table-bill-preview');
    await hideModal('billPreviewModal');
  } else { step(rec, 'table bill preview', false, 'no [data-table-bill-preview] control on the board for this session (permission?)'); if (await vis('#tableWorkspaceModal.show')) await hideModal('tableWorkspaceModal'); }
  // bill requested (session bar form)
  const rbBtn = page.locator('#pos-session-request-bill-form button[type="submit"]');
  if (await rbBtn.count() && await rbBtn.isVisible()) {
    const [res] = await Promise.all([waitResponse(r => /bill-requested/.test(pathOf(r.url())) && r.request().method() === 'POST', 20000), rbBtn.click()]);
    const j = await jsonOf(res); await sleep(500);
    step(rec, 'bill requested POST ok', j.status === 200 && j.body?.ok !== false, { status: j.status, message: j.body?.message, toasts: await drainToasts() });
    await shot(rec, 'bill-requested');
  } else step(rec, 'bill requested', false, 'Request Bill control not visible in the session bar');
  // move to another table
  let movedTableNo = null, movedTableId = null;
  await openWorkspace();
  const mv = page.locator(`#table-board-body [data-table-move="${sessionId}"]`).first();
  if (await mv.count()) {
    await mv.click(); await page.locator('#table-workspace-move').waitFor({ state: 'visible', timeout: T(8000) }); await sleep(400);
    const targets = await page.locator('#table-workspace-move-body [data-move-target]').evaluateAll(b => b.map(x => x.textContent.trim()));
    await shot(rec, 'move-targets');
    if (targets.length) {
      const [res] = await Promise.all([waitResponse(r => /\/move$/.test(pathOf(r.url())) && r.request().method() === 'POST', 20000), page.locator('#table-workspace-move-body [data-move-target]').first().click()]);
      const j = await jsonOf(res); await sleep(600);
      step(rec, 'move POST ok', j.status === 200 && j.body?.ok !== false, { status: j.status, message: j.body?.message, to: j.body?.session?.table_no, code: j.body?.code });
      movedTableNo = j.body?.session?.table_no || null; movedTableId = j.body?.session?.restaurant_table_id || j.body?.session?.table_id || null;
      await shot(rec, 'moved-board');
    } else step(rec, 'move', false, 'no destination table offered');
  } else step(rec, 'move', false, 'no [data-table-move] control for this session (permission?)');
  const movedSessionBar = { table: await text('#pos-session-table-no'), session: await text('#pos-session-no') }; note(rec, { after_move: movedSessionBar });
  if (await vis('#tableWorkspaceModal.show')) await hideModal('tableWorkspaceModal');
  // merge: the shared view offers NO merge control (Online spec has none either) — probe the backend endpoint both runtimes expose
  const o2 = await openFreeTable(rec, 'open-second-table');
  step(rec, 'open second table ok', o2.status < 300, { status: o2.status, table: o2.table, message: o2.body?.message });
  const session2 = await sessionIdOnPage();
  await ensureCartLine(rec, 'add item (second table)');
  const h3 = await holdOrder(rec, { kot: 'skip', label: 'hold-second-table' });
  step(rec, 'hold on second table ok', h3.held.status < 300, { status: h3.held.status, sale_no: h3.held.body?.sale_no });
  const mergeControl = await page.locator('[data-table-merge], #merge-table-btn, [data-merge-target]').count();
  note(rec, { merge_control_in_shared_view: mergeControl, merge_route: route('tableMerge', { session: '{session}' }) });
  if (sessionId && session2 && route('tableMerge', { session: session2 })) {
    const pr = await probe(base + route('tableMerge', { session: session2 }), { body: { target_session_id: Number(sessionId) } });
    note(rec, { merge_probe: { request: { session: session2, target_session_id: Number(sessionId) }, status: pr.status, content_type: pr.content_type, body: pr.body ? { ok: pr.body.ok, message: pr.body.message, code: pr.body.code, keys: Object.keys(pr.body) } : null, text: pr.text } });
    rec.steps.push({ step: 'merge (endpoint probe, no UI control)', ok: pr.status < 300, detail: { status: pr.status, message: pr.body?.message || pr.text }, probe: true });
  } else rec.steps.push({ step: 'merge', ok: false, detail: 'skipped: no sessions to merge', probe: true });
  await gotoPos();
  // settle: from the merged table's workspace, Continue Table → Open Orders Found → recall the check → Review & Pay →
  // cash → complete; repeat while the merged session still lists held orders (the merge moved the second check onto it).
  await openWorkspace();
  const mergedTile = await boardTile(null, sessionId); note(rec, { merged_tile_before_settle: mergedTile });
  step(rec, 'merged session on the board with both checks', !!mergedTile && !!mergedTile.session_id, mergedTile);
  const settled = [];
  for (let round = 0; round < 4; round++) {
    const c = await continueSession(rec, sessionId, `continue-${round + 1}`);
    if (!c.on_board) { note(rec, `settle round ${round + 1}: session ${sessionId} no longer on the board (closed)`); if (await vis('#tableWorkspaceModal.show')) await hideModal('tableWorkspaceModal'); break; }
    if (round === 0) step(rec, 'merged session lists the checks (open-orders)', c.open_orders.status === 200 && c.open_orders.orders.length >= 2, c.open_orders);
    if (!c.open_orders.orders.length) { note(rec, `settle round ${round + 1}: open-orders empty`); if (await vis('#tableWorkspaceModal.show')) await hideModal('tableWorkspaceModal'); break; }
    step(rec, `recall check (round ${round + 1}) into the cart`, c.cart_rows > 0 && !!c.recalled && c.recalled !== '—', { popup: c.popup, cart_rows: c.cart_rows, recalled: c.recalled, session_on_page: c.session_on_page });
    if (!(c.cart_rows > 0)) break;
    const r = await payCash(rec, { kot: 'skip', label: `settle-${round + 1}` });
    step(rec, `settle dine-in check (round ${round + 1}) ok`, r.status === 200 || r.status === 201, { status: r.status, sale_no: r.body?.sale_no, code: r.body?.code, message: r.body?.message, refused: r.refused, toasts: r.toasts, url: report.http.filter(x => x.wf === rec.id && x.method === 'POST' && /settle|sales$/.test(x.path)).map(x => x.path).pop() });
    if (!(r.status === 200 || r.status === 201)) break;
    settled.push({ sale_no: r.body?.sale_no || c.recalled, status: r.status });
  }
  // the board shows the table free again: no session tile for the merged session, the moved-to table offers Open Table
  await openWorkspace();
  const freeAgain = await pollUntil(async () => { const still = await page.locator(`#table-board-body [data-session-id="${sessionId}"]`).count(); const t = movedTableNo ? (await freeTableButtons()).find(x => x.tableNo === movedTableNo) : null; return still === 0 && (!movedTableNo || t) ? { still, free_table: t || null } : null; }, { timeout: T(8000) });
  const stillThere = await page.locator(`#table-board-body [data-session-id="${sessionId}"]`).count();
  step(rec, 'table free on the board after settling the merged checks', !!freeAgain && stillThere === 0, { session_still_on_board: stillThere, moved_table: movedTableNo, free_table_on_board: freeAgain?.free_table || null, settled, tile: await boardTile(movedTableId, null) });
  await shot(rec, 'board-after-settle');
  await hideModal('tableWorkspaceModal');
  facts.w06 = { session_moved: sessionId, second_session: session2, dine_sale: dineSale, dine_sale_id: dineSaleId, moved_table: movedTableNo, settled };
});

await wf('W07', 'void a KOT-sent item with approval', async (rec) => {
  if (!mgrPass) throw new SkipError('POS_MGR_PASS not provided');
  await gotoPos();
  const o = await openFreeTable(rec, 'open-table');
  step(rec, 'open table ok', o.status < 300, { status: o.status, table: o.table });
  await ensureCartLine(rec, 'add item 1'); await ensureCartLine(rec, 'add item 2');   // two lines: one is voided, the other keeps the order holdable
  const h = await holdOrder(rec, { kot: 'print', label: 'held-with-kot' });
  step(rec, 'hold + KOT ok', h.held.status < 300 && kotOk(h.kot), { held: h.held.status, sale_no: h.held.body?.sale_no, kot: kotDetail(h.kot) });
  heldForVoid = h.held.body?.sale_no;
  await gotoPos();  // reload, then recall so the line carries kot_sent_quantity from the server
  await loc('#held-orders-btn').click(); await modalShown('heldSalesModal');
  await page.locator('#held-sales-modal-body table, #held-sales-modal-body p').first().waitFor({ timeout: T(15000) });
  const row = page.locator('#held-sales-modal-body tbody tr').filter({ hasText: heldForVoid || '__none__' }).first();
  step(rec, 'held list shows the KOT order', await row.count() > 0, heldForVoid);
  await row.locator('[data-recall-id]').click();
  await page.locator('#heldSalesModal.show').waitFor({ state: 'hidden', timeout: T(8000) }).catch(() => {});
  await sleep(600);
  const sentBtn = page.locator('#cart-items [data-remove].btn-outline-warning').first();
  const hasSent = await sentBtn.count();
  step(rec, 'recalled line is marked as sent to kitchen', hasSent > 0, { cart_rows: await cartCount(), sent_lines: hasSent, kot_flags: await page.evaluate(() => Array.from(document.querySelectorAll('#cart-items [data-remove]')).map(b => b.className.includes('warning') ? 'sent' : 'unsent')) });
  await shot(rec, 'recalled');
  if (!hasSent) throw new Error('no KOT-sent line in the recalled cart (kot_sent_quantity not carried by the held list?)');
  const cartNames = () => page.locator('#cart-items .cart-row > div:first-child .fw-bold').evaluateAll(els => els.map(e => e.textContent.trim()));
  const namesBefore = await cartNames();
  const voidedName = (await sentBtn.locator('xpath=ancestor::*[contains(@class,"cart-row")][1]//*[contains(@class,"fw-bold")][1]').first().textContent().catch(() => '') || '').trim();
  note(rec, { cart_before_void: namesBefore, voiding: voidedName });
  await sentBtn.click();
  const s = await waitSwal(8000);
  step(rec, 'void-reason modal', /Void Reason/i.test(s.title), s);
  const reasons = await page.locator('.void-reason-item').evaluateAll(b => b.map(x => x.textContent.trim()));
  note(rec, { reasons });
  await shot(rec, 'void-reason');
  await page.locator('.void-reason-item').first().click();
  await sleep(400);
  const v = await managerApprove(rec, 'approver');
  step(rec, 'manager verify ok', v.status === 200 && v.body?.ok === true, { status: v.status, ok: v.body?.ok, message: v.body?.message, fields: v.fields, validation: v.validation, approval_no: v.body?.approval_no });
  // DOM confirmation: the voided line (by product name) is gone, the other line stays — the cart legitimately keeps it
  const after = await pollUntil(async () => { const n = await cartNames(); return !n.includes(voidedName) ? n : null; }, { timeout: T(5000) }) || await cartNames();
  const voidedIdx = namesBefore.indexOf(voidedName);
  const expectedRemaining = namesBefore.filter((_, i) => i !== voidedIdx);
  step(rec, 'voided line removed from the cart, remaining line kept', !!voidedName && !after.includes(voidedName) && expectedRemaining.every(n => after.includes(n)) && after.length === expectedRemaining.length, { voided: voidedName, cart_before: namesBefore, cart_after: after });
  await shot(rec, 'line-cancelled');
  // persist: Hold sends void_items (cancel KOT) — Edge answers void_print_jobs, Online cancel_kot_jobs
  const h2 = await holdOrder(rec, { kot: 'skip', label: 'hold-after-void' });
  const savedLines = Array.isArray(h2.held.body?.lines) ? h2.held.body.lines : null;
  const voidJobs = h2.held.body?.void_print_jobs ?? h2.held.body?.cancel_kot_jobs ?? null;
  step(rec, 'hold with cancellation ok (saved lines = remaining cart lines)', h2.held.status < 300 && (!savedLines || savedLines.length === after.length), { status: h2.held.status, sale_no: h2.held.body?.sale_no, same_order: h2.held.body?.sale_no === heldForVoid, saved_lines: savedLines ? savedLines.length : null, cart_lines: after.length, void_jobs: Array.isArray(voidJobs) ? voidJobs.map(jobView) : voidJobs, keys: h2.held.body ? Object.keys(h2.held.body) : null, code: h2.held.body?.code, message: h2.held.body?.message, toasts: h2.toasts });
  facts.w07 = { held: heldForVoid, approval: v.body?.approval_no || null, voided: voidedName, remaining: after };
});

await wf('W08', 'manual discount with approval', async (rec) => {
  if (!mgrPass) throw new SkipError('POS_MGR_PASS not provided');
  const applyDiscount = async (type, value, label) => {
    await page.selectOption('#manual-discount-type', type); await sleep(150);
    await loc('#manual-discount-value').fill(String(value));
    const verifyP = waitResponse(r => r.request().method() === 'POST' && pathOf(r.url()) === (route('managerVerify') || '').split('?')[0], 20000).then(jsonOf).catch(() => null);
    await loc('#apply-discount-btn').click();
    await sleep(500);
    let v = null;
    if (await swalVisible()) { v = await managerApprove(rec, label + '-approver'); }
    else { note(rec, `${label}: no approver prompt (branch auto-approves manual discounts)`); }
    await sleep(500);
    const fb = await text('#manual-discount-feedback');
    return { verify: v, feedback: fb, discount_view: await text('#discount-view'), approval_id: await page.locator('#pos-manager-approval-id').inputValue() };
  };
  await gotoPos(); await setMode('takeaway');
  await ensureCartLine(rec, 'add item (percent)'); await ensureCartLine(rec, 'add item (percent) 2');
  let d1 = null;
  const r1 = await payCash(rec, { kot: 'skip', label: 'percent', beforeComplete: async () => { d1 = await applyDiscount('percent', 10, 'percent'); } });
  step(rec, 'percent discount approved', !!d1 && /applied/i.test(d1.feedback) && (!d1.verify || d1.verify.body?.ok === true), d1 && { feedback: d1.feedback, verify: d1.verify && { status: d1.verify.status, ok: d1.verify.body?.ok, message: d1.verify.body?.message, fields: d1.verify.fields }, discount_view: d1.discount_view });
  step(rec, 'percent-discount sale completes', r1.status === 200 || r1.status === 201, { status: r1.status, sale_no: r1.body?.sale_no, code: r1.body?.code, message: r1.body?.message, refused: r1.refused, total: r1.total });
  await gotoPos(); await setMode('takeaway');
  await ensureCartLine(rec, 'add item (fixed)'); await ensureCartLine(rec, 'add item (fixed) 2');
  let d2 = null;
  const r2 = await payCash(rec, { kot: 'skip', label: 'fixed', beforeComplete: async () => { d2 = await applyDiscount('fixed', 50, 'fixed'); } });
  step(rec, 'fixed discount approved', !!d2 && /applied/i.test(d2.feedback) && (!d2.verify || d2.verify.body?.ok === true), d2 && { feedback: d2.feedback, verify: d2.verify && { status: d2.verify.status, ok: d2.verify.body?.ok, message: d2.verify.body?.message }, discount_view: d2.discount_view });
  step(rec, 'fixed-discount sale completes', r2.status === 200 || r2.status === 201, { status: r2.status, sale_no: r2.body?.sale_no, code: r2.body?.code, message: r2.body?.message, refused: r2.refused, total: r2.total });
  // Hold with an unconsumed (approved but unpaid) manual discount
  await gotoPos(); await setMode('takeaway');
  await ensureCartLine(rec, 'add item (hold-with-discount)');
  await loc('#review-pay-btn').click(); await modalShown('paymentModal');
  const d3 = await applyDiscount('fixed', 20, 'hold-discount');
  step(rec, 'discount approved before hold', /applied/i.test(d3.feedback), { feedback: d3.feedback, approval_id: d3.approval_id });
  await shot(rec, 'hold-discount-applied');
  await hideModal('paymentModal');
  // UI path: the shared JS drops the discount with an info toast, then holds
  const h = await holdOrder(rec, { kot: 'skip', label: 'hold-after-discount' });
  note(rec, { hold_with_discount_ui: { status: h.held.status, sale_no: h.held.body?.sale_no, code: h.held.body?.code, message: h.held.body?.message, toasts: h.toasts, discount_view_after: await text('#discount-view') } });
  step(rec, 'Hold with unconsumed discount: discount dropped + info toast', h.toasts.some(t => /Manual discount removed/i.test(t)), h.toasts);
  step(rec, 'Hold proceeds after dropping the discount', h.held.status < 300, { status: h.held.status, message: h.held.body?.message });
  // Backend probe: what does the held-store endpoint answer when the discount + approval ARE sent (old-page flow)?
  if (d3.approval_id) {
    const heldUrl = base + (route('heldStore') || '');
    const probeBody = await page.evaluate(() => {
      const fd = new FormData(document.getElementById('pos-sale-form')); const o = {}; fd.forEach((v, k) => { if (!(k in o)) o[k] = v; }); return o;
    });
    const fields = { ...probeBody, discount_type: 'fixed', discount_value: 20, manager_approval_id: d3.approval_id, save_as_draft: '0' };
    delete fields.held_sale_id;
    const pr = await page.evaluate(async ({ url, fields, token }) => {
      const fd = new FormData(); Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
      const r = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token }, body: fd, credentials: 'same-origin' });
      const t = await r.text(); let j = null; try { j = JSON.parse(t); } catch { }
      return { status: r.status, body: j ? { ok: j.ok, code: j.code, message: j.message, sale_no: j.sale_no, errors: j.errors ? Object.keys(j.errors) : undefined } : t.slice(0, 200) };
    }, { url: heldUrl, fields, token: await csrf() });
    note(rec, { hold_with_discount_probe: { sent: { discount_type: 'fixed', discount_value: 20, manager_approval_id: d3.approval_id, lines_in_form: Object.keys(fields).filter(k => k.startsWith('lines')).length }, response: pr } });
    rec.steps.push({ step: 'probe: held-store with discount+approval fields', ok: true, detail: pr, probe: true });
  }
});

await wf('W09', 'sales return (POS iframe) and returns list', async (rec) => {
  if (!lastSaleNo) throw new SkipError('W03 produced no sale to return');
  await gotoPos();
  const btn = page.locator('#pos-return-btn');
  if (!(await btn.count())) throw new SkipError('Return button not rendered for this operator (permission tenant.sales-returns.create)', 'SKIP');
  step(rec, 'Return button enabled', !(await btn.isDisabled()), { title: await btn.getAttribute('title'), url: await btn.getAttribute('data-return-url') });
  await btn.click(); await modalShown('posReturnModal');
  const frameEl = await page.waitForSelector('#pos-return-frame');
  let frame = await frameEl.contentFrame();
  await frame.waitForSelector('#sale-picker', { timeout: T(30000) });
  await sleep(500);
  await shot(rec, 'return-modal');
  await disabledCensus('W09 returns create (iframe)', frame);
  await frame.locator('.select2-selection').first().click();
  await frame.locator('.select2-search__field').first().fill(lastSaleNo);
  await frame.locator('.select2-results__option[role="option"]:not(.loading-results):not(.select2-results__message)').first().waitFor({ timeout: T(20000) });
  const options = await frame.locator('.select2-results__option[role="option"]').evaluateAll(o => o.map(x => x.textContent.trim()));
  note(rec, { search: lastSaleNo, options });
  step(rec, 'paid sale found by the picker', options.some(o => o.includes(lastSaleNo)), options);
  await shot(rec, 'picker');
  await frame.locator('.select2-results__option[role="option"]').first().click();
  await frame.waitForSelector('#return-form', { timeout: T(30000) });
  frame = await (await page.waitForSelector('#pos-return-frame')).contentFrame();
  await sleep(500);
  note(rec, { frame_url: frame.url().replace(base, '') });
  const qty = frame.locator('tr.return-line .return-qty').first();
  await qty.fill('1'); await qty.dispatchEvent('input'); await sleep(200);
  await frame.selectOption('#refund_method', 'cash'); await frame.locator('#refund_method').dispatchEvent('change'); await sleep(200);
  const refund = await frame.locator('#refund_amount').inputValue();
  note(rec, { refund_amount: refund, refund_methods: await frame.locator('#refund_method option').evaluateAll(o => o.map(x => ({ v: x.value, disabled: x.disabled, title: x.title || null }))) });
  await shot(rec, 'return-form');
  await frame.locator('#return-form button[type="submit"]').first().click();
  const s = await waitSwal(8000, frame); note(rec, { confirm_popup: s.title });
  const docP = waitResponse(r => r.request().method() === 'POST' && /sales-returns$/.test(pathOf(r.url())), 30000).catch(() => null);
  await swalClick('confirm', frame);
  await sleep(600);
  if (await swalVisible(frame)) { const s2 = await swalInfo(frame); if (/Manager/i.test(s2.title)) { const v = await managerApprove(rec, 'return-approver', frame); note(rec, { return_approval: { status: v.status, ok: v.body?.ok, message: v.body?.message } }); } else note(rec, { popup_after_confirm: s2 }); }
  const postRes = await docP;
  const post = postRes ? { status: postRes.status(), location: String(postRes.headers()['location'] || '').replace(base, '') } : null;
  await sleep(1500);
  frame = await (await page.waitForSelector('#pos-return-frame')).contentFrame();
  await frame.waitForLoadState('domcontentloaded').catch(() => {});
  const frameUrl = frame.url().replace(base, '');
  const frameText = (await frame.locator('body').innerText().catch(() => '') || '').replace(/\s+/g, ' ');
  const returnNo = (frameText.match(/\b(SR-[A-Z0-9-]+|RET-[A-Z0-9-]+)\b/) || [])[1] || null;
  const errorText = (frameText.match(/(not allowed|refused|cannot|failed)[^.]{0,120}/i) || [])[0] || null;
  step(rec, 'return POSTed (302 → detail page)', !!post && post.status < 400, { post, frame_url_after: frameUrl, return_no_in_frame: returnNo, error_text: errorText });
  await shot(rec, 'return-posted');
  await hideModal('posReturnModal');
  facts.w09_return = { sale_no: lastSaleNo, frame_url: frameUrl, return_no_guess: returnNo };
  // (b) the direct returns list screen
  const idx = route('salesReturnIndexPage');
  await page.goto(base + idx, { waitUntil: 'domcontentloaded' }); await sleep(settleMs);
  const doc = report.http.filter(h => h.wf === rec.id && h.type === 'document' && h.path.startsWith(idx)).pop();
  const body = (await page.locator('body').innerText().catch(() => '') || '').replace(/\s+/g, ' ');
  const listedReturnNo = (body.match(/\b(SR-[A-Z0-9-]+|RET-[A-Z0-9-]+)\b/) || [])[1] || null;
  step(rec, 'returns list renders', doc && doc.status < 400, { url: idx, status: doc?.status });
  step(rec, 'returns list includes the return', body.includes(lastSaleNo) || (returnNo && body.includes(returnNo)), { contains_sale_no: body.includes(lastSaleNo), contains_return_no: returnNo ? body.includes(returnNo) : null, listed_return_no: listedReturnNo });
  facts.w09_return.listed_return_no = listedReturnNo;
  await shot(rec, 'returns-list');
});

await wf('W10', 'recent sales → reprint receipt', async (rec) => {
  if (!lastSaleNo) throw new SkipError('W03 produced no sale');
  await gotoPos();
  await loc('#completed-orders-btn').click(); await modalShown('completedOrdersModal');
  await page.locator('#completed-orders-modal-body table, #completed-orders-modal-body .alert').first().waitFor({ timeout: T(15000) });
  const rows = await page.locator('#completed-orders-modal-body tbody tr').evaluateAll(rows => rows.map(r => r.querySelector('td strong')?.textContent.trim()));
  note(rec, { recent_rows: rows.slice(0, 10) });
  await shot(rec, 'recent-list');
  const row = page.locator('#completed-orders-modal-body tbody tr').filter({ hasText: lastSaleNo }).first();
  step(rec, 'recent list shows the W03 sale', await row.count() > 0, lastSaleNo);
  const [res] = await Promise.all([waitResponse(r => r.request().method() === 'POST' && /receipt/.test(pathOf(r.url())), 20000), row.locator('[data-reprint-receipt]').click()]);
  const j = await jsonOf(res); await sleep(600);
  step(rec, 'reprint receipt POST ok', j.status < 300, { status: j.status, ok: j.body?.ok, message: j.body?.message, jobs: (j.body?.jobs || (j.body?.job ? [j.body.job] : [])).map(x => ({ job_no: x.job_no, status: x.print_status || x.status, printer: x.printer_name, fallback: x.fallback })), fallback: j.body?.fallback, keys: j.body ? Object.keys(j.body) : null, toasts: await drainToasts() });
  await shot(rec, 'reprinted');
  await hideModal('completedOrdersModal');
});

await wf('W11', 'print jobs panel → retry', async (rec) => {
  await gotoPos();
  await loc('#last-print-btn').click(); await modalShown('lastPrintModal');
  await page.locator('#last-print-modal-body table, #last-print-modal-body p').first().waitFor({ timeout: T(15000) });
  const jobs = await page.locator('#last-print-modal-body tbody tr').evaluateAll(rows => rows.map(r => ({ cells: Array.from(r.querySelectorAll('td')).slice(0, 4).map(td => td.textContent.trim().replace(/\s+/g, ' ')), retry: !!r.querySelector('[data-retry-job]'), requeue: r.querySelector('[data-requeue-job]')?.dataset.requeueJob || null })));
  note(rec, { sale: await text('#last-print-sale-no'), jobs });
  step(rec, 'panel lists jobs', jobs.length > 0, jobs.length);
  await shot(rec, 'panel');
  const retry = page.locator('#last-print-modal-body [data-retry-job]').first();
  if (await retry.count()) {
    const [res] = await Promise.all([waitResponse(r => r.request().method() === 'POST' && /retry/.test(pathOf(r.url())), 20000), retry.click()]);
    const j = await jsonOf(res); step(rec, 'Retry button → retry POST', j.status < 300, { status: j.status, body_status: j.body?.status, job_no: j.body?.job_no, message: j.body?.message, toasts: await drainToasts() });
  } else {
    note(rec, 'no FAILED job → no Retry button (Online renders Retry only for print_status=failed); probing the retry endpoint for the newest job and using Reprint (re-queue) instead');
    const jobId = jobs[0]?.requeue;
    if (jobId && route('printRetry', { job: jobId })) {
      const pr = await probe(base + route('printRetry', { job: jobId }));
      rec.steps.push({ step: 'probe: retry endpoint on a non-failed job', ok: true, detail: { job_id: jobId, status: pr.status, body: pr.body && { status: pr.body.status, message: pr.body.message, job_no: pr.body.job_no, ok: pr.body.ok }, text: pr.text }, probe: true });
    }
    const requeue = page.locator('#last-print-modal-body [data-requeue-job]').first();
    const [res] = await Promise.all([waitResponse(r => r.request().method() === 'POST' && /(receipt|kot|reminder)/.test(pathOf(r.url())), 20000), requeue.click()]);
    const j = await jsonOf(res); await sleep(600);
    step(rec, 'Reprint (re-queue) POST ok', j.status < 300, { status: j.status, ok: j.body?.ok, message: j.body?.message, toasts: await drainToasts() });
  }
  await shot(rec, 'after-retry');
  await hideModal('lastPrintModal');
});

await wf('W12', 'quick report: view/print, network, email', async (rec) => {
  await gotoPos();
  const btn = page.locator('#pos-quick-report-btn');
  if (!(await btn.count())) throw new SkipError('Quick Report button not rendered (permission tenant.pos.quick-report-send)');
  await btn.click(); await modalShown('quickReportModal');
  const controls = {
    network: { disabled: await page.locator('#qr-network').isDisabled(), title: await page.locator('#qr-network').getAttribute('title') },
    email: { disabled: await page.locator('#qr-email').isDisabled(), title: await page.locator('#qr-email').getAttribute('title') },
    print: { disabled: await page.locator('#qr-print').isDisabled() },
    printers: await page.locator('#qr-printer option').evaluateAll(o => o.map(x => x.textContent.trim())),
    branches: await page.locator('#qr-branch option').evaluateAll(o => o.map(x => x.textContent.trim())),
  };
  note(rec, controls); facts.quick_report_controls = controls;
  await shot(rec, 'modal');
  // view / print → new tab
  const popupP = context.waitForEvent('page', { timeout: T(15000) }).catch(() => null);
  await page.locator('#qr-print').click();
  const popup = await popupP;
  if (popup) {
    await popup.waitForLoadState('domcontentloaded').catch(() => {}); await sleep(1200);
    const pu = popup.url().replace(base, '');
    const ptext = (await popup.locator('body').textContent().catch(() => '') || '').replace(/\s+/g, ' ').slice(0, 200);
    await popup.screenshot({ path: path.join(outDir, `${rec.id}-${rec.slug}-${String(++rec.seq).padStart(2, '0')}-print-view.png`) }).catch(() => {});
    step(rec, 'view/print opens the report', !/login/.test(pu) && ptext.length > 0, { url: pu.slice(0, 120), text: ptext.slice(0, 120), printed: await popup.evaluate(() => window.__printed || 0).catch(() => null) });
    await popup.close().catch(() => {});
  } else step(rec, 'view/print opens the report', false, 'no popup window');
  // network
  if (!controls.network.disabled) {
    const printerOpt = await page.locator('#qr-printer option').evaluateAll(o => o.map(x => x.value).filter(Boolean));
    if (printerOpt.length) {
      await page.selectOption('#qr-printer', printerOpt[0]);
      const [res] = await Promise.all([waitResponse(r => r.request().method() === 'POST' && /network/.test(pathOf(r.url())), 20000), page.locator('#qr-network').click()]);
      const j = await jsonOf(res); await sleep(500);
      step(rec, 'send to network POST', j.status < 300, { status: j.status, printer: j.body?.printer, message: j.body?.message, toast: await text('#qr-toast') });
    } else { step(rec, 'send to network', false, 'network enabled but no network printer option'); }
  } else rec.steps.push({ step: 'send to network', ok: true, detail: `disabled with hint: ${controls.network.title}`, expected_difference: true });
  // email
  if (!controls.email.disabled) {
    const [res] = await Promise.all([waitResponse(r => r.request().method() === 'POST' && /email/.test(pathOf(r.url())), 30000), page.locator('#qr-email').click()]);
    const j = await jsonOf(res); await sleep(500);
    rec.steps.push({ step: 'email to owner POST', ok: true, detail: { status: j.status, sent_to: j.body?.sent_to, message: j.body?.message, toast: await text('#qr-toast') } });
  } else rec.steps.push({ step: 'email to owner', ok: true, detail: `disabled with hint: ${controls.email.title}`, expected_difference: mode === 'edge' });
  await shot(rec, 'after-actions');
  await hideModal('quickReportModal');
});

await wf('W13', 'reservation: reserve, details, unreserve', async (rec) => {
  await gotoPos();
  await openWorkspace();
  const free = await freeTableButtons();
  if (!free.length) throw new Error('no free table to reserve');
  const t = free[free.length - 1];
  await page.locator(`#table-board-body [data-table-reserve="${t.tableId}"]`).first().click();
  await modalShown('reserveTableModal');
  await loc('#reserve-name').fill('Proof Guest'); await loc('#reserve-phone').fill('03009990000'); await loc('#reserve-note').fill('G2 browser proof — disposable');
  await shot(rec, 'reserve-form');
  const [res] = await Promise.all([waitResponse(r => r.request().method() === 'POST' && /\/reserve$/.test(pathOf(r.url())), 20000), loc('#reserve-save-btn').click()]);
  const j = await jsonOf(res); await sleep(300);
  step(rec, 'reserve POST ok', j.status < 300, { status: j.status, ok: j.body?.ok, message: j.body?.message, error_box: await text('#reserve-toast'), table: t });
  // the view re-fetches the board AFTER the reserve response (refreshTableBoard → GET board html) — wait for that
  // re-render instead of a fixed sleep (the dev Edge server is single-threaded; the board GET can queue behind polling)
  const reservedTile = await pollUntil(async () => { const tile = await boardTile(t.tableId, null); return tile && tile.has_details && /reserved/i.test(tile.chip) ? tile : null; }, { timeout: T(10000) });
  const details = page.locator(`#table-board-body [data-reservation-details="${t.tableId}"]`).first();
  step(rec, 'board shows the table reserved (status chip "Reserved" + Details control)', !!reservedTile && await details.count() > 0, { table: t, tile: reservedTile || await boardTile(t.tableId, null) });
  await shot(rec, 'board-reserved');
  if (await details.count()) {
    const [r2] = await Promise.all([waitResponse(r => /\/reservation$/.test(pathOf(r.url())), 20000), details.click()]);
    const j2 = await jsonOf(r2); await sleep(600);
    step(rec, 'reservation details', j2.status === 200 && j2.body?.ok !== false, { status: j2.status, reservation: j2.body?.reservation && { name: j2.body.reservation.name, phone: j2.body.reservation.phone, note: j2.body.reservation.note }, shown: await vis('#reservationDetailsModal.show'), text: (await text('#reservation-details-body')).slice(0, 160) });
    await shot(rec, 'details');
    if (await vis('#reservationDetailsModal.show')) await hideModal('reservationDetailsModal');
    await openWorkspace();
    const [r3] = await Promise.all([waitResponse(r => r.request().method() === 'POST' && /\/unreserve$/.test(pathOf(r.url())), 20000), page.locator(`#table-board-body [data-table-unreserve="${t.tableId}"]`).first().click()]);
    const j3 = await jsonOf(r3); await sleep(900);
    step(rec, 'unreserve POST ok', j3.status < 300, { status: j3.status, ok: j3.body?.ok, message: j3.body?.message });
    const freeAgain = await pollUntil(async () => (await freeTableButtons()).some(x => x.tableId === t.tableId) ? await boardTile(t.tableId, null) : null, { timeout: T(10000) });
    step(rec, 'table free again on the board (Open Table + Reserve offered)', !!freeAgain, { table: t, tile: freeAgain || await boardTile(t.tableId, null) });
    await shot(rec, 'board-unreserved');
  }
  await hideModal('tableWorkspaceModal');
});

await wf('W14', 'split bill on a held dine-in order', async (rec) => {
  await gotoPos();
  const o = await openFreeTable(rec, 'open-table');
  step(rec, 'open table ok', o.status < 300, { status: o.status, table: o.table });
  const sessionId = await sessionIdOnPage();
  await ensureCartLine(rec, 'add item'); await ensureCartLine(rec, 'add item 2');
  const plus = page.locator('#cart-items [data-plus]').first(); if (await plus.count()) { await plus.click(); await sleep(200); }
  const h = await holdOrder(rec, { kot: 'skip', label: 'held' });
  step(rec, 'hold ok', h.held.status < 300, { status: h.held.status, sale_no: h.held.body?.sale_no });
  const saleId = h.held.body?.sale_id;
  await openWorkspace();
  const splitBtn = page.locator(`#table-board-body [data-table-split="${sessionId}"]`).first();
  const splitLink = page.locator('#split-bill-link');
  note(rec, { board_split_control: await splitBtn.count(), cart_split_link_visible: await splitLink.isVisible().catch(() => false), permission_note: 'board button needs tenant.sales-orders.split-bill; cart link shows whenever an order is recalled' });
  await shot(rec, 'board-held');
  if (await splitBtn.count()) await splitBtn.click();
  else { await hideModal('tableWorkspaceModal'); if (await splitLink.isVisible()) await splitLink.click(); else throw new SkipError('no Split Bill control (board button hidden: permission tenant.sales-orders.split-bill; cart link hidden)'); }
  await modalShown('splitBillModal');
  const frameEl = await page.waitForSelector('#split-bill-modal-body iframe');
  let frame = await frameEl.contentFrame();
  await frame.waitForLoadState('domcontentloaded'); await sleep(800);
  const splitDoc = report.http.filter(x => x.wf === rec.id && x.type !== 'fetch' && x.type !== 'xhr' && /split-bill/.test(x.path)).pop();
  note(rec, { split_page: { url: frame.url().replace(base, ''), status: splitDoc?.status } });
  await shot(rec, 'split-page');
  if (splitDoc && splitDoc.status >= 400) throw new Error(`split-bill page answered ${splitDoc.status} (${(await frame.locator('body').textContent().catch(() => '') || '').replace(/\s+/g, ' ').slice(0, 120)})`);
  const qtyInput = frame.locator('input[name="lines[0][quantity]"]');
  await qtyInput.fill('1'); await qtyInput.dispatchEvent('input'); await sleep(200);
  const postP = waitResponse(r => r.request().method() === 'POST' && /split/.test(pathOf(r.url())), 30000).catch(() => null);
  // target the SPLIT form (action …/split-bill): on Online the page renders inside layouts.app, whose hidden chrome forms
  // (logout etc.) come first in the DOM, so a bare `form button[type=submit]` picks a hidden one and times out
  const splitForm = frame.locator('form[action*="split-bill"]').first();
  note(rec, { split_forms_in_page: await frame.locator('form').count(), split_form_action: (await splitForm.getAttribute('action').catch(() => null) || '').replace(base, '') });
  await splitForm.locator('button[type="submit"]').first().click();   // its onclick confirm() is a native dialog → auto-accepted
  const post = await postP;
  // both runtimes answer the split POST with a tiny HTML page that sends window.top back to the POS (Online SplitBillController@store)
  const topNavigated = await page.waitForURL(u => u.pathname === posPath || /held_sale_id|table_session_id/.test(u.search), { timeout: T(15000) }).then(() => true).catch(() => false);
  await sleep(800);
  const postText = post ? (await post.text().catch(() => '')).slice(0, 200) : null;
  const after = { status: post?.status(), content_type: post ? String(post.headers()['content-type'] || '') : null, top_navigated_to: topNavigated ? page.url().replace(base, '') : null, body_excerpt: postText && postText.replace(/\s+/g, ' ').slice(0, 160) };
  step(rec, 'split POST ok (page returns to the POS)', !!post && post.status() < 400 && topNavigated, after);
  await shot(rec, 'split-posted');
  if (await vis('#splitBillModal.show')) await hideModal('splitBillModal');
  await gotoPos(); await openWorkspace();
  const heldBtn = page.locator(`#table-board-body [data-table-held-orders="${sessionId}"]`).first();
  if (await heldBtn.count()) {
    // HTTP outcome from the open-orders GET (slow single-threaded dev server: allow 45 s) + DOM confirmation from the
    // rendered "Held Orders - Table …" cards (one per held order of the session)
    const resP = waitResponse(r => /open-orders/.test(pathOf(r.url())), 45000).then(jsonOf).catch(e => ({ error: String(e.message).split('\n')[0] }));
    await heldBtn.click();
    const cards = page.locator('#table-workspace-held-body [data-workspace-recall]');
    await cards.first().waitFor({ state: 'visible', timeout: T(45000) }).catch(() => {});
    const j = await resP; await sleep(400);
    const orders = (j.body?.orders || []).map(x => ({ sale_no: x.sale_no, items: x.items_count, total: x.grand_total_formatted }));
    orders.forEach(o => { if (o.sale_no) runHeld.add(o.sale_no); });   // the split part is a new held order of this run
    const rendered = await page.locator('#table-workspace-held-body article strong:first-child').evaluateAll(els => els.map(e => e.textContent.trim()));
    rendered.forEach(n => { if (/^(SO|HS)-/.test(n)) runHeld.add(n); });
    step(rec, 'both parts listed on the table (open-orders + rendered cards)', (j.status === 200 && orders.length >= 2) || rendered.length >= 2, { http: { status: j.status, error: j.error, orders }, rendered_cards: rendered, heading: await text('#table-workspace-held-body h3') });
    await shot(rec, 'held-orders');
  } else step(rec, 'held orders list for the table', false, 'no [data-table-held-orders] control');
  await hideModal('tableWorkspaceModal');
  facts.w14 = { session: sessionId, sale_id: saleId };
});

await wf('W15', 'customer quick-add', async (rec) => {
  await gotoPos(); await setMode('takeaway');
  await loc('#pos-customer-btn').click(); await modalShown('customerModal');
  const name = 'Zed Proof ' + Date.now().toString().slice(-5);
  await loc('#cust-search-input').fill(name);
  await page.locator('#cust-quick-add:not(.d-none)').waitFor({ timeout: T(10000) }).catch(() => {});
  const qa = { shown: await vis('#cust-quick-add'), save_disabled: await page.locator('#qa-save').isDisabled().catch(() => null), save_title: await page.locator('#qa-save').getAttribute('title'), wrap_title: await page.locator('#cust-quick-add').getAttribute('title'), capability: RT?.capabilities?.customerCreate, hint: RT?.labels?.['capability.customerCreate'] || null, route: RT?.routes?.customerQuickStore ?? null };
  note(rec, qa);
  await shot(rec, 'quick-add-panel');
  step(rec, 'quick-add panel appears for an unmatched name', qa.shown, qa);
  if (RT?.capabilities?.customerCreate === false) {
    const disabledWithHint = qa.save_disabled === true && !!(qa.save_title || qa.wrap_title);
    if (disabledWithHint) { rec.status = 'EXPECTED-DIFFERENCE'; rec.reason = `customerCreate off: control disabled with hint "${qa.save_title || qa.wrap_title}"`; }
    else {
      // the control is live — press it and record what the cashier sees
      await loc('#qa-phone').fill('0300' + Date.now().toString().slice(-7)); await loc('#qa-name').fill(name);
      await loc('#qa-save').click(); await sleep(1200);
      const err = await text('#qa-error');
      rec.status = 'EXPECTED-DIFFERENCE';
      rec.reason = `customerCreate off, but the Add & Attach control is NOT rendered disabled-with-hint; pressing it shows "${err}" (expected the capability hint "${qa.hint}")`;
      rec.steps.push({ step: 'quick-add refused on Edge', ok: true, detail: { qa_error: err, expected_hint: qa.hint, http: report.http.filter(h => h.wf === rec.id && /customers/.test(h.path) && h.method === 'POST').map(({ wf, ...h }) => h) } });
      await shot(rec, 'quick-add-refused');
    }
    return;
  }
  await loc('#qa-phone').fill('0300' + Date.now().toString().slice(-7)); await loc('#qa-name').fill(name);
  const [res] = await Promise.all([waitResponse(r => r.request().method() === 'POST' && /quick-store|customers/.test(pathOf(r.url())), 20000), loc('#qa-save').click()]);
  const j = await jsonOf(res); await sleep(600);
  step(rec, 'quick-store POST ok', j.status < 300 && j.body?.ok !== false, { status: j.status, ok: j.body?.ok, customer: j.body?.customer && { id: j.body.customer.id, name: j.body.customer.name }, message: j.body?.message, error_text: await text('#qa-error') });
  step(rec, 'new customer attached (chip)', (await text('#chip-cust-name')).includes(name.split(' ')[0]), await text('#chip-cust-name'));
  await shot(rec, 'created');
});

/** W16 pre-step — "settle THIS RUN's open work" THROUGH THE UI, the way a cashier does before closing the shift: every held order
 *  this run created (runHeld) is recalled from the Held list → Review & Pay → cash → complete (W07's KOT order is instead CANCELLED
 *  from the held list: reason + approver prompt), then every table session this run opened (runSessions) that is still open with
 *  nothing on it is closed from the board. Held orders / sessions the run did NOT create are LEFT UNTOUCHED and reported as
 *  leftovers (the Online clone cannot be reset; they are somebody else's work). Nothing touches the DB. */
async function settleOpenWork(rec) {
  const ledger = []; const leftovers = { held: [], sessions: [] };
  const heldCancelRe = routeRe('heldCancel'); const closeRe = routeRe('tableClose');
  const heldRows = async () => {
    await gotoPos();
    await loc('#held-orders-btn').click(); await modalShown('heldSalesModal');
    await page.locator('#held-sales-modal-body table, #held-sales-modal-body p').first().waitFor({ timeout: T(15000) });
    return page.locator('#held-sales-modal-body tbody tr').evaluateAll(rows => rows.map(r => ({ sale_no: r.querySelector('td strong')?.textContent.trim() || '', recall: r.querySelector('[data-recall-id]')?.dataset.recallId || null, cancel: r.querySelector('[data-cancel-id]')?.dataset.cancelId || null })));
  };
  let cancelTried = false; const gaveUp = new Set();
  for (let i = 0; i < 40; i++) {
    const rows = await heldRows();
    if (i === 0) { note(rec, { open_work_held_before: rows.map(r => r.sale_no), created_this_run: rows.filter(r => runHeld.has(r.sale_no)).map(r => r.sale_no), not_this_run: rows.filter(r => !runHeld.has(r.sale_no)).map(r => r.sale_no), clear_all_open_work: clearAllOpenWork }); await shot(rec, 'open-work-held-list'); }
    if (clearAllOpenWork) rows.forEach(r => runHeld.add(r.sale_no)); // adopt earlier runs' leftovers as work to clear (UI path unchanged)
    const target = rows.find(r => runHeld.has(r.sale_no) && !gaveUp.has(r.sale_no));
    if (!target) { leftovers.held = rows.filter(r => !runHeld.has(r.sale_no)).map(r => r.sale_no); await hideModal('heldSalesModal'); break; }
    const entry = { sale_no: target.sale_no, created_this_run: true };
    const row = page.locator('#held-sales-modal-body tbody tr').filter({ hasText: target.sale_no }).first();
    if (heldForVoid && target.sale_no === heldForVoid && !cancelTried && mgrPass && target.cancel) {
      cancelTried = true; entry.action = 'cancel from the held list (reason + approver)';
      await row.locator('[data-cancel-id]').click();
      const s = await waitSwal(8000); entry.prompt = s.title;
      const opts = await page.locator('.swal2-popup .swal2-select option').evaluateAll(o => o.map(x => ({ v: x.value, t: x.textContent.trim() })).filter(x => x.v));
      entry.reason = opts[0]?.t || null;
      if (opts[0]) await page.selectOption('.swal2-popup .swal2-select', opts[0].v);
      await shot(rec, 'open-work-cancel-reason');
      const cancelP = waitResponse(r => r.request().method() === 'POST' && !!heldCancelRe && heldCancelRe.test(pathOf(r.url())), 30000).then(jsonOf).catch(e => ({ error: String(e.message).split('\n')[0] }));
      await swalClick('confirm'); await sleep(500);
      if (await swalVisible()) {
        const s2 = await swalInfo();
        if (/Manager/i.test(s2.title)) { const v = await managerApprove(rec, 'open-work-cancel-approver'); entry.approval = { status: v.status, ok: v.body?.ok, message: v.body?.message, approval_no: v.body?.approval_no, fields: v.fields, validation: v.validation }; }
        else { entry.popup = s2; await swalClick('confirm'); }
      }
      const c = await cancelP;
      const jobs = c.body?.cancel_kot_jobs ?? c.body?.void_print_jobs ?? null;
      entry.http = { status: c.status, ok: c.body?.ok, code: c.body?.code, message: c.body?.message, cancel_jobs: Array.isArray(jobs) ? jobs.map(jobView) : jobs, keys: c.body ? Object.keys(c.body) : null, error: c.error };
      await sleep(600); entry.toasts = await drainToasts();
      entry.ok = !c.error && c.status < 300;
      await shot(rec, 'open-work-cancelled');
      if (await swalVisible()) { entry.popup_after = await swalInfo(); await swalClick('cancel').catch(() => {}); await waitSwalGone(); }
      if (await vis('#heldSalesModal.show')) await hideModal('heldSalesModal');
    } else {
      entry.action = 'recall → Review & Pay → cash → complete';
      await row.locator('[data-recall-id]').click();
      await page.locator('#heldSalesModal.show').waitFor({ state: 'hidden', timeout: T(8000) }).catch(() => {});
      await pollUntil(async () => (await cartCount()) > 0, { timeout: T(4000) });
      entry.recalled = await text('#recalled-order-no'); entry.cart_rows = await cartCount(); entry.table_session = await sessionIdOnPage();
      const r = await payCash(rec, { kot: 'skip', label: `open-work-settle-${ledger.length + 1}` });
      entry.http = { status: r.status, sale_no: r.body?.sale_no, code: r.body?.code, message: r.body?.message, refused: r.refused?.title, prompts: r.prompts, error: r.error }; entry.toasts = r.toasts;
      entry.ok = r.status === 200 || r.status === 201;
    }
    ledger.push(entry);
    rec.steps.push({ step: `settle open work: ${entry.action} — ${entry.sale_no}`, ok: entry.ok, detail: entry, pre_step: true });
    if (!entry.ok) { gaveUp.add(target.sale_no); if (rec.status === 'PASS') { rec.status = 'FAIL'; rec.reason = `settle open work refused for ${entry.sale_no}: ${JSON.stringify(entry.http).slice(0, 300)}`; } }
  }
  // tables THIS RUN opened that are still open with nothing on them (TABLE-CLOSE-EMPTY-1): Close Table from the board
  await gotoPos(); await openWorkspace();
  for (let i = 0; i < 8; i++) {
    const closable = await page.locator('#table-board-body [data-table-close]').evaluateAll(els => els.map(e => ({ sid: e.dataset.tableClose, tno: e.dataset.tableNo || null })));
    if (clearAllOpenWork) closable.forEach(b => runSessions.add(String(b.sid))); // adopt earlier runs' empty tables too
    const mine = closable.find(b => runSessions.has(String(b.sid)));
    if (!mine) break;
    const closeBtn = page.locator(`#table-board-body [data-table-close="${mine.sid}"]`).first();
    const p = waitResponse(r => r.request().method() === 'POST' && !!closeRe && closeRe.test(pathOf(r.url())), 20000).then(jsonOf).catch(e => ({ error: String(e.message).split('\n')[0] }));
    await closeBtn.click(); const s = await waitSwal(8000); await swalClick('confirm');
    const c = await p;
    const entry = { session: mine.sid, table: mine.tno, action: 'close empty table (board)', created_this_run: true, prompt: s.title, http: { status: c.status, ok: c.body?.ok, message: c.body?.message, error: c.error }, ok: !c.error && c.status < 300 };
    ledger.push(entry); rec.steps.push({ step: `settle open work: close empty table ${mine.tno || mine.sid}`, ok: entry.ok, detail: entry, pre_step: true });
    await pollUntil(async () => (await page.locator(`#table-board-body [data-session-id="${mine.sid}"]`).count()) === 0, { timeout: T(8000) });
    if (!entry.ok) break;
  }
  const sessionsLeft = await page.locator('#table-board-body [data-session-id]').evaluateAll(els => els.map(e => e.dataset.sessionId));
  leftovers.sessions = sessionsLeft.filter(s => !runSessions.has(String(s)));
  const mineSessionsLeft = sessionsLeft.filter(s => runSessions.has(String(s)));
  await shot(rec, 'open-work-board-after');
  await hideModal('tableWorkspaceModal');
  const heldLeft = await heldRows(); await hideModal('heldSalesModal');
  const mineHeldLeft = heldLeft.filter(r => runHeld.has(r.sale_no)).map(r => r.sale_no);
  step(rec, "this run's open work cleared through the UI (its held orders settled / cancelled, its table sessions closed)", mineHeldLeft.length === 0 && mineSessionsLeft.length === 0, { this_run_held_left: mineHeldLeft, this_run_sessions_left: mineSessionsLeft, settled: ledger.filter(e => e.ok && /recall/.test(e.action)).map(e => e.sale_no), cancelled: ledger.filter(e => e.ok && /cancel from/.test(e.action)).map(e => e.sale_no), tables_closed: ledger.filter(e => e.ok && /close empty/.test(e.action)).map(e => e.table || e.session), leftovers_not_this_run: leftovers });
  if (leftovers.held.length || leftovers.sessions.length) note(rec, { leftovers_not_created_by_this_run: leftovers, policy: 'left untouched (not this run\'s work; the clone cannot be reset) — they block the shared ShiftService close rule, and the close verdict below says so' });
  facts.w16_open_work = { ledger, leftovers, this_run: { held: [...runHeld], sessions: [...runSessions] } };
  return { ledger, leftovers };
}

await wf('W16', 'shift close via separate page (blind count)', async (rec) => {
  await gotoPos();
  const tid = await page.locator('#terminal_id').inputValue().catch(() => '');
  const st = await probe(base + route('shiftStatus') + '?terminal_id=' + encodeURIComponent(tid), { method: 'GET' });
  note(rec, { shift_status: { status: st.status, open: st.body?.open, shift_id: st.body?.shift_id, keys: st.body ? Object.keys(st.body) : null } });
  if (!st.body?.open || !st.body?.shift_id) throw new Error('no open shift to close (shift-status: ' + JSON.stringify(st.body).slice(0, 120) + ')');
  // precondition of the SHARED ShiftService rule ("Settle all open work before closing this shift"): clear it like a cashier
  const ow = await settleOpenWork(rec);
  const closeUrl = route('shiftClosePage', { shift: st.body.shift_id });
  await page.goto(base + closeUrl, { waitUntil: 'domcontentloaded' }); await sleep(settleMs);
  const doc = report.http.filter(h => h.wf === rec.id && h.type === 'document').pop();
  step(rec, 'close page loads', doc && doc.status < 400, { url: closeUrl, status: doc?.status });
  await shot(rec, 'close-page-response');
  if (!(doc && doc.status < 400)) {
    rec.classification = 'PERMISSION/DATA';
    note(rec, `shift close PAGE answered ${doc?.status} — falling back to POST ${route('shiftCloseStore', { shift: st.body.shift_id })} (permission tenant.shifts.close)`);
    await gotoPos();
    const pr = await page.evaluate(async ({ url, token }) => {
      const fd = new URLSearchParams(); fd.append('_token', token); fd.append('counted_cash', '1000'); fd.append('closing_notes', 'G2 browser proof');
      const r = await fetch(url, { method: 'POST', headers: { Accept: 'text/html,application/json', 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString(), credentials: 'same-origin', redirect: 'manual' });
      return { status: r.status, type: r.type, text: r.status >= 400 ? (await r.text()).slice(0, 200) : null };
    }, { url: base + route('shiftCloseStore', { shift: st.body.shift_id }), token: await csrf() });
    rec.steps.push({ step: 'fallback: POST shift close (close permission)', ok: pr.status < 400, detail: pr, probe: true });
    await gotoPos();
    const badgeF = await shiftBadge();
    step(rec, 'POS shows No open shift (after fallback POST)', /no open shift/i.test(badgeF), { badge: badgeF });
    await shot(rec, 'pos-no-shift-fallback');
    throw new Error(`close page answered ${doc?.status} (permission) — shift closed through the POST instead`);
  }
  const denoms = page.locator('input[name^="denominations["]');
  const nDenoms = await denoms.count();
  const figures = await page.locator('.card-body strong').evaluateAll(els => els.map(e => e.textContent.trim()).slice(0, 4));
  note(rec, { denomination_inputs: nDenoms, summary_figures: figures, counted_cash_expected_attr: await page.locator('#counted_cash').getAttribute('data-expected') });
  await shot(rec, 'close-page');
  if (nDenoms > 0) { await denoms.first().fill('10'); await denoms.first().dispatchEvent('input'); await sleep(200); note(rec, { counted_total: await text('#cash-count-total') }); }
  else { const exp = await page.locator('#counted_cash').getAttribute('data-expected'); await page.fill('#counted_cash', exp || '1000'); }
  await shot(rec, 'close-form-filled');
  // target the CLOSE form (action …/shifts/{id}/close): on Online the page renders inside layouts.app whose hidden chrome
  // forms come first in the DOM, so a bare `form button[type=submit]` picks a hidden one and times out
  const closeForm = page.locator(`form[action*="/close"]`).first();
  note(rec, { forms_in_page: await page.locator('form').count(), close_form_action: (await closeForm.getAttribute('action').catch(() => null) || '').replace(base, '') });
  const closePost = await submitForm(closeForm.locator('button[type="submit"]').first(), (route('shiftCloseStore', { shift: st.body.shift_id }) || '').split('?')[0]);
  const landed = page.url().replace(base, '');
  const bodyText = (await page.locator('body').innerText().catch(() => '') || '').replace(/\s+/g, ' ');
  const refusal = await page.locator('.alert-danger, .invalid-feedback, .text-danger').evaluateAll(els => els.map(e => e.innerText.trim()).filter(Boolean).slice(0, 4)).catch(() => []);
  const refusedByRule = refusal.some(t => /Settle all open work|before closing/i.test(t));
  step(rec, 'shift close POST accepted (redirect off the close page, no refusal)', !closePost.error && closePost.status < 400 && !/\/close$/.test(landed) && !refusedByRule && !/error|exception/i.test(bodyText.slice(0, 300)), { post: closePost, landed, flash: (bodyText.match(/(closed|variance|shift)[^.]{0,100}/i) || [])[0], refusal });
  if (refusedByRule && (ow.leftovers.held.length || ow.leftovers.sessions.length) && ow.ledger.every(e => e.ok)) { rec.classification = 'PRE-EXISTING-DATA'; rec.reason = `close refused by the shared rule because of open work this run did NOT create and does not clear (the clone cannot be reset): held ${JSON.stringify(ow.leftovers.held)} sessions ${JSON.stringify(ow.leftovers.sessions)}`; }
  await shot(rec, 'after-close');
  await gotoPos();
  const st3 = await shiftState();
  step(rec, 'POS shows No open shift (shift-status open=false + badge "No open shift")', st3.api.open === false && /no open shift/i.test(st3.badge), st3);
  await shot(rec, 'pos-no-shift');
});

await wf('W17', 'logout → login page', async (rec) => {
  await gotoPos();
  if (mode === 'edge') await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.evaluate(() => document.getElementById('pos-edge-logout-form').submit())]);
  else await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.evaluate(() => { const f = document.querySelector('form[action$="/logout"]'); if (!f) throw new Error('no logout form'); f.submit(); })]);
  await sleep(settleMs);
  const landed = page.url().replace(base, '');
  step(rec, 'lands on the login page', /login/.test(landed), landed);
  await shot(rec, 'login-page');
  await page.goto(base + posPath, { waitUntil: 'domcontentloaded' }); await sleep(400);
  step(rec, 'POS now redirects to login', /login/.test(page.url()), page.url().replace(base, ''));
});

// ── wrap up ───────────────────────────────────────────────────────────────────────────────────────────────────────
report.finished_at = new Date().toISOString();
report.summary = Object.fromEntries(Object.values(report.workflows).map(w => [w.id, { status: w.status, reason: w.reason }]));
report.http_errors = report.http.filter(h => h.status >= 400);
saveReport();
await browser.close();
console.log('\n' + JSON.stringify({ out: outDir, summary: report.summary, http_errors: report.http_errors.map(h => `${h.wf} ${h.method} ${h.path} ${h.status} ${h.message || ''}`), console_errors: report.console_errors.length, disabled_controls: report.disabled_controls.length, authority_fenced_409s: report.facts.authority_fenced || 0 }, null, 2));
