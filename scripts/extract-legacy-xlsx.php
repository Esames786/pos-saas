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
fputcsv($f, ['order_item_id', 'name', 'category_id', 'unit', 'order_rate', 'meat_rate', 'service_rate', 'qty_in_no', 'cat_allow', 'kitchen_id', 'kitchen_allow']);
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
    ]);
    $n++;
}
fclose($f);
echo "items=$n\n";

// ── customers: derived from tbl_OrderMaster (latest name/address per phone) ──
$rows = rowsOf($zip, $targets, $shared, 'tbl_OrderMaster');
$byPhone = [];
foreach (array_slice($rows, 1) as $r) {
    $id = (int) ($r[0] ?? 0);
    $name = $clean($r[4] ?? '');
    $phone = preg_replace('/[^0-9]/', '', (string) ($r[5] ?? ''));
    $addr = $clean($r[6] ?? '');
    if ($phone === '' || strlen($phone) < 7 || $name === '' || strtoupper($name) === 'CASH') { continue; }
    if (strtoupper($addr) === 'SELF') { $addr = ''; }
    if (! isset($byPhone[$phone]) || $id > $byPhone[$phone]['id']) {
        $byPhone[$phone] = ['id' => $id, 'name' => $name, 'addr' => $addr, 'orders' => ($byPhone[$phone]['orders'] ?? 0)];
    }
    $byPhone[$phone]['orders']++;
}
ksort($byPhone);
$f = fopen('docs/data/legacy-customers.csv', 'w');
fputcsv($f, ['phone', 'name', 'address', 'orders_count']);
foreach ($byPhone as $phone => $c) {
    fputcsv($f, [$phone, $c['name'], $c['addr'], $c['orders']]);
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
