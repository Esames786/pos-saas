<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ModifierGroup;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class ModifierGroupController extends Controller
{
    public function index(Request $request)
    {
        $query = ModifierGroup::with(['branch', 'modifiers'])
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where('name', 'like', "%{$search}%");
        }

        return view('tenant.modifier-groups.index', [
            'groups'   => $query->paginate(20)->withQueryString(),
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('tenant.modifier-groups.form', [
            'group'    => new ModifierGroup(['status' => 'active']),
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
            'products' => Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'sku']),
            'units'    => Unit::orderBy('name')->get(['id', 'name', 'code']),
            'title'    => 'Create Modifier Group',
        ]);
    }

    public function store(Request $request)
    {
        $group = ModifierGroup::create($this->validated($request));
        $this->syncModifiers($request, $group);

        return redirect(url('/modifier-groups/' . $group->id . '/edit'))->with('status', 'Modifier group created.');
    }

    public function edit(ModifierGroup $modifierGroup)
    {
        $modifierGroup->load(['modifiers.linkedProduct', 'branch']);

        return view('tenant.modifier-groups.form', [
            'group'    => $modifierGroup,
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
            'products' => Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'sku']),
            'units'    => Unit::orderBy('name')->get(['id', 'name', 'code']),
            'title'    => 'Edit Modifier Group',
        ]);
    }

    public function update(Request $request, ModifierGroup $modifierGroup)
    {
        $modifierGroup->update($this->validated($request));
        $this->syncModifiers($request, $modifierGroup);

        return redirect(url('/modifier-groups/' . $modifierGroup->id . '/edit'))->with('status', 'Modifier group updated.');
    }

    public function destroy(ModifierGroup $modifierGroup)
    {
        $modifierGroup->delete();

        return redirect(url('/modifier-groups'))->with('status', 'Modifier group deleted.');
    }

    public function syncProduct(Request $request, Product $product)
    {
        $data = $request->validate([
            'groups'                => ['array'],
            'groups.*.id'           => ['nullable', 'exists:modifier_groups,id'],
            'groups.*.enabled'      => ['nullable', 'boolean'],
            'groups.*.sort_order'   => ['nullable', 'integer', 'min:0'],
        ]);

        $sync = [];
        foreach ($data['groups'] ?? [] as $row) {
            if (empty($row['enabled']) || empty($row['id'])) {
                continue;
            }

            $sync[(int) $row['id']] = [
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }

        $product->modifierGroups()->sync($sync);

        return redirect(url('/products/' . $product->id))->with('status', 'Modifier groups updated.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'branch_id'   => ['nullable', 'exists:branches,id'],
            'name'        => ['required', 'string', 'max:190'],
            'min_select'  => ['nullable', 'integer', 'min:0'],
            'max_select'  => ['nullable', 'integer', 'min:1'],
            'is_required' => ['nullable', 'boolean'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'status'      => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $min = (int) ($data['min_select'] ?? 0);
        $max = isset($data['max_select']) && $data['max_select'] !== '' ? (int) $data['max_select'] : null;

        if ($max !== null && $max < $min) {
            throw ValidationException::withMessages([
                'max_select' => 'Max selections must be greater than or equal to min selections.',
            ]);
        }

        return [
            'branch_id'   => $data['branch_id'] ?: null,
            'name'        => $data['name'],
            'min_select'  => $min,
            'max_select'  => $max,
            'is_required' => $request->boolean('is_required'),
            'sort_order'  => (int) ($data['sort_order'] ?? 0),
            'status'      => $data['status'],
        ];
    }

    private function syncModifiers(Request $request, ModifierGroup $group): void
    {
        $data = $request->validate([
            'modifiers'                       => ['array'],
            'modifiers.*.id'                  => ['nullable', 'integer', 'exists:modifiers,id'],
            'modifiers.*.name'                => ['nullable', 'string', 'max:190'],
            'modifiers.*.price_delta'         => ['nullable', 'numeric', 'min:-9999999999.99', 'max:9999999999.99'],
            'modifiers.*.linked_product_id'   => ['nullable', 'exists:products,id'],
            // MODIFIER-INVENTORY-1 stock-consumption fields
            'modifiers.*.consume_stock'       => ['nullable', 'boolean'],
            'modifiers.*.linked_quantity'     => ['nullable', 'numeric', 'min:0.0001'],
            'modifiers.*.linked_unit_id'      => ['nullable', 'exists:units,id'],
            'modifiers.*.is_default'          => ['nullable', 'boolean'],
            'modifiers.*.sort_order'          => ['nullable', 'integer', 'min:0'],
            'modifiers.*.status'              => ['nullable', Rule::in(['active', 'inactive'])],
            'modifiers.*._delete'             => ['nullable', 'boolean'],
        ]);

        foreach ($data['modifiers'] ?? [] as $i => $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;

            if (!empty($row['_delete'])) {
                if ($id) {
                    $group->modifiers()->whereKey($id)->delete();
                }
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            // MODIFIER-INVENTORY-1: stock-consuming options need a valid, active,
            // stock-tracked linked product + a positive quantity.
            $consumeStock = !empty($row['consume_stock']);
            $linkedProductId = $row['linked_product_id'] ?: null;
            $linkedQuantity = (isset($row['linked_quantity']) && $row['linked_quantity'] !== '')
                ? (float) $row['linked_quantity'] : null;

            if ($consumeStock) {
                if (! $linkedProductId) {
                    throw ValidationException::withMessages([
                        "modifiers.{$i}.linked_product_id" => "\"{$name}\": choose a Linked Product when Consume Stock is on.",
                    ]);
                }
                if (! $linkedQuantity || $linkedQuantity <= 0) {
                    throw ValidationException::withMessages([
                        "modifiers.{$i}.linked_quantity" => "\"{$name}\": Linked Quantity is required when Consume Stock is on.",
                    ]);
                }
                $linked = Product::find($linkedProductId);
                if (! $linked || $linked->status !== 'active' || ! $linked->is_stock_tracked) {
                    throw ValidationException::withMessages([
                        "modifiers.{$i}.linked_product_id" => "\"{$name}\": linked product must be active and stock-tracked when Consume Stock is enabled.",
                    ]);
                }
            }

            $payload = [
                'name'              => $name,
                'price_delta'       => $row['price_delta'] ?? 0,
                'linked_product_id' => $linkedProductId,
                'consume_stock'     => $consumeStock,
                'linked_quantity'   => $consumeStock ? $linkedQuantity : null,
                'linked_unit_id'    => $consumeStock ? ($row['linked_unit_id'] ?: null) : null,
                'is_default'        => !empty($row['is_default']),
                'sort_order'        => (int) ($row['sort_order'] ?? 0),
                'status'            => $row['status'] ?? 'active',
            ];

            if ($id) {
                $modifier = $group->modifiers()->whereKey($id)->first();
                if ($modifier) {
                    $modifier->update($payload);
                }
                continue;
            }

            $group->modifiers()->create($payload);
        }
    }
}
