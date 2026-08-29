<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringProductionRelease;
use App\Models\Tenant\CateringProductionReleaseLine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CATERING-SLICE-3: production release (spec §14) — an immutable snapshot of
 * WHAT to produce for an event. A separate catering business event; it never
 * creates kot_batches / print POS KOTs, never moves stock, and carries zero
 * customer pricing. Actual production stock issue is a future flow that must
 * go through the approved Inventory/Kitchen authority (spec §20).
 */
class CateringProductionReleaseService
{
    public function __construct(
        private readonly CateringNumberService $numbers,
        private readonly CateringRequirementService $requirements,
    ) {}

    public function release(CateringEvent $event, ?int $userId = null): CateringProductionRelease
    {
        $estimate = $event->currentEstimate;
        if (! $estimate) {
            throw new RuntimeException("Event {$event->event_no} has no estimate to release.");
        }
        if ($estimate->isDraft()) {
            throw new RuntimeException('Send/lock the estimate before releasing production.');
        }
        if (! in_array($event->status, [
            CateringEvent::STATUS_CONFIRMED,
            CateringEvent::STATUS_PRODUCTION_READY,
            CateringEvent::STATUS_QUOTED, // allow direct release for short-notice bookings
        ], true)) {
            throw new RuntimeException("Event {$event->event_no} ({$event->status}) cannot release production.");
        }

        $consolidated = $this->requirements->consolidatedForEstimate($estimate, $event->branch_id);

        return DB::connection('tenant')->transaction(function () use ($event, $estimate, $consolidated, $userId) {
            $release = CateringProductionRelease::create([
                'release_no' => $this->numbers->nextProductionReleaseNo(),
                'catering_event_id' => $event->id,
                'catering_estimate_id' => $estimate->id,
                'event_snapshot' => [
                    'event_no' => $event->event_no,
                    'customer_name' => $event->customer_name,
                    'customer_name_ur' => $event->customer_name_ur,
                    'customer_phone' => $event->customer_phone,
                    'venue' => $event->venue,
                    'event_date' => $event->event_date->toDateString(),
                    'service_time' => $event->service_time,
                    'pax' => $event->pax,
                    'event_type' => $event->event_type,
                    'estimate_version' => $estimate->version_no,
                ],
                'requirements_snapshot' => $consolidated,
                'status' => CateringProductionRelease::STATUS_RELEASED,
                'released_at' => now(),
                'released_by_user_id' => $userId,
            ]);

            foreach ($estimate->lines as $index => $line) {
                $profile = $line->product?->cateringProfile;
                CateringProductionReleaseLine::create([
                    'catering_production_release_id' => $release->id,
                    'product_id' => $line->product_id,
                    // Production label wins over the commercial name on the kitchen floor.
                    'item_name' => $profile?->production_label ?: $line->item_name,
                    // KASHIF-URDU-CARRY-1: the kitchen sheet prints Urdu when it HAS
                    // Urdu. Production label first, then the line's own, then the
                    // product book itself — a blank here was the only reason the
                    // sheet read English on an Urdu sheet.
                    'item_name_ur' => $profile?->production_label_ur
                        ?: ($line->item_name_ur ?: $this->productUrduName($line->product_id)),
                    'quantity' => $line->quantity,
                    'unit_code' => $line->unit_code,
                    'production_station' => $profile?->production_station,
                    // KASHIF-CATERING-INSTRUCTIONS-1: the line's managed selections
                    // and free note as one string, then the dish profile's standing
                    // instruction. Snapshotted as TEXT — the kitchen sheet stays
                    // readable even if the vocabulary is edited later.
                    'instructions' => trim(implode("\n", array_filter([
                        $line->instructionSummary(),
                        // The kitchen must know what arrives from the CUSTOMER —
                        // it still gets cooked, but our store hands over none of
                        // it (or, on a split, only the billable balance).
                        ($cs = $line->costBlocks
                            ->filter(fn ($b) => $b->isMaterial() && $b->suppliedQty() > 0)
                            ->map(function ($b) {
                                $name = $b->material_name ?: $b->label;
                                if (! $b->isPartiallyCustomerSupplied()) {
                                    return $name;
                                }
                                $fmt = fn (float $q) => rtrim(rtrim(number_format($q, 4), '0'), '.');

                                return sprintf('%s %s %s (of %s %s)',
                                    $name, $fmt($b->suppliedQty()), $b->unit_code,
                                    $fmt($b->physicalRequirement()), $b->unit_code);
                            })
                            ->filter()->implode(', ')) !== ''
                            ? 'CUSTOMER SUPPLIES: '.$cs
                            : null,
                        $profile?->instructions,
                    ]))) ?: null,
                    'sort_order' => $index,
                ]);
            }

            $event->forceFill(['status' => CateringEvent::STATUS_RELEASED])->save();

            return $release;
        });
    }

    /** The product book's Urdu name, cached per request. */
    /** @var array<int, string|null> Read once per product, per request. */
    private array $urduNameCache = [];

    private function productUrduName(?int $productId): ?string
    {
        if (! $productId) {
            return null;
        }

        return $this->urduNameCache[$productId] ??= \Illuminate\Support\Facades\DB::connection('tenant')
            ->table('product_translations')
            ->where('product_id', $productId)
            ->where('language_code', 'ur')
            ->value('name');
    }
}
