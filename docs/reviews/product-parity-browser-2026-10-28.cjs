// Local-only browser verification. Requires the pp28 isolated test store built by ProductWorkspaceMySqlTest.
// Never point this at a production server. The SQL commands below are hard-coded to local test schemas.
const { createRequire } = require('node:module');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const r = createRequire('D:/laragon2/www/pos-saas-video/tools/demo-video/node_modules/');
const { chromium } = r('playwright-core');
const base = 'http://wstest.pos-saas.test:8938';
const out = path.resolve('storage/logs/product-parity-browser');
fs.mkdirSync(out, { recursive: true });
function sql(q) {
  return execFileSync('D:/laragon2/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe',
    ['--host=127.0.0.1', '--user=root', '--batch', '--skip-column-names', '-e', q], {encoding:'utf8'}).trim();
}
function business(b) {
  if (!['restaurant','industry','catering'].includes(b)) throw Error('Unknown test business');
  sql(`UPDATE pos_test_master_pp28.tenants SET primary_business='${b}' WHERE tenant_code='wstest'`);
}
const checks = [];
const runMark = new Date().toISOString();
function check(name, value) { checks.push({name, pass:!!value}); if (!value) throw Error(name); }
async function main() {
  const browser = await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe', headless:true,
    args:['--host-resolver-rules=MAP wstest.pos-saas.test 127.0.0.1','--no-proxy-server']});
  try {
    const page = await browser.newPage({viewport:{width:1366,height:768}});
    page.setDefaultTimeout(25000);
    page.setDefaultNavigationTimeout(90000);
    const errors=[];
    page.on('pageerror',e=>errors.push(String(e)));
    await page.goto(base+'/login');
    await page.locator('input[name=email]').fill('owner@pharmacydemo.com');
    await page.locator('input[name=password]').fill('demo1234');
    await Promise.all([page.waitForNavigation(), page.locator('button[type=submit]').click()]);
    check('Owner login succeeds', !page.url().includes('/login'));
    const scenarios = [
      {business:'restaurant',name:'Parity Recipe',tab:'recipe',field:'#recipe-notes',value:'Browser recipe note',
       query:"SELECT notes FROM pos_test_tenant_pp28_ws.recipes WHERE product_id=ID ORDER BY is_active DESC,id DESC LIMIT 1"},
      {business:'industry',name:'Parity Chair',tab:'bom',field:'#bom-notes',value:'Browser BOM note',
       query:"SELECT notes FROM pos_test_tenant_pp28_ws.manufacturing_boms WHERE finished_product_id=ID ORDER BY id DESC LIMIT 1"},
      {business:'catering',name:'Parity Blocks',tab:'catering',field:null}
    ];
    for (const s of scenarios) {
      if (s.value) s.value += ' ' + runMark;
      business(s.business);
      const id = sql(`SELECT id FROM pos_test_tenant_pp28_ws.products WHERE name='${s.name}' LIMIT 1`);
      check(s.tab+' fixture exists', /^\d+$/.test(id));
      await page.setViewportSize({width:1366,height:768});
      await page.goto(base+'/products/'+id+'/edit#'+s.tab);
      await page.locator('#tab-'+s.tab).click();
      const pane=page.locator('#tp-'+s.tab);
      check(s.tab+' tab visible', await pane.isVisible());
      const advanced=pane.locator('details.ws-advanced').first();
      check(s.tab+' advanced collapsed initially', !(await advanced.getAttribute('open') !== null));
      await advanced.locator('summary').click();
      check(s.tab+' advanced expands', await advanced.getAttribute('open') !== null);
      if (s.tab === 'recipe') {
        const ingredient = pane.locator('[data-ws-row]').first();
        await ingredient.locator('details summary').click();
        const override = ingredient.locator('[data-f="override"]');
        const original = await override.inputValue();
        await override.fill('0');
        // Fixture: free rice, 0.2 litre oil at 520, overhead 7.5% => 111.80.
        check('Zero override and overhead update the recipe preview', (await pane.locator('[data-ws-cost]').innerText()).includes('111.80'));
        await override.fill(original);
      }
      if (s.field) {
        await page.locator(s.field).fill(s.value);
        await Promise.all([page.waitForNavigation(),page.locator('#pv2-save').click()]);
        check(s.tab+' browser Save persisted',sql(s.query.replace('ID',id))===s.value);
        await page.goto(base+'/products/'+id+'/edit#'+s.tab);
        await page.locator('#tab-'+s.tab).click();
        await pane.locator('details.ws-advanced').first().locator('summary').click();
        check(s.tab+' reload retains value',await page.locator(s.field).inputValue()===s.value);
      } else {
        const linked=pane.locator('[data-ws-row]').filter({has:page.locator('[data-f="rate-source"] option[value="commercial_book"][selected]')});
        const source=linked.locator('[data-f="rate-source"]');
        check('House-book rate is readonly',await linked.locator('[data-f="rate"]').getAttribute('readonly')!==null);
        await linked.locator('details summary').click();
        await source.selectOption('manual');
        check('Manual rate becomes editable',await linked.locator('[data-f="rate"]').getAttribute('readonly')===null);
        await source.selectOption('commercial_book');
        check('Relink restores readonly',await linked.locator('[data-f="rate"]').getAttribute('readonly')!==null);
      }
      await page.evaluate(()=>scrollTo(0,0));
      await page.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth+2, null, {timeout:3000}).catch(()=>{});
      check(s.tab+' desktop has no page overflow',await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
      await page.screenshot({path:path.join(out,s.tab+'-desktop.png'),fullPage:true});
      await page.setViewportSize({width:390,height:844});
      // Responsive theme transitions can outlast setViewportSize; measure settled layout, not its animation frame.
      await page.waitForTimeout(400); // Theme sidebar transition (300ms), including the resize event frame.
      await page.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth+2, null, {timeout:3000}).catch(()=>{});
      await page.evaluate(()=>scrollTo(0,0));
      console.log(s.tab+' mobile geometry',await page.evaluate(()=>({viewport:innerWidth,document:document.documentElement.scrollWidth})));
      await page.screenshot({path:path.join(out,s.tab+'-phone.png'),fullPage:true});
      check(s.tab+' mobile has no page overflow',await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
      check(s.tab+' expanded guidance wraps inside its row',await pane.locator('details[open] .help').evaluateAll(els=>els.filter(el=>el.getBoundingClientRect().width).every(el=>el.scrollWidth<=el.clientWidth+2)));
      check(s.tab+' Save remains reachable',await page.locator('#pv2-save').isVisible());
    }
    check('No JavaScript page errors',errors.length===0);
  } finally { await browser.close(); }
}
main().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{
  fs.writeFileSync(path.join(out,'checks.json'),JSON.stringify(checks,null,2));
  console.log(checks.filter(c=>c.pass).length+'/'+checks.length+' checks passed');
});
