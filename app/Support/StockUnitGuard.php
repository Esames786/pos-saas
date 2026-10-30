<?php

namespace App\Support;

use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * UB-10 (unified-business roadmap, W2) — an item's stock unit is fixed once its stock or purchases have run in it.
 *
 * Purchase lines (GRN, PO) and stock movements keep NO unit of their own: they read the product's. So changing a unit
 * — "Each" to "Kilogram", or giving a unit to an item that had none — rewrites how every old entry reads (1,273 Each
 * becomes 1,273 kg). The owner's rule is that old entries never change, so the product screens refuse it and the
 * bulk import keeps the old unit. An item with no history can still change its unit freely. Sale lines keep their own
 * unit snapshot and are not part of this.
 */
final class StockUnitGuard
{
    /** Tables whose rows read the product's unit for their quantity. */
    private const READERS = ['stock_ledgers', 'goods_receipt_lines', 'purchase_order_lines'];

    public static function hasHistory(int $productId): bool
    {
        $schema = Schema::connection('tenant');
        foreach (self::READERS as $table) {
            if ($schema->hasTable($table) && DB::connection('tenant')->table($table)->where('product_id', $productId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** The unit as a form posted it: '' and null both mean "none". */
    public static function normalise(mixed $unitId): ?int
    {
        return $unitId === null || $unitId === '' ? null : (int) $unitId;
    }

    public static function changes(Product $product, mixed $newUnitId): bool
    {
        return self::normalise($product->unit_id) !== self::normalise($newUnitId);
    }

    /** The product screens, before saving: the saved product against the unit the form posted. */
    public static function refuseChange(Product $product, mixed $newUnitId): void
    {
        self::refuse((int) $product->id, self::normalise($product->unit_id), self::normalise($newUnitId));
    }

    /** Every write path (the model's updating event): from → to on one product. */
    public static function refuse(int $productId, ?int $fromUnitId, ?int $toUnitId): void
    {
        if ($fromUnitId === $toUnitId || ! $productId || ! self::hasHistory($productId)) {
            return;
        }
        $from = self::name($fromUnitId);
        $to = self::name($toUnitId);
        throw ValidationException::withMessages([
            'unit_id' => "This item's stock and purchases already ran in {$from}. Changing it to {$to} would make every old "
                . "entry read in {$to}, so its unit stays {$from}. (اس چیز کی پرانی انٹریاں {$from} میں ہیں — یونٹ نہیں بدلے گا)",
        ]);
    }

    private static function name(?int $unitId): string
    {
        return $unitId ? (string) (Unit::query()->whereKey($unitId)->value('name') ?: 'its unit') : 'no unit';
    }
}
