<?php
// Extract old_software.xlsx → docs/data/legacy-*.csv (staging, reviewable, deterministic)
$zip = new ZipArchive;
if ($zip->open('public/old_software.xlsx') !== true) { exit(1); }

$sharedXml = simplexml_load_string($zip->getFromName('xl/sharedStrings.xml'));
$shared = [];
foreach ($sharedXml->si as $si) {
    if (isset($si->t)) { $shared[] = (string) $si->t; }
    else { $t=''; foreach ($si->r as $r) { $t .= (string) $r->t; } $shared[] = $t; }
}
$wb = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
$rels = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
$relMap = [];
foreach ($rels->Relationship as $r) { $relMap[(string) $r['Id']] = (string) $r['Target']; }
$targets = [];
foreach ($wb->sheets->sheet as $s) {
    $rid = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
    $targets[(string) $s['name']] = 'xl/'.$relMap[$rid];
}
function ci(string $c): int { preg_match('/^([A-Z]+)/', $c, $m); $x=0; foreach (str_split($m[1]) as $ch) { $x = $x*26 + ord($ch)-64; } return $x-1; }
function rowsOf(ZipArchive $zip, array $targets, array $shared, string $name): array {
    $sx = simplexml_load_string($zip->getFromName($targets[$name]));
    $out = [];
    foreach ($sx->sheetData->row as $row) {
        $v = [];
        foreach ($row->c as $c) {
            $val = isset($c->v) ? (string) $c->v : '';
            if ((string) $c['t'] === 's') { $val = $shared[(int) $val] ?? $val; }
            if ((string) $c['t'] === 'inlineStr') { $val = (string) $c->is->t; }
            $v[ci((string) $c['r'])] = trim($val);
        }
        ksort($v);
        $out[(int) $row['r']] = $v;
    }
    ksort($out);
    return array_values($out);
}
$clean = fn ($s) => preg_replace('/\s+/', ' ', trim((string) $s));

// ── categories ──
$rows = rowsOf($zip, $targets, $shared, 'tbl_OrderCatagary');
$f = fopen('docs/data/legacy-categories.csv', 'w');
fputcsv($f, ['category_id', 'name', 'sequence_no']);
foreach (array_slice($rows, 1) as $r) {
    if (($r[0] ?? '') === '') { continue; }
    fputcsv($f, [(int) $r[0], $clean($r[1] ?? ''), (int) ($r[2] ?? 0)]);
}
fclose($f);

// ── kitchens ──
$rows = rowsOf($zip, $targets, $shared, 'tbl_kitchen');
$f = fopen('docs/data/legacy-kitchens.csv', 'w');
fputcsv($f, ['kitchen_id', 'name']);
foreach (array_slice($rows, 1) as $r) {
    if (($r[0] ?? '') === '' || $clean($r[1] ?? '') === '') { continue; }
    fputcsv($f, [(int) $r[0], $clean($r[1])]);
}
fclose($f);

// ── order items (the product master) ──
$rows = rowsOf($zip, $targets, $shared, 'tbl_OrderItem');
$f = fopen('docs/data/legacy-order-items.csv', 'w');
fputcsv($f, ['order_item_id', 'name', 'category_id', 'unit', 'order_rate', 'meat_rate', 'service_rate', 'qty_in_no', 'cat_allow', 'kitchen_id', 'kitchen_allow', 'complimentary', 'sequence_no', 'meat_type', 'additional_rate']);
$n = 0;
foreach (array_slice($rows, 1) as $r) {
    if (($r[0] ?? '') === '' || $clean($r[1] ?? '') === '') { continue; }
    fputcsv($f, [
        (int) $r[0], $clean($r[1]), (int) ($r[2] ?? 0), $clean($r[3] ?? ''),
        (float) ($r[4] ?? 0), (float) ($r[5] ?? 0), (float) ($r[6] ?? 0),
        (float) ($r[8] ?? 0),
        strtoupper($clean($r[11] ?? '')) === 'Y' ? 'Y' : 'N',
        (int) ($r[12] ?? 0),
        strtoupper($clean($r[13] ?? '')) === 'Y' ? 'Y' : 'N',
        strtoupper($clean($r[14] ?? '')) === 'Y' ? 'Y' : 'N',
        (int) ($r[15] ?? 0),
        strtoupper($clean($r[16] ?? '')),
        (float) ($r[18] ?? 0),
    ]);
    $n++;
}
fclose($f);
echo "items=$n\n";

// ── customers: derived from tbl_OrderMaster (latest name/address per phone) ──
//
// KASHIF-LEGACY-PHONE-SPLIT-1 — the old software kept TWO numbers in ONE field.
// Stripping every non-digit fused them into a 22-digit string, and that string
// then became the customer's identity: the same person, recorded once with one
// number and once with two, arrived as two customers — and the fused one was
// unreachable, because nobody searches by a 22-digit phone. On the live tenant
// that is 161 rows, 61 of which are duplicates of a customer already there.
//
// Split ONLY where the evidence is unambiguous: exactly 22 digits, both halves
// starting with 0. A 12-digit number with one digit left over is a typo, not a
// second phone, and splitting it would corrupt a real number — those are left
// exactly as they are for a human to look at.
$splitPhone = function ($raw): array {
    $d = preg_replace('/\D/', '', (string) $raw);
    if (strlen($d) === 22 && $d[0] === '0' && $d[11] === '0') {
        return [substr($d, 0, 11), substr($d, 11)];
    }

    return [$d, ''];
};

$rows = rowsOf($zip, $targets, $shared, 'tbl_OrderMaster');
$byPhone = [];
foreach (array_slice($rows, 1) as $r) {
    $id = (int) ($r[0] ?? 0);
    $name = $clean($r[4] ?? '');
    [$phone, $altPhone] = $splitPhone($r[5] ?? '');
    $addr = $clean($r[6] ?? '');
    if ($phone === '' || strlen($phone) < 7 || $name === '' || strtoupper($name) === 'CASH') { continue; }
    if (strtoupper($addr) === 'SELF') { $addr = ''; }
    if (! isset($byPhone[$phone]) || $id > $byPhone[$phone]['id']) {
        $byPhone[$phone] = [
            'id' => $id, 'name' => $name, 'addr' => $addr,
            // The alternate is remembered even when a later order carried only
            // the one number — losing it would be losing a way to reach them.
            'alt' => $altPhone !== '' ? $altPhone : ($byPhone[$phone]['alt'] ?? ''),
            'orders' => ($byPhone[$phone]['orders'] ?? 0),
        ];
    } elseif ($altPhone !== '' && ($byPhone[$phone]['alt'] ?? '') === '') {
        $byPhone[$phone]['alt'] = $altPhone;
    }
    $byPhone[$phone]['orders']++;
}
ksort($byPhone);
$f = fopen('docs/data/legacy-customers.csv', 'w');
fputcsv($f, ['phone', 'name', 'address', 'alt_phone', 'orders_count']);
foreach ($byPhone as $phone => $c) {
    fputcsv($f, [$phone, $c['name'], $c['addr'], $c['alt'] ?? '', $c['orders']]);
}
fclose($f);
echo 'customers='.count($byPhone)."\n";

// ── item ↔ raw material links (phase-2 reference) ──
$rows = rowsOf($zip, $targets, $shared, 'tbl_OrderItemFar');
$f = fopen('docs/data/legacy-item-materials.csv', 'w');
fputcsv($f, ['order_item_id', 'item_id', 'qty', 'consume', 'fixed']);
$n = 0;
foreach (array_slice($rows, 1) as $r) {
    if (($r[0] ?? '') === '') { continue; }
    fputcsv($f, [(int) $r[0], (int) ($r[1] ?? 0), (float) ($r[2] ?? 0), (float) ($r[4] ?? 0), $clean($r[5] ?? '')]);
    $n++;
}
fclose($f);
echo "item_materials=$n\n";

// ── orders: master + detail (go-live continuity + history reference) ──
$rows = rowsOf($zip, $targets, $shared, 'tbl_OrderMaster');
$f = fopen('docs/data/legacy-orders.csv', 'w');
fputcsv($f, ['id','order_no','booking_date','party_desc','phone','address','delivery_place','delivery_date','delivery_time','ot_charge','fare','service_charges','barbq_charges']);
$n=0;
foreach (array_slice($rows, 1) as $r) {
    if (($r[0] ?? '') === '') { continue; }
    fputcsv($f, [
        (int) $r[0], $clean($r[1] ?? ''), substr((string) ($r[2] ?? ''), 0, 10),
        $clean($r[4] ?? ''), preg_replace('/[^0-9]/', '', (string) ($r[5] ?? '')),
        $clean($r[6] ?? ''), $clean($r[7] ?? ''), substr((string) ($r[8] ?? ''), 0, 10),
        substr((string) ($r[9] ?? ''), 11, 8),
        (float) ($r[10] ?? 0), (float) ($r[11] ?? 0), (float) ($r[12] ?? 0), (float) ($r[13] ?? 0),
    ]);
    $n++;
}
fclose($f);
echo "orders=$n\n";

$rows = rowsOf($zip, $targets, $shared, 'tbl_orderdetail');
$f = fopen('docs/data/legacy-order-lines.csv', 'w');
fputcsv($f, ['order_id','sno','order_item_id','instruction','cat','meat','rice','additional','daigs','rate','net']);
$n=0;
foreach (array_slice($rows, 1) as $r) {
    if (($r[0] ?? '') === '') { continue; }
    fputcsv($f, [
        (int) $r[0], (int) ($r[1] ?? 0), (int) ($r[2] ?? 0), $clean($r[3] ?? ''),
        strtoupper($clean($r[4] ?? '')), (float) ($r[5] ?? 0), (float) ($r[6] ?? 0),
        (float) ($r[7] ?? 0), (float) ($r[8] ?? 0), (float) ($r[9] ?? 0), (float) ($r[11] ?? 0),
    ]);
    $n++;
}
fclose($f);
echo "order_lines=$n\n";

// ── raw material master (tbl_Item): the real materials AND their rates ──
$rows = rowsOf($zip, $targets, $shared, 'tbl_Item');
$f = fopen('docs/data/legacy-materials.csv', 'w');
fputcsv($f, ['item_id', 'name', 'unit', 'rate', 'category_id']);
$n = 0;
foreach (array_slice($rows, 1) as $r) {
    if (($r[0] ?? '') === '' || $clean($r[1] ?? '') === '') { continue; }
    fputcsv($f, [(int) $r[0], $clean($r[1]), $clean($r[5] ?? ''), (float) ($r[6] ?? 0), (int) ($r[7] ?? 0)]);
    $n++;
}
fclose($f);
echo "materials=$n
";

// ── suppliers (V_Supplier): the vendor book, as thin as the old software kept it ──
//
// KASHIF-LEGACY-SUPPLIERS-1. 252 rows sit under GL parent 201002, but only the
// NAME is reliably there: phone is on 28 of them and address, city, NTN, sales
// tax number, discount and payment terms are empty on all 252 — those fields do
// not exist in the old software, so nothing downstream should pretend they do.
//
// Two kinds of row are dropped, not imported: `DELETE` (14) and `EMTY` (2) are
// the old book's own tombstones.
//
// The opening balances ARE carried into the CSV but deliberately NOT into the
// tenant by the importer: 7 suppliers hold ~6.49M credit and ~4.49M debit, and
// that is money. It reaches the ledger only through a separate, GL-posting step
// once the owner confirms the figures are still true.
$rows = rowsOf($zip, $targets, $shared, 'V_Supplier');
$f = fopen('docs/data/legacy-suppliers.csv', 'w');
fputcsv($f, ['account_no', 'name', 'phone', 'address', 'city', 'opening_credit', 'opening_debit']);
$n = 0;
$skipped = 0;
foreach (array_slice($rows, 1) as $r) {
    $name = $clean($r[1] ?? '');
    $accountNo = $clean($r[0] ?? '');
    if ($accountNo === '' || $name === '') { $skipped++; continue; }
    if (in_array(strtoupper($name), ['DELETE', 'EMTY', 'EMPTY'], true)) { $skipped++; continue; }

    // The address column carries a stray number on one row (1500000) — a
    // mis-keyed entry in the old software. An address made only of digits is
    // not an address.
    $addr = $clean($r[7] ?? '');
    if ($addr !== '' && preg_match('/^\d+$/', $addr)) { $addr = ''; }

    fputcsv($f, [
        $accountNo, $name,
        preg_replace('/[^0-9+]/', '', (string) ($r[8] ?? '')),
        $addr, $clean($r[9] ?? ''),
        (float) ($r[4] ?? 0), (float) ($r[3] ?? 0),
    ]);
    $n++;
}
fclose($f);
echo "suppliers=$n skipped=$skipped\n";