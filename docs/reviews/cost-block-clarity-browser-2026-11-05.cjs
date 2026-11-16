// COST-BLOCK-CLARITY-1 — local-only browser check of the cost-block area on the product form (5 Nov 2026).
// Needs the isolated store left by ProductWorkspaceMySqlTest with the _cb05 databases (dish "Clarity Blocks": Making 500 a KG,
// Chicken 0.5 KG at the house rate 200) and a local server on :8939 for that store. Never point it at a real server:
// every SQL statement is hard-coded to the local test schemas.
const { createRequire } = require('node:module');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const r = createRequire('D:/laragon2/www/pos-saas-video/tools/demo-video/node_modules/');
const { chromium } = r('playwright-core');
const base = 'http://wstest.pos-saas.test:8939';
const MASTER = 'pos_test_master_cb05', STORE = 'pos_test_tenant_cb05_ws';
const out = path.resolve('storage/logs/cost-block-clarity-browser');
fs.mkdirSync(out, { recursive: true });
function sql(q) {
  if (!/pos_test_/.test(q)) throw Error('test schemas only');
  return execFileSync('D:/laragon2/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe',
    ['--host=127.0.0.1', '--user=root', '--batch', '--skip-column-names', '-e', q], { encoding: 'utf8' }).replace(/\r/g, '').trim();
}
const checks = [];
function check(name, value, detail) { checks.push({ name, pass: !!value, detail: detail === undefined ? null : detail }); if (!value) throw Error(name + (detail === undefined ? '' : ' — ' + JSON.stringify(detail))); }

async function main() {
  sql(`UPDATE ${MASTER}.tenants SET primary_business='catering' WHERE tenant_code='wstest'`);
  const id = sql(`SELECT id FROM ${STORE}.products WHERE name='Clarity Blocks' LIMIT 1`);
  const blocksAt = () => sql(`SELECT GROUP_CONCAT(CONCAT(id,':',updated_at,':',rate,':',rate_basis,':',commercial_rate_source,':',IFNULL(charge_role,'-')) ORDER BY id) FROM ${STORE}.catering_product_cost_blocks WHERE product_id=${id}`);
  const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true,
    args: ['--host-resolver-rules=MAP wstest.pos-saas.test 127.0.0.1', '--no-proxy-server'] });
  try {
    check('fixture dish exists', /^\d+$/.test(id), id);
    const page = await browser.newPage({ viewport: { width: 1366, height: 768 } });
    page.setDefaultTimeout(25000);
    page.setDefaultNavigationTimeout(120000);
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    page.on('dialog', d => d.accept());
    await page.goto(base + '/login');
    await page.locator('input[name=email]').fill('owner@pharmacydemo.com');
    await page.locator('input[name=password]').fill('demo1234');
    await Promise.all([page.waitForNavigation(), page.locator('button[type=submit]').click()]);
    check('owner login', !page.url().includes('/login'), page.url());

    const open = async () => {
      await page.goto(base + '/products/' + id + '/edit#catering');
      await page.locator('#tab-catering').click();
      return page.locator('[data-ws-editor="cost_blocks"]');
    };
    let ed = await open();
    const blocks = ed.locator('[data-ws-rows] > [data-ws-row]');
    const making = blocks.filter({ has: page.locator('[data-f="label"][value="Making"]') });
    const chicken = blocks.filter({ has: page.locator('[data-f="label"][value="Chicken"]') });
    const total = ed.locator('[data-cb-sample-total]');
    check('two saved blocks, each one group', await blocks.count() === 2, await blocks.count());
    check('what it is comes first: the first box is the Material / Charge choice',
      await blocks.first().evaluate(b => b.querySelector('input:not([type="hidden"]), select').getAttribute('data-f') === 'type'));

    // The sample = the event screen's own price (PHP test: priceLine at 10 = rate 600, total 6000).
    check('sample at 10 KG = the event screen: rate Rs 600, total Rs 6,000',
      /Rate for 1 KG: Rs 600\.00/.test(await total.innerText()) && /Total for 10 KG: Rs 6,000\.00/.test(await total.innerText()), await total.innerText());
    check('Making adds Rs 500 to 1 KG', (await making.locator('[data-f="adds"]').innerText()) === 'Rs 500.00', await making.locator('[data-f="adds"]').innerText());
    check('Chicken adds 0.5 × 200 = Rs 100 to 1 KG', (await chicken.locator('[data-f="adds"]').innerText()) === 'Rs 100.00', await chicken.locator('[data-f="adds"]').innerText());
    check('chicken line shows the working', /0\.5 × 10 = 5 KG × Rs 200\.00/.test(await ed.locator('[data-cb-sample-lines]').innerText()), await ed.locator('[data-cb-sample-lines]').innerText());
    check('the line under the rate says it in words, naming the material', (await chicken.locator('[data-f="rate-words"]').innerText()) === 'Rs 200.00 for 1 KG of Clarity Blocks chicken', await chicken.locator('[data-f="rate-words"]').innerText());
    check('the "rate is for" box stays short', (await chicken.locator('[data-f="rate-basis"] option[data-per="material"]').innerText()) === 'per KG of material', await chicken.locator('[data-f="rate-basis"] option[data-per="material"]').innerText());
    check('a charge says it in words too', (await making.locator('[data-f="rate-words"]').innerText()) === 'Rs 500.00 for every 1 KG of the dish', await making.locator('[data-f="rate-words"]').innerText());
    check('house-book rate is read-only', await chicken.locator('[data-f="rate"]').getAttribute('readonly') !== null);
    await chicken.locator('[data-f="rate-source"]').selectOption('manual');
    check('own rate makes it editable', await chicken.locator('[data-f="rate"]').getAttribute('readonly') === null);
    await chicken.locator('[data-f="rate-source"]').selectOption('commercial_book');
    check('house rate book makes it read-only again', await chicken.locator('[data-f="rate"]').getAttribute('readonly') !== null);

    // Live: the sample follows typing; its own quantity is not an unsaved change and is never posted.
    await making.locator('[data-f="rate"]').fill('550');
    check('typing a rate updates the sample live', /Rate for 1 KG: Rs 650\.00/.test(await total.innerText()) && /Rs 6,500\.00/.test(await total.innerText()), await total.innerText());
    await making.locator('[data-f="rate"]').fill('500');
    await ed.locator('[data-cb-sample-qty]').fill('4');
    check('a booking of 4 KG = Rs 2,400', /Total for 4 KG: Rs 2,400\.00/.test(await total.innerText()), await total.innerText());
    check('Making is a visible Yes / No, set to Yes', await making.locator('.cb-more [data-f="role"][value="making"]').isChecked() && await making.locator('.cb-more').isVisible());
    check('a material shows where its rate comes from, not Making', await chicken.locator('[data-f="rate-source"]').isVisible() && !(await chicken.locator('[data-f="role"]').first().isVisible()));

    // A new block: focus lands on what it is; Material ↔ Charge keeps the right basis (the old bug left "per dish").
    await ed.locator('[data-ws-add]').click();
    const added = blocks.nth(2);
    check('a new block focuses the Material / Charge choice first', await page.evaluate(() => document.activeElement && document.activeElement.getAttribute('data-f') === 'type'));
    check('a new empty block warns its rate is 0', /the rate is 0/.test(await ed.locator('[data-cb-warn]').innerText()));
    await added.locator('label[for$="-t-m"]').click();
    check('Material shows the material boxes', await added.locator('[data-f="mat"]').isVisible() && await added.locator('[data-f="rate-source"]').isVisible());
    check('a new material is priced per unit of the material', await added.locator('[data-f="rate-basis"]').inputValue() === 'per_material_unit');
    await added.locator('label[for$="-t-c"]').click();
    check('Charge hides the material boxes and asks about Making', !(await added.locator('[data-f="mat"]').isVisible()) && await added.locator('.cb-more [data-f="role"]').first().isVisible());
    await added.locator('label[for$="-t-m"]').click();
    check('back to Material: per unit of the material again (old bug: stayed per dish)', await added.locator('[data-f="rate-basis"]').inputValue() === 'per_material_unit');
    await added.locator('[data-f="mat"]').selectOption({ label: 'Clarity Blocks gas' });
    check('choosing a material fills its own unit', (await added.locator('[data-f="unit"] option:checked').innerText()).trim() === 'PC', await added.locator('[data-f="unit"] option:checked').innerText());
    await added.locator('label[for$="-t-c"]').click();
    await added.locator('[data-f="label"]').fill('Second making');
    await added.locator('[data-f="rate"]').fill('50');
    await added.locator('.cb-more label[for$="-role-y"]').click();
    check('two Making charges are warned about', /Only one Making charge/.test(await ed.locator('[data-cb-warn]').innerText()));
    await added.locator('[data-f="basis"]').selectOption('lump_sum');
    check('once per order is shown apart and left out of the rate',
      /Rate for 1 KG: Rs 600\.00/.test(await total.innerText()) && /of which once per order: Rs 50\.00/.test(await total.innerText()) && (await added.locator('[data-f="adds"]').innerText()) === 'once Rs 50.00', await total.innerText());

    // "?" on every box: opens its words, Esc closes it and focus returns.
    const qs = ed.locator('.cb-q[data-cb-help]');
    const noHelp = await blocks.first().evaluate(b => [...b.querySelectorAll('input[name]:not([type="hidden"]), select[name]')]
      .filter(el => !el.closest('.cb-field, .cb-more-in') || !el.closest('.cb-field, .cb-more-in').querySelector('.cb-q[data-cb-help]')).map(el => el.name));
    check('every box of a block has a "?"', noHelp.length === 0, noHelp);
    const q = making.locator('.cb-q[data-cb-help="rate"]');
    await q.click();
    const pop = ed.locator('#cb-pop');
    check('"?" opens its words', await pop.isVisible() && /Never leave it 0/.test(await pop.innerText()) && await q.getAttribute('aria-expanded') === 'true');
    const box = await pop.boundingBox();
    check('the words stay on the screen', box && box.x >= 0 && box.x + box.width <= 1366, box);
    await page.keyboard.press('Escape');
    check('Esc closes it and focus returns to the "?"', !(await pop.isVisible()) && await q.evaluate(el => el === document.activeElement));
    await making.locator('.cb-q[data-cb-help="making"]').click();
    check('another "?" shows its own words', /Making Adjustment/.test(await pop.innerText()));
    await page.locator('#tab-catering').click();
    check('a click elsewhere closes it', !(await pop.isVisible()));
    check('the help count matches the boxes', await qs.count() >= 3 * 8, await qs.count());

    await page.evaluate(() => scrollTo(0, 0));
    check('desktop: no page overflow', await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2));
    const fit = () => page.evaluate(() => {
      const e = document.querySelector('[data-ws-editor="cost_blocks"]'), sc = e.querySelector('.table-responsive'), er = e.getBoundingClientRect();
      const ctx = document.createElement('canvas').getContext('2d');
      const cut = [...e.querySelectorAll('[data-ws-rows] select')].filter(s => s.offsetWidth && s.dataset.f !== 'mat').map(s => {
        ctx.font = getComputedStyle(s).font;
        const cs = getComputedStyle(s), room = s.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        return { f: s.dataset.f, text: s.options[s.selectedIndex] ? s.options[s.selectedIndex].text : '', room, need: Math.ceil(ctx.measureText(s.options[s.selectedIndex] ? s.options[s.selectedIndex].text : '').width) };
      }).filter(x => x.need > x.room + 1);
      const kind = e.querySelector('[data-ws-rows] [data-f="type"] + label').getBoundingClientRect();
      return { editor: Math.round(er.width), scrolls: sc.scrollWidth > sc.clientWidth + 1, cut, kindInView: kind.left >= er.left - 1 && kind.right <= er.right + 1,
        cards: getComputedStyle(e.querySelector('[data-ws-rows] thead')).display === 'none' };
    });
    for (const w of [1366, 1280, 1920]) {
      await page.setViewportSize({ width: w, height: 768 });
      await page.waitForTimeout(300);
      const g = await fit();
      check(w + 'px: the blocks fit — no sideways scroll, no box cut short, "What is it?" in view', !g.scrolls && !g.cut.length && g.kindInView, g);
    }
    await page.setViewportSize({ width: 1366, height: 768 });
    await page.waitForTimeout(300);
    check('1366px: the blocks are a table with its headings', !(await fit()).cards);
    await ed.screenshot({ path: path.join(out, 'cost-blocks-desktop.png') });
    await page.setViewportSize({ width: 1280, height: 768 });
    await page.waitForTimeout(300);
    await ed.screenshot({ path: path.join(out, 'cost-blocks-1280.png') });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(400);
    await page.waitForFunction(() => document.documentElement.scrollWidth <= innerWidth + 2, null, { timeout: 3000 }).catch(() => {});
    check('phone: no page overflow', await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2),
      await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth })));
    await making.locator('.cb-q[data-cb-help="charged"]').click();
    const pbox = await pop.boundingBox();
    check('phone: the words fit the screen', pbox && pbox.x >= 0 && pbox.x + pbox.width <= 390 + 1, pbox);
    await page.keyboard.press('Escape');
    const pg = await fit();
    check('phone: each block is a card with nothing cut', pg.cards && !pg.scrolls && !pg.cut.length && pg.kindInView, pg);
    const lonely = await page.evaluate(() => [...document.querySelectorAll('[data-ws-rows] .cb-q[data-cb-help]')].filter(q => q.offsetWidth).filter(q => {
      const box = q.parentElement.querySelector('input:not([type="hidden"]):not([type="radio"]), select, .yn, .cb-kinds');
      if (!box) return false;
      const a = q.getBoundingClientRect(), b = box.getBoundingClientRect(), mid = a.top + a.height / 2;
      return mid < b.top || mid > b.bottom;
    }).map(q => q.dataset.cbHelp));
    check('phone: every "?" sits on the line of its own box', lonely.length === 0, lonely);
    await ed.screenshot({ path: path.join(out, 'cost-blocks-phone.png') });
    await page.setViewportSize({ width: 1366, height: 768 });

    // A real browser Save with nothing changed writes nothing (the boxes post what was saved).
    const before = blocksAt();
    ed = await open();
    await ed.locator('[data-cb-sample-qty]').fill('7');
    await Promise.all([page.waitForNavigation(), page.locator('#pv2-save').click()]);
    check('saved unchanged in the browser: every block as it was', blocksAt() === before, { before, after: blocksAt() });
    check('no JavaScript errors', errors.length === 0, errors);
  } finally { await browser.close(); }
}
main().catch(e => { console.error(e.message); process.exitCode = 1; }).finally(() => {
  fs.writeFileSync(path.join(out, 'checks.json'), JSON.stringify(checks, null, 2));
  console.log(checks.filter(c => c.pass).length + '/' + checks.length + ' checks passed');
});
