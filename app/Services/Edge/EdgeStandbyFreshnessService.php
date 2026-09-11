<?php

namespace App\Services\Edge;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Q — WARM STANDBY FRESHNESS (appliance side).
 *
 * While the Cloud is the writer, the appliance keeps its local selling position CURRENT from what the heartbeat
 * advertises: the config revision (pull the refresh package when the Cloud's revision is ahead), the stock position
 * (pull a fresh baseline when the Cloud's stock watermark moved), the F1 returnable-sale projection and the F2
 * supplier-finance projection. The writer never refreshes itself from the Cloud.
 */
class EdgeStandbyFreshnessService
{
    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeConfigRefreshClient $configClient,
        private readonly EdgeLocalConfigRefreshApplier $applier,
        private readonly EdgeBaselineClient $baselineClient,
        private readonly EdgeBaselineCutoverService $cutover,
        private readonly EdgeOperationalBaselineService $baselines,
        private readonly EdgeReturnableCacheClient $returnableClient,
        private readonly EdgeReturnableSaleCacheService $returnableCache,
        private readonly EdgeSupplierFinanceCacheClient $supplierFinanceClient,
        private readonly EdgeSupplierFinanceCacheService $supplierFinanceCache,
    ) {
    }

    /** One standby tick: refresh whatever the last heartbeat proved to be behind. Never throws; reports per area. */
    public function tick(): array
    {
        $meta = $this->context->current();
        if (! $meta || (string) $meta->authority_state !== EdgeAuthorityService::STANDBY) {
            return ['config' => 'skipped:not-standby', 'stock' => 'skipped:not-standby']; // the writer never refreshes itself from the Cloud
        }
        $config = 'current';
        $seenRevision = $meta->standby_config_revision_seen !== null ? (int) $meta->standby_config_revision_seen : null;
        $applied = (int) ($meta->last_applied_config_revision ?? 0);
        if ($seenRevision !== null && $seenRevision > $applied) {
            try {
                $this->refreshConfig();
                $config = "refreshed:{$applied}->{$seenRevision}";
            } catch (Throwable $e) {
                $config = 'error:' . mb_substr($e->getMessage(), 0, 160);
            }
        }
        $stock = 'current';
        try {
            $stock = $this->refreshStockIfBehind();
        } catch (Throwable $e) {
            $stock = 'error:' . mb_substr($e->getMessage(), 0, 160);
        }
        $returnable = 'current';
        try {
            $returnable = $this->refreshReturnableIfBehind();
        } catch (Throwable $e) {
            $returnable = 'error:' . mb_substr($e->getMessage(), 0, 160);
        }
        $supplierFinance = 'current';
        try {
            $supplierFinance = $this->refreshSupplierFinanceIfBehind();
        } catch (Throwable $e) {
            $supplierFinance = 'error:' . mb_substr($e->getMessage(), 0, 160);
        }

        return ['config' => $config, 'stock' => $stock, 'returnable' => $returnable, 'supplier_finance' => $supplierFinance];
    }

    /** F1 — pull the returnable-sale projection when the Cloud advertised a watermark we do not hold. */
    public function refreshReturnableIfBehind(): string
    {
        $meta = $this->context->requireCurrent();
        $seen = $meta->standby_returnable_watermark_seen !== null ? (string) $meta->standby_returnable_watermark_seen : null;
        $cached = $meta->returnable_cache_watermark !== null ? (string) $meta->returnable_cache_watermark : null;
        if ($seen === null || $seen === $cached) {
            return 'current';
        }
        $stats = $this->returnableCache->apply($this->returnableClient->fetchPackage());

        return 'refreshed:' . $stats['sales'] . '-sales';
    }

    /** F2 — pull the supplier-finance projection when the Cloud advertised a watermark we do not hold. */
    public function refreshSupplierFinanceIfBehind(): string
    {
        $meta = $this->context->requireCurrent();
        $seen = $meta->standby_supplier_finance_watermark_seen !== null ? (string) $meta->standby_supplier_finance_watermark_seen : null;
        $cached = $meta->supplier_finance_cache_watermark !== null && $meta->supplier_finance_cache_watermark !== '' ? (string) $meta->supplier_finance_cache_watermark : null;
        if ($seen === null || $seen === $cached) {
            return 'current';
        }
        $stats = $this->supplierFinanceCache->apply($this->supplierFinanceClient->fetchPackage());

        return 'refreshed:' . ($stats['suppliers'] ?? 0) . '-suppliers';
    }

    public function refreshConfig(): array
    {
        $package = $this->configClient->fetchPackage();
        $result = $this->applier->apply($package['manifest'], $package['sections']);
        $this->context->requireCurrent()->forceFill(['standby_config_refreshed_at' => now()])->save();

        return $result;
    }

    /**
     * Stock freshness proof: the Cloud's stock watermark moved → the appliance pulls a fresh baseline and accepts it
     * (initial / cutover / standby refresh) — the local selling position follows the Cloud (100 → 95 → 92).
     */
    public function refreshStockIfBehind(): string
    {
        $meta = $this->context->requireCurrent();
        if ((string) $meta->authority_state !== EdgeAuthorityService::STANDBY) {
            throw new RuntimeException('STANDBY_REFRESH_NOT_STANDBY: only a standby appliance refreshes stock from the Cloud.');
        }
        $accepted = $this->baselines->currentAccepted(); // null when none, or when the config revision moved past it
        $seen = $meta->standby_stock_watermark_seen !== null ? (string) $meta->standby_stock_watermark_seen : null;
        if ($accepted !== null && $seen !== null && (string) ($accepted->stock_watermark ?? '') === $seen) {
            return 'current';
        }
        if ($accepted !== null && $seen === null) {
            return 'current'; // nothing advertised yet — nothing provably newer to pull
        }
        $package = $this->baselineClient->fetch((string) $meta->source_revision, (int) $meta->activation_epoch);
        $this->baselineClient->assertResolvable($package);
        $position = $package['cloud_position'] ?? [];
        $anyForBinding = DB::connection('tenant')->table('edge_operational_stock_baselines')
            ->where('branch_id', (int) $meta->branch_id)->where('device_uuid', (string) $meta->device_uuid)
            ->where('activation_epoch', (int) $meta->activation_epoch)->where('status', 'accepted')->first();
        if ($anyForBinding === null) {
            $row = $this->baselines->accept((string) $package['baseline_uuid'], (string) $package['content_hash'], $package['items'], (string) $package['source_revision']);
            DB::connection('tenant')->table('edge_operational_stock_baselines')->where('id', (int) $row->id)->update([
                'stock_watermark' => $position['stock_watermark'] ?? null,
                'cloud_as_of' => isset($position['as_of']) ? Carbon::parse($position['as_of']) : null,
                'freshness_kind' => 'initial',
                'updated_at' => now(),
            ]);
            $kind = 'initial';
        } elseif ($accepted === null) {
            $this->cutover->acceptCutover($package, 'authority-worker', 'warm standby: config revision moved — baseline at the new watermark');
            $kind = 'cutover';
        } else {
            $this->cutover->acceptStandbyRefresh($package, 'authority-worker');
            $kind = 'standby';
        }
        $this->context->requireCurrent()->forceFill(['standby_stock_refreshed_at' => now()])->save();

        return "refreshed:{$kind}";
    }

    /**
     * STANDBY_FRESH_ENOUGH — the proof behind the takeover gate: the applied config revision equals the advertised
     * one, and the accepted stock baseline equals the advertised watermark (or was issued strictly after the last
     * acknowledged heartbeat — the Cloud could not have advertised anything newer). Never an age heuristic alone.
     */
    public function freshEnough(): array
    {
        $meta = $this->context->current();
        if (! $meta) {
            return ['ok' => false, 'config_ok' => false, 'stock_ok' => false, 'reasons' => ['appliance not bound'], 'facts' => []];
        }
        $reasons = [];
        $seenRevision = $meta->standby_config_revision_seen !== null ? (int) $meta->standby_config_revision_seen : null;
        $applied = (int) ($meta->last_applied_config_revision ?? 0);
        $configOk = $seenRevision !== null && $applied === $seenRevision;
        if ($seenRevision === null) {
            $reasons[] = 'the Cloud never advertised a config revision to this appliance';
        } elseif (! $configOk) {
            $reasons[] = "config revision {$applied} applied, Cloud advertised {$seenRevision}";
        }
        $accepted = $this->baselines->currentAccepted();
        $seenWatermark = $meta->standby_stock_watermark_seen !== null ? (string) $meta->standby_stock_watermark_seen : null;
        $lastAck = $meta->authority_last_ack_at ? Carbon::parse($meta->authority_last_ack_at) : null;
        $asOf = $accepted && $accepted->cloud_as_of ? Carbon::parse($accepted->cloud_as_of) : null;
        $stockOk = false;
        if ($accepted === null) {
            $reasons[] = 'no accepted stock baseline at the current config revision';
        } elseif ($seenWatermark === null) {
            $reasons[] = 'the Cloud never advertised a stock watermark to this appliance';
        } elseif ((string) ($accepted->stock_watermark ?? '') === $seenWatermark) {
            $stockOk = true;
        } elseif ($asOf !== null && $lastAck !== null && $asOf->greaterThan($lastAck)) {
            $stockOk = true;
        } else {
            $age = ($asOf && $lastAck) ? max(0, $lastAck->getTimestamp() - $asOf->getTimestamp()) : null;
            $reasons[] = 'accepted stock baseline does not equal the last advertised Cloud position'
                . ($age !== null ? " (issued {$age}s before the last acknowledged heartbeat)" : '');
        }

        return [
            'ok' => $configOk && $stockOk,
            'config_ok' => $configOk,
            'stock_ok' => $stockOk,
            'reasons' => $reasons,
            'facts' => [
                'config_revision_applied' => $applied,
                'config_revision_advertised' => $seenRevision,
                'stock_watermark_accepted' => $accepted?->stock_watermark,
                'stock_watermark_advertised' => $seenWatermark,
                'stock_as_of' => $asOf?->toIso8601String(),
                'last_ack_at' => $lastAck?->toIso8601String(),
                'max_stock_age_seconds' => (int) config('edge.standby.max_stock_age_seconds', 300),
            ],
        ];
    }
}
