{{-- KASHIF-CATERING-OPERATOR-UI-1: the document stylesheet, shared verbatim
     between the single document and the bulk composition. --}}
<style>
    @page { size: A4 portrait; margin: 12mm; }
    * { box-sizing: border-box; }
    /* @page margin applies to PAPER only — on screen the sheet is drawn at real
       A4 size on a grey desk so the preview matches the printed output. Undone
       inside @media print so margins are never doubled. */
    html { background: #e5e7eb; }
    body {
        font-family: {{ $isUr ? "'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Nastaliq Urdu', serif" : "Arial, Helvetica, sans-serif" }};
        color: #111827; font-size: 14px; line-height: 1.5;
        width: 210mm; min-height: 297mm; margin: 12px auto; padding: 12mm;
        background: #fff; box-shadow: 0 2px 14px rgba(0,0,0,.18);
    }
    @media screen and (max-width: 230mm) {
        body { width: 100%; min-height: 0; margin: 0; padding: 8mm; box-shadow: none; }
    }
    @media print {
        html { background: #fff; }
        body { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    }
    /* Nastaliq descenders clip at the body's Latin leading — and the kitchen
       reads this sheet at arm's length, so give it extra room. */
    .ur { font-family: 'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Nastaliq Urdu', serif; direction: rtl; line-height: 2.1; }
    .head { border: 3px solid #111827; border-radius: 8px; padding: 12px 18px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
    .head .customer { font-size: 26px; font-weight: bold; }
    .head .big { font-size: 20px; font-weight: bold; }
    .head .label { font-size: 11px; text-transform: uppercase; color: #6b7280; letter-spacing: 1px; }
    .badge-row { display: flex; gap: 18px; margin-top: 6px; flex-wrap: wrap; }
    .doc-meta { display: flex; justify-content: space-between; margin: 8px 2px; color: #6b7280; font-size: 12px; }
    h3.station { background: #111827; color: #fff; padding: 6px 12px; border-radius: 4px; margin: 16px 0 0; font-size: 15px; }
    table.items { width: 100%; border-collapse: collapse; }
    table.items th { text-align: {{ $isUr ? 'right' : 'left' }}; border-bottom: 2px solid #111827; padding: 8px; font-size: 12px; text-transform: uppercase; color: #374151; }
    table.items th.num, table.items td.num { text-align: {{ $isUr ? 'left' : 'right' }}; }
    table.items td { padding: 10px 8px; border-bottom: 1px solid #d1d5db; vertical-align: top; }
    .item-name { font-size: 17px; font-weight: bold; }
    .item-ur { font-size: 18px; }
    .qty { font-size: 18px; font-weight: bold; white-space: nowrap; }
    .instructions { color: #374151; }
    .req { margin-top: 22px; page-break-inside: avoid; }
    .req h3 { border-bottom: 2px solid #111827; padding-bottom: 4px; font-size: 14px; }
    table.req-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    table.req-table th { text-align: {{ $isUr ? 'right' : 'left' }}; padding: 5px 8px; background: #f3f4f6; }
    table.req-table th.num, table.req-table td.num { text-align: {{ $isUr ? 'left' : 'right' }}; }
    table.req-table td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
    /* Sits in the grey gutter beside the sheet; it used to overlap the header. */
    .print-bar { position: fixed; top: 10px; {{ $isUr ? 'left' : 'right' }}: 10px; z-index: 10; }
    @media print { .print-bar { display: none; } }
    /* A station's rows must not split mid-dish across a page break. */
    table.items thead, table.req-table thead { display: table-header-group; }
    table.items tr, table.req-table tr, .head { break-inside: avoid; page-break-inside: avoid; }
    h3.station { break-after: avoid; page-break-after: avoid; }
</style>