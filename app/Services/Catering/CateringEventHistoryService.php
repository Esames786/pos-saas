<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringEventRevision;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * KASHIF-EVENT-HISTORY-1 — the booking's memory, and the way back.
 *
 * record() writes one append-only revision per meaningful save: the WHOLE
 * operational state (header + lines + rates + supply splits + instructions +
 * charges) plus a human sentence about what changed. revertTo() takes any
 * revision and brings the CURRENT event back to that state — through the
 * normal pipelines only (saveDraftLines, the block/override authorities,
 * revise when the current quotation is immutable). There is no second writer
 * of pricing here, and history itself is never edited: a revert appends its
 * own row.
 *
 * Money (advances/refunds/invoices/GL) and released production are NEVER
 * part of a snapshot and never touched by a revert. They already happened.
 */
class CateringEventHistoryService
{
    public function __construct(
        private CateringEstimateService $estimates,
        private CateringLineCostBlockService $lineBlocks,
    ) {}

    /** Human labels for the timeline. */
    public const ACTIONS = [
        'created' => 'Booking created',
        'updated' => 'Booking details changed',
        'lines_saved' => 'Items / rates saved',
        'finalized' => 'Quotation finalized',
        'version_restored' => 'Old quotation version restored',
        'reverted' => 'Reverted to an earlier state',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Remember
    // ─────────────────────────────────────────────────────────────────────────

    public function record(CateringEvent $event, string $action, ?int $userId = null): ?CateringEventRevision
    {
        // Canonical form = exactly what a JSON round-trip returns (10.0 becomes
        // int 10, key order fixed). Without this, a fresh snapshot never
        // compares identical to a stored one and every save writes a noise row.
        $snapshot = json_decode(json_encode($this->snapshotOf($event->refresh())), true);

        $latest = CateringEventRevision::where('catering_event_id', $event->id)
            ->orderByDesc('changed_at')->orderByDesc('id')->first();

        // A save that changed nothing is not history — it is noise, and enough
        // of it would bury the entries an operator actually needs to find.
        // Compared ORDER-INSENSITIVELY: MySQL's JSON type stores objects in its
        // own key order, so a read-back snapshot never matches byte-for-byte.
        if ($latest && $this->canonical($latest->snapshot) === $this->canonical($snapshot)) {
            return null;
        }

        return CateringEventRevision::create([
            'catering_event_id' => $event->id,
            'changed_by_user_id' => $userId,
            'action' => $action,
            'change_summary' => $latest
                ? ($this->diffSummary($latest->snapshot, $snapshot) ?: (self::ACTIONS[$action] ?? $action))
                : 'Booking created',
            'snapshot' => $snapshot,
            'changed_at' => now(),
        ]);
    }

    /** The whole operational state, one array. Money deliberately absent. */
    public function snapshotOf(CateringEvent $event): array
    {
        $estimate = $this->currentEstimate($event);

        return [
            'header' => [
                'customer_name' => $event->customer_name,
                'customer_name_ur' => $event->customer_name_ur,
                'customer_phone' => $event->customer_phone,
                'customer_address' => $event->customer_address,
                'event_type' => $event->event_type,
                'event_date' => $event->event_date?->toDateString(),
                'service_time' => $event->service_time,
                'venue' => $event->venue,
                'pax' => (int) $event->pax,
                'branch_id' => (int) $event->branch_id,
            ],
            'charges' => $estimate ? [
                'service_charge_amount' => (float) $estimate->service_charge_amount,
                'other_charge_label' => $estimate->other_charge_label,
                'other_charge_amount' => (float) $estimate->other_charge_amount,
                'discount_type' => $estimate->discount_type,
                'discount_value' => (float) $estimate->discount_value,
                'tax_amount' => (float) $estimate->tax_amount,
                'terms' => $estimate->terms,
            ] : [],
            'lines' => $estimate
                ? $estimate->lines()->orderBy('sort_order')->get()->map(fn (CateringEstimateLine $line) => [
                    'product_id' => $line->product_id ? (int) $line->product_id : null,
                    'item_name' => $line->item_name,
                    'item_name_ur' => $line->item_name_ur,
                    'quantity' => (float) $line->quantity,
                    'unit_id' => $line->unit_id ? (int) $line->unit_id : null,
                    'unit_code' => $line->unit_code,
                    'rate' => (float) $line->rate,
                    'rate_override_reason' => $line->rate_override_reason,
                    'instructions' => $line->instructions,
                    'instruction_ids' => $line->managedInstructions()->pluck('catering_instructions.id')->map(fn ($id) => (int) $id)->all(),
                    'blocks' => $line->costBlocks()->orderBy('sort_order')->get()->map(fn ($block) => [
                        'label' => $block->label,
                        'material_name' => $block->material_name,
                        'event_material_qty' => $block->event_material_qty !== null ? (float) $block->event_material_qty : null,
                        'is_customer_supplied' => (bool) $block->is_customer_supplied,
                        'customer_supplied_qty' => $block->customer_supplied_qty !== null ? (float) $block->customer_supplied_qty : null,
                    ])->all(),
                ])->all()
                : [],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The human sentence
    // ─────────────────────────────────────────────────────────────────────────

    private const HEADER_LABELS = [
        'customer_name' => 'Customer', 'customer_name_ur' => 'Customer (Urdu)',
        'customer_phone' => 'Phone', 'customer_address' => 'Address',
        'event_type' => 'Type', 'event_date' => 'Date', 'service_time' => 'Time',
        'venue' => 'Venue', 'pax' => 'PAX', 'branch_id' => 'Branch',
    ];

    private const CHARGE_LABELS = [
        'service_charge_amount' => 'Service charges',
        'other_charge_label' => 'Other charge name',
        'other_charge_amount' => 'Other charges',
        'discount_type' => 'Discount type',
        'discount_value' => 'Discount value',
        'tax_amount' => 'Tax',
        'terms' => 'Terms',
    ];

    /**
     * The same diff as change_summary, but shaped for human scanning. Header
     * and charge changes stay separate from item rows; every changed row owns
     * its own list, so the UI never has to parse a punctuation-heavy sentence.
     *
     * @return array{event: array<int, array<string, mixed>>, charges: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>}
     */
    public function structuredDiff(array $old, array $new): array
    {
        $groups = ['event' => [], 'charges' => [], 'rows' => []];

        foreach (self::HEADER_LABELS as $key => $label) {
            $before = $old['header'][$key] ?? null;
            $after = $new['header'][$key] ?? null;
            if ($before != $after) {
                $groups['event'][] = $this->change($label, $before, $after);
            }
        }

        foreach (self::CHARGE_LABELS as $key => $label) {
            $before = $old['charges'][$key] ?? null;
            $after = $new['charges'][$key] ?? null;
            if ($before != $after) {
                $groups['charges'][] = $this->change($label, $before, $after);
            }
        }

        $oldLines = array_values($old['lines'] ?? []);
        $newLines = array_values($new['lines'] ?? []);
        $count = max(count($oldLines), count($newLines));

        for ($index = 0; $index < $count; $index++) {
            $before = $oldLines[$index] ?? null;
            $after = $newLines[$index] ?? null;

            if ($before === null && $after !== null) {
                $groups['rows'][] = [
                    'row' => $index + 1,
                    'item' => $after['item_name'] ?? 'Item',
                    'kind' => 'added',
                    'changes' => [
                        $this->change('Item', null, $after['item_name'] ?? null),
                        $this->change('Quantity', null, $this->quantityWithUnit($after)),
                        $this->change('Customer rate', null, $this->q($after['rate'] ?? 0)),
                    ],
                ];

                continue;
            }

            if ($before !== null && $after === null) {
                $groups['rows'][] = [
                    'row' => $index + 1,
                    'item' => $before['item_name'] ?? 'Item',
                    'kind' => 'removed',
                    'changes' => [$this->change('Item', $before['item_name'] ?? null, null)],
                ];

                continue;
            }

            if ($before === null || $after === null) {
                continue;
            }

            $changes = [];
            foreach ([
                'item_name' => 'Item name',
                'item_name_ur' => 'Urdu name',
                'unit_code' => 'Unit',
                'instructions' => 'Instructions',
            ] as $key => $label) {
                if (($before[$key] ?? null) != ($after[$key] ?? null)) {
                    $changes[] = $this->change($label, $before[$key] ?? null, $after[$key] ?? null);
                }
            }
            if ((float) ($before['quantity'] ?? 0) !== (float) ($after['quantity'] ?? 0)) {
                $changes[] = $this->change('Quantity', $this->quantityWithUnit($before), $this->quantityWithUnit($after));
            }
            if ((float) ($before['rate'] ?? 0) !== (float) ($after['rate'] ?? 0)) {
                $changes[] = $this->change('Customer rate', $this->q($before['rate'] ?? 0), $this->q($after['rate'] ?? 0));
            }

            $beforeBlocks = collect($before['blocks'] ?? [])->keyBy('label');
            $afterBlocks = collect($after['blocks'] ?? [])->keyBy('label');
            foreach ($beforeBlocks->keys()->merge($afterBlocks->keys())->unique() as $label) {
                $oldBlock = $beforeBlocks->get($label);
                $newBlock = $afterBlocks->get($label);
                if ($oldBlock === null || $newBlock === null) {
                    $changes[] = $this->change('Material: '.$label, $oldBlock ? 'Present' : null, $newBlock ? 'Present' : null);
                    continue;
                }
                if (($oldBlock['event_material_qty'] ?? null) != ($newBlock['event_material_qty'] ?? null)) {
                    $changes[] = $this->change($label.' kitchen quantity', $this->q($oldBlock['event_material_qty'] ?? 0), $this->q($newBlock['event_material_qty'] ?? 0));
                }
                if (($oldBlock['is_customer_supplied'] ?? false) !== ($newBlock['is_customer_supplied'] ?? false)) {
                    $changes[] = $this->change($label.' supplied by',
                        ($oldBlock['is_customer_supplied'] ?? false) ? 'Customer' : 'Us',
                        ($newBlock['is_customer_supplied'] ?? false) ? 'Customer' : 'Us');
                }
                if (($oldBlock['customer_supplied_qty'] ?? null) != ($newBlock['customer_supplied_qty'] ?? null)) {
                    $changes[] = $this->change($label.' customer quantity', $this->q($oldBlock['customer_supplied_qty'] ?? 0), $this->q($newBlock['customer_supplied_qty'] ?? 0));
                }
            }

            if ($changes !== []) {
                $groups['rows'][] = [
                    'row' => $index + 1,
                    'item' => $after['item_name'] ?? $before['item_name'] ?? 'Item',
                    'kind' => 'changed',
                    'changes' => $changes,
                ];
            }
        }

        return $groups;
    }

    private function change(string $label, mixed $before, mixed $after): array
    {
        return [
            'label' => $label,
            'before' => $this->displayValue($before),
            'after' => $this->displayValue($after),
        ];
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return (string) $value;
    }

    private function quantityWithUnit(array $line): string
    {
        return trim($this->q($line['quantity'] ?? 0).' '.($line['unit_code'] ?? ''));
    }

    public function diffSummary(array $old, array $new): string
    {
        $parts = [];

        foreach (self::HEADER_LABELS as $key => $label) {
            $a = $old['header'][$key] ?? null;
            $b = $new['header'][$key] ?? null;
            if ($a != $b) {
                $parts[] = sprintf('%s %s→%s', $label, $a === null || $a === '' ? '—' : $a, $b === null || $b === '' ? '—' : $b);
            }
        }

        foreach (($new['charges'] ?? []) as $key => $b) {
            $a = ($old['charges'] ?? [])[$key] ?? null;
            if ($a != $b) {
                $parts[] = sprintf('%s %s→%s', str_replace('_', ' ', $key), $a ?? '—', $b ?? '—');
            }
        }

        $oldLines = collect($old['lines'] ?? [])->keyBy(fn ($l, $i) => ($l['item_name'] ?? '?').'#'.$i);
        $newLines = collect($new['lines'] ?? [])->keyBy(fn ($l, $i) => ($l['item_name'] ?? '?').'#'.$i);

        foreach ($newLines as $key => $line) {
            $was = $oldLines->get($key);
            if ($was === null) {
                $parts[] = sprintf('Added %s %s %s', $line['item_name'], $this->q($line['quantity']), $line['unit_code']);

                continue;
            }
            if ((float) $was['quantity'] !== (float) $line['quantity']) {
                $parts[] = sprintf('%s %s→%s %s', $line['item_name'], $this->q($was['quantity']), $this->q($line['quantity']), $line['unit_code']);
            }
            if ((float) $was['rate'] !== (float) $line['rate']) {
                $parts[] = sprintf('%s rate %s→%s', $line['item_name'], $this->q($was['rate']), $this->q($line['rate']));
            }

            $wasBlocks = collect($was['blocks'] ?? [])->keyBy('label');
            foreach (($line['blocks'] ?? []) as $block) {
                $wb = $wasBlocks->get($block['label']);
                if (! $wb) {
                    continue;
                }
                if (($wb['event_material_qty'] ?? null) != ($block['event_material_qty'] ?? null)) {
                    $parts[] = sprintf('%s: %s %s→%s', $line['item_name'], $block['label'],
                        $this->q($wb['event_material_qty'] ?? 0), $this->q($block['event_material_qty'] ?? 0));
                }
                if (($wb['is_customer_supplied'] ?? false) !== ($block['is_customer_supplied'] ?? false)) {
                    $parts[] = sprintf('%s: %s %s', $line['item_name'], $block['label'],
                        ($block['is_customer_supplied'] ?? false) ? '→ customer-supplied' : '→ supplied by us');
                }
                if (($wb['customer_supplied_qty'] ?? null) != ($block['customer_supplied_qty'] ?? null)) {
                    $parts[] = sprintf('%s: %s customer share %s→%s', $line['item_name'], $block['label'],
                        $this->q($wb['customer_supplied_qty'] ?? 0), $this->q($block['customer_supplied_qty'] ?? 0));
                }
            }
        }

        foreach ($oldLines as $key => $line) {
            if (! $newLines->has($key)) {
                $parts[] = sprintf('Removed %s', $line['item_name']);
            }
        }

        return implode('; ', array_slice($parts, 0, 12)).(count($parts) > 12 ? '; …' : '');
    }

    private function q(float|int|string|null $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
    }

    /** One byte-string per state, whatever key order the storage chose. */
    private function canonical(array $snapshot): string
    {
        $this->ksortDeep($snapshot);

        return (string) json_encode($snapshot);
    }

    private function ksortDeep(array &$a): void
    {
        ksort($a);
        foreach ($a as &$v) {
            if (is_array($v)) {
                $this->ksortDeep($v);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The way back
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Bring the CURRENT event back to a remembered state. The operator sees an
     * overwrite; the record sees a new step. Everything flows through the
     * normal pipelines: header via the model, lines via saveDraftLines, block
     * splits and agreed rates via their own authorities, and — when the current
     * quotation is already immutable — a new version via revise() first.
     */
    public function revertTo(CateringEventRevision $revision, ?int $userId = null): CateringEvent
    {
        $event = $revision->event()->firstOrFail();
        $snapshot = $revision->snapshot;

        return DB::connection('tenant')->transaction(function () use ($event, $snapshot, $revision, $userId) {
            $event->fill($snapshot['header'] ?? [])->save();

            $current = $this->currentEstimate($event);
            if ($current === null) {
                throw new RuntimeException('This booking has no quotation to revert.');
            }

            if (! $current->isDraft()) {
                if ($current->status === CateringEstimate::STATUS_SUPERSEDED) {
                    throw new RuntimeException('The current version is superseded — open the booking afresh and try again.');
                }
                $current = $this->estimates->revise($current, $userId);
            }

            $lines = collect($snapshot['lines'] ?? [])->map(fn ($line) => [
                'product_id' => $line['product_id'],
                'item_name' => $line['item_name'],
                'item_name_ur' => $line['item_name_ur'] ?? null,
                'quantity' => $line['quantity'],
                'unit_id' => $line['unit_id'] ?? null,
                'unit_code' => $line['unit_code'] ?? null,
                'rate' => $line['rate'],
                'instructions' => $line['instructions'] ?? null,
                'instruction_ids' => $line['instruction_ids'] ?? [],
            ])->all();

            $estimate = $this->estimates->saveDraftLines($current, $lines, $snapshot['charges'] ?? []);
            if (array_key_exists('terms', $snapshot['charges'] ?? [])) {
                $estimate->fill(['terms' => $snapshot['charges']['terms']])->save();
            }

            // The per-line decisions: event material quantities, supply splits,
            // agreed rates — each through its own authority, none by hand.
            $estimateLines = $estimate->lines()->orderBy('sort_order')->get()->values();
            foreach ($estimateLines as $i => $line) {
                $want = ($snapshot['lines'] ?? [])[$i] ?? null;
                if ($want === null) {
                    continue;
                }

                $wantBlocks = collect($want['blocks'] ?? [])->keyBy('label');
                foreach ($this->lineBlocks->snapshotsFor($line) as $block) {
                    $wb = $wantBlocks->get($block->label);
                    if (! $wb || ! $block->isMaterial()) {
                        continue;
                    }
                    if (($wb['event_material_qty'] ?? null) !== null
                        && round((float) $wb['event_material_qty'], 4) !== round((float) $block->event_material_qty, 4)) {
                        $this->lineBlocks->overrideMaterialQuantity($block, (float) $wb['event_material_qty']);
                    }
                    if ($wb['is_customer_supplied'] ?? false) {
                        $this->lineBlocks->setCustomerSupplied($block, true);
                    } elseif (($wb['customer_supplied_qty'] ?? null) !== null && (float) $wb['customer_supplied_qty'] > 0) {
                        $this->lineBlocks->setCustomerSupplied($block, false, (float) $wb['customer_supplied_qty']);
                    }
                }

                if (($want['rate_override_reason'] ?? null) !== null && $line->refresh()->costBlocks()->exists()) {
                    $this->lineBlocks->overrideQuotedRate($line, (float) $want['rate'], (string) $want['rate_override_reason']);
                }
            }

            $this->record($event->refresh(), 'reverted', $userId);

            return $event;
        });
    }

    private function currentEstimate(CateringEvent $event): ?CateringEstimate
    {
        return CateringEstimate::where('catering_event_id', $event->id)
            ->orderByDesc('version_no')
            ->first();
    }
}
