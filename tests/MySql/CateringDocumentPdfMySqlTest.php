<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringDocumentController;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-PDF-1 — the quotation as a file.
 *
 * Three claims are worth protecting here, and none of them is "a PDF appears".
 *
 *   1. It is the SAME document. The PDF and the sheet the customer is handed
 *      must never be two documents that can disagree; the only difference is a
 *      stylesheet that says the same layout in a dialect dompdf can render.
 *   2. Urdu is REFUSED, not attempted. dompdf has no complex-script shaping, so
 *      Urdu would emerge as isolated letters running the wrong way — a page that
 *      looks like the feature worked and cannot be read. The house already
 *      answers this way for thermal printing.
 *   3. It needs no new route, and therefore no new permission on any tenant.
 *      Whoever may read this document on screen may read it as a file; a second
 *      authority is a second thing that can be granted wrongly.
 *
 * The controller is called directly rather than through a seeded tenant host:
 * this is about what the ACTION does, and the action is the real one. Route and
 * permission facts are asserted where they actually live — on the route table.
 */
class CateringDocumentPdfMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        View::share('errors', new \Illuminate\Support\ViewErrorBag);

        $this->cleanTenant([
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_final_invoices',
            'catering_events', 'catering_settings',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'units', 'products', 'categories', 'branches',
        ]);

        $branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = $this->makeProduct($categoryId, ['name' => 'Chicken Biryani', 'unit_id' => $unitId]);

        $estimates = app(CateringEstimateService::class);
        $this->event = $estimates->createEvent([
            'branch_id' => $branchId,
            'customer_name' => 'PDF Test Customer',
            'customer_phone' => '03001234567',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(9)->toDateString(),
            'venue' => 'Orbit Hall',
            'pax' => 450,
        ]);
        $estimates->saveDraftLines($this->event->currentEstimate, [[
            'product_id' => $productId, 'item_name' => 'Chicken Biryani',
            'quantity' => 28, 'unit_id' => $unitId, 'unit_code' => 'KG', 'rate' => 3375,
        ]], []);

        $this->event->refresh();
    }

    private function ask(string $query)
    {
        return (new CateringDocumentController)->estimate(
            Request::create('/catering/documents/estimate/1?'.$query),
            $this->event->currentEstimate
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_quotation_comes_back_as_a_real_pdf_file(): void
    {
        $res = $this->ask('lang=en&format=pdf');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));

        $bytes = $res->getContent();
        $this->assertStringStartsWith('%PDF-', $bytes, 'the bytes must actually be a PDF');
        $this->assertGreaterThan(5000, strlen($bytes), 'an empty-looking PDF is a failed render');

        // The file arrives named after the booking, not "document.pdf".
        $this->assertStringContainsString($this->event->event_no,
            $res->headers->get('Content-Disposition'));
    }

    /**
     * The refusal is the feature, not a gap in it.
     *
     * A page of separated Urdu letters running left to right would look printed
     * and be unreadable, and the operator would only find out after handing it
     * over. So the answer is no, said in a page they can read, with the way that
     * DOES work written on it.
     */
    public function test_urdu_is_refused_rather_than_drawn_as_broken_letters(): void
    {
        foreach (['ur', 'both'] as $lang) {
            $res = $this->ask('lang='.$lang.'&format=pdf');

            $this->assertSame(422, $res->getStatusCode(), "lang={$lang} must be refused");
            $this->assertStringNotContainsString('application/pdf', (string) $res->headers->get('Content-Type'));

            $body = $res->getContent();
            $this->assertStringContainsString('English only', $body);
            $this->assertStringContainsString('Save as PDF', $body,
                'the refusal must name the way that does work');
        }
    }

    /** Without the parameter, the browser gets exactly the page it always got. */
    public function test_the_screen_document_is_untouched(): void
    {
        $res = $this->ask('lang=en');

        $html = $res->render();
        $this->assertStringContainsString('doc-header', $html);
        $this->assertStringContainsString('display: flex', $html,
            'the browser document still lays itself out the way it always has');
        $this->assertStringNotContainsString('.doc-header { display: table;', $html,
            'and it must never see the PDF override sheet');
    }

    /**
     * The override sheet OVERRIDES; it does not rewrite the document.
     *
     * dompdf has no flexbox, so the header, the two meta boxes and the signature
     * line would each stack vertically and the sheet would look nothing like the
     * printed one. The same layout is stated again in CSS 2.1 tables — and the
     * original rules stay exactly where they are, which is what keeps the
     * browser's document unchanged.
     */
    public function test_the_pdf_says_the_same_layout_in_a_dialect_dompdf_renders(): void
    {
        $html = view('tenant.catering.documents.estimate', [
            'estimate' => $this->event->currentEstimate,
            'event' => $this->event,
            'lang' => 'en',
            'position' => app(\App\Services\Catering\CateringFinancialPositionService::class)
                ->position($this->event),
            'advanceTotal' => 0,
            'businessName' => 'Test Caterers',
            'pdf' => true,
        ])->render();

        foreach ([
            '.doc-header { display: table;',
            '.meta-box { display: table-cell;',
            '.footer { display: table;',
            '.print-bar { display: none; }',
        ] as $rule) {
            $this->assertStringContainsString($rule, $html, "the PDF sheet must carry: {$rule}");
        }

        // Overridden, not deleted.
        $this->assertStringContainsString('display: flex', $html,
            'the original rules stay — this is an override sheet, not a rewrite');

        // A one-sided auto margin is not resolved by dompdf, so the totals block
        // is placed with a real figure instead of hoping.
        $this->assertStringContainsString('.totals { margin-left: 54%', $html);
    }

    /**
     * No new route, and therefore no new permission to grant on every tenant.
     *
     * `deploy.sh` grants a new route's permission to Owner alone; every other
     * role gets its own from a per-tenant seeder that a deploy does not run. A
     * parameter on a route that is already gated inherits the gate that already
     * exists, and there is nothing left to get wrong.
     */
    public function test_the_pdf_needs_no_route_and_no_permission_of_its_own(): void
    {
        $this->assertTrue(Route::has('tenant.catering.documents.estimate'));
        $this->assertContains('route.permission',
            Route::getRoutes()->getByName('tenant.catering.documents.estimate')->gatherMiddleware());

        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('pdf', (string) $route->getName(),
                'the PDF must ride the document route it belongs to, not a route of its own');
        }
    }

    /**
     * The layout SURVIVES into the file — proved from the file, not from the CSS.
     *
     * Asserting that the override stylesheet is present only proves a stylesheet
     * is present. What matters is where the ink lands: dompdf ignoring the
     * layout would stack the brand above the title, the customer box above the
     * event box and the two signatures above one another, and every one of those
     * pages would still contain the stylesheet and still be a valid PDF.
     *
     * So this reads the PDF's own content stream. Two things printed side by
     * side share a Y and differ in X. Two things stacked do the opposite. There
     * is no way to fake that with a string.
     */
    public function test_the_pdf_keeps_the_layout_instead_of_stacking_it(): void
    {
        $runs = $this->textRuns($this->ask('lang=en&format=pdf')->getContent());
        $this->assertNotEmpty($runs, 'the PDF must contain readable text runs');

        foreach ([
            ['CUSTOMER', 'EVENT', 'the customer box and the event box sit side by side'],
            ['Prepared By', 'Customer Approval', 'the two signature lines sit side by side'],
        ] as [$leftText, $rightText, $why]) {
            $left = $this->runFor($runs, $leftText);
            $right = $this->runFor($runs, $rightText);

            $this->assertLessThan(4, abs($left['y'] - $right['y']),
                $why.' — they are on different lines, so the layout stacked');
            $this->assertGreaterThan(150, $right['x'] - $left['x'],
                $why.' — they are not far enough apart to be two columns');
        }

        // The totals block sits on the far side of the page. dompdf does not
        // resolve the one-sided auto margin that puts it there in a browser, so
        // the override states a real figure — and this is what proves it took.
        $subtotal = $this->runFor($runs, 'Subtotal');
        $this->assertGreaterThan(280, $subtotal['x'],
            'the totals block must sit on the far side, not against the left margin');

        // The on-screen Print button is not part of the paper.
        foreach ($runs as $r) {
            $this->assertNotSame('Print', $r['t'], 'the screen-only Print button must not reach the file');
        }
    }

    /**
     * Every text run in the PDF, as x / y / text.
     *
     * dompdf writes each run as `BT <x> <y> Td … [(text)] TJ ET`, so the page's
     * own content stream says exactly where each string was placed.
     */
    private function textRuns(string $pdf): array
    {
        $content = '';
        $pos = 0;
        while (($s = strpos($pdf, 'stream', $pos)) !== false) {
            $b = $s + 6;
            $b += ($pdf[$b] ?? '') === "\r" ? 1 : 0;
            $b += ($pdf[$b] ?? '') === "\n" ? 1 : 0;
            $e = strpos($pdf, 'endstream', $b);
            if ($e === false) {
                break;
            }
            $chunk = substr($pdf, $b, $e - $b);
            $inflated = @gzuncompress($chunk);
            if ($inflated === false) {
                $inflated = @gzinflate($chunk);
            }
            $content .= ($inflated === false ? $chunk : $inflated)."\n";
            $pos = $e + 9;
        }

        preg_match_all('/BT\s+([\d.-]+)\s+([\d.-]+)\s+Td(.*?)ET/s', $content, $m, PREG_SET_ORDER);

        $runs = [];
        foreach ($m as $run) {
            preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/', $run[3], $t);
            $text = trim(implode('', array_map('stripcslashes', $t[1])));
            if ($text !== '') {
                $runs[] = ['x' => (float) $run[1], 'y' => (float) $run[2], 't' => $text];
            }
        }

        return $runs;
    }

    private function runFor(array $runs, string $text): array
    {
        foreach ($runs as $r) {
            if ($r['t'] === $text) {
                return $r;
            }
        }

        $this->fail("the PDF does not contain the text [{$text}]");
    }

    /** Drawing a file is not an accounting event. */
    public function test_asking_for_the_pdf_writes_nothing(): void
    {
        $db = DB::connection('tenant');
        $before = [
            'journals' => $db->table('journal_entries')->count(),
            'stock' => $db->table('stock_ledgers')->count(),
            'total' => $db->table('catering_estimates')->where('id', $this->event->currentEstimate->id)->value('grand_total'),
            'touched' => $db->table('catering_estimates')->where('id', $this->event->currentEstimate->id)->value('updated_at'),
        ];

        $this->ask('lang=en&format=pdf');

        $after = [
            'journals' => $db->table('journal_entries')->count(),
            'stock' => $db->table('stock_ledgers')->count(),
            'total' => $db->table('catering_estimates')->where('id', $this->event->currentEstimate->id)->value('grand_total'),
            'touched' => $db->table('catering_estimates')->where('id', $this->event->currentEstimate->id)->value('updated_at'),
        ];

        $this->assertSame($before, $after, 'printing a document must change nothing at all');
    }
}
