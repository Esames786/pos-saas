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
        color: #111827; font-size: 13px; line-height: 1.35;
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
    .ur { font-family: 'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Nastaliq Urdu', serif; direction: rtl; line-height: 1.7; }
    .head { border: 2px solid #111827; border-radius: 6px; padding: 5px 10px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
    .head .customer { font-size: 17px; font-weight: bold; }
    .head .big { font-size: 16px; font-weight: bold; }
    .head .label { font-size: 9px; text-transform: uppercase; color: #6b7280; letter-spacing: .8px; }
    .badge-row { display: flex; gap: 14px; margin-top: 3px; flex-wrap: wrap; }
    .doc-meta { display: flex; justify-content: space-between; margin: 4px 2px; color: #6b7280; font-size: 11px; }
    table.items { width: 100%; border-collapse: collapse; }
    table.items th { text-align: {{ $isUr ? 'right' : 'left' }}; border-bottom: 2px solid #111827; padding: 4px 8px; font-size: 11px; text-transform: uppercase; color: #374151; }
    table.items th.num, table.items td.num { text-align: {{ $isUr ? 'left' : 'right' }}; }
    table.items td { padding: 2px 8px; border-bottom: 1px solid #d1d5db; vertical-align: top; }
    table.items th.sr, table.items td.sr { text-align: center; color: #6b7280; font-size: 12px; }
    .item-name { font-size: 15px; font-weight: bold; }
    .item-ur { font-size: 16px; }
    .qty { font-size: 16px; font-weight: bold; white-space: nowrap; }
    .instructions { color: #374151; }
    /* KASHIF-KITCHEN-MATERIALS-1: the material line sits UNDER the dish and
       stays quieter than it — the dish name is what the cook reads first. */
    .line-mats-inline { font-size: 10px; color: #4b5563; margin-top: 0; line-height: 1.25; }
    .req { margin-top: 12px; page-break-inside: avoid; }
    .req h3 { border-bottom: 2px solid #111827; padding-bottom: 4px; font-size: 14px; }
    table.req-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    table.req-table th { text-align: {{ $isUr ? 'right' : 'left' }}; padding: 5px 8px; background: #f3f4f6; }
    table.req-table th.num, table.req-table td.num { text-align: {{ $isUr ? 'left' : 'right' }}; }
    table.req-table td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
    /* Sits in the grey gutter beside the sheet; it used to overlap the header. */
    .print-bar { position: fixed; top: 10px; {{ $isUr ? 'left' : 'right' }}: 10px; z-index: 10; }
    @media print { .print-bar { display: none; } }
    /* Rows must not split mid-dish across a page break, and the header repeats
       on every continuation page. */
    table.items thead, table.req-table thead { display: table-header-group; }
    table.items tr, table.req-table tr, .head { break-inside: avoid; page-break-inside: avoid; }
</style>