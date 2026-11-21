<?php

namespace App\Services\Edge\Continuity;

use App\Models\Tenant\SalesOrder;
use App\Services\Edge\EdgeCanonicalJson;

/**
 * EDGE 0.9 CONTINUITY — the ONE fingerprint of an open Cloud order, computed identically on both ends:
 *
 *   appliance  fromMirror()  over the K1 live-state order (lines + total) at mirror time — stored on the mirror row and, when the
 *                            order is adopted, carried by the sale envelope (`adoption.cloud_fingerprint`);
 *   Cloud      fromOrder()   over the CURRENT sales_orders / sales_order_lines rows at merge time.
 *
 * Equal ⇒ the Cloud order is exactly what the cashier adopted (nothing was added, removed, repriced or re-quantified on the Cloud
 * after the mirror revision) ⇒ the ingestion may complete it. Different ⇒ the Cloud order moved after the mirror → the envelope is
 * refused ADOPTED_ORDER_CONFLICT ("needs attention"), never guessed.
 *
 * Canonical form: lines sorted by line_uuid, each [line_uuid, product_id, variant_id|0, qty (3 dp), unit_price (2 dp)], plus the
 * order total (2 dp). Status / payments are checked separately (an adoptable order is unpaid; the Cloud re-checks under lock).
 */
final class ContinuityFingerprint
{
    /** @param array<string,mixed> $order a K1 order (table order or held sale): {total, lines:[{line_uuid, product_id, variant_id, qty, unit_price}]} */
    public static function fromMirror(array $order): string
    {
        $lines = [];
        foreach ((array) ($order['lines'] ?? []) as $l) {
            $lines[] = self::line((string) ($l['line_uuid'] ?? ''), (int) ($l['product_id'] ?? 0), $l['variant_id'] ?? null, $l['qty'] ?? 0, $l['unit_price'] ?? 0);
        }

        return self::compute($lines, $order['total'] ?? 0);
    }

    /** The same fingerprint over the Cloud's current rows (lines loaded fresh by the caller, under lock where it matters). */
    public static function fromOrder(SalesOrder $order, iterable $lines): string
    {
        $out = [];
        foreach ($lines as $l) {
            $out[] = self::line((string) $l->line_uuid, (int) $l->product_id, $l->product_variant_id, $l->quantity, $l->unit_price);
        }

        return self::compute($out, $order->grand_total);
    }

    /** @param list<array{0:string,1:int,2:int,3:string,4:string}> $lines */
    private static function compute(array $lines, mixed $total): string
    {
        usort($lines, fn ($a, $b) => strcmp($a[0], $b[0]));

        return EdgeCanonicalJson::hash(['lines' => $lines, 'total' => self::money($total)]);
    }

    private static function line(string $uuid, int $productId, mixed $variantId, mixed $qty, mixed $unitPrice): array
    {
        return [$uuid, $productId, (int) ($variantId ?? 0), number_format(round((float) $qty, 3), 3, '.', ''), self::money($unitPrice)];
    }

    private static function money(mixed $v): string
    {
        return number_format(round((float) $v, 2), 2, '.', '');
    }
}
