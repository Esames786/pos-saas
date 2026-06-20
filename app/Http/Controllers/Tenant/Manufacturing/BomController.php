<?php

namespace App\Http\Controllers\Tenant\Manufacturing;

use App\Http\Controllers\Concerns\GeneratesSequentialCode;
use App\Http\Controllers\Controller;
use App\Models\Tenant\ManufacturingBom;
use App\Models\Tenant\ManufacturingBomLine;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BomController extends Controller
{
    use GeneratesSequentialCode;

    public function index(Request $request)
    {
        $query = ManufacturingBom::query()->with('finishedProduct');

        if ($request->filled('q')) {
            $s = trim($request->q);
            $query->where(function ($q) use ($s) {
                $q->where('bom_no', 'like', "%{$s}%")
                  ->orWhere('name', 'like', "%{$s}%")
                  ->orWhereHas('finishedProduct', fn ($p) =>
                      $p->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")
                  );
            });
        }

        if ($request->filled('status') && in_array($request->status, ManufacturingBom::STATUSES, true)) {
            $query->where('status', $request->status);
        }

        if ($request->filled('product_id')) {
            $query->where('finished_product_id', $request->product_id);
        }

        $boms     = $query->withCount('lines')->orderByDesc('id')->paginate(20)->withQueryString();
        $products = Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'sku']);

        return view('tenant.manufacturing.bom.index', [
            'boms'     => $boms,
            'products' => $products,
            'filters'  => $request->only(['q', 'status', 'product_id']),
            'statuses' => ManufacturingBom::STATUSES,
        ]);
    }

    public function create()
    {
        return view('tenant.manufacturing.bom.create', $this->formData() + [
            'bom'    => null,
            'title'  => 'Create Bill of Materials',
            'nextNo' => $this->nextBomNo(),
        ]);
    }

    public function store(Request $request)
    {
        if (empty(trim($request->input('bom_no', '')))) {
            $request->merge(['bom_no' => $this->nextBomNo()]);
        }

        $data = $this->validateBom($request);
        $this->validateLines($request, $data['finished_product_id']);

        $bom = ManufacturingBom::create(array_merge(
            $data,
            ['created_by_user_id' => auth('tenant')->id()]
        ));

        $this->syncLines($bom, $request->input('lines', []));

        if ($data['status'] === 'active') {
            $this->deactivateOtherBoms($bom);
        }

        return redirect(url('/manufacturing/bom/' . $bom->id))
            ->with('status', 'BOM created.');
    }

    public function show(ManufacturingBom $manufacturingBom)
    {
        $manufacturingBom->load(['finishedProduct', 'lines.componentProduct', 'lines.unit', 'createdBy']);

        return view('tenant.manufacturing.bom.show', [
            'bom' => $manufacturingBom,
        ]);
    }

    public function edit(ManufacturingBom $manufacturingBom)
    {
        $manufacturingBom->load(['lines.componentProduct', 'lines.unit']);

        return view('tenant.manufacturing.bom.edit', $this->formData() + [
            'bom'    => $manufacturingBom,
            'title'  => 'Edit BOM: ' . $manufacturingBom->bom_no,
            'nextNo' => null,
        ]);
    }

    public function update(Request $request, ManufacturingBom $manufacturingBom)
    {
        $data = $this->validateBom($request, $manufacturingBom);
        $this->validateLines($request, $data['finished_product_id'], $manufacturingBom->id);

        $manufacturingBom->update($data);
        $this->syncLines($manufacturingBom, $request->input('lines', []));

        if ($data['status'] === 'active') {
            $this->deactivateOtherBoms($manufacturingBom);
        }

        return redirect(url('/manufacturing/bom/' . $manufacturingBom->id))
            ->with('status', 'BOM updated.');
    }

    public function destroy(ManufacturingBom $manufacturingBom)
    {
        $manufacturingBom->update(['status' => 'archived']);

        return redirect(url('/manufacturing/bom'))
            ->with('status', 'BOM archived.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formData(): array
    {
        return [
            'products' => Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'sku']),
            'units'    => Unit::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'statuses' => ManufacturingBom::STATUSES,
        ];
    }

    private function validateBom(Request $request, ?ManufacturingBom $bom = null): array
    {
        return $request->validate([
            'bom_no'              => ['required', 'string', 'max:50',
                                      Rule::unique('manufacturing_boms', 'bom_no')->ignore($bom?->id)],
            'finished_product_id' => ['required', 'integer', 'exists:products,id'],
            'name'                => ['nullable', 'string', 'max:255'],
            'version'             => ['required', 'string', 'max:50'],
            'output_quantity'     => ['required', 'numeric', 'min:0.0001'],
            'status'              => ['required', Rule::in(ManufacturingBom::STATUSES)],
            'effective_from'      => ['nullable', 'date'],
            'notes'               => ['nullable', 'string', 'max:3000'],
        ]);
    }

    private function validateLines(Request $request, int $finishedProductId, ?int $bomId = null): void
    {
        $request->validate([
            'lines'                          => ['required', 'array', 'min:1'],
            'lines.*.component_product_id'   => ['required', 'integer', 'exists:products,id'],
            'lines.*.unit_id'                => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.quantity'               => ['required', 'numeric', 'min:0.0001'],
            'lines.*.wastage_percent'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.sort_order'             => ['nullable', 'integer'],
            'lines.*.notes'                  => ['nullable', 'string', 'max:500'],
        ]);

        $componentIds = collect($request->input('lines', []))->pluck('component_product_id');

        // No component can equal the finished product
        if ($componentIds->contains((string) $finishedProductId)) {
            throw ValidationException::withMessages([
                'lines' => 'A component cannot be the same product as the finished product.',
            ]);
        }

        // No duplicate components
        if ($componentIds->count() !== $componentIds->unique()->count()) {
            throw ValidationException::withMessages([
                'lines' => 'Duplicate component products are not allowed. Combine them into one line.',
            ]);
        }
    }

    private function syncLines(ManufacturingBom $bom, array $lines): void
    {
        $bom->lines()->delete();

        foreach ($lines as $i => $line) {
            ManufacturingBomLine::create([
                'manufacturing_bom_id'  => $bom->id,
                'component_product_id'  => $line['component_product_id'],
                'unit_id'               => $line['unit_id'] ?: null,
                'quantity'              => $line['quantity'],
                'wastage_percent'       => $line['wastage_percent'] ?? 0,
                'sort_order'            => $line['sort_order'] ?? $i,
                'notes'                 => $line['notes'] ?? null,
            ]);
        }
    }

    private function deactivateOtherBoms(ManufacturingBom $activeBom): void
    {
        ManufacturingBom::where('finished_product_id', $activeBom->finished_product_id)
            ->where('id', '!=', $activeBom->id)
            ->where('status', 'active')
            ->update(['status' => 'inactive']);
    }

    private function nextBomNo(): string
    {
        return $this->nextSequentialCode(ManufacturingBom::class, 'bom_no', 'BOM-', 6);
    }
}
