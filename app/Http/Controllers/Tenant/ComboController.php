<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Combo;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Support\DealSplit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        // ONLINE-ORDERING-1: website hissa pehle validate — deal adhoori save na ho.
        $website = app(\App\Services\OnlineOrdering\WebsiteFields::class);
        $website->validate($request);

        $combo = DB::connection('tenant')->transaction(function () use ($data) {
            $components = $data['components'] ?? [];
            unset($data['components']);

            $combo = Combo::create($data);
            $this->syncComponents($combo, $components);

            return $combo;
        });

        $website->apply($request, $combo);

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
        // ONLINE-ORDERING-1: website hissa pehle validate — deal adhoori save na ho.
        $website = app(\App\Services\OnlineOrdering\WebsiteFields::class);
        $website->validate($request);

        DB::connection('tenant')->transaction(function () use ($combo, $data) {
            $components = $data['components'] ?? [];
            unset($data['components']);

            $combo->update($data);
            $this->syncComponents($combo, $components);
        });

        $website->apply($request, $combo);

        return redirect(url('/combos'))->with('status', 'Combo updated successfully.');
    }

    public function destroy(Combo $combo)
    {
        $combo->delete();
        return back()->with('status', 'Combo deleted successfully.');
    }

    private function validateCombo(Request $request, ?Combo $combo = null): array
    {
        $rules = [
            'branch_id' => ['nullable', 'exists:branches,id'],
            // POS-COMBO-CATEGORY-1: optional POS grouping (Al-Faham / Midnight / …); null = "Deals" tab.
            'category_id' => ['nullable', 'exists:categories,id'],
            'code' => ['nullable', 'string', 'max:80', Rule::unique('combos', 'code')->ignore($combo?->id)],
            'name' => ['required', 'string', 'max:190'],
            'price' => ['required', 'numeric', 'min:0'],
            // ONLINE-ORDERING-1: website ki apni qeemat; khaali = deal ki qeemat. Khaana sirf
            // "Online Ordering" wale plan par aata hai — na aaye to key hi nahi, purani qeemat salamat.
            'website_price' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'in:active,inactive'],
            'description' => ['nullable', 'string'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.product_id' => ['required', 'exists:products,id'],
            'components.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'components.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'components.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];

        // DEAL-SPLIT-REPORT-1: "How should this deal show in reports?" — only while the Master switch is on AND the
        // columns exist. Off, the two fields are not even validated, so they never reach the saved data: a tenant with
        // the switch off (or code deployed a second before the migration) never names report_by_items / report_amount,
        // even when a crafted POST sends them.
        $split = DealSplit::active();
        if ($split) {
            $rules['report_by_items'] = ['nullable', 'boolean'];
            $rules['components.*.report_amount'] = ['nullable', 'numeric', 'min:0'];
        }

        $data = $request->validate($rules);

        if (! $split) {
            unset($data['report_by_items']);
            foreach (array_keys($data['components'] ?? []) as $i) {
                unset($data['components'][$i]['report_amount']);
            }

            return $data;
        }

        // On: the choice is always saved. "One rate" keeps no parts (the boxes are switched off on the form).
        $byItems = (bool) ($data['report_by_items'] ?? false);
        $data['report_by_items'] = $byItems;
        foreach ($data['components'] as $i => $component) {
            $amount = $component['report_amount'] ?? null;
            $data['components'][$i]['report_amount'] = $byItems && $amount !== null && $amount !== ''
                ? round((float) $amount, 2)
                : null;
        }

        if ($byItems) {
            $this->assertPartsAddUp($data['components'], (float) $data['price']);
        }

        return $data;
    }

    /**
     * DEAL-SPLIT-REPORT-1: "Each item's rate" — every item has a part, at least one part is above 0, and the parts of
     * ONE deal add up to the deal's price (to the paisa). Refused before anything is saved, with the gap in plain words.
     */
    private function assertPartsAddUp(array $components, float $price): void
    {
        $amounts = array_map(fn ($c) => $c['report_amount'], $components);

        if (in_array(null, $amounts, true)) {
            throw ValidationException::withMessages(['report_by_items' =>
                'Each item\'s rate: type a report rate for every item (type 0 for an item that should not show in reports).']);
        }

        if (max($amounts) <= 0) {
            throw ValidationException::withMessages(['report_by_items' =>
                'Each item\'s rate: at least one item needs a report rate above 0.']);
        }

        $parts = (int) round(array_sum($amounts) * 100);
        $deal = (int) round($price * 100);
        if (abs($parts - $deal) > 1) {
            $gap = abs($parts - $deal) / 100;
            throw ValidationException::withMessages(['report_by_items' => sprintf(
                'Parts add up to %s but the deal price is %s — %s %s. Make them equal, or choose "One rate".',
                self::rs($parts / 100), self::rs($deal / 100), self::rs($gap), $parts < $deal ? 'short' : 'too much'
            )]);
        }
    }

    /** 900 → "900", 1850.5 → "1,850.5" — the same way the form's live line writes it. */
    private static function rs(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2), '0'), '.');
    }

    private function syncComponents(Combo $combo, array $components): void
    {
        $combo->components()->delete();

        foreach ($components as $index => $component) {
            if (empty($component['product_id'])) {
                continue;
            }

            $row = [
                'product_id' => $component['product_id'],
                'product_variant_id' => $component['product_variant_id'] ?? null,
                'quantity' => $component['quantity'] ?? 1,
                'sort_order' => $component['sort_order'] ?? (($index + 1) * 10),
            ];
            // DEAL-SPLIT-REPORT-1: present only when the switch is on (validateCombo decides) — rows are recreated, so
            // the part is carried onto the new row here.
            if (array_key_exists('report_amount', $component)) {
                $row['report_amount'] = $component['report_amount'];
            }

            $combo->components()->create($row);
        }
    }

    private function formData(): array
    {
        return [
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'categories' => \App\Models\Tenant\Category::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'products' => Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'sku']),
            'variants' => ProductVariant::where('is_active', true)->orderBy('name')->get(['id', 'product_id', 'name', 'sku']),
            // DEAL-SPLIT-REPORT-1: the "How should this deal show in reports?" section and the Report rate boxes.
            'dealSplit' => DealSplit::active(),
        ];
    }
}
