<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Mail\SalesReportMail;
use App\Services\Reports\ReportScheduleService;
use App\Services\Reports\SalesReportEngine;
use App\Services\Reports\SalesReportExporter;
use App\Support\CsvStreamer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * SALES REPORT CENTER (Khatri onboarding, sections O–AC) — ONE responsive screen over the ONE shared
 * SalesReportEngine. Tabs: Overview / Categories / Items / Waiters / Order Types / Departments /
 * Details / Cash & Bank. Z-Report preset = today's Overview + Order Types + Categories + Waiters +
 * Payments + Cash & Bank in one action. Exports/prints/emails all render from the SAME engine output.
 */
class SalesReportCenterController extends Controller
{
    public const SECTIONS = ['overview', 'categories', 'items', 'waiters', 'order_types', 'order_type_combos', 'cancellations', 'detailed', 'cash_bank'];

    /**
     * Per-section permission (Khatri #7): a role sees/prints/exports ONLY its assigned sections.
     * These are synthetic (non-route) spatie keys — seeded by the tenant migration which also
     * back-grants them to every role that already had Report Center access.
     */
    public static function sectionPermission(string $section): string
    {
        return 'tenant.reports.center.sections.' . str_replace('_', '-', $section);
    }

    /** Sections the current user may see, in canonical order. */
    private function allowedSections(): array
    {
        $user = auth('tenant')->user();

        return array_values(array_filter(
            self::SECTIONS,
            fn ($s) => $user && $user->can(self::sectionPermission($s))
        ));
    }

    public function __construct(
        private readonly SalesReportEngine $engine,
        private readonly SalesReportExporter $exporter,
    ) {
    }

    private function filters(Request $request): array
    {
        // date presets (today/yesterday/this_week/this_month handled client-side into from/to).
        $filters = $this->engine->normalizeFilters($request->only([
            'date_from', 'date_to', 'branch_ids', 'branch_id', 'terminal_id', 'shift_id',
            'cashier_id', 'waiter_id', 'order_type', 'category_id', 'product_id', 'payment_method_id',
        ]));

        // USER-DATA-SCOPE-1: a terminal/order-type restricted operator's reports (screen, print,
        // export, email) can only ever describe his own terminal and order types.
        return app(\App\Services\Security\UserDataScope::class)->applyToReportFilters($filters, auth('tenant')->user());
    }

    public function index(Request $request)
    {
        $allowed = $this->allowedSections();
        $tab = $request->input('tab', 'overview');
        $preset = $request->input('preset');
        $filters = $this->filters($request);

        // Section permissions: an unassigned tab is refused (departments rides the order_types
        // grant; the Z preset needs its constituent sections).
        $tabSection = $tab === 'departments' ? 'order_types' : $tab;
        if ($tab !== 'z' && ! in_array($tabSection, $allowed, true) && in_array($tabSection, self::SECTIONS, true)) {
            $tab = $allowed[0] ?? 'overview';
        }

        $data = in_array('overview', $allowed, true) ? ['overview' => $this->engine->overview($filters)] : ['overview' => null];
        if ($preset === 'z') {
            $tab = 'z';
            $data += [
                'order_types' => in_array('order_types', $allowed, true) ? $this->engine->byOrderType($filters) : [],
                'categories' => in_array('categories', $allowed, true) ? $this->engine->byCategory($filters) : [],
                'waiters' => in_array('waiters', $allowed, true) ? $this->engine->byWaiter($filters) : [],
                'cash_bank' => in_array('cash_bank', $allowed, true) ? $this->engine->cashBank($filters) : null,
            ];
        } else {
            $data += match ($tab) {
                'categories' => ['categories' => $this->engine->byCategory($filters)],
                'items' => ['items' => $this->engine->byItem($filters, $request->input('sort', 'net'))],
                'waiters' => ['waiters' => $this->engine->byWaiter($filters)],
                'order_types' => ['order_types' => $this->engine->byOrderType($filters)],
                'order_type_combos' => ['order_type_combos' => $this->engine->orderTypeCombos($filters)],
                'cancellations' => ['cancellations' => $this->engine->cancellations($filters)],
                'departments' => ['departments' => $this->departments($filters)],
                'detailed' => ['detailed' => $this->engine->detailedQuery($filters)->paginate(50)->withQueryString()],
                'cash_bank' => ['cash_bank' => $this->engine->cashBank($filters)],
                default => [],
            };
        }

        return view('tenant.reports.center.index', [
            'tab' => $tab,
            'allowedSections' => $allowed,
            'filters' => $filters,
            'data' => $data,
            'branches' => app(\App\Services\Security\UserDataScope::class)
                ->branchesForPos(auth('tenant')->user()),
            'terminals' => app(\App\Services\Security\UserDataScope::class)
                ->terminalsForPos(auth('tenant')->user()),
            'waiters' => DB::connection('tenant')->table('restaurant_waiters')->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'categories' => DB::connection('tenant')->table('categories')->where('is_active', 1)->orderBy('name')->get(['id', 'name', 'parent_id']),
            'paymentMethods' => DB::connection('tenant')->table('payment_methods')->where('is_active', 1)->orderBy('name')->get(['id', 'name']),
            'orderTypes' => ($types = app(\App\Services\Security\UserDataScope::class)->orderTypes(auth('tenant')->user()))
                ? array_intersect_key(\App\Models\Tenant\User::ORDER_TYPES, array_flip($types))
                : \App\Models\Tenant\User::ORDER_TYPES,
            'schedules' => DB::connection('tenant')->table('report_schedules')->orderBy('id')->get(),
        ]);
    }

    /** Departments tab — delegates to the EXISTING department-sales authority (resolver + Unassigned). */
    private function departments(array $filters): array
    {
        return app(\App\Services\Reports\DepartmentReportService::class)->sales([
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'branch_id' => $filters['branch_ids'][0] ?? null,
            'order_type' => $filters['order_type'],
            'department_id' => null,
        ]);
    }

    /** Export the selected sections — ONE Excel-friendly CSV, full filtered set. */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $allowed = $this->allowedSections();
        $sections = array_values(array_intersect((array) $request->input('sections', $allowed), $allowed));
        $csvBySection = $this->exporter->sections($filters, $sections);

        return CsvStreamer::download(
            'sales-report-' . $filters['date_from'] . '_' . $filters['date_to'] . '.csv',
            CsvStreamer::financeHeader('Sales Report Center', ['Period' => $filters['date_from'] . ' → ' . $filters['date_to']]),
            function ($out) use ($csvBySection) {
                foreach ($csvBySection as $section => $csv) {
                    fputcsv($out, ['== ' . strtoupper(str_replace('_', ' ', $section)) . ' ==']);
                    // strip the per-section BOM; rows are already CSV text.
                    fwrite($out, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
                    fputcsv($out, []);
                }
            }
        );
    }

    /** Thermal (58/80mm Z-style) or A4 print view — same engine output, browser-print = PDF path. */
    public function print(Request $request)
    {
        $filters = $this->filters($request);
        $mode = $request->input('mode', 'a4') === 'thermal' ? 'thermal' : 'a4';
        $paper = in_array($request->input('paper'), ['58mm', '80mm'], true) ? $request->input('paper') : '80mm';

        // Checkbox selection (Khatri #2): print ONLY the requested sections, capped to the
        // sections this user is permitted. No selection = everything permitted ("Print All").
        $allowed = $this->allowedSections();
        $requested = (array) $request->input('sections', []);
        $sections = array_values(array_intersect($requested ?: $allowed, $allowed));

        $pick = fn (string $key, callable $loader) => in_array($key, $sections, true) ? $loader() : null;

        // Category and item nets are MERCHANDISE only — the delivery charge rides on the order,
        // not on any line — so those totals legitimately differ from NET SALES. Printed bare that
        // reads as three unrelated numbers. Load the overview ONCE and hand every section the
        // bridging figures so each total can show how it reaches NET SALES, whichever sections the
        // user ticked.
        $summary = $this->engine->overview($filters);

        return view('tenant.reports.center.print', [
            'mode' => $mode,
            'paper' => $paper,
            'filters' => $filters,
            'sections' => $sections,
            'bridge' => $summary,
            'overview' => in_array('overview', $sections, true) ? $summary : null,
            'orderTypes' => $pick('order_types', fn () => $this->engine->byOrderType($filters)),
            'categories' => $pick('categories', fn () => $this->engine->byCategory($filters)),
            'items' => $pick('items', fn () => $this->engine->byItem($filters)),
            'waiters' => $pick('waiters', fn () => $this->engine->byWaiter($filters)),
            'combos' => $pick('order_type_combos', fn () => $this->engine->orderTypeCombos($filters)),
            'cancellations' => $pick('cancellations', fn () => $this->engine->cancellations($filters)),
            'cashBank' => $pick('cash_bank', fn () => $this->engine->cashBank($filters)),
        ]);
    }

    /** Email Now — tenant default email; controlled error when unconfigured (spec Z). */
    public function emailNow(Request $request)
    {
        $recipient = app()->bound('tenant') ? app('tenant')?->owner_email : null;
        if (! $recipient) {
            return back()->withErrors(['email' => 'The tenant default email (owner email) is not configured — set it in tenant settings first.']);
        }
        $filters = $this->filters($request);
        $allowed = $this->allowedSections();
        $sections = array_values(array_intersect((array) $request->input('sections', $allowed), $allowed));
        $csv = $this->exporter->sections($filters, $sections);
        Mail::to($recipient)->send(new SalesReportMail(
            (string) app('tenant')->business_name,
            $filters['date_from'] . ' → ' . $filters['date_to'],
            $csv
        ));

        return back()->with('status', 'Report emailed to ' . $recipient . '.');
    }

    public function storeSchedule(Request $request, ReportScheduleService $schedules)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['string', 'in:' . implode(',', self::SECTIONS)],
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'weekday' => ['nullable', 'integer', 'min:1', 'max:7', 'required_if:frequency,weekly'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31', 'required_if:frequency,monthly'],
            'send_time' => ['required', 'date_format:H:i'],
        ]);
        if (! (app()->bound('tenant') ? app('tenant')?->owner_email : null)) {
            return back()->withErrors(['email' => 'Set the tenant default email (owner email) before scheduling reports.']);
        }
        DB::connection('tenant')->table('report_schedules')->insert([
            'name' => $data['name'],
            'sections' => json_encode(array_values($data['sections'])),
            'frequency' => $data['frequency'],
            'weekday' => $data['weekday'] ?? null,
            'day_of_month' => $data['day_of_month'] ?? null,
            'send_time' => $data['send_time'],
            'is_active' => true,
            'created_by_user_id' => auth('tenant')->id(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('status', 'Schedule created (' . $data['frequency'] . ' at ' . $data['send_time'] . ', ' . $schedules->timezone() . ').');
    }

    public function destroySchedule(int $schedule)
    {
        DB::connection('tenant')->table('report_schedules')->where('id', $schedule)->delete();

        return back()->with('status', 'Schedule removed.');
    }
}
