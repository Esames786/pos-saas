{{-- KASHIF-CATERING-PDF-1 — the same document, said in a dialect dompdf speaks.

     This is an OVERRIDE sheet, included last and only when the page is being
     drawn into a PDF. The browser never sees it, so the document a customer has
     been handed for months is byte-identical to what it was.

     Why it is needed at all: the document lays its header, its two meta boxes
     and its signature line out with flexbox, and dompdf has no flexbox. Left
     alone, every one of those pairs would stack vertically and the sheet would
     look nothing like the one on screen. CSS 2.1 table display says the same
     thing in a language dompdf renders exactly.

     ONE partial, included by both the estimate and the final invoice, because
     two copies of this knowledge would drift the first time either changed. --}}
@if($pdf ?? false)
<style>
    /* dompdf honours @media print, so the sheet's own print rules already
       apply. These are stated again rather than assumed: a PDF that quietly
       depends on somebody else's media query is a PDF that breaks silently. */
    html, body { background: #fff; }
    body { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    .print-bar { display: none; }

    /* Brand on one side, document title on the other. */
    .doc-header { display: table; width: 100%; }
    .doc-header > div { display: table-cell; vertical-align: top; }

    /* Customer box and event box, side by side. */
    .meta-grid { display: table; width: 100%; border-spacing: 12px 0; }
    .meta-box { display: table-cell; width: 50%; vertical-align: top; }

    /* Label on the reading side, value on the far side. Class selectors only —
       no :last-child, no sibling combinators, nothing dompdf might not parse. */
    .meta-row { display: table; width: 100%; }
    .meta-row > span { display: table-cell; text-align: {{ ($isUr ?? false) ? 'left' : 'right' }}; }
    .meta-row > span.k { text-align: {{ ($isUr ?? false) ? 'right' : 'left' }}; white-space: nowrap; }

    /* The two signature lines. */
    .footer { display: table; width: 100%; }
    .footer > .sig { display: table-cell; }

    /* `margin-left: auto` is how the totals block sits on the far side of the
       page in a browser. dompdf does not resolve one-sided auto margins, so the
       same position is stated as a real figure — 46% wide, pushed by 54%. */
    .totals { margin-left: {{ ($isUr ?? false) ? '0' : '54%' }}; margin-right: {{ ($isUr ?? false) ? '54%' : '0' }}; }
</style>
@endif
