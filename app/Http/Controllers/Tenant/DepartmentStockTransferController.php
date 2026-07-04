<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Department;
use App\Models\Tenant\DepartmentStockBalance;
use App\Models\Tenant\DepartmentStockTransfer;
use App\Services\Departments\DepartmentInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * DEPT-2 — department custody stock: balances index + issue/return/transfer
 * documents. Custody only: official branch stock, GL, and posting are never
 * touched (that all stays in InventoryService / branch documents).
 */
class DepartmentStockTransferController extends Controller
{
    public function __construct(private readonly DepartmentInventoryService $service) {}

    /** /department-stock — custody balances. */
    public function index(Request $request)
    {
        $query = DepartmentStockBalance::query()
            ->with(['branch', 'department', 'product.unit', 'variant'])
            ->orderBy('branch_id')->orderBy('department_id');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->input('department_id'));
        }
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->whereHas('product', fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%"));
        }
        if ($request->boolean('nonzero', true)) {
            $query->where('quantity_on_hand', '!=', 0);
        }

        return view('tenant.department-stock.index', [
            'balances'    => $query->paginate(25)->withQueryString(),
            'branches'    => Branch::where('status', 'active')->orderBy('name')->get(),
            'departments' => Department::with('branch')->orderBy('branch_id')->orderBy('sort_order')->get(),
        ]);
    }

    public function transfersIndex(Request $request)
    {
        $query = DepartmentStockTransfer::query()
            ->with(['branch', 'fromDepartment', 'toDepartment'])
            ->withCount('lines')
            ->orderByDesc('id');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }
        if ($request->filled('transfer_type')) {
            $query->where('transfer_type', $request->input('transfer_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return view('tenant.department-stock.transfers.index', [
            'transfers' => $query->paginate(20)->withQueryString(),
            'branches'  => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return $this->form(null);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $transfer = DB::connection('tenant')->transaction(function () use ($request, $data) {
            $transfer = DepartmentStockTransfer::create($data + [
                'transfer_no' => 'DST-' . now()->format('YmdHis') . '-' . random_int(100, 999),
                'status'      => 'draft',
                'created_by'  => auth()->id(),
            ]);
            $this->syncLines($request, $transfer);

            return $transfer;
        });

        return redirect('/department-stock/transfers/' . $transfer->id)
            ->with('status', 'Draft saved. Review the lines, then Post to move custody stock.');
    }

    public function show(DepartmentStockTransfer $transfer)
    {
        $transfer->load(['branch', 'fromDepartment', 'toDepartment', 'lines.product.unit', 'lines.variant', 'postedBy', 'cancelledBy', 'createdBy']);

        // Live availability preview per line (custody guidance before posting).
        $availability = [];
        foreach ($transfer->lines as $line) {
            $availability[$line->id] = [
                'available_to_issue' => $this->service->availableToIssue($transfer->branch_id, $line->product_id, $line->product_variant_id, $line->inventory_batch_id),
                'source_on_hand'     => $transfer->from_department_id
                    ? $this->service->departmentOnHand($transfer->from_department_id, $line->product_id, $line->product_variant_id, $line->inventory_batch_id)
                    : null,
            ];
        }

        return view('tenant.department-stock.transfers.show', compact('transfer', 'availability'));
    }

    public function edit(DepartmentStockTransfer $transfer)
    {
        if ($transfer->status !== 'draft') {
            return redirect('/department-stock/transfers/' . $transfer->id)
                ->withErrors(['transfer' => 'Only draft documents can be edited.']);
        }

        return $this->form($transfer);
    }

    public function update(Request $request, DepartmentStockTransfer $transfer)
    {
        if ($transfer->status !== 'draft') {
            return redirect('/department-stock/transfers/' . $transfer->id)
                ->withErrors(['transfer' => 'Only draft documents can be edited.']);
        }

        $data = $this->validated($request);

        DB::connection('tenant')->transaction(function () use ($request, $transfer, $data) {
            $transfer->update($data);
            $transfer->lines()->delete();
            $this->syncLines($request, $transfer);
        });

        return redirect('/department-stock/transfers/' . $transfer->id)
            ->with('status', 'Draft updated.');
    }

    public function post(DepartmentStockTransfer $transfer)
    {
        try {
            $this->service->postTransferDocument($transfer, auth()->id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['transfer' => $e->getMessage()]);
        }

        return redirect('/department-stock/transfers/' . $transfer->id)
            ->with('status', 'Document posted — department custody stock updated. Official branch stock is unchanged.');
    }

    public function cancel(DepartmentStockTransfer $transfer)
    {
        if ($transfer->status !== 'draft') {
            return back()->withErrors(['transfer' => 'Only draft documents can be cancelled. Posted custody movements cannot be cancelled in this phase.']);
        }

        $transfer->update([
            'status'       => 'cancelled',
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
        ]);

        return back()->with('status', 'Draft cancelled.');
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function form(?DepartmentStockTransfer $transfer)
    {
        if ($transfer) {
            $transfer->load(['lines.product.unit', 'lines.variant']);
        }

        return view('tenant.department-stock.transfers.form', [
            'transfer'    => $transfer,
            'title'       => $transfer ? 'Edit Custody Document ' . $transfer->transfer_no : 'New Department Stock Document',
            'branches'    => Branch::where('status', 'active')->orderBy('name')->get(),
            'departments' => Department::where('status', 'active')->orderBy('branch_id')->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'branch_id', 'name', 'code']),
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'branch_id'            => ['required', 'exists:branches,id'],
            'transfer_date'        => ['required', 'date'],
            'transfer_type'        => ['required', Rule::in(DepartmentStockTransfer::TYPES)],
            'from_department_id'   => ['nullable', 'exists:departments,id'],
            'to_department_id'     => ['nullable', 'exists:departments,id'],
            'notes'                => ['nullable', 'string'],
            'lines'                => ['required', 'array', 'min:1'],
            'lines.*.product_id'   => ['required', 'exists:products,id'],
            'lines.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'lines.*.quantity'     => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost'    => ['nullable', 'numeric', 'min:0'],
            'lines.*.notes'        => ['nullable', 'string', 'max:500'],
        ]);

        $branchId = (int) $data['branch_id'];
        $type     = $data['transfer_type'];
        $fromId   = $data['from_department_id'] ?? null;
        $toId     = $data['to_department_id'] ?? null;

        $belongsToBranch = fn ($deptId) => $deptId
            && Department::where('id', $deptId)->where('branch_id', $branchId)->exists();

        $fail = function (string $message): never {
            throw \Illuminate\Validation\ValidationException::withMessages(['transfer' => $message]);
        };

        // Type-shape rules: issue = pool->dept, return = dept->pool, transfer = dept->dept.
        if ($type === 'issue') {
            if (! $toId || ! $belongsToBranch($toId)) {
                $fail('An Issue needs a To Department belonging to the selected branch.');
            }
            $fromId = null;
        } elseif ($type === 'return') {
            if (! $fromId || ! $belongsToBranch($fromId)) {
                $fail('A Return needs a From Department belonging to the selected branch.');
            }
            $toId = null;
        } else { // transfer
            if (! $fromId || ! $toId || ! $belongsToBranch($fromId) || ! $belongsToBranch($toId)) {
                $fail('A Transfer needs both From and To Departments belonging to the selected branch.');
            }
            if ((int) $fromId === (int) $toId) {
                $fail('From and To Departments must be different.');
            }
        }

        return [
            'branch_id'          => $branchId,
            'transfer_date'      => $data['transfer_date'],
            'transfer_type'      => $type,
            'from_department_id' => $fromId,
            'to_department_id'   => $toId,
            'notes'              => $data['notes'] ?? null,
        ];
    }

    private function syncLines(Request $request, DepartmentStockTransfer $transfer): void
    {
        foreach ($request->input('lines', []) as $line) {
            if (empty($line['product_id']) || (float) ($line['quantity'] ?? 0) <= 0) {
                continue;
            }

            $product = \App\Models\Tenant\Product::findOrFail((int) $line['product_id']);
            $variant = $this->service->resolveVariant(
                $product,
                ! empty($line['product_variant_id'])
                    ? \App\Models\Tenant\ProductVariant::where('product_id', $product->id)->find((int) $line['product_variant_id'])
                    : null
            );

            $transfer->lines()->create([
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'inventory_batch_id' => null,
                'quantity'           => (float) $line['quantity'],
                'unit_cost'          => (float) ($line['unit_cost'] ?? 0),
                'notes'              => $line['notes'] ?? null,
            ]);
        }

        if ($transfer->lines()->count() === 0) {
            throw new RuntimeException('At least one valid product line is required.');
        }
    }
}
