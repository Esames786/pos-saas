<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Department;
use App\Models\Tenant\Product;
use App\Models\Tenant\User;
use App\Services\Reports\DepartmentReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * DEPARTMENT-FOUNDATION-1 — department master + category/product mapping.
 * Reporting/mapping only: no stock movement, no GL, no posting.
 */
class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Department::query()
            ->with(['branch', 'categoryMaps.category'])
            ->withCount(['categoryMaps', 'includeOverrides', 'excludeOverrides'])
            ->orderBy('branch_id')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return view('tenant.departments.index', [
            'departments' => $query->paginate(15)->withQueryString(),
            'branches'    => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return $this->form(null);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        DB::connection('tenant')->transaction(function () use ($request, $data) {
            $department = Department::create($data);
            $this->syncMappings($request, $department);
        });

        return redirect('/departments')->with('status', 'Department created successfully.');
    }

    public function show(Department $department, DepartmentReportService $reportService)
    {
        $department->load(['branch', 'manager', 'categoryMaps.category', 'productOverrides.product']);

        return view('tenant.departments.show', [
            'department'   => $department,
            'preview'      => $reportService->setupPreview($department),
            // DEPT-2: custody stock summary card.
            'stockSummary' => $reportService->stockSummary($department),
        ]);
    }

    public function edit(Department $department)
    {
        return $this->form($department);
    }

    public function update(Request $request, Department $department)
    {
        $data = $this->validated($request, $department);

        DB::connection('tenant')->transaction(function () use ($request, $department, $data) {
            $department->update($data);
            $this->syncMappings($request, $department);
        });

        return redirect('/departments')->with('status', 'Department updated successfully.');
    }

    public function destroy(Department $department)
    {
        // Mappings are pure configuration (FK cascade removes them). Reports are
        // computed live, so a deleted department simply stops appearing — its
        // products fall back to "Unassigned". Nothing financial depends on it.
        $department->delete();

        return back()->with('status', 'Department deleted successfully.');
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function form(?Department $department)
    {
        if ($department) {
            $department->load(['categoryMaps', 'productOverrides.product']);
        }

        return view('tenant.departments.form', [
            'department' => $department,
            'title'      => $department ? 'Edit Department' : 'Create Department',
            'branches'   => Branch::where('status', 'active')->orderBy('name')->get(),
            'managers'   => User::orderBy('name')->get(['id', 'name']),
            'categories' => Category::with('children')->whereNull('parent_id')
                ->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    private function validated(Request $request, ?Department $department = null): array
    {
        $data = $request->validate([
            'branch_id'                 => ['required', 'exists:branches,id'],
            'code'                      => [
                'required', 'string', 'max:50',
                Rule::unique('departments', 'code')
                    ->where('branch_id', (int) $request->input('branch_id'))
                    ->ignore($department?->id),
            ],
            'name'                      => ['required', 'string', 'max:190'],
            'description'               => ['nullable', 'string'],
            'manager_user_id'           => ['nullable', 'exists:users,id'],
            'status'                    => ['required', Rule::in(['active', 'inactive'])],
            'allow_stock_issue'         => ['nullable', 'boolean'],
            'require_end_day_count'     => ['nullable', 'boolean'],
            'sort_order'                => ['nullable', 'integer', 'min:0'],
            'category_ids'              => ['nullable', 'array'],
            'category_ids.*'            => ['integer', 'exists:categories,id'],
            'category_include_children' => ['nullable', 'array'],
            'include_product_ids'       => ['nullable', 'array'],
            'include_product_ids.*'     => ['integer', 'exists:products,id'],
            'exclude_product_ids'       => ['nullable', 'array'],
            'exclude_product_ids.*'     => ['integer', 'exists:products,id'],
        ]);

        return [
            'branch_id'             => (int) $data['branch_id'],
            'code'                  => strtoupper(trim($data['code'])),
            'name'                  => $data['name'],
            'description'           => $data['description'] ?? null,
            'manager_user_id'       => $data['manager_user_id'] ?? null,
            'status'                => $data['status'],
            'allow_stock_issue'     => $request->boolean('allow_stock_issue'),
            'require_end_day_count' => $request->boolean('require_end_day_count'),
            'sort_order'            => (int) ($data['sort_order'] ?? 0),
        ];
    }

    private function syncMappings(Request $request, Department $department): void
    {
        // Category maps — replace with the submitted set (config-only rows).
        $categoryIds     = array_map('intval', $request->input('category_ids', []) ?: []);
        $includeChildren = array_map('intval', $request->input('category_include_children', []) ?: []);

        $department->categoryMaps()->whereNotIn('category_id', $categoryIds)->delete();
        foreach ($categoryIds as $categoryId) {
            $department->categoryMaps()->updateOrCreate(
                ['category_id' => $categoryId],
                ['include_children' => in_array($categoryId, $includeChildren, true)]
            );
        }

        // Product overrides — exclude wins over include if the same product is
        // submitted in both lists (defensive; UI prevents it).
        $includeIds = array_map('intval', $request->input('include_product_ids', []) ?: []);
        $excludeIds = array_map('intval', $request->input('exclude_product_ids', []) ?: []);
        $includeIds = array_diff($includeIds, $excludeIds);

        $keepIds = array_merge($includeIds, $excludeIds);
        $department->productOverrides()->whereNotIn('product_id', $keepIds ?: [0])->delete();

        foreach ($includeIds as $productId) {
            $department->productOverrides()->updateOrCreate(
                ['product_id' => $productId],
                ['mapping_type' => 'include']
            );
        }
        foreach ($excludeIds as $productId) {
            $department->productOverrides()->updateOrCreate(
                ['product_id' => $productId],
                ['mapping_type' => 'exclude']
            );
        }
    }
}
