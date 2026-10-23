<?php

namespace App\Services\Manufacturing;

use App\Http\Controllers\Concerns\GeneratesSequentialCode;
use App\Models\Tenant\ManufacturingBom;
use App\Models\Tenant\ManufacturingBomLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * W3/W4 S3b — the one place a bill of materials is written, shared by the BOM screen and (S3c) the product workspace.
 * Moved here from BomController without changing what it saves (RecipeBomSaveCharacterizationMySqlTest), plus one
 * transaction around the header, its lines and the switch-off of the product's older BOM, which the controller never had.
 * One difference: a line posted without a unit box is saved with no unit, where the controller hit an undefined key (500).
 */
final class BomWriter
{
    use GeneratesSequentialCode;

    public function nextNo(): string
    {
        return $this->nextSequentialCode(ManufacturingBom::class, 'bom_no', 'BOM-', 6);
    }

    /**
     * Check a BOM as the screen posts it. A new BOM without a typed number gets the next one. The header is checked
     * first, then the lines, then the two refusals (the product as its own part, a part twice) — the screen's order.
     *
     * @return array{0: array, 1: array}  [the header fields to save, the lines]
     * @throws ValidationException
     */
    public function validate(array $input, ?ManufacturingBom $bom = null): array
    {
        if (! $bom && trim((string) ($input['bom_no'] ?? '')) === '') {
            $input['bom_no'] = $this->nextNo();
        }

        $header = Validator::make($input, [
            'bom_no'              => ['required', 'string', 'max:50',
                                      Rule::unique('manufacturing_boms', 'bom_no')->ignore($bom?->id)],
            'finished_product_id' => ['required', 'integer', 'exists:products,id'],
            'name'                => ['nullable', 'string', 'max:255'],
            'version'             => ['required', 'string', 'max:50'],
            'output_quantity'     => ['required', 'numeric', 'min:0.0001'],
            'status'              => ['required', Rule::in(ManufacturingBom::STATUSES)],
            'effective_from'      => ['nullable', 'date'],
            'notes'               => ['nullable', 'string', 'max:3000'],
        ])->validate();

        Validator::make($input, [
            'lines'                          => ['required', 'array', 'min:1'],
            'lines.*.component_product_id'   => ['required', 'integer', 'exists:products,id'],
            'lines.*.unit_id'                => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.quantity'               => ['required', 'numeric', 'min:0.0001'],
            'lines.*.wastage_percent'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.sort_order'             => ['nullable', 'integer'],
            'lines.*.notes'                  => ['nullable', 'string', 'max:500'],
        ])->validate();

        $componentIds = collect($input['lines'])->pluck('component_product_id');

        // No component can equal the finished product
        if ($componentIds->contains((string) $header['finished_product_id'])) {
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

        // The posted keys stay: a line without a sort order is placed by its key, as the screen always did.
        return [$header, $input['lines']];
    }

    /**
     * Create ($bom null) or rewrite a BOM: its header, every line (replaced), and — when it is active — the product's
     * other active BOMs switched off. One transaction.
     */
    public function save(?ManufacturingBom $bom, array $header, array $lines, ?int $userId = null): ManufacturingBom
    {
        return DB::connection('tenant')->transaction(function () use ($bom, $header, $lines, $userId) {
            if ($bom) {
                $bom->update($header);
            } else {
                $bom = ManufacturingBom::create(array_merge($header, ['created_by_user_id' => $userId]));
            }
            $bom->lines()->delete();
            foreach ($this->lines($bom, $lines) as $line) {
                ManufacturingBomLine::create($line);
            }
            if ($header['status'] === 'active') {
                ManufacturingBom::where('finished_product_id', $bom->finished_product_id)
                    ->where('id', '!=', $bom->id)
                    ->where('status', 'active')
                    ->update(['status' => 'inactive']);
            }

            return $bom;
        });
    }

    /**
     * W3/W4 S3: the saved BOM already says exactly this — nothing to write. The product workspace posts its BOM section
     * on every save; an unchanged one is left alone, so its lines keep their ids (material requisitions point at them).
     */
    public function unchanged(ManufacturingBom $bom, array $header, array $lines): bool
    {
        $norm = fn ($v) => is_numeric($v) ? (float) $v : ($v === '' ? null : $v);
        foreach ($header as $k => $v) {
            $saved = $bom->{$k};
            $saved = $saved instanceof \DateTimeInterface ? $saved->format('Y-m-d') : $saved;
            if ($norm($saved) != $norm($v)) {
                return false;
            }
        }
        $keys = ['component_product_id', 'unit_id', 'quantity', 'wastage_percent', 'sort_order', 'notes'];
        $pick = fn ($l) => array_map($norm, collect($keys)->mapWithKeys(fn ($k) => [$k => data_get($l, $k)])->all());
        $saved = $bom->lines()->orderBy('sort_order')->orderBy('id')->get()->map($pick)->values()->all();
        $posted = collect($this->lines($bom, $lines))->sortBy('sort_order')->map($pick)->values()->all();

        return $saved == $posted;
    }

    private function lines(ManufacturingBom $bom, array $lines): array
    {
        $out = [];
        foreach ($lines as $i => $line) {
            $out[] = [
                'manufacturing_bom_id'  => $bom->id,
                'component_product_id'  => $line['component_product_id'],
                'unit_id'               => ($line['unit_id'] ?? null) ?: null,
                'quantity'              => $line['quantity'],
                'wastage_percent'       => $line['wastage_percent'] ?? 0,
                'sort_order'            => $line['sort_order'] ?? $i,
                'notes'                 => $line['notes'] ?? null,
            ];
        }

        return $out;
    }
}
