{{-- KASHIF-CATERING-OPERATOR-UI-1: the document stylesheet, shared verbatim
     between the single document and the bulk composition. --}}
<style>
    @page { size: A4 portrait; margin: 14mm; }
    * { box-sizing: border-box; }
    /* @page margin applies to PAPER only. On screen the sheet is drawn at real
       A4 size on a grey desk so the preview matches what comes out of the
       printer — otherwise the document sits flush against the browser edge and
       looks nothing like the print. Undone inside @media print so the paper
       uses @page alone and margins are never doubled. */
    html { background: #e5e7eb; }
    body {
        font-family: {{ $isUr ? "'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Nastaliq Urdu', serif" : "Arial, Helvetica, sans-serif" }};
        color: #111827; font-size: {{ $isUr ? '16px' : '13px' }}; line-height: 1.5;
        width: 210mm; min-height: 297mm; margin: 12px auto; padding: 14mm;
        background: #fff; box-shadow: 0 2px 14px rgba(0,0,0,.18);
    }
    @media screen and (max-width: 230mm) {
        body { width: 100%; min-height: 0; margin: 0; padding: 10mm; box-shadow: none; }
    }
    @media print {
        html { background: #fff; }
        body { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    }
    /* Nastaliq descenders clip at the body's Latin leading. */
    .ur { font-family: 'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Nastaliq Urdu', serif; direction: rtl; line-height: 2; font-size: {{ $isUr ? '1em' : '1.28em' }}; }
    .doc-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #111827; padding-bottom: 12px; }
    .brand { font-size: 26px; font-weight: bold; letter-spacing: 0.5px; }
    .brand-sub { color: #6b7280; font-size: {{ $isUr ? '16px' : '12px' }}; }
    .doc-title { text-align: {{ $isUr ? 'left' : 'right' }}; }
    .doc-title h2 { margin: 0; font-size: 20px; }
    .doc-state { margin-top: 4px; font-weight: bold; font-size: 11px; letter-spacing: .06em; }
    .doc-state.superseded { color: #9a3412; }
    /* CAT-DOC-001: loud on screen, and it survives print — browsers strip
       backgrounds by default, so the border and the weight carry it. */
    .draft-banner {
        border: 2px dashed #b45309; background: #fffbeb; color: #7c2d12;
        padding: 8px 12px; margin: 0 0 14px; border-radius: 4px; font-size: 12px;
    }
    .draft-banner strong { display: block; font-size: 14px; letter-spacing: .06em; margin-bottom: 2px; }
    @media print {
        .draft-banner { border: 2px dashed #000; color: #000; background: transparent; }
    }
    .meta-grid { display: flex; gap: 24px; margin: 14px 0; }
    .meta-box { flex: 1; border: 1px solid #d1d5db; border-radius: 6px; padding: 10px 14px; }
    .meta-box h4 { margin: 0 0 6px; font-size: {{ $isUr ? '15px' : '11px' }}; text-transform: uppercase; color: #6b7280; letter-spacing: 1px; }
    .meta-row { display: flex; justify-content: space-between; padding: 2px 0; }
    .meta-row .k { color: #6b7280; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.items th { background: #111827; color: #fff; padding: 8px; font-size: {{ $isUr ? '15px' : '12px' }}; text-align: {{ $isUr ? 'right' : 'left' }}; }
    table.items th.num, table.items td.num { text-align: {{ $isUr ? 'left' : 'right' }}; }
    table.items td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
    table.items tr:nth-child(even) td { background: #f9fafb; }
    .item-ur { color: #374151; font-size: 18px; }
    .totals { width: 46%; margin-{{ $isUr ? 'right' : 'left' }}: auto; margin-top: 10px; border-collapse: collapse; }
    .totals td { padding: 5px 8px; }
    .totals .k { color: #6b7280; }
    .totals .num { text-align: {{ $isUr ? 'left' : 'right' }}; }
    .totals .grand td { font-weight: bold; font-size: 15px; border-top: 2px solid #111827; border-bottom: 3px double #111827; }
    .terms { margin-top: 18px; font-size: 11px; color: #6b7280; white-space: pre-line; }
    .footer { margin-top: 26px; display: flex; justify-content: space-between; font-size: 12px; }
    .sig { border-top: 1px solid #9ca3af; padding-top: 4px; width: 200px; text-align: center; color: #6b7280; }
    /* Sits in the grey gutter beside the sheet. It used to overlap the document
       header — in Urdu it flipped to the left and covered the estimate number. */
    .print-bar { position: fixed; top: 10px; {{ $isUr ? 'left' : 'right' }}: 10px; z-index: 10; }
    @media print { .print-bar { display: none; } }
    /* Keep rows, totals and signatures whole across a page break; repeat the
       header row on every continuation page. A 15-line estimate spills to p2. */
    table.items thead { display: table-header-group; }
    table.items tr, .totals, .terms, .footer, .meta-box { break-inside: avoid; page-break-inside: avoid; }
</style>
