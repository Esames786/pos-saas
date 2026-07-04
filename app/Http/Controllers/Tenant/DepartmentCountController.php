<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Department;
use App\Models\Tenant\DepartmentCountSession;
use App\Services\Departments\DepartmentCountService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * DEPT-4 — department count/reconciliation. Custody-only: approval adjusts the
 * department sub-ledger; official branch stock/GL are never touched.
 */
class DepartmentCountController extends Controller
{
    public function __construct(private readonly DepartmentCountService $service) {}

    public function index(Request $request)
    {
        $query = DepartmentCountSession::query()
            ->with(['branch', 'department', 'lines'])
            ->orderByDesc('id');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->input('department_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return view('tenant.department-counts.index', [
            'sessions'    => $query->paginate(20)->withQueryString(),
            'branches'    => Branch::where('status', 'active')->orderBy('name')->get(),
            'departments' => Department::with('branch')->orderBy('branch_id')->orderBy('sort_order')->get(),
        ]);
    }

    public function create()
    {
        return view('tenant.department-counts.create', [
            'branches'    => Branch::where('status', 'active')->orderBy('name')->get(),
            'departments' => Department::where('status', 'active')->orderBy('branch_id')->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'branch_id', 'name', 'code']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'     => ['required', 'exists:branches,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'count_date'    => ['required', 'date'],
            'notes'         => ['nullable', 'string'],
        ]);

        try {
            $session = $this->service->createDraft(
                (int) $data['branch_id'],
                (int) $data['department_id'],
                $data['count_date'],
                $data['notes'] ?? null,
                auth()->id()
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['count' => $e->getMessage()]);
        }

        return redirect('/department-counts/' . $session->id . '/edit')
            ->with('status', 'Draft count created — expected quantities loaded from department custody stock.');
    }

    public function show(DepartmentCountSession $session)
    {
        $session->load(['branch', 'department', 'lines.product.unit', 'lines.variant',
            'submittedBy', 'approvedBy', 'rejectedBy', 'cancelledBy', 'createdBy', 'adjustments']);

        return view('tenant.department-counts.show', compact('session'));
    }

    public function edit(DepartmentCountSession $session)
    {
        if (! $session->isDraft()) {
            return redirect('/department-counts/' . $session->id)
                ->withErrors(['count' => 'Only draft counts can be edited.']);
        }

        $session->load(['branch', 'department', 'lines.product.unit', 'lines.variant']);

        return view('tenant.department-counts.edit', [
            'session'     => $session,
            'reasonCodes' => DepartmentCountSession::REASON_CODES,
        ]);
    }

    public function update(Request $request, DepartmentCountSession $session)
    {
        $request->validate([
            'lines'                 => ['required', 'array'],
            'lines.*.counted_qty'   => ['required', 'numeric', 'min:0'],
            'lines.*.reason_code'   => ['nullable', 'string', 'in:' . implode(',', DepartmentCountSession::REASON_CODES)],
            'lines.*.notes'         => ['nullable', 'string', 'max:500'],
            'notes'                 => ['nullable', 'string'],
        ]);

        try {
            $this->service->updateCounts($session, $request->input('lines', []));
            $session->update(['notes' => $request->input('notes')]);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['count' => $e->getMessage()]);
        }

        return redirect('/department-counts/' . $session->id . '/edit')
            ->with('status', 'Counts saved. Submit when the physical count is complete.');
    }

    public function submit(DepartmentCountSession $session)
    {
        try {
            $this->service->submit($session, auth()->id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['count' => $e->getMessage()]);
        }

        return redirect('/department-counts/' . $session->id)
            ->with('status', 'Count submitted for approval.');
    }

    public function approve(DepartmentCountSession $session)
    {
        try {
            $this->service->approve($session, auth()->id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['count' => $e->getMessage()]);
        }

        return redirect('/department-counts/' . $session->id)
            ->with('status', 'Count approved — department custody stock now matches the counted quantities. Official branch stock is unchanged.');
    }

    public function reject(Request $request, DepartmentCountSession $session)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:500']]);

        try {
            $this->service->reject($session, $data['rejection_reason'], auth()->id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['count' => $e->getMessage()]);
        }

        return redirect('/department-counts/' . $session->id)
            ->with('status', 'Count rejected.');
    }

    public function cancel(DepartmentCountSession $session)
    {
        try {
            $this->service->cancel($session, auth()->id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['count' => $e->getMessage()]);
        }

        return back()->with('status', 'Count cancelled.');
    }
}
