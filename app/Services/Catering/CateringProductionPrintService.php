<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringPrinterMapping;
use App\Models\Tenant\CateringProductionRelease;
use App\Models\Tenant\Printer;
use App\Models\Tenant\PrintJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * CATERING-V1-CLOSURE-1 (§6): physical delivery of a production release
 * through the EXISTING PrintJob transport (print_jobs + polling LAN agent).
 *
 * Contract:
 *  - a catering production ticket is its own document type
 *    (`catering_production`) — NEVER a POS KOT: no kot_batches, no
 *    sales_orders reference, no sale side effects (PrintJobService's
 *    markPrinted is a no-op for non-sales references by design).
 *  - routing uses catering_printer_mappings ONLY; POS category_printer_mappings
 *    are never read or written here.
 *  - one job per physical printer destination; the payload is frozen at queue
 *    time from the release's immutable snapshot and carries NO commercial
 *    prices.
 *  - idempotent: the durable logical_key
 *    `catering-production:{release_uuid}:printer-{id}` makes a retry of the
 *    same release yield the SAME business jobs (unique-key catch, the
 *    createLogicalJob pattern). An explicit reprint creates a controlled new
 *    copy under `catering-production-copy:...:{copy_no}`.
 *  - thermal output is ENGLISH text on the current transport. Urdu/bilingual
 *    thermal is NOT claimed — that needs a Unicode/RTL raster pipeline plus
 *    physical certification; Urdu stays on the A4/browser documents.
 */
class CateringProductionPrintService
{
    private const COLS = 42;

    private const BOLD_ON = "\x1B\x45\x01";

    private const BOLD_OFF = "\x1B\x45\x00";

    private const SIZE_DOUBLE = "\x1D\x21\x11";

    private const SIZE_NORMAL = "\x1D\x21\x00";

    private const CUT = "\x1D\x56\x42\x00";

    /**
     * Queue one job per mapped printer for the release. Idempotent per
     * (release, printer). Returns the PrintJob rows (existing rows on retry).
     *
     * @return Collection<int, PrintJob>
     */
    public function queueRelease(CateringProductionRelease $release, ?int $userId = null): Collection
    {
        $release->loadMissing('lines', 'event');
        $destinations = $this->resolveDestinations($release);

        return $destinations->map(function (array $destination) use ($release, $userId) {
            /** @var Printer $printer */
            $printer = $destination['printer'];
            $lines = $destination['lines'];

            return $this->createJob($release, $printer, $lines, [
                'logical_key' => "catering-production:{$release->release_uuid}:printer-{$printer->id}",
                'copy_no' => 1,
                'is_reprint' => false,
            ], $userId);
        })->values();
    }

    /**
     * Explicit reprint: a controlled NEW physical copy per mapped printer
     * (or one specific printer), with an incremented copy number.
     *
     * @return Collection<int, PrintJob>
     */
    public function reprintRelease(CateringProductionRelease $release, ?int $printerId = null, ?int $userId = null): Collection
    {
        $release->loadMissing('lines', 'event');
        $destinations = $this->resolveDestinations($release)
            ->when($printerId, fn ($all) => $all->filter(fn ($d) => $d['printer']->id === $printerId));

        return $destinations->map(function (array $destination) use ($release, $userId) {
            /** @var Printer $printer */
            $printer = $destination['printer'];
            $copyNo = (int) PrintJob::query()
                ->where('document_type', 'catering_production')
                ->where('reference_type', 'catering_production_release')
                ->where('reference_id', $release->id)
                ->where('printer_id', $printer->id)
                ->max('copy_no') + 1;

            return $this->createJob($release, $printer, $destination['lines'], [
                'logical_key' => "catering-production-copy:{$release->release_uuid}:printer-{$printer->id}:{$copyNo}",
                'copy_no' => $copyNo,
                'is_reprint' => true,
            ], $userId);
        })->values();
    }

    /**
     * Match release lines to active catering printer mappings:
     * a station-scoped mapping matches lines of that production station;
     * a category (or all-categories) mapping matches by the line product's
     * category. Branch NULL = all branches. One destination per printer with
     * the union of its matched lines.
     *
     * @return Collection<int, array{printer: Printer, lines: Collection}>
     */
    public function resolveDestinations(CateringProductionRelease $release): Collection
    {
        $branchId = $release->event?->branch_id;

        $mappings = CateringPrinterMapping::with(['printer', 'category'])
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($qq) => $qq->orWhere('branch_id', $branchId)))
            ->get()
            ->filter(fn (CateringPrinterMapping $mapping) => $mapping->printer && $mapping->printer->is_active);

        $categoryByProduct = \App\Models\Tenant\Product::query()
            ->whereIn('id', $release->lines->pluck('product_id')->filter()->unique())
            ->pluck('category_id', 'id');

        $byPrinter = [];
        foreach ($release->lines as $line) {
            $lineCategoryId = $line->product_id ? ($categoryByProduct[$line->product_id] ?? null) : null;

            foreach ($mappings as $mapping) {
                $matches = $mapping->production_station !== null
                    ? ($line->production_station !== null
                        && strcasecmp($mapping->production_station, $line->production_station) === 0)
                    : ($mapping->category_id === null || $mapping->category_id === $lineCategoryId);

                if ($matches) {
                    $byPrinter[$mapping->printer_id]['printer'] = $mapping->printer;
                    $byPrinter[$mapping->printer_id]['lines'][$line->id] = $line;
                }
            }
        }

        return collect($byPrinter)
            ->map(fn (array $destination) => [
                'printer' => $destination['printer'],
                'lines' => collect($destination['lines'])->sortBy('sort_order')->values(),
            ])
            ->values();
    }

    private function createJob(CateringProductionRelease $release, Printer $printer, Collection $lines, array $meta, ?int $userId): PrintJob
    {
        $snapshot = $release->event_snapshot;
        $payload = [
            'release_no' => $release->release_no,
            'release_uuid' => $release->release_uuid,
            'event' => $snapshot,
            'copy_no' => $meta['copy_no'],
            'is_reprint' => $meta['is_reprint'],
            // Self-contained line snapshot — NO commercial prices by design.
            'lines' => $lines->map(fn ($line) => [
                'item_name' => $line->item_name,
                'item_name_ur' => $line->item_name_ur,
                'quantity' => (float) $line->quantity,
                'unit_code' => $line->unit_code,
                'production_station' => $line->production_station,
                'instructions' => $line->instructions,
            ])->values()->all(),
        ];

        $attributes = [
            'job_no' => 'PJ-'.now()->format('YmdHis').'-'.random_int(100, 999),
            'logical_key' => $meta['logical_key'],
            'copy_no' => $meta['copy_no'],
            'branch_id' => $release->event?->branch_id,
            'printer_id' => $printer->id,
            'document_type' => 'catering_production',
            'print_status' => 'queued',
            'reference_type' => 'catering_production_release',
            'reference_id' => $release->id,
            'reference_no' => $release->release_no,
            'payload' => $payload,
            'raw_payload' => $this->escPos($payload),
            'created_by_user_id' => $userId,
        ];

        try {
            return PrintJob::create($attributes);
        } catch (QueryException $exception) {
            // Idempotency: the unique logical_key means this destination already
            // has its business job — return it, never a duplicate.
            $existing = PrintJob::where('logical_key', $meta['logical_key'])->first();
            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    /** English-only ESC/POS text ticket (42 cols), frozen at queue time. */
    private function escPos(array $payload): string
    {
        $event = $payload['event'] ?? [];
        $center = fn (string $text) => str_pad(mb_substr($text, 0, self::COLS), self::COLS, ' ', STR_PAD_BOTH);
        $row = fn (string $left, string $right) => mb_substr(str_pad($left, self::COLS - mb_strlen($right)), 0, self::COLS - mb_strlen($right)).$right;
        $rule = str_repeat('-', self::COLS);

        $out = self::SIZE_DOUBLE.self::BOLD_ON.$center('CATERING PRODUCTION')."\n".self::SIZE_NORMAL.self::BOLD_OFF;
        if (! empty($payload['is_reprint'])) {
            $out .= self::BOLD_ON.$center('*** COPY #'.$payload['copy_no'].' ***')."\n".self::BOLD_OFF;
        }
        $out .= $rule."\n";
        $out .= $row($payload['release_no'] ?? '', $event['event_no'] ?? '')."\n";
        $out .= self::BOLD_ON.($event['customer_name'] ?? '')."\n".self::BOLD_OFF;
        $when = trim(($event['event_date'] ?? '').' '.($event['service_time'] ?? ''));
        if ($when !== '') {
            $out .= $when."\n";
        }
        if (! empty($event['venue'])) {
            $out .= $event['venue']."\n";
        }
        $out .= self::BOLD_ON.'PAX: '.number_format((int) ($event['pax'] ?? 0))."\n".self::BOLD_OFF;
        $out .= $rule."\n";

        $station = null;
        foreach ($payload['lines'] as $line) {
            if (($line['production_station'] ?? null) !== $station) {
                $station = $line['production_station'];
                if ($station !== null) {
                    $out .= self::BOLD_ON.'['.strtoupper($station).']'."\n".self::BOLD_OFF;
                }
            }
            $qty = rtrim(rtrim(number_format($line['quantity'], 3), '0'), '.').' '.($line['unit_code'] ?? '');
            $out .= self::SIZE_DOUBLE.self::BOLD_ON.$qty.'  '.$line['item_name']."\n".self::SIZE_NORMAL.self::BOLD_OFF;
            if (! empty($line['instructions'])) {
                $out .= '  > '.str_replace("\n", "\n  > ", $line['instructions'])."\n";
            }
        }

        $out .= $rule."\n".$center('NO PRICES - PRODUCTION DOCUMENT')."\n\n\n".self::CUT;

        return $out;
    }
}
