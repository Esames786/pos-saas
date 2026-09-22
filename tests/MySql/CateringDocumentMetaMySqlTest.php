<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringEventController;
use App\Models\Tenant\CateringEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-PAX-OPTIONAL-1 + CATERING-DOC-META-1 — client, 22 September.
 *
 * Two complaints off one quotation (EV-20260922-0034):
 *
 *  1. "this is optional" — PAX refused to let a booking be saved without a
 *     guest count. An inquiry often arrives before the count does, and the
 *     browser's own "Please fill out this field" was blocking it.
 *
 *  2. "fix the address gap" — the document printed
 *     "Addresssaima royal residency bl 2 near imtiaz super store gulshan iqbal".
 *     Label and value are two flex items with only `justify-content:
 *     space-between` holding them apart, and a long value fills its line right
 *     up to the label.
 *
 *  3. "all this should be capitalize" — the operator types in whatever case the
 *     moment allows, and it reaches the customer that way.
 *
 * WHAT THIS FILE CAN AND CANNOT PROVE. The PAX behaviour is real and is
 * exercised through the controller. The spacing is VISUAL — no assertion here
 * can see a rendered millimetre, so those checks lock the RULES in place
 * (padding on the label, not `gap`; the uppercase class on the free text) and
 * would go red if a later edit dropped them. They are regression locks, and are
 * not a substitute for looking at the document.
 *
 * `gap` is deliberately not used and that is asserted: the same markup is drawn
 * by dompdf for the PDF, and dompdf implements no flex gap — verified in
 * vendor/dompdf/dompdf/src/Css. A gap-only fix would look right on screen and
 * still print joined up.
 */
class CateringDocumentMetaMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'customers', 'branches',
        ]);

        $this->branchId = $this->makeBranch();
    }

    // ── CATERING-PAX-OPTIONAL-1 ────────────────────────────────────────────

    /** The complaint itself: a booking with no guest count is accepted. */
    public function test_a_booking_saves_with_no_pax_at_all(): void
    {
        $data = $this->valid();
        unset($data['pax']);

        $this->controller()->store($this->jsonRequest($data));

        $event = CateringEvent::firstOrFail();
        $this->assertSame(0, (int) $event->pax,
            'an absent count is stored as zero — the column is NOT NULL with a default '
            .'a DEFAULT never applies to an explicit null');
    }

    /** An empty box is the same thing: that is what the operator actually sends. */
    public function test_an_empty_pax_box_is_accepted(): void
    {
        $this->controller()->store($this->jsonRequest($this->valid(['pax' => ''])));

        $this->assertSame(0, (int) CateringEvent::firstOrFail()->pax);
    }

    /** A real count is still stored exactly, and still validated. */
    public function test_a_real_pax_is_kept_and_nonsense_is_still_refused(): void
    {
        $this->controller()->store($this->jsonRequest($this->valid(['pax' => 551])));
        $this->assertSame(551, (int) CateringEvent::firstOrFail()->pax);

        try {
            $this->controller()->store($this->jsonRequest($this->valid(['pax' => -5])));
            $this->fail('a negative guest count must still be refused');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('pax', $e->errors());
        }
    }

    // ── CATERING-DOC-META-1 ────────────────────────────────────────────────

    /**
     * The label is held off the value by PADDING, which dompdf honours, and NOT
     * by flex `gap`, which it silently ignores.
     */
    public function test_the_meta_label_is_separated_in_a_way_the_pdf_can_render(): void
    {
        $css = $this->renderStyle();

        $this->assertMatchesRegularExpression(
            '/\.meta-row \.k \{[^}]*padding-(left|right):\s*\d/s',
            $css,
            'the label carries its own padding, so the address cannot touch it'
        );
        $this->assertMatchesRegularExpression(
            '/\.meta-row \.k \{[^}]*flex:\s*0 0 auto/s',
            $css,
            'and the label cannot be squeezed into the value'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.meta-row \{[^}]*gap:/s',
            $css,
            'flex gap would look right on screen and still print joined up — dompdf has none'
        );
    }

    /** The free text the operator types is lifted to caps ON THE DOCUMENT. */
    public function test_the_free_text_is_uppercased_on_the_document(): void
    {
        $css = $this->renderStyle();
        $this->assertMatchesRegularExpression(
            '/\.meta-box \.cap[^{]*\{[^}]*text-transform:\s*uppercase/s',
            $css
        );

        $body = file_get_contents(resource_path(
            'views/tenant/catering/documents/partials/estimate-body.blade.php'
        ));

        foreach (['customer_address', 'event_type', 'venue'] as $field) {
            // `v cap`: the value cell AND the case lift. Both classes matter —
            // `v` is what dompdf's override sheet and the browser both align and
            // wrap, `cap` is the case.
            $this->assertMatchesRegularExpression(
                '/<span class="v cap">\{\{ \$event->'.$field.' \}\}<\/span>/',
                $body,
                "{$field} is the operator's own typing and reads as part of the block"
            );
        }
        $this->assertStringContainsString('<div class="name"', $body,
            'the customer name too');
    }

    /**
     * And the RECORD is untouched. The document is where it reads as caps; the
     * booking still holds what the operator typed, because rewriting stored text
     * to make a page look tidy loses the only copy of what was actually entered.
     */
    public function test_the_stored_text_keeps_the_operators_own_spelling(): void
    {
        $this->controller()->store($this->jsonRequest($this->valid([
            'customer_address' => 'saima royal residency bl 2 near imtiaz super store',
            'venue' => 'al mehmil banquet five star',
            'event_type' => 'valima',
        ])));

        $event = CateringEvent::firstOrFail();
        $this->assertSame('saima royal residency bl 2 near imtiaz super store', $event->customer_address);
        $this->assertSame('al mehmil banquet five star', $event->venue);
        $this->assertSame('valima', $event->event_type);
    }

    /**
     * "captalize in pdf print also" — so this does not reason about CSS, it
     * DRAWS the real quotation through the same dompdf the controller uses and
     * reads the text back out of the PDF.
     *
     * Worth the trouble: dompdf implements only part of CSS, and this document
     * needs a whole override sheet (pdf-overrides) precisely because it has no
     * flexbox. "The property exists in their source" is not the same claim as
     * "the PDF comes out in capitals", and only one of those is what was asked
     * for.
     */
    public function test_the_pdf_really_comes_out_in_capitals(): void
    {
        $address = 'saima royal residency bl 2 near imtiaz super store';

        $this->controller()->store($this->jsonRequest($this->valid([
            'customer_address' => $address,
            'venue' => 'al mehmil banquet five star',
        ])));

        $event = CateringEvent::firstOrFail();
        // Exactly what CateringDocumentController::estimate() hands the view —
        // including `position`, which the document needs and which comes from
        // the one settlement service every finance surface uses.
        $position = app(\App\Services\Catering\CateringFinancialPositionService::class)
            ->position($event);

        $html = view('tenant.catering.documents.estimate', [
            'estimate' => $event->currentEstimate,
            'event' => $event,
            'lang' => 'en',
            'pdf' => true,
            'position' => $position,
            'advanceTotal' => $position['net_received'],
            'businessName' => 'Kashif Kitchen',
        ])->render();

        // The controller's own options, unchanged.
        $options = new \Dompdf\Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $pdf = new \Dompdf\Dompdf($options);
        $pdf->setPaper('a4', 'portrait');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        $this->assertGreaterThan(0, $pdf->getCanvas()->get_page_count(), 'the sheet still draws');

        // Uncompressed, or the text sits inside a FlateDecode stream and every
        // assertion below would pass by being unable to read anything at all.
        $bytes = $pdf->output(['compress' => 0]);

        // WORD by word, not the whole sentence: the meta box is narrow and a
        // long address is drawn as several text runs, so searching for all fifty
        // characters in one piece fails even when the PDF is perfect. That is
        // what it did on the first attempt at this test.
        $this->assertStringContainsString('Address', $bytes,
            'the probe can read this PDF at all — without this, a "not found" below means nothing');

        foreach (['SAIMA', 'ROYAL', 'RESIDENCY', 'IMTIAZ'] as $word) {
            $this->assertStringContainsString($word, $bytes,
                "'{$word}' reaches the PDF in capitals");
        }
        foreach (['saima', 'royal', 'residency'] as $word) {
            $this->assertStringNotContainsString($word, $bytes,
                "'{$word}' — the lower-case original is not what was drawn");
        }

        $this->assertStringContainsString('MEHMIL', $bytes, 'the venue too');
    }

    private function renderStyle(): string
    {
        // Rendered, not read off disk: the file is a Blade template whose
        // padding side flips for Urdu, so the compiled output is the thing that
        // actually reaches the page.
        return view('tenant.catering.documents.partials.estimate-style', [
            'isUr' => false, 'isBoth' => false,
        ])->render();
    }

    private function controller(): CateringEventController
    {
        return app(CateringEventController::class);
    }

    private function jsonRequest(array $data): Request
    {
        $request = Request::create('/x', 'POST', $data, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setLaravelSession(app('session.store'));

        return $request;
    }

    private function valid(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR,ABDUL NAEEM',
            'customer_phone' => '03002639896',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 120,
        ], $overrides);
    }
}
