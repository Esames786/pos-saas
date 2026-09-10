<?php

namespace App\Services\Edge;

use App\Models\Master\EdgeDevice;
use App\Models\Tenant\Branch;
use App\Models\Tenant\EdgeInboundReturnIngestion;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\User;
use App\Services\Sales\SalesReturnService;
use App\Support\Edge\EdgeIdentity;
use App\Support\EdgeRuntime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * OFFLINE EDGE — F1 (Cloud side): exactly-once ingestion of an Edge-originated SALES-RETURN event.
 *
 * A thin authenticated shell around the EXISTING official return authority (SalesReturnService::processReturn — the
 * same validation, stock reversal through FEFO, sales-ledger entry, shift buckets, GL reversal with COGS restock and
 * the cash/bank refund movement Online posts). The Cloud recomputes the return from the requested quantities; an
 * envelope whose refund does not equal the official computation is refused (never "adjusted"). Finance-complete
 * postconditions are verified before APPLIED is ever answered; anything short of that fails atomically.
 *
 * Idempotency/conflict (registry keyed by return_uuid): same uuid + same hash → the stored result, zero effects;
 * same uuid + different hash → hard conflict, no mutation.
 */
class EdgeInboundReturnIngestionService
{
    public const SUPPORTED_SCHEMA = EdgeReturnEnvelopeBuilder::SCHEMA;

    public function __construct(
        private readonly EdgeBootstrapService $canonical,
        private readonly EdgeActivationEpochService $epochs,
        private readonly SalesReturnService $returns,
        private readonly EdgeFinancePostingVerifier $financeVerifier,
    ) {
    }

    public function ingest(array $envelope): array
    {
        if (EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('EdgeInboundReturnIngestionService is Cloud-only; it must never run on a Branch Server.');
        }
        $returnUuid = (string) ($envelope['return_uuid'] ?? '');
        $contentHash = (string) ($envelope['content_hash'] ?? '');
        if (! EdgeIdentity::isValid($returnUuid, EdgeIdentity::FORMAT_ULID)) {
            return $this->refuse($envelope, 'RETURN_UUID_INVALID', 'the envelope has no valid canonical return_uuid');
        }
        if ((string) ($envelope['envelope_schema_version'] ?? '') !== self::SUPPORTED_SCHEMA) {
            return $this->refuse($envelope, 'SCHEMA_UNSUPPORTED', 'unsupported return envelope schema version');
        }
        if (! $this->hashMatches($envelope, $contentHash)) {
            return $this->refuse($envelope, 'HASH_INVALID', 'the content hash is not self-consistent');
        }
        $existing = EdgeInboundReturnIngestion::query()->where('return_uuid', $returnUuid)->first();
        if ($existing !== null) {
            if ($existing->isApplied()) {
                if (! hash_equals((string) $existing->content_hash, $contentHash)) {
                    return $this->conflictAck($existing, $contentHash);
                }

                return array_merge($existing->ack_payload ?? [], ['status' => 'already_applied']);
            }
            if ($existing->status === EdgeInboundReturnIngestion::STATUS_CONFLICT) {
                return $existing->ack_payload;
            }
            if (! hash_equals((string) $existing->content_hash, $contentHash)) {
                return $this->conflictAck($existing, $contentHash);
            }
        }

        try {
            [$device, $branch] = $this->validateAuthority($envelope);
        } catch (IngestionRefusal $e) {
            return $this->refuse($envelope, $e->refusalCode, $e->getMessage());
        }

        try {
            return EdgeIngestionAuthority::run((int) $branch->id, function () use ($envelope, $device, $branch, $returnUuid, $contentHash) {
                return DB::connection('tenant')->transaction(function () use ($envelope, $device, $branch, $returnUuid, $contentHash) {
                    $ingestionUuid = (string) Str::ulid();
                    $registry = $this->claimRegistry($envelope, $device, $branch, $contentHash, $ingestionUuid);

                    $sale = $this->resolveOriginalSale($envelope, $branch);
                    $lines = $this->resolveLines($envelope, $sale);
                    $actor = $this->resolveActor($envelope);
                    $approvalAudit = $this->requireApprovalWhereConfigured($envelope, $branch);
                    $refundMethod = (string) ($envelope['refund_method'] ?? '');
                    if (! in_array($refundMethod, ['cash', 'bank_transfer', 'card', 'other'], true)) {
                        throw new IngestionRefusal('REFUND_METHOD_UNSUPPORTED', 'the envelope refund method is not a known method');
                    }
                    $refundAmount = round((float) ($envelope['totals']['grand_total'] ?? $envelope['refund_amount'] ?? 0), 2);

                    // THE OFFICIAL RETURN AUTHORITY — same effects as Online: validation, FEFO stock in, ledger, shift, GL, cash/bank.
                    try {
                        $return = $this->returns->processReturn($sale, $lines, $envelope['reason'] ?? null, $refundMethod, $refundAmount, (int) $actor->id);
                    } catch (RuntimeException $e) {
                        throw new IngestionRefusal('RETURN_REFUSED', $e->getMessage());
                    }
                    $this->afterOfficialReturn();
                    // FINANCE-COMPLETE OR REFUSE: never APPLIED with GL / stock / cash-bank / ledger effects missing.
                    $this->financeVerifier->verifyPostedReturn($return->fresh()->load(['lines.orderLine.product', 'order']));

                    $ack = $this->buildAck($returnUuid, $contentHash, $ingestionUuid, $return, $sale, (int) $envelope['activation_epoch'], $envelope['config_revision'] ?? null);
                    $registry->update([
                        'status' => EdgeInboundReturnIngestion::STATUS_APPLIED,
                        'failure_code' => null,
                        'sales_order_id' => (int) $sale->id,
                        'sales_return_id' => (int) $return->id,
                        'official_return_no' => (string) $return->return_no,
                        'approval_audit' => $approvalAudit,
                        'ack_payload' => $ack,
                        'ingested_at' => now(),
                    ]);
                    Log::info('[edge-return-ingest] applied', ['return_uuid' => $returnUuid, 'sales_return_id' => $return->id, 'return_no' => $return->return_no]);

                    return $ack;
                });
            });
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains((string) $e->getMessage(), 'return_uuid')) {
                $winner = EdgeInboundReturnIngestion::query()->where('return_uuid', $returnUuid)->first();
                if ($winner && $winner->isApplied()) {
                    return hash_equals((string) $winner->content_hash, $contentHash) ? $winner->ack_payload : $this->conflictAck($winner, $contentHash);
                }
            }

            return $this->recordException($envelope, $contentHash, 'DB_ERROR', $e->getMessage());
        } catch (IngestionRefusal $e) {
            return $this->recordException($envelope, $contentHash, $e->refusalCode, $e->getMessage());
        } catch (Throwable $e) {
            return $this->recordException($envelope, $contentHash, 'INGEST_FAILED', $e->getMessage());
        }
    }

    /**
     * TEST-ONLY seam: production no-op. The MySQL proof flips `edge.testing.fail_after_official_return` to make the
     * step AFTER the official return posted fail, proving finance-complete-or-refuse rolls the whole ingestion back.
     */
    protected function afterOfficialReturn(): void
    {
        if (config('edge.testing.fail_after_official_return', false) === true) {
            throw new RuntimeException('TEST SEAM: simulated failure after the official return posted');
        }
    }

    /** @return array{0: EdgeDevice, 1: Branch} */
    private function validateAuthority(array $envelope): array
    {
        $device = EdgeDevice::query()->where('public_uuid', (string) ($envelope['device_public_uuid'] ?? ''))->first();
        if (! $device) {
            throw new IngestionRefusal('DEVICE_UNKNOWN', 'no such Edge device');
        }
        if ($device->isRevoked() || $device->active_slot !== EdgeDevice::ACTIVE_SLOT) {
            throw new IngestionRefusal('DEVICE_REVOKED', 'the Edge device is revoked or not the active slot');
        }
        if ((int) $device->tenant_id !== (int) ($envelope['tenant_id'] ?? 0)) {
            throw new IngestionRefusal('WRONG_TENANT', 'the envelope tenant does not match the device');
        }
        if ((int) $device->branch_id !== (int) ($envelope['branch_id'] ?? 0)) {
            throw new IngestionRefusal('WRONG_BRANCH', 'the envelope branch does not match the device');
        }
        $branch = Branch::on('tenant')->find((int) $envelope['branch_id']);
        if (! $branch) {
            throw new IngestionRefusal('BRANCH_UNKNOWN', 'the branch does not resolve in this tenant');
        }
        $current = $this->epochs->currentGeneration((int) $device->tenant_id, (int) $branch->id);
        if ($current === 0 || (int) ($envelope['activation_epoch'] ?? -1) !== $current) {
            throw new IngestionRefusal('STALE_ACTIVATION', "activation epoch {$envelope['activation_epoch']} is not the current generation {$current}");
        }
        if (($envelope['event_type'] ?? '') !== 'sales_return' || ! is_array($envelope['lines'] ?? null) || $envelope['lines'] === []) {
            throw new IngestionRefusal('RETURN_INVALID', 'the envelope is not a sales_return event with lines');
        }

        return [$device, $branch];
    }

    /** The ORIGINAL Cloud sale: by Cloud id (a Cloud-created sale returned offline) or by sale_uuid (an Edge sale the Cloud ingested). */
    private function resolveOriginalSale(array $envelope, Branch $branch): SalesOrder
    {
        $origin = $envelope['original'] ?? [];
        $kind = (string) ($origin['kind'] ?? '');
        if ($kind === 'cloud') {
            $sale = SalesOrder::on('tenant')->find((int) ($origin['cloud_sales_order_id'] ?? 0));
            if (! $sale) {
                throw new IngestionRefusal('ORIGINAL_SALE_UNKNOWN', 'the original Cloud sale does not exist');
            }
        } elseif ($kind === 'edge') {
            $uuid = (string) ($origin['sale_uuid'] ?? '');
            $sale = $uuid !== '' ? SalesOrder::on('tenant')->where('sale_uuid', $uuid)->first() : null;
            if (! $sale) {
                // Retryable: the sale's own envelope may still be in flight (the outbox sends in order).
                throw new IngestionRefusal('ORIGINAL_SALE_NOT_INGESTED', 'the original Edge sale has not been ingested yet');
            }
        } else {
            throw new IngestionRefusal('RETURN_INVALID', 'unknown original sale kind');
        }
        if ((int) $sale->branch_id !== (int) $branch->id) {
            throw new IngestionRefusal('WRONG_BRANCH', 'the original sale belongs to another branch');
        }
        if (! in_array((string) $sale->status, ['paid', 'partially_returned'], true)) {
            throw new IngestionRefusal('RETURN_REFUSED', 'the original sale is not returnable (status ' . $sale->status . ')');
        }

        return $sale;
    }

    /** @return array<int,array{sales_order_line_id:int, quantity:float}> */
    private function resolveLines(array $envelope, SalesOrder $sale): array
    {
        $out = [];
        foreach ($envelope['lines'] as $l) {
            $line = null;
            if (! empty($l['cloud_sales_order_line_id'])) {
                $line = SalesOrderLine::on('tenant')->where('id', (int) $l['cloud_sales_order_line_id'])->where('sales_order_id', $sale->id)->first();
            } elseif (! empty($l['line_uuid'])) {
                $line = SalesOrderLine::on('tenant')->where('line_uuid', (string) $l['line_uuid'])->where('sales_order_id', $sale->id)->first();
            }
            if (! $line) {
                throw new IngestionRefusal('RETURN_LINE_UNKNOWN', 'a return line does not resolve to a line of the original sale');
            }
            if ((int) $line->product_id !== (int) $l['product_id']) {
                throw new IngestionRefusal('RETURN_LINE_UNKNOWN', 'a return line names a different product than the original line');
            }
            $out[] = ['sales_order_line_id' => (int) $line->id, 'quantity' => (float) $l['quantity']];
        }

        return $out;
    }

    private function resolveActor(array $envelope): User
    {
        $user = User::on('tenant')->find((int) ($envelope['actor']['user_id'] ?? 0));
        if (! $user) {
            throw new IngestionRefusal('ACTOR_UNKNOWN', 'the return actor does not resolve to a tenant user');
        }

        return $user;
    }

    /** Where the branch requires a manager, the envelope must carry the appliance's approval audit by a real, active user. */
    private function requireApprovalWhereConfigured(array $envelope, Branch $branch): ?array
    {
        $required = ($branch->sales_return_approval_mode ?? Branch::SALES_RETURN_AUTO_APPROVE) !== Branch::SALES_RETURN_AUTO_APPROVE;
        $approval = $envelope['approval'] ?? null;
        if (! $required) {
            return is_array($approval) ? $approval : null;
        }
        if (! is_array($approval) || empty($approval['approved_by_user_id']) || empty($approval['approved_at'])) {
            throw new IngestionRefusal('APPROVAL_REQUIRED', 'this branch requires a manager approval for returns; the event carries none');
        }
        $approver = User::on('tenant')->find((int) $approval['approved_by_user_id']);
        if (! $approver || (string) $approver->status !== 'active') {
            throw new IngestionRefusal('APPROVER_UNAUTHORIZED', 'the approving manager does not resolve to an active tenant user');
        }
        $binding = $approval['binding'] ?? [];
        if (abs(round((float) ($binding['refund_amount'] ?? -1), 2) - round((float) ($envelope['totals']['grand_total'] ?? 0), 2)) > 0.01) {
            throw new IngestionRefusal('APPROVAL_REQUIRED', 'the approval is bound to a different refund amount than the event');
        }

        return $approval;
    }

    private function claimRegistry(array $envelope, EdgeDevice $device, Branch $branch, string $contentHash, string $ingestionUuid): EdgeInboundReturnIngestion
    {
        return EdgeInboundReturnIngestion::updateOrCreate(['return_uuid' => (string) $envelope['return_uuid']], [
            'content_hash' => $contentHash,
            'envelope_schema_version' => (string) $envelope['envelope_schema_version'],
            'tenant_id' => (int) $device->tenant_id,
            'branch_id' => (int) $branch->id,
            'device_public_uuid' => (string) $device->public_uuid,
            'activation_epoch' => (int) $envelope['activation_epoch'],
            'config_revision' => $envelope['config_revision'] ?? null,
            'ingestion_uuid' => $ingestionUuid,
            'status' => EdgeInboundReturnIngestion::STATUS_EXCEPTION,
            'failure_code' => 'IN_PROGRESS',
        ]);
    }

    private function buildAck(string $returnUuid, string $contentHash, string $ingestionUuid, SalesReturn $return, SalesOrder $sale, int $epoch, ?int $configRevision): array
    {
        return [
            'status' => 'applied',
            'sale_uuid' => $returnUuid,           // transport identity (the outbox row's key)
            'return_uuid' => $returnUuid,
            'content_hash' => $contentHash,
            'ingestion_uuid' => $ingestionUuid,
            'sales_return_id' => (int) $return->id,
            'official_return_no' => (string) $return->return_no,
            'sales_order_id' => (int) $sale->id,
            'activation_epoch' => $epoch,
            'config_revision' => $configRevision,
            'ingested_at' => now()->toIso8601String(),
        ];
    }

    private function conflictAck(EdgeInboundReturnIngestion $existing, string $incomingHash): array
    {
        return [
            'status' => 'conflict',
            'failure_code' => 'ENVELOPE_CONFLICT',
            'sale_uuid' => (string) $existing->return_uuid,
            'return_uuid' => (string) $existing->return_uuid,
            'content_hash' => (string) $existing->content_hash,
            'incoming_content_hash' => $incomingHash,
            'message' => 'this return_uuid was already ingested with different content; the first accepted truth is authoritative',
        ];
    }

    private function refuse(array $envelope, string $code, string $message): array
    {
        $ack = ['status' => 'refused', 'failure_code' => $code, 'sale_uuid' => (string) ($envelope['return_uuid'] ?? ''), 'return_uuid' => (string) ($envelope['return_uuid'] ?? ''), 'message' => $message];
        $this->persistTerminal($envelope, (string) ($envelope['content_hash'] ?? ''), EdgeInboundReturnIngestion::STATUS_REFUSED, $code, $message, $ack);

        return $ack;
    }

    private function recordException(array $envelope, string $contentHash, string $code, string $message): array
    {
        $ack = ['status' => 'exception', 'failure_code' => $code, 'sale_uuid' => (string) ($envelope['return_uuid'] ?? ''), 'return_uuid' => (string) ($envelope['return_uuid'] ?? ''), 'message' => $message];
        $this->persistTerminal($envelope, $contentHash, EdgeInboundReturnIngestion::STATUS_EXCEPTION, $code, $message, $ack);

        return $ack;
    }

    /** Persist a non-applied outcome in its OWN transaction; never overwrite an applied row. */
    private function persistTerminal(array $envelope, string $contentHash, string $status, string $code, string $message, array $ack): void
    {
        $uuid = (string) ($envelope['return_uuid'] ?? '');
        if (! EdgeIdentity::isValid($uuid, EdgeIdentity::FORMAT_ULID)) {
            return;
        }
        try {
            DB::connection('tenant')->transaction(function () use ($envelope, $uuid, $contentHash, $status, $code, $message, $ack) {
                $row = EdgeInboundReturnIngestion::query()->where('return_uuid', $uuid)->lockForUpdate()->first();
                if ($row && $row->isApplied()) {
                    return;
                }
                EdgeInboundReturnIngestion::updateOrCreate(['return_uuid' => $uuid], [
                    'content_hash' => $contentHash,
                    'envelope_schema_version' => (string) ($envelope['envelope_schema_version'] ?? ''),
                    'tenant_id' => (int) ($envelope['tenant_id'] ?? 0),
                    'branch_id' => (int) ($envelope['branch_id'] ?? 0),
                    'device_public_uuid' => (string) ($envelope['device_public_uuid'] ?? ''),
                    'activation_epoch' => (int) ($envelope['activation_epoch'] ?? 0),
                    'config_revision' => $envelope['config_revision'] ?? null,
                    'ingestion_uuid' => $row?->ingestion_uuid ?? (string) Str::ulid(),
                    'status' => $status,
                    'failure_code' => $code,
                    'last_error' => mb_substr($message, 0, 2000),
                    'ack_payload' => $ack,
                ]);
            });
        } catch (Throwable $e) {
            Log::warning('[edge-return-ingest] could not persist terminal outcome', ['return_uuid' => $uuid, 'error' => $e->getMessage()]);
        }
    }

    private function hashMatches(array $envelope, string $contentHash): bool
    {
        if ($contentHash === '') {
            return false;
        }
        $copy = $envelope;
        unset($copy['content_hash']);

        return hash_equals(hash('sha256', $this->canonical->canonicalJson($copy)), $contentHash);
    }
}
