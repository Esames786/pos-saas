<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Combo;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ComboController extends Controller
{
    public function index()
    {
        return view('tenant.combos.index', [
            'combos' => Combo::with(['branch', 'components.product'])->orderBy('sort_order')->orderBy('name')->paginate(20),
        ]);
    }

    public function create()
    {
        return view('tenant.combos.form', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validateCombo($request);

        DB::connection('tenant')->transaction(function () use ($data) {
            $components = $data['components'] ?? [];
            unset($data['components']);

            $combo = Combo::create($data);
            $this->syncComponents($combo, $components);
        });

        return redirect(url('/combos'))->with('status', 'Combo created successfully.');
    }

    public function edit(Combo $combo)
    {
        return view('tenant.combos.form', array_merge(
            ['combo' => $combo->load('components')],
            $this->formData()
        ));
    }

    public function update(Request $request, Combo $combo)
    {
        $data = $this->validateCombo($request, $combo);

        DB::connection('tenant')->transaction(function () use ($combo, $data) {
            $components = $data['components'] ?? [];
            unset($data['components']);

            $combo->update($data);
            $this->syncComponents($combo, $components);
        });

        return redirect(url('/combos'))->with('status', 'Combo updated successfully.');
    }

    public function destroy(Combo $combo)
    {
        $combo->delete();
        return back()->with('status', 'Combo deleted successfully.');
    }

    private function validateCombo(Request $request, ?Combo $combo = null): array
    {
        return $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'code' => ['nullable', 'string', 'max:80', Rule::unique('combos', 'code')->ignore($combo?->id)],
            'name' => ['required', 'string', 'max:190'],
            'price' => ['required', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'in:active,inactive'],
            'description' => ['nullable', 'string'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.product_id' => ['required', 'exists:products,id'],
            'components.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'components.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'components.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function syncComponents(Combo $combo, array $components): void
    {
        $combo->components()->delete();

        foreach ($components as $index => $component) {
            if (empty($component['product_id'])) {
                continue;
            }

            $combo->components()->create([
                'product_id' => $component['product_id'],
                'product_variant_id' => $component['product_variant_id'] ?? null,
                'quantity' => $component['quantity'] ?? 1,
                'sort_order' => $component['sort_order'] ?? (($index + 1) * 10),
            ]);
        }
    }

    private function formData(): array
    {
        return [
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'products' => Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'sku']),
            'variants' => ProductVariant::where('is_active', true)->orderBy('name')->get(['id', 'product_id', 'name', 'sku']),
        ];
    }
}
