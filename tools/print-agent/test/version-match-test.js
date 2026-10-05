/**
 * AGENT-VERSION-MATCH-1 — version EK hi hai, do nahi.
 *
 * ── YE TEST KYUN BANA ─────────────────────────────────────────────────────
 *
 * 4 October. Malik ne client ke PC par agent ka installer download kiya aur
 * setup ke sar par likha dekha: **2.5.0** — jabke app ki screen "Latest
 * version 2.6.0" ka ailan kar rahi thi.
 *
 * Wajah ye thi ke version DO jagah likhi hai:
 *
 *   print-agent.js                      AGENT_VERSION = '2.6.0'   ← screen yahan se parhti hai
 *   installer/windows/*.iss             AppVersion    = '2.5.0'   ← setup ka sar yahan se
 *
 * Maine `.js` badli aur `.iss` chhoot gayi. Koi error nahi aaya, kuch laal nahi
 * hua — bas screen ne ek cheez ka ailan kiya aur haath me doosri cheez di, aur
 * ye galti client ke PC tak pahunch gayi.
 *
 * Ye us kism ki kharabi hai jo khud ko chhupati hai: dono adad apni jagah
 * bilkul theek dikhte hain, farq sirf unhein SAATH rakh kar nazar aata hai.
 *
 * Isi liye ye test adad ki qeemat nahi jaanchta (wo kal badlegi) — wo sirf ye
 * dekhta hai ke DONO EK HAIN.
 */

const fs = require('fs');
const path = require('path');
const assert = require('assert');

const base = path.join(__dirname, '..');
let passed = 0;
const ok = (label) => { console.log(`  ✓ ${label}`); passed++; };

console.log('\n── Agent ki version har jagah ek hi honi chahiye');

const agentJs = fs.readFileSync(path.join(base, 'print-agent.js'), 'utf8');
const jsMatch = agentJs.match(/AGENT_VERSION\s*=\s*'([0-9]+\.[0-9]+\.[0-9]+)'/);
assert.ok(jsMatch, "print-agent.js me AGENT_VERSION = 'x.y.z' milna chahiye");
const jsVersion = jsMatch[1];
ok(`print-agent.js  → ${jsVersion}`);

const issPath = path.join(base, 'installer', 'windows', 'BingooPrintAgent.iss');
const iss = fs.readFileSync(issPath, 'utf8');
// Sirf wo satar jo waqai setting hai — comment me likha hua adad nahi.
const issMatch = iss.match(/^AppVersion\s*=\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$/m);
assert.ok(issMatch, 'BingooPrintAgent.iss me AppVersion=x.y.z milna chahiye');
const issVersion = issMatch[1];
ok(`BingooPrintAgent.iss → ${issVersion}`);

assert.strictEqual(issVersion, jsVersion,
    `Setup ka sar "${issVersion}" kahega jabke screen "${jsVersion}" ka ailan karegi — `
    + 'yehi 4 Oct ko client ke PC par hua tha. Dono ko baraabar karo.');
ok(`dono baraabar: ${jsVersion}`);

const pkgPath = path.join(base, 'package.json');
if (fs.existsSync(pkgPath)) {
    const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'));
    assert.strictEqual(pkg.version, jsVersion,
        `package.json "${pkg.version}" aur agent "${jsVersion}" alag hain`);
    ok(`package.json → ${pkg.version}`);
}

console.log(`\n✅ ALL PASSED (${passed} assertions) · version match\n`);
