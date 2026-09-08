<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\PosQuickReportController;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Printer;
use App\Models\Tenant\RestaurantWaiter;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Printing\EscPosPayloadService;
use App\Services\Printing\PrintJobFactory;
use App\Services\Reports\SalesReportDocumentService;
use App\Services\Reports\SalesReportEngine;
use App\Services\Security\UserDataScope;
use App\Support\TenantClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EDGE-CASHIER-UI-5 — the cashier Quick Report on the Branch Server.
 *
 * Reuses the CURRENT Online report authority end to end — SalesReportEngine (business_date population,
 * NET SALES bridge, Sold/Ret/Net, deal components not counted as sales, deal own identity/category,
 * items-by-category hierarchy, charge breakup, GRAND TOTAL, QUICK-REPORT-OPEN-BILLS-1 include_open),
 * SalesReportDocumentService (the section data), the canonical thermal Blade (view / print here) and
 * EscPosPayloadService::buildReport (network bytes). NO Edge-specific arithmetic exists here: this class
 * is the same filter/selection front-end as Tenant\PosQuickReportController, bound to the appliance's
 * branch and to the operator's branch scope (QUICK-REPORT-BRANCH-SCOPE-1), with network printing on
 * the Edge print authority. EMAIL is truthfully "Internet required": the appliance never fakes "sent".
 */
class EdgeQuickReportController extends Controller
{
    public const SECTIONS = PosQuickReportController::SECTIONS;

    private const PERMISSION = 'tenant.pos.quick-report-send';

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly SalesReportEngine $engine,
        private readonly SalesReportDocumentService $document,
    ) {
    }

    private function guard(): void
    {
        abort_unless((bool) auth('tenant')->user()?->can(self::PERMISSION), 403, 'Permission denied.');
    }

    private function branch(): Branch
    {
        return Branch::on('tenant')->findOrFail((int) $this->context->requireCurrent()->branch_id);
    }

    /**
     * The same filters canonical context() builds: ONE business date, own branch scope, multi-value
     * category/item/waiter/order-type filters that narrow the WHOLE report, include_open = true.
     * On the appliance the scope is the bound branch — and only if the operator's assignment allows it.
     */
    private function context(Request $request): array
    {
        $branch = $this->branch();
        $date = $request->input('date');
        if (! $date || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            $date = app(TenantClock::class)->currentBusinessDate($branch);
        }
        $allowed = array_map('intval', app(UserDataScope::class)->branchIds(auth('tenant')->user()) ?: []);
        if ($allowed !== [] && ! in_array((int) $branch->id, $allowed, true)) {
            abort(403, 'This report is outside your branch scope.');
        }

        $filters = $this->engine->normalizeFilters([
            'date_from' => (string) $date,
            'date_to' => (string) $date,
            'branch_ids' => [(int) $branch->id],
            'category_ids' => (array) $request->input('category_ids', []),
            'product_ids' => $request->boolean('all_items') ? [] : (array) $request->input('product_ids', []),
            'waiter_ids' => (array) $request->input('waiter_ids', []),
            'order_types' => (array) $request->input('order_types', []),
            // QUICK-REPORT-OPEN-BILLS-1: the live picture mid-service — held + draft in every line-based section.
            'include_open' => true,
        ]);
        $sections = array_values(array_intersect((array) $request->input('sections', self::SECTIONS), self::SECTIONS)) ?: self::SECTIONS;

        return [$filters, $sections, (string) $date, $branch];
    }

    /** What the modal needs: sections, today's business date, filter books, network printers, and the truthful email state. */
    public function options(): JsonResponse
    {
        $this->guard();
        $branch = $this->branch();

        return response()->json([
            'sections' => self::SECTIONS,
            'date' => app(TenantClock::class)->currentBusinessDate($branch),
            'categories' => Category::on('tenant')->forBranch((int) $branch->id)->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'parent_id', 'name']),
            'waiters' => RestaurantWaiter::on('tenant')->where('status', 'active')->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))->orderBy('name')->get(['id', 'name']),
            'order_types' => User::ORDER_TYPES,
            'printers' => Printer::on('tenant')->where('is_active', true)->where('printer_type', 'network')->whereNotNull('ip_address')
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))->orderBy('name')->get(['id', 'name', 'paper_size']),
            'email' => ['available' => false, 'reason' => 'Internet required — the branch server cannot email reports offline.'],
        ]);
    }

    /** VIEW / PRINT HERE — the canonical thermal report page (same Blade as Report Center's thermal print). */
    public function view(Request $request)
    {
        $this->guard();
        [$filters, $sections, $date, $branch] = $this->context($request);

        $data = $this->document->data($filters, $sections, false);
        $data['mode'] = 'thermal';
        $data['paper'] = in_array($request->input('paper'), ['58mm', '80mm'], true) ? $request->input('paper') : '80mm';
        $data['business_name'] = $branch->name;

        return view('tenant.reports.center.print', $data);
    }

    /** NETWORK — the same report bytes as Report Center, queued on the Edge print authority. */
    public function network(Request $request, EscPosPayloadService $esc): JsonResponse
    {
        $this->guard();
        $request->validate(['printer_id' => ['required', 'integer']]);
        [$filters, $sections, $date, $branch] = $this->context($request);

        $printer = Printer::on('tenant')->where('id', $request->integer('printer_id'))->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))->first();
        if (! $printer || $printer->printer_type !== 'network' || ! $printer->ip_address) {
            return response()->json(['ok' => false, 'message' => 'Choose a network printer on this branch that has an IP address.'], 422);
        }

        $data = $this->document->data($filters, $sections, false);
        $report = [
            'sections' => $sections,
            'bridge' => $data['bridge'],
            'overview' => $data['overview'],
            'orderTypes' => $data['orderTypes'],
            'categories' => $data['categories'],
            'items' => $data['items'],
            'waiters' => $data['waiters'],
            'cancellations' => $data['cancellations'],
            'cashBank' => $data['cashBank'],
            'meta' => [
                'business_name' => $branch->name,
                'label' => 'Z / End of Day',
                'date_from' => $date,
                'date_to' => $date,
                'generated' => app(TenantClock::class)->now()->format('d-M-Y H:i'),
                'paper' => in_array($printer->paper_size, ['58mm', '80mm'], true) ? $printer->paper_size : '80mm',
            ],
        ];

        $terminalId = (int) $request->session()->get(EdgeLocalPosController::TERMINAL_SESSION_KEY, 0);
        $job = app(PrintJobFactory::class)->create([
            'branch_id' => (int) $branch->id,
            'terminal_id' => $terminalId > 0 ? (string) $terminalId : null,
            'printer_id' => $printer->id,
            'document_type' => 'report',
            'print_status' => 'queued',
            'reference_type' => 'report',
            'reference_no' => $date,
            'payload' => ['sections' => $sections, 'date_from' => $date, 'date_to' => $date],
            'raw_payload' => $esc->buildReport($report),
            'created_by_user_id' => auth('tenant')->id(),
        ], 'RPT');

        return response()->json(['ok' => true, 'job_id' => $job->id, 'printer' => $printer->name]);
    }

    /** EMAIL — ONLINE_REQUIRED on the appliance. Never a fake "sent". */
    public function email(): JsonResponse
    {
        $this->guard();

        return response()->json([
            'ok' => false,
            'internet_required' => true,
            'message' => 'Internet required — the branch server cannot email reports offline. Use the Cloud POS Quick Report to email once online.',
        ], 422);
    }
}
