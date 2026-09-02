<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Mail\SalesReportMail;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Printer;
use App\Models\Tenant\PosQuickReportSetting;
use App\Services\Printing\EscPosPayloadService;
use App\Services\Printing\PrintJobFactory;
use App\Services\Reports\SalesReportDocumentService;
use App\Services\Reports\SalesReportEngine;
use App\Support\TenantClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * QUICK-REPORT-SEND-1 — the POS "Quick Report" modal backend.
 *
 * A TRUSTED user (holding `tenant.pos.quick-report-send`) builds a Sales Report over the WHOLE tenant
 * (no terminal / order-type scoping — the permission is the only gate) for a single business date,
 * picks sections + optionally specific categories / items / waiters / order-types, and emails it as an
 * A4 PDF to the owner recipients, prints it here (thermal), or streams it to a network thermal printer.
 * Output is byte-identical to the Report Center — this is only a filter/selection front-end that reuses
 * the same engine, A4 document, thermal template and network-report bytes.
 */
class PosQuickReportController extends Controller
{
    /** Sections offered in the modal (Report Center's, minus the CSV-only "detailed"). */
    public const SECTIONS = ['overview', 'categories', 'items', 'deals', 'waiters', 'order_types', 'order_type_combos', 'cancellations', 'cash_bank'];

    private const PERMISSION = 'tenant.pos.quick-report-send';

    public function __construct(
        private readonly SalesReportEngine $engine,
        private readonly SalesReportDocumentService $document,
    ) {}

    private function guard(): void
    {
        abort_unless((bool) auth('tenant')->user()?->can(self::PERMISSION), 403, 'Permission denied.');
    }

    /**
     * Build the UNSCOPED filters (whole tenant, single date) + the requested sections. The
     * category/item/waiter/order-type multi-selects are passed into the engine so they narrow the
     * WHOLE report (every section + the NET SALES headline) and AND-compose — categories pull in their
     * sub-categories, and "All items" then means all items WITHIN the selected categories. Deliberately
     * does NOT call UserDataScope — an unscoped whole-tenant report is the whole point of the feature.
     */
    private function context(Request $request): array
    {
        $date = $request->input('date');
        if (! $date || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            $date = app(TenantClock::class)->currentBusinessDate();
        }

        $filters = $this->engine->normalizeFilters([
            'date_from'  => (string) $date,
            'date_to'    => (string) $date,
            // Whole tenant: every active branch. No terminal / order-type / cashier restriction.
            'branch_ids' => Branch::where('status', 'active')->pluck('id')->all(),
            // Multi-value report filters (empty = all). Item selection is ignored when All-items is on.
            'category_ids' => (array) $request->input('category_ids', []),
            'product_ids'  => $request->boolean('all_items') ? [] : (array) $request->input('product_ids', []),
            'waiter_ids'   => (array) $request->input('waiter_ids', []),
            'order_types'  => (array) $request->input('order_types', []),
        ]);

        $allowed  = self::SECTIONS;
        $sections = array_values(array_intersect((array) $request->input('sections', $allowed), $allowed));
        if ($sections === []) {
            $sections = $allowed;
        }

        return [$filters, $sections, (string) $date];
    }

    /** Owner recipients = the tenant's configured scheduled-report recipients, else the owner email. */
    private function recipients(): array
    {
        $row = DB::connection('tenant')->table('report_schedules')
            ->whereNotNull('recipient_emails')->orderBy('id')->value('recipient_emails');
        $emails = $row ? (array) json_decode((string) $row, true) : [];

        $emails = array_values(array_unique(array_filter(
            array_map(fn ($e) => strtolower(trim((string) $e)), $emails),
            fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false,
        )));

        if ($emails === []) {
            $owner = app()->bound('tenant') ? app('tenant')?->owner_email : null;
            if ($owner && filter_var($owner, FILTER_VALIDATE_EMAIL)) {
                $emails = [strtolower(trim($owner))];
            }
        }

        return $emails;
    }

    private function businessName(): string
    {
        return (string) (app()->bound('tenant') ? (app('tenant')->business_name ?? 'Sales Report') : 'Sales Report');
    }

    /* ── Actions ─────────────────────────────────────────────────────────────────────────────────── */

    /** Email the selected report as an A4 PDF to the owner recipients. */
    public function email(Request $request)
    {
        $this->guard();
        [$filters, $sections, $date] = $this->context($request);

        $recipients = $this->recipients();
        if ($recipients === []) {
            return response()->json(['ok' => false, 'message' => 'No owner email is configured to send the report to.'], 422);
        }

        $pdf = $this->document->pdf($filters, $sections);
        Mail::to($recipients)->send(new SalesReportMail(
            $this->businessName(), $date . ' to ' . $date, [], $pdf, 'sales-report-' . $date . '.pdf', $sections
        ));

        return response()->json(['ok' => true, 'sent_to' => $recipients]);
    }

    /** Thermal print view (browser print → the local receipt printer). */
    public function print(Request $request)
    {
        $this->guard();
        [$filters, $sections] = $this->context($request);

        $data = $this->document->data($filters, $sections, false);
        $data['mode']  = 'thermal';
        $data['paper'] = in_array($request->input('paper'), ['58mm', '80mm'], true) ? $request->input('paper') : '80mm';

        return view('tenant.reports.center.print', $data);
    }

    /** Queue the selected report to a network thermal printer (same bytes as Report Center). */
    public function sendToNetwork(Request $request, EscPosPayloadService $esc)
    {
        $this->guard();
        $request->validate(['printer_id' => ['required', 'exists:printers,id']]);

        $printer = Printer::findOrFail($request->integer('printer_id'));
        if ($printer->printer_type !== 'network' || ! $printer->ip_address) {
            return response()->json(['ok' => false, 'message' => 'Choose a network printer that has an IP address.'], 422);
        }

        [$filters, $sections, $date] = $this->context($request);
        $data = $this->document->data($filters, $sections, false);

        $report = [
            'sections'      => $sections,
            'bridge'        => $data['bridge'],
            'overview'      => $data['overview'],
            'orderTypes'    => $data['orderTypes'],
            'categories'    => $data['categories'],
            'items'         => $data['items'],
            'waiters'       => $data['waiters'],
            'cancellations' => $data['cancellations'],
            'cashBank'      => $data['cashBank'],
            'meta'          => [
                'business_name' => $this->businessName(),
                'label'         => 'Z / End of Day',
                'date_from'     => $date,
                'date_to'       => $date,
                'generated'     => app(TenantClock::class)->now()->format('d-M-Y H:i'),
                'paper'         => in_array($printer->paper_size, ['58mm', '80mm'], true) ? $printer->paper_size : '80mm',
            ],
        ];

        $job = app(PrintJobFactory::class)->create([
            'branch_id'          => auth('tenant')->user()?->default_branch_id,
            'terminal_id'        => null,
            'printer_id'         => $printer->id,
            'document_type'      => 'report',
            'print_status'       => 'queued',
            'reference_type'     => 'report',
            'reference_no'       => $date,
            'payload'            => ['sections' => $sections, 'date_from' => $date, 'date_to' => $date],
            'raw_payload'        => $esc->buildReport($report),
            'created_by_user_id' => auth('tenant')->id(),
        ], 'RPT');

        return response()->json(['ok' => true, 'job_id' => $job->id, 'printer' => $printer->name]);
    }

    /* ── Per-user saved settings ─────────────────────────────────────────────────────────────────── */

    public function saveSettings(Request $request)
    {
        $this->guard();
        $data = $request->validate([
            'sections'      => ['array'],
            'sections.*'    => ['string'],
            'category_ids'  => ['array'],
            'product_ids'   => ['array'],
            'waiter_ids'    => ['array'],
            'order_types'   => ['array'],
            'all_items'     => ['nullable', 'boolean'],
        ]);

        PosQuickReportSetting::updateOrCreate(
            ['user_id' => auth('tenant')->id()],
            ['payload' => [
                'sections'     => array_values(array_intersect((array) ($data['sections'] ?? []), self::SECTIONS)),
                'category_ids' => array_values(array_filter(array_map('intval', (array) ($data['category_ids'] ?? [])))),
                'product_ids'  => array_values(array_filter(array_map('intval', (array) ($data['product_ids'] ?? [])))),
                'waiter_ids'   => array_values((array) ($data['waiter_ids'] ?? [])),
                'order_types'  => array_values((array) ($data['order_types'] ?? [])),
                'all_items'    => $request->boolean('all_items'),
            ]]
        );

        return response()->json(['ok' => true]);
    }

    public function settings()
    {
        $this->guard();
        $row = PosQuickReportSetting::where('user_id', auth('tenant')->id())->first();

        return response()->json(['ok' => true, 'settings' => $row->payload ?? null]);
    }
}
