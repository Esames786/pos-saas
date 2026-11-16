<?php

namespace App\Services\Kitchen;

use App\Models\Tenant\Recipe;
use App\Models\Tenant\RecipeIngredient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * W3/W4 S3b — the one place a kitchen recipe is written, shared by the Recipes screen and (S3c) the product workspace.
 * Moved here from RecipeController without changing what it saves (RecipeBomSaveCharacterizationMySqlTest), plus one
 * transaction around the header and its lines, which the controller never had.
 */
final class RecipeWriter
{
    /** The rules the Recipes screen has always used. */
    public static function rules(): array
    {
        return [
            'product_id'              => ['required', 'exists:products,id'],
            'name'                    => ['required', 'string', 'max:190'],
            'yield_quantity'          => ['required', 'numeric', 'min:0.0001'],
            'yield_unit_id'           => ['nullable', 'exists:units,id'],
            'is_active'               => ['boolean'],
            'notes'                   => ['nullable', 'string'],
            'ingredients'             => ['required', 'array', 'min:1'],
            'ingredients.*.product_id'         => ['required', 'exists:products,id'],
            'ingredients.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'ingredients.*.quantity'           => ['required', 'numeric', 'min:0.0001'],
            'ingredients.*.unit_id'            => ['nullable', 'exists:units,id'],
            'ingredients.*.cost_override'      => ['nullable', 'numeric', 'min:0'],
            'ingredients.*.line_section'       => ['nullable', Rule::in(array_keys(RecipeIngredient::SECTIONS))],
            'ingredients.*.applicable_order_types'   => ['nullable', 'array'],
            'ingredients.*.applicable_order_types.*' => [Rule::in(array_keys(RecipeIngredient::ORDER_TYPES))],
            'ingredients.*.sort_order'         => ['nullable', 'integer', 'min:0'],
            // KITCHEN-RECIPE-COST-1 report header
            'doc_no'                  => ['nullable', 'string', 'max:100'],
            'recipe_no'               => ['nullable', 'string', 'max:100'],
            'revision_no'             => ['nullable', 'integer', 'min:1'],
            'review_date'             => ['nullable', 'date'],
            'overhead_percent'        => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ];
    }

    /**
     * @param  array  $attributes  names for the messages (the product form calls a line's product "ingredient")
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validate(array $input, array $attributes = []): array
    {
        return Validator::make($input, self::rules(), [], $attributes)->validate();
    }

    /**
     * Create ($recipe null) or rewrite a recipe: its header, every line (replaced), and — when it is active — the same
     * product's other recipes switched off. One transaction.
     */
    public function save(?Recipe $recipe, array $data, bool $active): Recipe
    {
        return DB::connection('tenant')->transaction(function () use ($recipe, $data, $active) {
            $header = $this->header($data, $active);
            if ($recipe) {
                $recipe->update($header);
                $recipe->ingredients()->delete();
            } else {
                $recipe = Recipe::create($header);
            }
            foreach ($this->lines($data) as $line) {
                $recipe->ingredients()->create($line);
            }
            $this->enforceSingleActive($recipe);

            return $recipe;
        });
    }

    /**
     * W3/W4 S3: the saved recipe already says exactly this — nothing to write. The product workspace posts its recipe
     * section on every save; an unchanged one is left alone, so its lines keep their ids.
     */
    public function unchanged(Recipe $recipe, array $data, bool $active): bool
    {
        $norm = fn ($v) => is_numeric($v) ? (float) $v : ($v === '' ? null : $v);
        $header = array_map($norm, $this->header($data, $active));
        foreach ($header as $k => $v) {
            $saved = $recipe->{$k};
            $saved = $saved instanceof \DateTimeInterface ? $saved->format('Y-m-d') : $saved;
            if ($norm(is_bool($saved) ? (int) $saved : $saved) != (is_bool($v) ? (int) $v : $v)) {
                return false;
            }
        }
        $keys = ['product_id', 'product_variant_id', 'quantity', 'unit_id', 'cost_override', 'line_section', 'applicable_order_types', 'sort_order'];
        $saved = $recipe->ingredients()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($l) => array_map($norm, collect($keys)->mapWithKeys(fn ($k) => [$k => $l->{$k}])->all()))->values()->all();
        $posted = collect($this->lines($data))->sortBy('sort_order')
            ->map(fn ($l) => array_map($norm, collect($keys)->mapWithKeys(fn ($k) => [$k => $l[$k] ?? null])->all()))->values()->all();

        return $saved == $posted;
    }

    /** Submitted order types: null / empty / "all" → null (every order type); else the de-duplicated specific ones. */
    public function normalizeOrderTypes(?array $types): ?array
    {
        if (empty($types) || in_array(RecipeIngredient::ORDER_TYPE_ALL, $types, true)) {
            return null;
        }

        $clean = array_values(array_unique(array_intersect($types, RecipeIngredient::SPECIFIC_ORDER_TYPES)));

        return $clean === [] ? null : $clean;
    }

    private function header(array $data, bool $active): array
    {
        return [
            'product_id'       => $data['product_id'],
            'name'             => $data['name'],
            'yield_quantity'   => $data['yield_quantity'],
            'yield_unit_id'    => $data['yield_unit_id'] ?? null,
            'is_active'        => $active,
            'notes'            => $data['notes'] ?? null,
            'doc_no'           => $data['doc_no'] ?? null,
            'recipe_no'        => $data['recipe_no'] ?? null,
            'revision_no'      => $data['revision_no'] ?? 1,
            'review_date'      => $data['review_date'] ?? null,
            'overhead_percent' => $data['overhead_percent'] ?? 0,
        ];
    }

    private function lines(array $data): array
    {
        $out = [];
        foreach ($data['ingredients'] as $i => $ing) {
            $out[] = [
                'product_id'             => $ing['product_id'],
                'product_variant_id'     => $ing['product_variant_id'] ?? null,
                'quantity'               => $ing['quantity'],
                'unit_id'                => $ing['unit_id'] ?? null,
                'cost_override'          => isset($ing['cost_override']) && $ing['cost_override'] !== '' ? $ing['cost_override'] : null,
                'line_section'           => $ing['line_section'] ?? 'food_cost',
                'applicable_order_types' => $this->normalizeOrderTypes($ing['applicable_order_types'] ?? null),
                'sort_order'             => $ing['sort_order'] ?? $i,
            ];
        }

        return $out;
    }

    /** Only one active recipe per finished product: switch the others off. */
    private function enforceSingleActive(Recipe $recipe): void
    {
        if (! $recipe->is_active) {
            return;
        }

        Recipe::where('product_id', $recipe->product_id)
            ->where('id', '!=', $recipe->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }
}
