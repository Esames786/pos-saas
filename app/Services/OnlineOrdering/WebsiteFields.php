<?php

namespace App\Services\OnlineOrdering;

use App\Models\Tenant\Category;
use App\Models\Tenant\Combo;
use App\Models\Tenant\ModifierGroup;
use App\Models\Tenant\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ONLINE-ORDERING-1 — product aur deal form ka "Website" hissa: aik jagah validate + save.
 *
 * Sirf tab chalta hai jab form par hissa tha (`_website` aaya) — warna (module band, ya purana form)
 * kuch nahi chhuta. Sirf website_* khaane + show_on_website; POS ka naam/qeemat/tasveer kabhi nahi.
 */
class WebsiteFields
{
    public function __construct(private readonly WebImageService $images) {}

    /**
     * Pehle validate (POS cheez save hone se PEHLE — adhoora save na ho), phir `apply()` likhta hai.
     *
     * @return array<string, mixed>|null null = form par Website hissa tha hi nahi
     */
    public function validate(Request $request): ?array
    {
        if (! $request->has('_website')) {
            return null;
        }

        return $request->validate([
            'show_on_website' => ['nullable', 'boolean'],
            'website_card_name' => ['nullable', 'string', 'max:190'],
            'website_size_label' => ['nullable', 'string', 'max:80'],
            'website_category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'website_tag' => ['nullable', Rule::in(array_keys(Product::WEBSITE_TAGS))],
            'website_popular' => ['nullable', 'boolean'],
            'website_sort_order' => ['nullable', 'integer', 'min:0'],
            'website_description' => ['nullable', 'string', 'max:1000'],
            'website_image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:4096'],
            'remove_website_image' => ['nullable', 'boolean'],
            'website_addon_groups' => ['nullable', 'array'],
            'website_addon_groups.*' => ['integer'],
        ]);
    }

    public function apply(Request $request, Product|Combo $model): void
    {
        $data = $this->validate($request);
        if ($data === null) {
            return;
        }

        $model->fill([
            'show_on_website' => $request->boolean('show_on_website'),
            'website_card_name' => $this->blank($data['website_card_name'] ?? null),
            'website_size_label' => $this->blank($data['website_size_label'] ?? null),
            'website_category_id' => $data['website_category_id'] ?? null,
            'website_tag' => $data['website_tag'] ?? null,
            'website_popular' => $request->boolean('website_popular'),
            'website_sort_order' => $data['website_sort_order'] ?? null,
            'website_description' => $this->blank($data['website_description'] ?? null),
        ]);

        if ($request->boolean('remove_website_image') && $model->website_image_path) {
            $this->images->delete($model->website_image_path);
            $model->website_image_path = null;
        }
        if ($request->hasFile('website_image')) {
            $old = $model->website_image_path;
            $model->website_image_path = $this->images->store(
                $request->file('website_image')->getRealPath(),
                (string) (app()->bound('tenant') ? app('tenant')->tenant_code : 'tenant'),
                $model->website_card_name ?: $model->name,
            );
            $this->images->delete($old);
        }

        $model->save();

        // Deal ke add-ons: sirf "Website only" / "POS + Website" groups chune ja sakte hain.
        if ($model instanceof Combo && $request->has('_website_addons')) {
            $allowed = ModifierGroup::whereIn('channel', ['website', 'both'])
                ->whereIn('id', $data['website_addon_groups'] ?? [])->pluck('id')->all();
            $model->modifierGroups()->sync(collect($allowed)->mapWithKeys(fn ($id, $i) => [$id => ['sort_order' => $i]])->all());
        }

        // Product (malik, 16 Oct: "human friendly" — pehle ye sirf product ke doosre safhe par tha): yahan sirf
        // "Website only" groups badalte hain; POS / "POS + Website" groups product ke modifier panel se — wo yahan
        // nahi chhute.
        if ($model instanceof Product && $request->has('_website_addons')) {
            $websiteIds = ModifierGroup::where('channel', 'website')->pluck('id')->all();
            $keep = $model->modifierGroups()->get()->reject(fn ($g) => in_array($g->id, $websiteIds, true))
                ->mapWithKeys(fn ($g) => [$g->id => ['sort_order' => (int) ($g->pivot->sort_order ?? 0)]])->all();
            $chosen = array_values(array_intersect($websiteIds, array_map('intval', $data['website_addon_groups'] ?? [])));
            $model->modifierGroups()->sync($keep + collect($chosen)->mapWithKeys(fn ($id, $i) => [$id => ['sort_order' => 100 + $i]])->all());
        }
    }

    /**
     * Group ka qaida aam alfaaz me — "Customer must choose 1", "Optional — up to 2".
     */
    public static function ruleText(ModifierGroup $g): string
    {
        $min = (int) $g->min_select;
        $max = $g->max_select !== null ? (int) $g->max_select : null;
        if ($min >= 1) {
            return $max !== null && $max > $min ? "Customer must choose {$min}–{$max}" : "Customer must choose {$min}";
        }

        return $max !== null ? "Optional — customer may add up to {$max}" : 'Optional — any number';
    }

    /** Form ke liye: POS categories aur deal par lag sakne wale add-on groups. */
    public static function formData(): array
    {
        return [
            'websiteCategories' => Category::orderBy('name')->get(['id', 'name', 'parent_id']),
            'websiteAddonGroups' => ModifierGroup::whereIn('channel', ['website', 'both'])->where('status', 'active')
                ->with(['modifiers' => fn ($q) => $q->where('status', 'active')->orderBy('sort_order')])
                ->orderBy('name')->get(['id', 'name', 'channel', 'min_select', 'max_select']),
            'websiteSizeLabels' => self::sizeLabels(),
        ];
    }

    /**
     * "Size on card" ki dropdown (malik, 15 Oct): jo sizes pehle se chal rahe hain (sab se zyada wale upar), phir
     * aam sizes — dropdown se chunne par har jagah aik hi likhai ("Half" vs "half" vs "Half Kg" nahi).
     *
     * @return list<string>
     */
    public static function sizeLabels(): array
    {
        $used = collect(\Illuminate\Support\Facades\DB::connection('tenant')->table('products')->whereNotNull('website_size_label')->pluck('website_size_label'))
            ->merge(\Illuminate\Support\Facades\DB::connection('tenant')->table('combos')->whereNotNull('website_size_label')->pluck('website_size_label'))
            ->map(fn ($l) => trim((string) $l))->filter()
            ->countBy()->sortDesc()->keys();
        $common = ['Regular', 'Small', 'Medium', 'Large', 'Half', 'Full', 'Single', 'Double', '1 Person', '2 Persons', '4 Persons', '6 Persons'];

        return $used->merge($common)->unique(fn ($l) => mb_strtolower($l))->values()->all();
    }

    private function blank(?string $v): ?string
    {
        $v = $v === null ? null : trim($v);

        return $v === '' ? null : $v;
    }
}
